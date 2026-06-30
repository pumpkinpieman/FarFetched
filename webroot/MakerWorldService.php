<?php
declare(strict_types=1);

/**
 * MakerWorldService
 *
 * Second model source for FarFetched, alongside PrintablesService. Mirrors that
 * class's public contract so the worker/UI can treat sources interchangeably.
 *
 * Confirmed API surface (reverse-engineered from MakerWorld web client):
 *   - Search  : GET  /api/v1/search-service/select/design2
 *               ?keyword=&limit=&offset=&orderBy=score&designType=0
 *               Returns {"total":N,"hits":[ {id,title,slug,cover,
 *                        designCreator:{name,handle}, nsfw,
 *                        designExtension:{model_files:[…]}}, … ]}
 *               No auth needed; requires the X-BBL-* client headers.
 *   - Zip link: GET  /api/v1/design-service/design/{designId}/model
 *               ?modelType=all&type=download   (needs `token` cookie)
 *               Returns JSON containing a signed makerworld.bblmw.com .zip URL.
 *   - Download: GET  https://makerworld.bblmw.com/.../all.zip?at=&exp=&key=&uid=
 *               Auth-free, ~5 min expiry. Plain GET (mirror Printables).
 *
 * MakerWorld downloads are always "whole-model ZIP" (PACK-equivalent).
 */
