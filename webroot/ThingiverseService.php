<?php
declare(strict_types=1);

/**
 * ThingiverseService
 *
 * Thingiverse REST API v2 client.
 * Docs: https://www.thingiverse.com/developers/rest-api-reference
 *
 * Confirmed endpoints:
 *   Search  : GET /search/{term}?per_page=&page=&sort=
 *             Returns {"hits":N,"results":[{id,name,thumbnail,creator:{name},
 *             zip_data:{url},files:[{public_url,name,size}]}, …]}
 *   Thing   : GET /things/{id}         — detail
 *   Files   : GET /things/{id}/files   — [{public_url,name,size}]
 *   Download: GET public_url (redirect to CDN, auth via token header)
 *
 * Auth: Bearer token in Authorization header.
 *   DevTools → Network → any api.thingiverse.com request → Authorization header.
 */
final class ThingiverseService
{
    private const API = 'https://api.thingiverse.com';
    private const UA  = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) FarFetched/1.0';
    private const CDN = 'cdn.thingiverse.com';

    public string $lastError     = '';
    public int    $lastTotal     = 0;

    private string $token;

    public function __construct(?string $token = null)
    {
        $this->token = $token ?? (function_exists('cfg') ? (string) cfg('thingiverse_token') : '');
        $this->token = trim($this->token);
        // Strip "Bearer " prefix if pasted with it
        $this->token = preg_replace('/^Bearer\s+/i', '', $this->token) ?? $this->token;
    }

    public function isAuthed(): bool { return $this->token !== ''; }

    /**
     * Search Thingiverse. Returns normalized model rows:
     * [id, slug, name, creator, thumb, images, size, source=>'thingiverse']
     *
     * @return array<int,array<string,mixed>>
     */
    public function search(string $query, int $limit = 20, int $page = 1, bool $showNsfw = false, ?string $categoryId = null): array
    {
        $this->lastError = '';
        $this->lastTotal = 0;

        if (!$this->isAuthed()) {
            $this->lastError = 'Thingiverse token not set (paste it in Settings).';
            return [];
        }

        $query = trim($query);
        if ($query === '') {
            return $this->popular($limit, $page, $categoryId);
        }

        $params = [
            'per_page' => max(1, min(30, $limit)),
            'page'     => max(1, $page),
            'sort'     => 'relevant',
            'type'     => 'things',
        ];
        if ($categoryId !== null && $categoryId !== '') {
            // Category search: /categories/{id}/things?q=...
            $params['q'] = $query;
            $url = self::API . '/categories/' . rawurlencode($categoryId) . '/things?' . http_build_query($params);
        } else {
            $url = self::API . '/search/' . rawurlencode($query) . '?' . http_build_query($params);
        }

        $json = $this->apiGet($url);
        if ($json === null) return [];

        $this->lastTotal = (int) ($json['total'] ?? $json['hits'] ?? 0);
        $results = $json['hits'] ?? $json['results'] ?? $json ?? [];
        if (!is_array($results)) {
            $this->lastError = 'Unexpected search response from Thingiverse.';
            return [];
        }

        return $this->normalize($results, $showNsfw);
    }

    /**
     * Popular/newest browse (no keyword).
     * @return array<int,array<string,mixed>>
     */
    public function popular(int $limit = 20, int $page = 1, ?string $categoryId = null): array
    {
        $this->lastError = '';
        if (!$this->isAuthed()) {
            $this->lastError = 'Thingiverse token not set.';
            return [];
        }
        $params = [
            'per_page' => max(1, min(30, $limit)),
            'page'     => max(1, $page),
        ];
        // /popular ignores category_id — use /categories/{id}/things instead.
        if ($categoryId !== null && $categoryId !== '') {
            $url = self::API . '/categories/' . rawurlencode($categoryId) . '/things?' . http_build_query($params);
        } else {
            $url = self::API . '/popular?' . http_build_query($params);
        }
        $json  = $this->apiGet($url);
        if ($json === null) return [];
        $items = is_array($json) && isset($json[0]) ? $json : ($json['results'] ?? []);
        return $this->normalize($items);
    }

    /**
     * Fetch all images for a thing — used by the lazy hover slider.
     * GET /things/{id}/images
     * Returns array of full-size image URLs.
     * @return string[]
     */
    public function getThingImages(string $thingId): array
    {
        $thingId = preg_replace('/[^0-9]/', '', $thingId);
        if ($thingId === '' || !$this->isAuthed()) return [];

        $json = $this->apiGet(self::API . '/things/' . $thingId . '/images');
        if (!is_array($json)) return [];

        $urls = [];
        foreach ($json as $img) {
            if (!is_array($img)) continue;
            // Prefer large, fall back to medium, then url root
            $u = (string) ($img['sizes']['large']['url']
                ?? $img['sizes']['medium']['url']
                ?? $img['url']
                ?? '');
            if ($u !== '' && !in_array($u, $urls, true)) $urls[] = $u;
        }
        return $urls;
    }

    /**
     * Thingiverse provides a per-thing ZIP at /things/{id}/zip
     * Falls back to individual file list if ZIP is unavailable.
     * Returns '' on failure.
     */
    public function getThingZipUrl(string $thingId): string
    {
        $this->lastError = '';
        $thingId = preg_replace('/[^0-9]/', '', $thingId);
        if ($thingId === '') {
            $this->lastError = 'Invalid Thingiverse thing ID.';
            return '';
        }
        if (!$this->isAuthed()) {
            $this->lastError = 'Thingiverse token not set.';
            return '';
        }

        // The /zip endpoint returns a redirect to a signed CDN URL.
        $url = self::API . '/things/' . $thingId . '/zip';
        $ch  = $this->baseCurl($url);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false); // catch the redirect
        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $loc    = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);

        if ($status === 302 || $status === 301) {
            return (string) $loc;
        }
        if ($status === 200) {
            // Some responses return JSON with a url field
            $j = json_decode((string) $body, true);
            if (is_array($j) && !empty($j['url'])) return (string) $j['url'];
        }

        $this->lastError = 'Thingiverse ZIP endpoint returned HTTP ' . $status . ' (no redirect).';
        return '';
    }

    /**
     * List individual files for a thing.
     * @return array<int,array{id:string,name:string,url:string,size:int}>
     */
    public function getFiles(string $thingId): array
    {
        $this->lastError = '';
        $thingId = preg_replace('/[^0-9]/', '', $thingId);
        if ($thingId === '' || !$this->isAuthed()) return [];

        $json = $this->apiGet(self::API . '/things/' . $thingId . '/files');
        if (!is_array($json)) return [];

        $out = [];
        foreach ($json as $f) {
            if (!is_array($f)) continue;
            $url = (string) ($f['public_url'] ?? $f['download_url'] ?? '');
            if ($url === '') continue;
            $out[] = [
                'id'   => (string) ($f['id'] ?? ''),
                'name' => (string) ($f['name'] ?? 'file'),
                'url'  => $url,
                'size' => (int) ($f['size'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * Download a URL (ZIP or individual file) to disk.
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
        if ($fh === false) { $this->lastError = 'Cannot open temp file.'; return false; }

        $ch = $this->baseCurl($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fh,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_FAILONERROR    => true,
        ]);
        if ($onProgress !== null) {
            curl_setopt($ch, CURLOPT_NOPROGRESS, false);
            curl_setopt($ch, CURLOPT_XFERINFOFUNCTION, static function ($ch, $dt, $dn, $ut, $un) use ($onProgress) {
                $onProgress((int)$dt, (int)$dn); return 0;
            });
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

    /** @return array<string,mixed>|null */
    private function apiGet(string $url): ?array
    {
        $ch   = $this->baseCurl($url);
        $body = curl_exec($ch);
        $st   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($body === false) { $this->lastError = 'Network error: ' . $cerr; return null; }
        if ($st >= 400) {
            $j = json_decode((string)$body, true);
            $msg = is_array($j) ? ((string)($j['error'] ?? $j['message'] ?? '')) : '';
            $this->lastError = 'Thingiverse HTTP ' . $st . ($msg !== '' ? ': ' . $msg : '');
            return null;
        }
        $json = json_decode((string)$body, true);
        if (!is_array($json)) { $this->lastError = 'Non-JSON response from Thingiverse.'; return null; }
        return $json;
    }

    private function baseCurl(string $url): \CurlHandle
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_ENCODING       => '',
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->token,
                'User-Agent: ' . self::UA,
                'Accept: application/json',
            ],
        ]);
        return $ch;
    }

    /** @param array<int,mixed> $items */
    private function normalize(array $items, bool $showNsfw = false): array
    {
        $out = [];
        foreach ($items as $it) {
            if (!is_array($it)) continue;

            $thumb = (string) ($it['thumbnail'] ?? $it['default_image']['url'] ?? '');
            $images = [$thumb];
            if (isset($it['images']) && is_array($it['images'])) {
                foreach ($it['images'] as $img) {
                    $u = is_array($img) ? (string)($img['url'] ?? '') : (string)$img;
                    if ($u !== '' && !in_array($u, $images, true)) $images[] = $u;
                }
                $images = array_slice(array_filter($images), 0, 8);
            }

            $size = 0;
            if (isset($it['files']) && is_array($it['files'])) {
                foreach ($it['files'] as $f) { $size += (int)($f['size'] ?? 0); }
            }

            $id   = (string) ($it['id'] ?? '');
            $name = (string) ($it['name'] ?? 'Untitled');
            $slug = (string) ($it['slug'] ?? preg_replace('/[^a-z0-9]+/', '-', strtolower($name)) ?? $id);

            $out[] = [
                'id'      => $id,
                'slug'    => $slug,
                'name'    => $name,
                'creator' => (string) ($it['creator']['name'] ?? $it['author_name'] ?? 'unknown'),
                'thumb'   => $thumb,
                'images'  => array_values(array_filter($images)),
                'size'    => $size,
                'source'  => 'thingiverse',
            ];
        }
        return $out;
    }
}
