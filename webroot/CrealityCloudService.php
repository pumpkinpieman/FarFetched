<?php
/**
 * CrealityCloudService — search + whole-model download for crealitycloud.com.
 *
 * Auth model (session-based, pasted from the browser):
 *   - token        : the __CXY_TOKEN_ header value (identical to the
 *                    `model_token` cookie) — a 64-char hex string.
 *   - userId       : the model_user_id / __CXY_UID_ value.
 *   - cfClearance  : the Cloudflare cf_clearance cookie (anti-bot gate).
 *
 * The public web API is JSON over POST under /api/cxy/. Every call carries a
 * family of __CXY_* headers (platform, app id/version, brand) plus the cookie
 * jar. Downloads are clean: memberDownload returns an array of signed URLs to
 * the real .stl/.3mf files (no .cxbin, no encryption).
 *
 * Flow:
 *   search          POST /api/cxy/smart_search/v1/model   {keyword,page,pageSize}
 *   list files      POST /api/cxy/v3/model/fileListPage    {modelId,page,pageSize}
 *   get URLs        POST /api/cxy/v3/model/memberDownload  {modelId,trailType:2}
 *                     -> result.downloadUrls[] = {fileName,url,size,fileId,expire}
 *   fetch each url  GET  <signed url>                       (auth_key in query)
 */
