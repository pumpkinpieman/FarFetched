<?php
declare(strict_types=1);

/**
 * PrintablesService.php — live Printables GraphQL client.
 *
 * Public surface:
 *   searchModels($categorySlug, $limit, $offset) -> rows for the grid
 *   getModelFiles($modelId, $fileType)           -> [ [id,name,type], ... ]
 *   getDownloadLink($fileId, $modelId, $fileType)-> temporary signed URL
 *   downloadToFile($url, $destPath)              -> bool (streamed)
 *
 * Security / robustness:
 *   - Token read from the out-of-web-root store.
 *   - GraphQL queries never interpolate user input; all params are typed
 *     `variables` (injection-safe by construction).
 *   - Every network path degrades to a clean error string in $lastError;
 *     nothing throws to the caller, nothing fatals.
 *
 * REVERSE-ENGINEERED SEAMS (verify each against your own Network tab):
 *   [S1] morePrints search query + nested field names
 *   [S2] numeric categoryId per category slug
 *   [S3] image CDN prefix
 *   [S4] model -> files query (field names + STL/3MF enum values)
 *   [S5] getDownloadLink mutation (already sighted in community tooling)
 */

require_once __DIR__ . '/bootstrap.php';

final class PrintablesService
{
    private const API = 'https://api.printables.com/graphql/';
    private const REFRESH_URL = 'https://www.printables.com/auth/refresh';
    private const UA  = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
                      . 'AppleWebKit/537.36 Chrome/116 Safari/537.36';

    // Real Printables top-level category IDs (from the live category menu).
    // 'all' => null means no category filter. Verified against ?category=NN URLs.
    private const CATEGORY_IDS = [
        'all'         => null,
        '3d-printers' => '1',
        'art'         => '13',
        'costumes'    => '76',
        'fashion'     => '17',
        'gadgets'     => '21',
        'healthcare'  => '87',
        'hobby'       => '48',
        'household'   => '3',
        'learning'    => '90',
        'seasonal'    => '65',
        'sports'      => '9',
        'tabletop'    => '101',
        'toys'        => '30',
        'world-scans' => '58',
    ];

    private string $token;
    private string $refreshToken;
    public string $lastError = '';
    public ?string $lastCursor = null;
    public int $lastTotalCount = 0;

    public function __construct(?string $token = null)
    {
        $this->token = $token ?? get_token();
        $this->refreshToken = get_refresh_token();
    }

    public function isAuthed(): bool
    {
        // Authed if we can produce a working access token: either one is already
        // stored, or we hold a refresh token we can mint a fresh one from.
        return $this->token !== '' || $this->refreshToken !== '';
    }

    /**
     * Guarantee a usable access token before an authed call. Cheap when the
     * current token is still comfortably valid (just an exp check); otherwise
     * mints a new one from the refresh token. Returns false only when we have
     * no way to authenticate (no valid access token and no refresh token).
     */
    public function ensureFreshToken(): bool
    {
        $s = token_status();
        if ($s['state'] === 'valid' && (int) ($s['seconds'] ?? 0) > 60) {
            return true; // plenty of life left
        }
        if ($this->refreshToken === '') {
            // Only a (stale) access token and nothing to renew with.
            if ($this->token !== '' && $s['state'] !== 'expired') {
                return true; // unknown-exp but present; let the call try
            }
            $this->lastError = 'Token expired — paste a fresh token in Settings.';
            return false;
        }
        return $this->refreshAccessToken();
    }

