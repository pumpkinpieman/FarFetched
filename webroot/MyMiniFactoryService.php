<?php
declare(strict_types=1);

/**
 * MyMiniFactoryService
 *
 * Auth: Cookie-based (reverse-engineered from browser traffic).
 * Two cookies are needed:
 *   PHPSESSID   — session cookie (short-lived, re-established by REMEMBERME)
 *   REMEMBERME  — persistent login token (~30 days)
 *
 * Stored in config as:
 *   myminifactory_token         = PHPSESSID value
 *   myminifactory_remember_me   = REMEMBERME value
 *
 * How to get them:
 *   myminifactory.com → log in (check "Remember me") → DevTools →
 *   Application → Cookies → copy PHPSESSID and REMEMBERME values.
 *
 * API base: https://www.myminifactory.com/api/v2
 * Search  : GET /v2/search?q=&per_page=&page=&type=objects
 * Object  : GET /v2/objects/{id}
 * Files   : GET /v2/objects/{id}/files
 */
final class MyMiniFactoryService
{
    private const API  = 'https://www.myminifactory.com/api/v2';
    private const HOST = 'https://www.myminifactory.com';
    private const UA   = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:151.0) Gecko/20100101 Firefox/151.0';

    public string $lastError = '';
    public int    $lastTotal = 0;

    private string $sessId;
    private string $rememberMe;

    public function __construct(?string $sessId = null, ?string $rememberMe = null)
    {
        $this->sessId     = trim($sessId     ?? (function_exists('cfg') ? (string) cfg('myminifactory_token')       : ''));
        $this->rememberMe = trim($rememberMe ?? (function_exists('cfg') ? (string) cfg('myminifactory_remember_me') : ''));
    }

    public function isAuthed(): bool
    {
        // Either cookie is sufficient to attempt auth; REMEMBERME is preferred.
        return $this->sessId !== '' || $this->rememberMe !== '';
    }

    private function cookieHeader(): string
    {
        $parts = [];
        if ($this->sessId     !== '') $parts[] = 'PHPSESSID=' . $this->sessId;
        if ($this->rememberMe !== '') $parts[] = 'REMEMBERME=' . $this->rememberMe;
        return implode('; ', $parts);
    }

    /**
     * Keyword search or featured browse (empty query).
     * @return array<int,array<string,mixed>>
     */
    public function search(string $query, int $limit = 20, int $page = 1, bool $showNsfw = false): array
    {
        $this->lastError = '';
        $this->lastTotal = 0;

        if (!$this->isAuthed()) {
            $this->lastError = 'MyMiniFactory cookies not set (paste them in Settings).';
            return [];
        }

        $params = [
            'per_page' => max(1, min(30, $limit)),
            'page'     => max(1, $page),
        ];

        if (trim($query) !== '') {
            $params['q']    = trim($query);
            $params['type'] = 'objects';
            $endpoint = self::API . '/search?' . http_build_query($params);
        } else {
            $params['featured'] = 1;
            $endpoint = self::API . '/objects?' . http_build_query($params);
        }

        $json = $this->apiGet($endpoint);
        if ($json === null) return [];

        $hits    = $json['items'] ?? $json;
        $this->lastTotal = (int) ($hits['hits'] ?? $hits['total_count'] ?? 0);
        $results = $hits['results'] ?? $hits['objects'] ?? $hits ?? [];

        if (!is_array($results)) {
            $this->lastError = 'Unexpected response from MyMiniFactory.';
            return [];
        }

        return $this->normalize($results);
    }

    /**
     * Resolve individual file download URLs for an object.
     * @return array<int,array{id:string,name:string,url:string,size:int}>
     */
    public function getFiles(string $objectId): array
    {
        $this->lastError = '';
        $objectId = preg_replace('/[^0-9]/', '', $objectId);
        if ($objectId === '' || !$this->isAuthed()) return [];

        $json = $this->apiGet(self::API . '/objects/' . $objectId . '/files');
        if (!is_array($json)) return [];

        $items = $json['items'] ?? $json;
        if (!is_array($items)) return [];

        $out = [];
        foreach ($items as $f) {
            if (!is_array($f)) continue;
            $url = (string) ($f['download_url'] ?? $f['url'] ?? '');
            if ($url === '') continue;
            $out[] = [
                'id'   => (string) ($f['id'] ?? ''),
                'name' => (string) ($f['filename'] ?? $f['name'] ?? 'file'),
                'url'  => $url,
                'size' => (int) ($f['size'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * Download a file URL to disk with cookie auth.
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
            curl_setopt($ch, CURLOPT_XFERINFOFUNCTION,
                static function ($ch, $dt, $dn, $ut, $un) use ($onProgress) {
                    $onProgress((int)$dt, (int)$dn); return 0;
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

    /** @return array<string,mixed>|null */
    private function apiGet(string $url): ?array
    {
        $ch   = $this->baseCurl($url);
        $body = curl_exec($ch);
        $st   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($body === false) { $this->lastError = 'Network error: ' . $cerr; return null; }
        if ($st === 401 || $st === 403) {
            $this->lastError = 'MyMiniFactory auth failed (HTTP ' . $st . ') — re-paste cookies in Settings.';
            return null;
        }
        if ($st >= 400) {
            $j   = json_decode((string)$body, true);
            $msg = is_array($j) ? ((string)($j['message'] ?? $j['error'] ?? '')) : '';
            $this->lastError = 'MyMiniFactory HTTP ' . $st . ($msg !== '' ? ': ' . $msg : '');
            return null;
        }
        $json = json_decode((string)$body, true);
        if (!is_array($json)) { $this->lastError = 'Non-JSON response from MyMiniFactory.'; return null; }
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
                'User-Agent: ' . self::UA,
                'Accept: application/json',
                'Cookie: ' . $this->cookieHeader(),
                'Referer: ' . self::HOST . '/',
                'DNT: 1',
            ],
        ]);
        return $ch;
    }

    /** @param array<int,mixed> $items */
    private function normalize(array $items): array
    {
        $out = [];
        foreach ($items as $it) {
            if (!is_array($it)) continue;

            $thumb = (string) (
                $it['mainImage']['thumbnail']['url'] ??
                $it['thumbnail_url'] ??
                $it['images'][0]['url'] ??
                ''
            );

            $images = $thumb !== '' ? [$thumb] : [];
            if (isset($it['images']) && is_array($it['images'])) {
                foreach ($it['images'] as $img) {
                    $u = is_array($img) ? (string)($img['url'] ?? '') : (string)$img;
                    if ($u !== '' && !in_array($u, $images, true)) $images[] = $u;
                }
                $images = array_slice($images, 0, 8);
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
                'creator' => (string) ($it['designer']['username'] ?? $it['author'] ?? 'unknown'),
                'thumb'   => $thumb,
                'images'  => array_values(array_filter($images)),
                'size'    => $size,
                'source'  => 'myminifactory',
            ];
        }
        return $out;
    }
}