final class CrealityCloudService
{
    private const BASE = 'https://www.crealitycloud.com';
    private const UA   = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:151.0) Gecko/20100101 Firefox/151.0';

    public string $lastError = '';
    public int    $lastTotal = 0;

    private string $token;
    private string $userId;
    private string $cfClearance;
    private string $duid;

    public function __construct(?string $token = null, ?string $userId = null, ?string $cfClearance = null)
    {
        $this->token       = trim($token       ?? (function_exists('cfg') ? (string) cfg('creality_token')        : ''));
        $this->userId      = trim($userId      ?? (function_exists('cfg') ? (string) cfg('creality_user_id')      : ''));
        $this->cfClearance = trim($cfClearance ?? (function_exists('cfg') ? (string) cfg('creality_cf_clearance') : ''));
        // A stable device UUID. Reuse a stored one or synthesize a constant; the
        // API only needs it to be present and consistent.
        $stored = function_exists('cfg') ? (string) cfg('creality_duid') : '';
        $this->duid = $stored !== '' ? $stored : 'uuid-8bd6ae2a-e1b5-45d4-97d1-1192ba9b98d8';
    }

    public function isAuthed(): bool
    {
        return $this->token !== '' && $this->userId !== '';
    }

    /**
     * Fetch the category tree and flatten it to id => label.
     * Creality returns a nested tree; we surface top-level + one level of
     * children, prefixing children with their parent for clarity.
     * @return array<string,string>
     */
    public function categories(): array
    {
        $this->lastError = '';
        if (!$this->isAuthed()) return [];

        $resp = $this->apiPost('/api/cxy/v2/common/categoryList', ['type' => 7]);
        if ($resp === null) return [];
        $list = $resp['result']['list'] ?? [];
        if (!is_array($list)) return [];

        $out = [];
        foreach ($list as $top) {
            if (!is_array($top)) continue;
            $tid   = (string) ($top['id'] ?? '');
            $tname = (string) ($top['en_name'] ?? ($top['name'] ?? ''));
            if ($tid === '' || $tname === '') continue;
            $out[$tid] = $tname;
            // One level of children, labelled "Parent › Child".
            $kids = $top['children'] ?? [];
            if (is_array($kids)) {
                foreach ($kids as $c) {
                    if (!is_array($c)) continue;
                    $cid   = (string) ($c['id'] ?? '');
                    $cname = (string) ($c['en_name'] ?? ($c['name'] ?? ''));
                    if ($cid === '' || $cname === '') continue;
                    $out[$cid] = $tname . ' › ' . $cname;
                }
            }
        }
        return $out;
    }

    /**
     * Browse models within a category via the trending/feed endpoint.
     * @return array<int,array<string,mixed>>
     */
    public function browseCategory(string $categoryId, int $limit = 24, int $page = 1): array
    {
        $this->lastError = '';
        $this->lastTotal = 0;
        if (!$this->isAuthed()) {
            $this->lastError = 'Creality Cloud credentials not set.';
            return [];
        }

        $body = [
            'page'                  => max(1, $page),
            'pageSize'              => max(1, min(30, $limit)),
            'trendType'             => 3,
            'filterType'            => 10,
            'isPay'                 => 0,
            'isExclusive'          => 0,
            'promoType'             => 0,
            'isVip'                 => 0,
            'multiMark'             => 0,
            'hasCfgFile'            => 0,
            'hasCubeMeModel'        => 1,
            'isPrintAlwaysSuccess'  => 0,
            'isPickModel'           => 0,
        ];
        if ($categoryId !== '') {
            // Creality filters by an array of category IDs, not a single string.
            $body['categoryIds'] = [$categoryId];
        }

        $resp = $this->apiPost('/api/cxy/v3/model/listTrend', $body);
        if ($resp === null) return [];
        $list = $resp['result']['list'] ?? [];
        if (!is_array($list)) $list = [];
        $this->lastTotal = (int) ($resp['result']['total'] ?? count($list));
        return $this->normalize($list);
    }

    // ---- public API ----------------------------------------------------------

    /**
     * Keyword search. Creality has no "browse all" without a keyword, so an
     * empty query returns nothing (the UI should prompt for a search term).
     * @return array<int,array<string,mixed>>
     */
    public function search(string $query = '', int $limit = 20, int $page = 1): array
    {
        $this->lastError = '';
        $this->lastTotal = 0;

        if (!$this->isAuthed()) {
            $this->lastError = 'Creality Cloud credentials not set (paste token + user ID in Settings).';
            return [];
        }
        $query = trim($query);
        if ($query === '') {
            // No keyword: nothing to show (search-only source).
            return [];
        }

        $resp = $this->apiPost('/api/cxy/smart_search/v1/model', [
            'page'     => max(1, $page),
            'pageSize' => max(1, min(30, $limit)),
            'keyword'  => $query,
        ]);
        if ($resp === null) return [];

        $list = $resp['result']['list'] ?? [];
        if (!is_array($list)) $list = [];
        $this->lastTotal = (int) ($resp['result']['total'] ?? count($list));
        return $this->normalize($list);
    }

    /**
     * List the individual files (STL/3MF) inside a model group.
     * @return array<int,array<string,mixed>>
     */
    public function listFiles(string $modelId, int $page = 1, int $pageSize = 50): array
    {
        $this->lastError = '';
        $resp = $this->apiPost('/api/cxy/v3/model/fileListPage', [
            'modelId'  => $modelId,
            'page'     => max(1, $page),
            'pageSize' => max(1, min(199, $pageSize)),
        ]);
        if ($resp === null) return [];
        $list = $resp['result']['list'] ?? [];
        return is_array($list) ? $list : [];
    }

    /**
     * Resolve every downloadable file in a model to a signed URL.
     * Returns a list of ['fileName'=>, 'url'=>, 'size'=>, 'fileId'=>].
     * @return array<int,array<string,mixed>>
     */
    public function resolveDownloadUrls(string $modelId): array
    {
        $this->lastError = '';
        $resp = $this->apiPost('/api/cxy/v3/model/memberDownload', [
            'modelId'   => $modelId,
            'trailType' => 2,
        ]);
        if ($resp === null) return [];

        $urls = $resp['result']['downloadUrls'] ?? [];
        if (!is_array($urls) || $urls === []) {
            $this->lastError = $this->lastError !== '' ? $this->lastError
                : 'No download URLs returned (model may be paid or region-locked).';
            return [];
        }
        $out = [];
        foreach ($urls as $u) {
            if (!is_array($u)) continue;
            $url = (string) ($u['url'] ?? '');
            if ($url === '') continue;
            $out[] = [
                'fileName' => (string) ($u['fileName'] ?? ''),
                'url'      => $url,
                'size'     => (int) ($u['size'] ?? 0),
                'fileId'   => (string) ($u['fileId'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Download every file in a model into $destDir. Returns the number of files
     * saved (0 on failure, with lastError set).
     */
    public function downloadModel(string $modelId, string $destDir, ?callable $onProgress = null): int
    {
        $this->lastError = '';
        if (!$this->isAuthed()) {
            $this->lastError = 'Creality Cloud credentials not set.';
            return 0;
        }
        $files = $this->resolveDownloadUrls($modelId);
        if ($files === []) {
            return 0;
        }
        if (!is_dir($destDir) && !@mkdir($destDir, 0777, true) && !is_dir($destDir)) {
            $this->lastError = 'Cannot create destination dir: ' . $destDir;
            return 0;
        }

        $saved = 0;
        foreach ($files as $f) {
            $name = $this->safeName($f['fileName'], $f['url']);
            $dest = rtrim($destDir, '/') . '/' . $name;
            if ($this->downloadToFile($f['url'], $dest, $onProgress)) {
                $saved++;
            } else {
                // Keep going; report the last error but try the rest.
                logfn_safe('Creality file failed: ' . $name . ' — ' . $this->lastError);
            }
        }
        if ($saved === 0 && $this->lastError === '') {
            $this->lastError = 'No files could be downloaded.';
        }
        return $saved;
    }

    /** Validate credentials by issuing a cheap authenticated call. */
    public function validate(): bool
    {
        $this->lastError = '';
        $resp = $this->apiPost('/api/cxy/v2/common/getConfigVersion', new \stdClass());
        if ($resp === null) {
            if ($this->lastError === '') $this->lastError = 'Validation failed.';
            return false;
        }
        if ((int) ($resp['code'] ?? -1) !== 0) {
            $this->lastError = 'Creality rejected the token (code ' . ($resp['code'] ?? '?') . '). Re-paste a fresh token/cf_clearance.';
            return false;
        }
        return true;
    }

    /** Download a single signed URL to a path. */
    public function downloadToFile(string $url, string $destPath, ?callable $onProgress = null): bool
    {
        $this->lastError = '';
        $dir = dirname($destPath);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            $this->lastError = 'Cannot create destination dir: ' . $dir;
            return false;
        }
        $tmp = $destPath . '.part';
        $fh  = @fopen($tmp, 'wb');
        if ($fh === false) { $this->lastError = 'Cannot open temp file.'; return false; }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fh,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_FAILONERROR    => true,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_ENCODING       => 'gzip, deflate, br',
            CURLOPT_HTTPHEADER     => [
                'User-Agent: ' . self::UA,
                'Accept: */*',
            ],
        ]);
        if ($onProgress !== null) {
            curl_setopt($ch, CURLOPT_NOPROGRESS, false);
            curl_setopt($ch, CURLOPT_XFERINFOFUNCTION,
                static function ($ch, $dt, $dn, $ut, $un) use ($onProgress) {
                    $onProgress((int) $dt, (int) $dn); return 0;
                }
            );
        }
        $ok     = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr   = curl_error($ch);
        curl_close($ch);
        fclose($fh);

        if ($ok === false || $status >= 400) {
            @unlink($tmp);
            $this->lastError = $cerr !== '' ? 'Download error: ' . $cerr : 'HTTP ' . $status;
            return false;
        }
        if (!@rename($tmp, $destPath)) {
            @unlink($tmp);
            $this->lastError = 'Could not finalize file (rename failed).';
            return false;
        }
        return true;
    }

    // ---- internals -----------------------------------------------------------

    /**
     * POST a JSON body to a Creality API path and return the decoded array.
     * Returns null on transport/HTTP/error-code failure (lastError set).
     * @param array<string,mixed>|\stdClass $body
     * @return array<string,mixed>|null
     */
    private function apiPost(string $path, $body): ?array
    {
        $url  = self::BASE . $path;
        $json = json_encode($body, JSON_UNESCAPED_SLASHES);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_ENCODING       => 'gzip, deflate, br',
            CURLOPT_HTTPHEADER     => $this->headers(),
        ]);
        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr   = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            $this->lastError = 'Network error: ' . $cerr;
            return null;
        }
        if ($status === 403) {
            $this->lastError = 'Creality returned 403 (Cloudflare). Re-paste a fresh cf_clearance cookie in Settings.';
            return null;
        }
        if ($status >= 400) {
            $this->lastError = 'HTTP ' . $status . ' from Creality.';
            return null;
        }
        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            $this->lastError = 'Unexpected response from Creality (not JSON).';
            return null;
        }
        // Creality wraps everything in {code,msg,result}. code 0 == ok.
        if (isset($data['code']) && (int) $data['code'] !== 0) {
            $msg = (string) ($data['msg'] ?? '');
            // A token/login failure usually comes back as a non-zero code here.
            if (stripos($msg, 'login') !== false || stripos($msg, 'token') !== false || (int) $data['code'] === 401) {
                $this->lastError = 'Creality auth failed — re-paste token in Settings.';
            } else {
                $this->lastError = 'Creality error: ' . ($msg !== '' ? $msg : ('code ' . $data['code']));
            }
            return null;
        }
        return $data;
    }

    /** The required __CXY_* header family + cookie jar. */
    private function headers(): array
    {
        return [
            'User-Agent: ' . self::UA,
            'Accept: application/json, text/plain, */*',
            'Accept-Language: en-US,en;q=0.9',
            'Content-Type: application/json',
            'Origin: ' . self::BASE,
            '__CXY_OS_VER_: Windows 10',
            '__CXY_OS_LANG_: 0',
            '__CXY_PLATFORM_: 2',
            '__CXY_BRAND_: creality',
            '__CXY_APP_CH_: Firefox 151.0',
            '__CXY_DUID_: ' . $this->duid,
            '__CXY_APP_ID_: creality_model',
            '__CXY_TOKEN_: ' . $this->token,
            '__CXY_UID_: ' . $this->userId,
            '__CXY_APP_VER_: 7.3.10',
            '__CXY_TIMEZONE_: -14400',
            '__CXY_JWTOKEN_: ',
            '_x_cxy_ehrtoken_: ',
            'Cookie: ' . $this->cookieHeader(),
        ];
    }

    private function cookieHeader(): string
    {
        $parts = [
            'model_device_id='   . $this->duid,
            'model_os_version='  . rawurlencode('Windows 10'),
            'model_platform_type=2',
            'timeZone=-14400',
            'sensorsObjType=1',
            'model_lang=0',
            'cre-theme=dark',
            'model_token='   . $this->token,
            'model_user_id=' . $this->userId,
        ];
        if ($this->cfClearance !== '') {
            $parts[] = 'cf_clearance=' . $this->cfClearance;
        }
        return implode('; ', $parts);
    }

    /**
     * Map Creality search results to FarFetched's model shape.
     * @param array<int,mixed> $items
     * @return array<int,array<string,mixed>>
     */
    private function normalize(array $items): array
    {
        $out = [];
        foreach ($items as $it) {
            if (!is_array($it)) continue;

            $id   = (string) ($it['id'] ?? '');
            $name = (string) ($it['groupName'] ?? ($it['name'] ?? 'Untitled'));

            // First usable cover image.
            $thumb  = '';
            $covers = $it['covers'] ?? [];
            if (is_array($covers)) {
                foreach ($covers as $c) {
                    $u = is_array($c) ? (string) ($c['url'] ?? '') : '';
                    if ($u !== '') { $thumb = $u; break; }
                }
            }
            $images = $thumb !== '' ? [$thumb] : [];

            // Payment flag: list/search results carry isPay (bool). Some also
            // carry isExclusive/promoType for premium content. Any of these →
            // treat as paid so the UI can badge it.
            $isPaid = !empty($it['isPay'])
                || !empty($it['isExclusive'])
                || ((int) ($it['promoType'] ?? 0) > 0);

            // Creality's list/search endpoints don't return author names (only a
            // numeric userId, and there's no batch name lookup), so we leave the
            // creator blank — the UI hides the author line when it's empty.
            $creator = '';

            $out[] = [
                'id'      => $id,
                'slug'    => $id,                      // Creality keys everything off the group id
                'name'    => $name,
                'creator' => $creator,
                'thumb'   => $thumb,
                'images'  => array_values(array_filter($images)),
                'size'    => (int) ($it['totalFileSize'] ?? 0),
                'price'   => $isPaid ? 1 : 0,          // 1 = paid (UI shows "Payment Required" badge)
                'source'  => 'creality',
            ];
        }
        return $out;
    }

    /** Derive a safe on-disk filename from the API name and/or signed URL. */
    private function safeName(string $fileName, string $url): string
    {
        $name = trim($fileName);
        // If the API name lacks an extension, pull one from the URL path.
        if ($name === '' || !preg_match('/\.[A-Za-z0-9]{2,5}$/', $name)) {
            $path = (string) parse_url($url, PHP_URL_PATH);
            $base = $path !== '' ? basename($path) : '';
            if ($base !== '') {
                // Use the URL basename's extension, keep the friendly name if present.
                $ext = pathinfo($base, PATHINFO_EXTENSION);
                if ($name === '') {
                    $name = $base;
                } elseif ($ext !== '') {
                    $name .= '.' . $ext;
                }
            }
        }
        if ($name === '') $name = 'model.bin';
        // Strip path separators / unsafe chars.
        $name = str_replace(['/', '\\', "\0"], '_', $name);
        $name = preg_replace('/[^A-Za-z0-9 ._\-()\[\]]+/', '_', $name) ?? $name;
        return $name;
    }
}

/** Best-effort logger that won't fatal if the worker logger isn't loaded. */
if (!function_exists('logfn_safe')) {
    function logfn_safe(string $msg): void
    {
        if (function_exists('logln')) { logln($msg); }
    }
}