    /**
     * POST /auth/refresh with the refresh-token cookie (no body, no CSRF).
     * Stores the new access token AND the rotated refresh token from the
     * response Set-Cookie headers. Serialized via a lock so the worker and a
     * concurrent web request can't clobber the rotating token.
     */
    private function refreshAccessToken(): bool
    {
        $lock = @fopen(REFRESH_LOCK, 'c');
        if ($lock !== false) {
            @flock($lock, LOCK_EX);
            // Another process may have refreshed while we waited for the lock.
            clearstatcache();
            $reload = get_token();
            if ($reload !== '' && $reload !== $this->token) {
                $this->token = $reload;
                $s = token_status();
                if ($s['state'] === 'valid' && (int) ($s['seconds'] ?? 0) > 60) {
                    $this->releaseLock($lock);
                    return true;
                }
            }
            $storedRefresh = get_refresh_token();
            if ($storedRefresh !== '') {
                $this->refreshToken = $storedRefresh; // may have rotated
            }
        }

        $ch = curl_init(self::REFRESH_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => '',          // empty body, like the browser
            CURLOPT_HEADER         => true,        // need Set-Cookie from response
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => [
                'Cookie: auth.refresh_token=' . $this->refreshToken,
                'Origin: https://www.printables.com',
                'ngsw-bypass: true',
                'Content-Length: 0',
                'User-Agent: ' . self::UA,
            ],
        ]);
        $resp   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hsize  = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $cerr   = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            $this->lastError = 'Token refresh network error: ' . $cerr;
            ff_log('error', $this->lastError);
            $this->releaseLock($lock);
            return false;
        }
        if ($status !== 200) {
            // 401/403 → refresh token itself is dead/expired; user must re-paste.
            $this->lastError = ($status === 401 || $status === 403)
                ? 'Refresh token rejected (expired) — paste a fresh one in Settings.'
                : 'Token refresh failed (HTTP ' . $status . ').';
            ff_log('error', $this->lastError);
            $this->releaseLock($lock);
            return false;
        }

        $headers   = substr((string) $resp, 0, $hsize);
        $newAccess = $this->parseSetCookie($headers, 'auth.access_token');
        $newRefresh = $this->parseSetCookie($headers, 'auth.refresh_token');

        if ($newAccess === null || $newAccess === '') {
            $this->lastError = 'Refresh succeeded but no access token in response.';
            ff_log('error', $this->lastError);
            $this->releaseLock($lock);
            return false;
        }

        set_token($newAccess);
        $this->token = $newAccess;
        if ($newRefresh !== null && $newRefresh !== '') {
            set_refresh_token($newRefresh);          // CRITICAL: token rotates
            $this->refreshToken = $newRefresh;
        }

        $s = token_status();
        ff_log('info', 'Token refreshed — access valid ' . human_duration((int) ($s['seconds'] ?? 0)) . '.');
        $this->releaseLock($lock);
        return true;
    }

    /** @param resource|false $lock */
    private function releaseLock($lock): void
    {
        if ($lock !== false) {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    /** Pull a cookie value out of raw Set-Cookie response headers. */
    private function parseSetCookie(string $headers, string $name): ?string
    {
        $pattern = '/^set-cookie:\s*' . preg_quote($name, '/') . '=([^;\r\n]*)/im';
        return preg_match($pattern, $headers, $m) ? $m[1] : null;
    }

    /** @return array<int,array{id:string,slug:string,name:string,creator:string,thumb:string}> */
    public function searchModels(string $categorySlug, int $limit = 36, ?string $cursor = null): array
    {
        $this->lastError = '';
        if (!$this->isAuthed()) {
            $this->lastError = 'No Printables token — set one in Settings.';
            return [];
        }
        if (!array_key_exists($categorySlug, self::CATEGORY_IDS)) {
            // Not a known slug — allow a raw numeric category ID (paste-any-ID box).
            if (ctype_digit($categorySlug)) {
                $categoryId = $categorySlug;
            } else {
                $this->lastError = 'Unknown category.';
                return [];
            }
        } else {
            $categoryId = self::CATEGORY_IDS[$categorySlug];
        }

        // The list items are Print objects, so we can ask for their file sizes
        // (stls/otherFiles -> fileSize, both confirmed-real fields). These nested
        // fields are heavier and *might* be rejected on the list type; if so the
        // whole query fails, so we retry WITHOUT them rather than break Browse.
        $build = static function (string $itemFields): string {
            return "query ModelList(\$limit: Int!, \$cursor: String, \$categoryId: ID, \$ordering: String) {
              morePrints(limit: \$limit, cursor: \$cursor, categoryId: \$categoryId, ordering: \$ordering) {
                cursor
                items { $itemFields }
              }
            }";
        };
        $baseFields = 'id slug name user { publicUsername } image { filePath }';
        $sizeFields = $baseFields . ' stls { fileSize } otherFiles { fileSize }';

        $vars = [
            'limit'      => max(1, min($limit, 100)),
            'cursor'     => $cursor,
            'categoryId' => $categoryId,
            'ordering'   => 'trending',
        ];

        // Attempt the rich (size-bearing) query first; fall back to plain on failure.
        $data = $this->gql($build($sizeFields), $vars);
        if ($data === null) {
            $this->lastError = '';
            $data = $this->gql($build($baseFields), $vars);
        }
        if ($data === null) {
            return [];
        }

        $items = $data['morePrints']['items'] ?? null;
        if (!is_array($items)) {
            $this->lastError = 'Unexpected response shape — verify morePrints fields.';
            return [];
        }

        // Stash the next-page cursor for callers that paginate.
        $this->lastCursor = $data['morePrints']['cursor'] ?? null;

        return array_map(static function (array $it): array {
            $thumb = $it['image']['filePath'] ?? '';
            if ($thumb !== '' && !preg_match('#^https?://#', $thumb)) {
                $thumb = 'https://media.printables.com/' . ltrim($thumb, '/'); // [S3] verify prefix
            }
            $size = 0;
            foreach (['stls', 'otherFiles'] as $grp) {
                if (!empty($it[$grp]) && is_array($it[$grp])) {
                    foreach ($it[$grp] as $f) {
                        $size += (int) ($f['fileSize'] ?? 0);
                    }
                }
            }
            return [
                'id'      => (string) ($it['id'] ?? ''),
                'slug'    => (string) ($it['slug'] ?? ''),
                'name'    => (string) ($it['name'] ?? 'Untitled'),
                'creator' => (string) ($it['user']['publicUsername'] ?? 'unknown'),
                'thumb'   => (string) $thumb,
                'images'  => [$thumb],
                'size'    => $size,
            ];
        }, $items);
    }

    /**
     * Keyword search via the live SearchModels operation (searchPrints2).
     * Offset-based paging: pass offset, walk it forward by $limit. Sets
     * $this->lastTotalCount so callers can tell when the result set is exhausted.
     *
     * @param string $paid PaidEnum: 'all' | 'free' | 'paid'
     * @return array<int,array{id:string,slug:string,name:string,creator:string,thumb:string,size:int,price:float,club:bool}>
     */
    public function searchByKeyword(string $query, int $limit = 36, int $offset = 0, string $paid = 'all'): array
    {
        $this->lastError = '';
        $this->lastTotalCount = 0;
        $query = trim($query);
        if (!$this->isAuthed()) {
            $this->lastError = 'No Printables token — set one in Settings.';
            return [];
        }
        if ($query === '') {
            return [];
        }

        // searchPrints2 items are PrintType, so file sizes (stls/otherFiles ->
        // fileSize) may be available. Heavier, so retry without on failure.
        $build = static function (string $itemFields): string {
            return "query SearchModels(\$query: String!, \$limit: Int, \$cursor: Int, \$paid: PaidEnum, \$ordering: SearchChoicesEnum) {
              result: searchPrints2(query: \$query, printType: print, limit: \$limit, offset: \$cursor, paid: \$paid, ordering: \$ordering) {
                totalCount
                items { $itemFields }
              }
            }";
        };
        $baseFields = 'id slug name image { filePath } user { publicUsername } price club: premium';
        $sizeFields = $baseFields . ' stls { fileSize } otherFiles { fileSize }';

        $vars = [
            'query'    => $query,
            'limit'    => max(1, min($limit, 100)),
            'cursor'   => max(0, $offset),     // searchPrints2 'offset' arg
            'paid'     => $paid,               // PaidEnum
            'ordering' => null,                // null = Printables' default (relevance)
        ];

        $data = $this->gql($build($sizeFields), $vars);
        if ($data === null) {
            $this->lastError = '';
            $data = $this->gql($build($baseFields), $vars);
        }
        if ($data === null) {
            return [];
        }

        $items = $data['result']['items'] ?? null;
        if (!is_array($items)) {
            $this->lastError = 'Unexpected search response — verify searchPrints2 fields.';
            return [];
        }
        $this->lastTotalCount = (int) ($data['result']['totalCount'] ?? 0);

        return array_map(static function (array $it): array {
            $thumb = $it['image']['filePath'] ?? '';
            if ($thumb !== '' && !preg_match('#^https?://#', $thumb)) {
                $thumb = 'https://media.printables.com/' . ltrim($thumb, '/');
            }
            $size = 0;
            foreach (['stls', 'otherFiles'] as $grp) {
                if (!empty($it[$grp]) && is_array($it[$grp])) {
                    foreach ($it[$grp] as $f) {
                        $size += (int) ($f['fileSize'] ?? 0);
                    }
                }
            }
            return [
                'id'      => (string) ($it['id'] ?? ''),
                'slug'    => (string) ($it['slug'] ?? ''),
                'name'    => (string) ($it['name'] ?? 'Untitled'),
                'creator' => (string) ($it['user']['publicUsername'] ?? 'unknown'),
                'thumb'   => (string) $thumb,
                'images'  => [$thumb],
                'size'    => $size,
                'price'   => (float) ($it['price'] ?? 0),
                'club'    => (bool) ($it['club'] ?? false),
            ];
        }, $items);
    }

    /**
     * Resolve the downloadable files on a model, filtered to a type (STL/3MF).
     * [S4] verify the model-detail query + file field names + enum values.
     *
     * @return array<int,array{id:string,name:string,type:string}>
     */
    public function getModelFiles(string $modelId, string $fileType = 'STL'): array
    {
        $this->lastError = '';
        if (!$this->isAuthed()) {
            $this->lastError = 'No Printables token.';
            return [];
        }

        $query = <<<'GQL'
        query ModelFiles($id: ID!) {
          print(id: $id) {
            id
            stls { id name fileSize }
            otherFiles { id name fileSize }
          }
        }
        GQL;

        $data = $this->gql($query, ['id' => $modelId]);
        if ($data === null) {
            return [];
        }

        // Printables splits a model's files across several lists. .stl files
        // live in `stls`; .3mf files may live in `stls` OR `otherFiles`
        // depending on how the creator uploaded them. Rather than assume a
        // list, scan BOTH and match by extension, tagging each hit with the
        // download enum of the list it came from (stls->stl, otherFiles->other),
        // which getDownloadLink needs to request the right signed URL.
        $upper   = strtoupper($fileType);
        $wantExt = ($upper === '3MF') ? '3mf' : 'stl';

        $stls   = $data['print']['stls'] ?? [];
        $others = $data['print']['otherFiles'] ?? [];
        if (!is_array($stls) || !is_array($others)) {
            $this->lastError = 'Unexpected files shape — verify the print() query.';
            return [];
        }

        $out      = [];
        $stlCount = 0;
        foreach ([['list' => $stls, 'dl' => 'stl'], ['list' => $others, 'dl' => 'other']] as $grp) {
            foreach ($grp['list'] as $f) {
                $name = (string) ($f['name'] ?? 'file');
                $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if ($ext === 'stl') {
                    $stlCount++;
                }
                if ($ext !== $wantExt) {
                    continue;
                }
                $out[] = [
                    'id'   => (string) ($f['id'] ?? ''),
                    'name' => $name,
                    'type' => $fileType,
                    'dl'   => $grp['dl'],   // download enum for this file's source list
                ];
            }
        }

        // Helpful skip reason when a 3MF was requested but the model has none.
        if ($out === [] && $upper === '3MF') {
            $this->lastError = $stlCount > 0
                ? "No .3mf files on this model (it has {$stlCount} STL file(s)). Pick STL or use Queue ZIP."
                : 'No .3mf files on this model. Use Queue ZIP for the whole-model pack.';
        }
        return $out;
    }

    /**
     * Map a UI file-type label to Printables' DownloadFileTypeEnum value.
     * Confirmed valid enum values are lowercase: stl, other, gcode, sla.
     * Printables has NO "3mf" enum — .3mf files download as "other".
     */
    private function apiFileType(string $uiType): string
    {
        switch (strtoupper($uiType)) {
            case '3MF':   return 'other';
            case 'GCODE': return 'gcode';
            case 'SLA':   return 'sla';
            case 'STL':
            default:      return 'stl';
        }
    }

    /**
     * [S5] GetDownloadLink mutation — returns a short-lived signed URL.
     * Mutation shape sighted in community CLI tooling; confirm enum values
     * for $fileType (e.g. STL / BINARY_STL / 3MF) and $source against live.
     */
    public function getDownloadLink(string $fileId, string $modelId, string $fileType = 'STL', ?string $apiType = null): string
    {
        $this->lastError = '';
        $mutation = <<<'GQL'
        mutation GetDownloadLink($id: ID!, $modelId: ID!, $fileType: DownloadFileTypeEnum!, $source: DownloadSourceEnum!) {
          getDownloadLink(id: $id, printId: $modelId, fileType: $fileType, source: $source) {
            ok
            output { link ttl }
            errors { field messages }
          }
        }
        GQL;

        $data = $this->gql($mutation, [
            'id'       => $fileId,
            'modelId'  => $modelId,
            'fileType' => $apiType ?? $this->apiFileType($fileType),  // per-file enum (stl/other) or STL->stl, 3MF->other
            'source'   => 'model_detail',
        ]);
        if ($data === null) {
            return '';
        }

        $node = $data['getDownloadLink'] ?? null;
        $link = $node['output']['link'] ?? '';
        if (empty($node['ok']) || $link === '') {
            $this->lastError = 'Download link refused by API.';
            return '';
        }
        return (string) $link;
    }

    /**
     * Minimal model info (name + slug) for naming folders on paste-ID jobs.
     * Auth-free in practice (the print query resolves without a bearer).
     *
     * @return array{name:string,slug:string}
     */
    public function getModelInfo(string $modelId): array
    {
        $this->lastError = '';
        $query = <<<'GQL'
        query ModelInfo($id: ID!) {
          model: print(id: $id) { id name slug }
        }
        GQL;
        $data = $this->gql($query, ['id' => $modelId]);
        $m = $data['model'] ?? [];
        return [
            'name' => (string) ($m['name'] ?? ''),
            'slug' => (string) ($m['slug'] ?? ''),
        ];
    }

    /**
     * Fetch the download packs for a model. Each model exposes one or more
     * "packs" (whole-model ZIPs): typically a MODEL_FILES pack (the "ALL MODEL
     * FILES" button) and an OTHER_FILES pack. Returns the list as-is.
     *
     * @return array<int,array{id:string,fileType:string,fileSize:int,name:string}>
     */
    public function getModelPacks(string $modelId): array
    {
        $this->lastError = '';
        $query = <<<'GQL'
        query ModelPacks($id: ID!) {
          model: print(id: $id) {
            id
            downloadPacks { id name fileSize fileType }
          }
        }
        GQL;

        $data = $this->gql($query, ['id' => $modelId]);
        if ($data === null) {
            return [];
        }
        $packs = $data['model']['downloadPacks'] ?? [];
        $out = [];
        foreach ($packs as $p) {
            $out[] = [
                'id'       => (string) ($p['id'] ?? ''),
                'name'     => (string) ($p['name'] ?? ''),
                'fileSize' => (int) ($p['fileSize'] ?? 0),
                'fileType' => (string) ($p['fileType'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Resolve the signed ZIP URL for a model's pack. Prefers the MODEL_FILES
     * pack (the printable model files) unless $packType says otherwise.
     * Reuses getDownloadLink with fileType "pack". Returns '' if none.
     */
    public function getPackLink(string $modelId, string $packType = 'MODEL_FILES'): string
    {
        $this->lastError = '';
        $packs = $this->getModelPacks($modelId);
        if ($packs === []) {
            if ($this->lastError === '') {
                $this->lastError = 'No download packs for this model.';
            }
            return '';
        }

        // Pick the requested pack type, else fall back to the first pack.
        $packId = '';
        foreach ($packs as $p) {
            if ($p['fileType'] === $packType) {
                $packId = $p['id'];
                break;
            }
        }
        if ($packId === '') {
            $packId = $packs[0]['id'];
        }

        // Same getDownloadLink mutation, but fileType "pack" and id = pack id.
        $mutation = <<<'GQL'
        mutation GetDownloadLink($id: ID!, $modelId: ID!, $fileType: DownloadFileTypeEnum!, $source: DownloadSourceEnum!) {
          getDownloadLink(id: $id, printId: $modelId, fileType: $fileType, source: $source) {
            ok
            output { link ttl }
            errors { field messages }
          }
        }
        GQL;

        $data = $this->gql($mutation, [
            'id'       => $packId,
            'modelId'  => $modelId,
            'fileType' => 'pack',
            'source'   => 'model_detail',
        ]);
        if ($data === null) {
            return '';
        }
        $node = $data['getDownloadLink'] ?? null;
        $link = $node['output']['link'] ?? '';
        if (empty($node['ok']) || $link === '') {
            $this->lastError = 'Pack link refused by API.';
            return '';
        }
        return (string) $link;
    }

    /**
     * Stream a (signed) URL to disk. Returns true on success.
     * Uses the auth header too — harmless on a presigned URL, required if not.
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
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->token,
                'User-Agent: ' . self::UA,
            ],
        ]);
        // Live byte progress (used by the worker to drive the queue UI). curl
        // calls this frequently; the callback itself throttles its writes.
        if ($onProgress !== null) {
            curl_setopt($ch, CURLOPT_NOPROGRESS, false);
            curl_setopt($ch, CURLOPT_XFERINFOFUNCTION, static function ($ch, $dlTotal, $dlNow, $ulTotal, $ulNow) use ($onProgress) {
                $onProgress((int) $dlTotal, (int) $dlNow);
                return 0; // returning non-zero would abort the transfer
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

    /** @return array<string,mixed>|null */
    /** Public wrapper around gql() for use by auxiliary endpoints (e.g. print_images.php). */
    public function gqlPublic(string $query, array $variables): ?array
    {
        return $this->gql($query, $variables);
    }

    private function gql(string $query, array $variables): ?array
    {
        // Self-renew the access token if it's expired/expiring (no-op if valid).
        if (!$this->ensureFreshToken()) {
            $this->lastError = $this->lastError !== '' ? $this->lastError : 'Not authenticated.';
            return null;
        }

        $ch = curl_init(self::API);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->token,
                'User-Agent: ' . self::UA,
            ],
            CURLOPT_POSTFIELDS => json_encode(
                ['query' => $query, 'variables' => $variables],
                JSON_THROW_ON_ERROR
            ),
        ]);
        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr   = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            $this->lastError = 'Network error: ' . $cerr;
            return null;
        }
        if ($status === 401 || $status === 403) {
            $this->lastError = 'Token rejected (expired/invalid). Refresh it in Settings.';
            return null;
        }
        if ($status === 429) {
            $this->lastError = 'Rate limited (429). Slow down / retry later.';
            return null;
        }
        if ($status !== 200) {
            $this->lastError = 'HTTP ' . $status . ' from Printables.';
            return null;
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            $this->lastError = 'Non-JSON response.';
            return null;
        }
        if (!empty($decoded['errors'])) {
            $this->lastError = 'API error: ' . (string) ($decoded['errors'][0]['message'] ?? 'GraphQL error');
            return null;
        }
        $data = $decoded['data'] ?? null;
        return is_array($data) ? $data : null;
    }
}