final class MakerWorldService
{
    private const API   = 'https://makerworld.com/api/v1';
    private const UA    = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) FarFetched/1.0';
    private const CDN_RE = '~https://makerworld\.bblmw\.com/[^\s"\'\\\\]+~';

    public string $lastError      = '';
    public int    $lastTotalCount = 0;
    /** Raw hit count of the most recent search page (before NSFW filtering). */
    public int    $lastPageHitCount = 0;
    /** Raw body of the most recent API call — surfaced for debugging empty parses. */
    public string $lastRaw        = '';

    private string $token;

    public function __construct(?string $token = null)
    {
        // Falls back to the stored MakerWorld token when not explicitly passed.
        $this->token = $token ?? (function_exists('cfg') ? (string) cfg('makerworld_token') : '');
        $this->token = trim($this->token);
    }

    public function isAuthed(): bool
    {
        return $this->token !== '';
    }

    /**
     * Keyword search. Returns a list of normalized model rows:
     *   ['id','slug','name','creator','thumb','size','nsfw','source'=>'makerworld']
     * `size` is 0 when MakerWorld doesn't expose file sizes for that hit.
     *
     * @return array<int,array<string,mixed>>
     */
    public function searchByKeyword(string $query, int $limit = 20, int $offset = 0, bool $showNsfw = false, string $categoryId = ''): array
    {
        $this->lastError = '';
        $this->lastTotalCount = 0;
        $this->lastPageHitCount = 0;

        $categoryId = preg_replace('/[^0-9]/', '', $categoryId);
        $isBrowse   = ($categoryId !== '' || $query === '');

        $params = [
            'keyword'    => $query,
            'limit'      => max(1, $limit),
            'offset'     => max(0, $offset),
            // Browsing (category / All Models) ranks by hotScore like MakerWorld's
            // own category pages; keyword search ranks by relevance (score).
            'orderBy'    => $isBrowse ? 'hotScore' : 'score',
            'designType' => 0,
        ];
        if ($isBrowse) {
            // Confirmed category filter: ?categories={numericId} (bare id, plural key).
            // Empty = All Models (popular browse). entrance=list matches the site.
            if ($categoryId !== '') {
                $params['categories'] = $categoryId;
            }
            $params['designCreateSince'] = 0;
            $params['entrance']          = 'list';
        } else {
            $params['isFromSearchList'] = 'false';
        }
        $json = $this->apiGet(self::API . '/search-service/select/design2?' . http_build_query($params));
        if ($json === null) {
            // lastError already set by apiGet
            return [];
        }

        $this->lastTotalCount = (int) ($json['total'] ?? 0);
        $hits = $json['hits'] ?? null;
        if (!is_array($hits)) {
            $this->lastError = 'Unexpected search response (no hits array).';
            return [];
        }
        // Raw hit count BEFORE NSFW filtering — drives pagination independently of
        // `total` (MakerWorld's popular/All-Models feed often returns total=0).
        $this->lastPageHitCount = count($hits);

        $out = [];
        foreach ($hits as $hit) {
            if (!is_array($hit)) {
                continue;
            }
            $nsfw = (bool) ($hit['nsfw'] ?? false);
            if ($nsfw && !$showNsfw) {
                continue; // hide adult content unless explicitly allowed
            }

            $creator = '';
            if (isset($hit['designCreator']) && is_array($hit['designCreator'])) {
                $creator = (string) ($hit['designCreator']['name'] ?? $hit['designCreator']['handle'] ?? '');
            }

            $size = 0;
            if (isset($hit['designExtension']['model_files']) && is_array($hit['designExtension']['model_files'])) {
                $size = $this->sumModelFiles($hit['designExtension']['model_files']);
            }

            // Gallery images for the hover slider. design_pictures[0] is the cover,
            // so the slider opens on the same image as the static thumb. Each URL is
            // forced through the OSS transform to a small webp — this also collapses
            // multi-MB animated GIFs to a light static frame, keeping the grid cheap.
            $images = [];
            $cover  = (string) ($hit['cover'] ?? '');
            if (isset($hit['designExtension']['design_pictures']) && is_array($hit['designExtension']['design_pictures'])) {
                foreach ($hit['designExtension']['design_pictures'] as $pic) {
                    if (!is_array($pic)) {
                        continue;
                    }
                    $u = (string) ($pic['url'] ?? '');
                    if ($u === '') {
                        continue;
                    }
                    $u = $this->thumbTransform($u);
                    if (!in_array($u, $images, true)) {
                        $images[] = $u;
                    }
                    if (count($images) >= 8) { // cap gallery size
                        break;
                    }
                }
            }
            $thumb = $cover !== '' ? $this->thumbTransform($cover) : '';
            if ($thumb !== '' && (empty($images) || $images[0] !== $thumb)) {
                array_unshift($images, $thumb);
                $images = array_slice(array_values(array_unique($images)), 0, 8);
            }

            $out[] = [
                'id'      => (string) ($hit['id'] ?? ''),
                'slug'    => (string) ($hit['slug'] ?? ''),
                'name'    => (string) ($hit['title'] ?? ''),
                'creator' => $creator,
                'thumb'   => $thumb,
                'images'  => $images,
                'size'    => $size,
                'nsfw'    => $nsfw,
                'source'  => 'makerworld',
            ];
        }
        return $out;
    }

    /**
     * Mint a signed download URL for a design (auth required).
     * Always fetches the combined whole-model pack (all formats included).
     * Tries, in order:
     *   1. design/{id}/model?modelType=all&type=download  → combined pack
     *   2. design/{id}/model?type=download                → plain download doc
     *   3. instance/{id}/f3mf?type=download               → per-instance fallback
     * Returns the first signed makerworld.bblmw.com URL found, or '' on failure.
     */
    /**
     * Print stats for a design's default (or best) profile, from the same
     * design endpoint we already hit. MakerWorld stores, per instance:
     *   prediction = total print seconds, weight = total grams.
     * We pick the default instance (defaultInstanceId) when present, else the
     * first, and also surface the colour count and a couple of alternates.
     *
     * @return array{ok:bool,printSeconds?:int,weightG?:int,colors?:int,plates?:int,profiles?:array}
     */
    /**
     * Cover image URL for a single design, or '' if unavailable. Uses the same
     * design-service endpoint as getPrintStats; the design object carries the
     * cover (and/or a design_pictures gallery whose first entry is the cover).
     */
    public function coverForModel(string $designId): string
    {
        $json = $this->apiGet(self::API . '/design-service/design/' . $designId);
        if (!is_array($json)) return '';
        // The design endpoint returns fields at the top level (no data wrapper).
        // Verified field names: coverUrl (primary), coverPortrait / coverLandscape
        // as fallbacks. Values are already absolute https URLs.
        $root = (isset($json['data']) && is_array($json['data'])) ? $json['data'] : $json;
        foreach (['coverUrl', 'coverPortrait', 'coverLandscape'] as $key) {
            $u = trim((string) ($root[$key] ?? ''));
            if ($u !== '') return $u;
        }
        return '';
    }

    public function getPrintStats(string $designId): array
    {
        $json = $this->apiGet(self::API . '/design-service/design/' . $designId);
        if (!is_array($json)) {
            return ['ok' => false];
        }
        $root = $json;
        if (isset($json['data']) && is_array($json['data'])) {
            $root = $json['data'];
        }
        $instances = $root['instances'] ?? [];
        if (!is_array($instances) || $instances === []) {
            return ['ok' => false];
        }

        $defaultId = (string) ($root['defaultInstanceId'] ?? '');

        // Choose the default instance, else the first with a prediction.
        $chosen = null;
        foreach ($instances as $inst) {
            if ($defaultId !== '' && (string) ($inst['id'] ?? '') === $defaultId) {
                $chosen = $inst;
                break;
            }
        }
        if ($chosen === null) {
            foreach ($instances as $inst) {
                if ((int) ($inst['prediction'] ?? 0) > 0) { $chosen = $inst; break; }
            }
        }
        if ($chosen === null) {
            return ['ok' => false];
        }

        $printSeconds = (int) ($chosen['prediction'] ?? 0);
        $weightG      = (int) round((float) ($chosen['weight'] ?? 0));
        $colors       = (int) ($chosen['materialColorCnt'] ?? ($chosen['materialCnt'] ?? 0));
        $plates       = is_array($chosen['plates'] ?? null) ? count($chosen['plates']) : 0;

        if ($printSeconds === 0 && $weightG === 0) {
            return ['ok' => false];
        }

        // A few alternate profiles for context (title + time + weight).
        $profiles = [];
        foreach (array_slice($instances, 0, 4) as $inst) {
            $ps = (int) ($inst['prediction'] ?? 0);
            if ($ps <= 0) continue;
            $profiles[] = [
                'title'        => (string) ($inst['title'] ?? ''),
                'printSeconds' => $ps,
                'weightG'      => (int) round((float) ($inst['weight'] ?? 0)),
            ];
        }

        return [
            'ok'           => true,
            'printSeconds' => $printSeconds,
            'weightG'      => $weightG,
            'colors'       => $colors,
            'plates'       => $plates,
            'profiles'     => $profiles,
        ];
    }

    /**
     * Force the per-instance STL/3MF download route, bypassing the combined
     * whole-model pack. Used when the combined pack is unavailable because the
     * model's *source* file (e.g. a parametric .scad/.step) is marked private —
     * MakerWorld still serves the printable STL/3MF instances. Returns a signed
     * URL or '' (with lastError set). On success, sets $this->sourcePrivateNote
     * so the caller can warn that only STL/3MF were fetched.
     */
    public string $sourcePrivateNote = '';

    public function getInstanceFileLink(string $designId): string
    {
        $this->lastError = '';
        $this->sourcePrivateNote = '';
        $designId = preg_replace('/[^0-9]/', '', $designId);
        if ($designId === '') { $this->lastError = 'Invalid MakerWorld design id.'; return ''; }
        if (!$this->isAuthed()) { $this->lastError = 'MakerWorld token not set.'; return ''; }

        $instanceId = $this->findInstanceId($designId);
        if ($instanceId === '') {
            $this->lastError = 'No printable instance found for this MakerWorld model.';
            return '';
        }
        $url = $this->mintFrom(self::API . '/design-service/instance/' . $instanceId . '/f3mf?type=download');
        if ($url !== '') {
            $this->sourcePrivateNote = 'source file is not available - stl/3mf files downloaded';
            return $url;
        }
        // last resort: per-instance STL bundle
        $url = $this->mintFrom(self::API . '/design-service/instance/' . $instanceId . '/stl?type=download');
        if ($url !== '') {
            $this->sourcePrivateNote = 'source file is not available - stl/3mf files downloaded';
            return $url;
        }
        if ($this->lastError === '') {
            $this->lastError = 'No STL/3MF instance files available for this MakerWorld model.';
        }
        return '';
    }

    /** Heuristic: does a getModelZipLink failure look like a private/forbidden source file? */
    public function looksPrivateSource(): bool
    {
        $e = strtolower($this->lastError);
        return strpos($e, 'private') !== false
            || strpos($e, 'permission') !== false
            || strpos($e, 'forbidden') !== false
            || strpos($e, '403') !== false
            || strpos($e, 'not available') !== false
            || strpos($e, 'no downloadable') !== false;
    }

    public function getModelZipLink(string $designId, string $fileType = 'PACK'): string
    {
        $this->lastError = '';        $designId = preg_replace('/[^0-9]/', '', $designId);
        if ($designId === '') {
            $this->lastError = 'Invalid MakerWorld design id.';
            return '';
        }
        if (!$this->isAuthed()) {
            $this->lastError = 'MakerWorld token not set (paste it in Settings).';
            return '';
        }

        // 1) combined whole-model pack
        $url = $this->mintFrom(self::API . '/design-service/design/' . $designId . '/model?modelType=all&type=download');
        if ($url !== '') {
            return $url;
        }
        $firstErr = $this->lastError;

        // 2) plain download doc (no modelType) — may carry per-instance links
        $url = $this->mintFrom(self::API . '/design-service/design/' . $designId . '/model?type=download');
        if ($url !== '') {
            return $url;
        }

        // 3) per-instance fallback
        $instanceId = $this->findInstanceId($designId);
        if ($instanceId !== '') {
            $url = $this->mintFrom(self::API . '/design-service/instance/' . $instanceId . '/f3mf?type=download');
            if ($url !== '') {
                return $url;
            }
        }

        $this->lastError = $firstErr !== '' ? $firstErr
            : ($this->lastError !== '' ? $this->lastError : 'No downloadable files found for this MakerWorld model.');
        return '';
    }

    /** Call a download-doc endpoint and extract a signed bblmw .zip URL (named field or regex). */
    private function mintFrom(string $url): string
    {
        $json = $this->apiGet($url);
        if (is_array($json)) {
            $candidate = $this->pick($json, 'url', 'downloadUrl', 'fileUrl', 'zipUrl');
            if ($candidate === '' && isset($json['data']) && is_array($json['data'])) {
                $candidate = $this->pick($json['data'], 'url', 'downloadUrl', 'fileUrl', 'zipUrl');
            }
            if ($candidate !== '') {
                return $candidate;
            }
        }
        // Covers all.zip AND per-instance _stls.zip / _f3mf.zip URLs embedded in the body.
        if ($this->lastRaw !== '' && preg_match(self::CDN_RE, $this->lastRaw, $m)) {
            return $m[0];
        }
        return '';
    }

    /**
     * Resolve a design's default print-profile (instance) id, needed for the
     * per-instance STL route. Tries the design-detail endpoint and the common
     * field shapes. Returns '' if it can't be determined.
     */
    private function findInstanceId(string $designId): string
    {
        $json = $this->apiGet(self::API . '/design-service/design/' . $designId);
        if (!is_array($json)) {
            return '';
        }
        // Common shapes: {instances:[{id}]}, {defaultInstanceId}, {instanceId},
        // or nested under data{}.
        $roots = [$json];
        if (isset($json['data']) && is_array($json['data'])) {
            $roots[] = $json['data'];
        }
        foreach ($roots as $r) {
            foreach (['defaultInstanceId', 'instanceId'] as $k) {
                if (!empty($r[$k]) && (is_int($r[$k]) || ctype_digit((string) $r[$k]))) {
                    return (string) $r[$k];
                }
            }
            if (isset($r['instances'][0]['id']) && (is_int($r['instances'][0]['id']) || ctype_digit((string) $r['instances'][0]['id']))) {
                return (string) $r['instances'][0]['id'];
            }
        }
        return '';
    }

    /**
     * Download a signed CDN URL to disk. The signed URL is auth-free, so this is
     * a plain GET — mirrors PrintablesService::downloadToFile (sans bearer).
     */
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
        if ($fh === false) {
            $this->lastError = 'Cannot open temp file for writing.';
            return false;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fh,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_FAILONERROR    => true,
            CURLOPT_HTTPHEADER     => ['User-Agent: ' . self::UA],
        ]);
        if ($onProgress !== null) {
            curl_setopt($ch, CURLOPT_NOPROGRESS, false);
            curl_setopt($ch, CURLOPT_XFERINFOFUNCTION, static function ($ch, $dlTotal, $dlNow, $ulTotal, $ulNow) use ($onProgress) {
                $onProgress((int) $dlTotal, (int) $dlNow);
                return 0;
            });
        }
        $ok     = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr   = curl_error($ch);
        curl_close($ch);
        fclose($fh);

        if ($ok === false || $status >= 400) {
            @unlink($tmp);
            $this->lastError = $cerr !== '' ? ('Download error: ' . $cerr)
                                            : ('Download HTTP ' . $status);
            return false;
        }
        if (!@rename($tmp, $destPath)) {
            @unlink($tmp);
            $this->lastError = 'Could not finalize file (rename failed).';
            return false;
        }
        return true;
    }

    // ---- internals --------------------------------------------------------

    /**
     * Authenticated GET against the MakerWorld API. Returns decoded JSON array,
     * or null on transport/parse failure (lastError set). Raw body kept in lastRaw.
     *
     * @return array<string,mixed>|null
     */
    private function apiGet(string $url): ?array
    {
        $this->lastRaw = '';
        $headers = [
            'Accept: */*',
            'Content-Type: application/json',
            'User-Agent: ' . self::UA,
            'X-BBL-Client-Type: web',
            'X-BBL-Client-Version: 00.00.00.01',
            'X-BBL-App-Source: makerworld',
            'X-BBL-Client-Name: MakerWorld',
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_ENCODING       => '', // accept gzip/br transparently
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        if ($this->token !== '') {
            curl_setopt($ch, CURLOPT_COOKIE, 'token=' . $this->token);
        }

        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr   = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            $this->lastError = 'Network error: ' . ($cerr !== '' ? $cerr : 'unknown');
            return null;
        }
        $this->lastRaw = (string) $body;

        if ($status >= 400) {
            // MakerWorld returns JSON errors like {"code":1,"error":"Please log in…"}
            $msg = '';
            $j = json_decode((string) $body, true);
            if (is_array($j)) {
                $msg = (string) ($j['error'] ?? $j['message'] ?? '');
            }
            $this->lastError = 'MakerWorld HTTP ' . $status . ($msg !== '' ? (': ' . $msg) : '');
            return null;
        }

        $json = json_decode((string) $body, true);
        if (!is_array($json)) {
            $this->lastError = 'MakerWorld returned non-JSON (len ' . strlen((string) $body) . ').';
            return null;
        }
        return $json;
    }

    /**
     * Force a bblmw CDN image URL through the OSS resize/format transform so the
     * grid loads small static webp thumbnails instead of full-size (and sometimes
     * multi-MB animated GIF) originals. Any pre-existing query is replaced.
     */
    private function thumbTransform(string $url, int $width = 800): string
    {
        if ($url === '' || !preg_match('~^https://makerworld\.bblmw\.com/~', $url)) {
            return $url; // leave non-CDN urls untouched
        }
        $base = explode('?', $url, 2)[0];
        return $base . '?x-oss-process=image/resize,w_' . $width . '/format,webp';
    }

    /** Recursively sum modelSize across files + nested dir children. */
    private function sumModelFiles(array $files): int
    {
        $total = 0;
        foreach ($files as $f) {
            if (!is_array($f)) {
                continue;
            }
            if (!empty($f['isDir']) && isset($f['children']) && is_array($f['children'])) {
                $total += $this->sumModelFiles($f['children']);
            } else {
                $total += (int) ($f['modelSize'] ?? 0);
            }
        }
        return $total;
    }

    /** First non-empty string value among the given keys. */
    private function pick(array $arr, string ...$keys): string
    {
        foreach ($keys as $k) {
            if (isset($arr[$k]) && is_string($arr[$k]) && $arr[$k] !== '') {
                return $arr[$k];
            }
        }
        return '';
    }
}
