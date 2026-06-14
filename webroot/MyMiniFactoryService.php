<?php
declare(strict_types=1);

/**
 * MyMiniFactoryService
 *
 * MyMiniFactory REST API v2 client.
 * Docs: https://www.myminifactory.com/api/v2
 *
 * Confirmed endpoints:
 *   Search  : GET /v2/search?q=&per_page=&page=
 *             Returns {"items":{"hits":N,"results":[{id,name,
 *             mainImage:{thumbnail:{url}},designer:{username},
 *             files:[{size}]}]}}
 *   Object  : GET /v2/objects/{id}
 *   Files   : GET /v2/objects/{id}/files
 *             Returns {"items":[{download_url,filename,size}]}
 *
 * Auth: API key as `Authorization: Bearer {key}` header.
 *   myminifactory.com → Account → API → Generate key.
 */
final class MyMiniFactoryService
{
    private const API = 'https://www.myminifactory.com/api/v2';
    private const UA  = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) FarFetched/1.0';

    public string $lastError = '';
    public int    $lastTotal = 0;

    private string $token;

    public function __construct(?string $token = null)
    {
        $this->token = $token ?? (function_exists('cfg') ? (string) cfg('myminifactory_token') : '');
        $this->token = trim($this->token);
    }

    public function isAuthed(): bool { return $this->token !== ''; }

    /**
     * Keyword search. Returns normalized model rows:
     * [id, slug, name, creator, thumb, images, size, source=>'myminifactory']
     *
     * @return array<int,array<string,mixed>>
     */
    public function search(string $query, int $limit = 20, int $page = 1, bool $showNsfw = false): array
    {
        $this->lastError = '';
        $this->lastTotal = 0;

        if (!$this->isAuthed()) {
            $this->lastError = 'MyMiniFactory token not set (paste it in Settings).';
            return [];
        }

        $endpoint = trim($query) !== ''
            ? self::API . '/search?' . http_build_query([
                'q'        => trim($query),
                'per_page' => max(1, min(30, $limit)),
                'page'     => max(1, $page),
            ])
            : self::API . '/objects?' . http_build_query([
                'per_page' => max(1, min(30, $limit)),
                'page'     => max(1, $page),
                'featured' => 1,
            ]);

        $json = $this->apiGet($endpoint);
        if ($json === null) return [];

        // Search wraps in items{}; object listing is direct
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
     * Download a file URL to disk.
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
                'Authorization: Bearer ' . $this->token,
                'User-Agent: ' . self::UA,
                'Accept: application/json',
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

            // Thumbnail
            $thumb = (string) (
                $it['mainImage']['thumbnail']['url'] ??
                $it['thumbnail_url'] ??
                $it['images'][0]['url'] ??
                ''
            );

            // Gallery
            $images = $thumb !== '' ? [$thumb] : [];
            if (isset($it['images']) && is_array($it['images'])) {
                foreach ($it['images'] as $img) {
                    $u = is_array($img) ? (string)($img['url'] ?? '') : (string)$img;
                    if ($u !== '' && !in_array($u, $images, true)) $images[] = $u;
                }
                $images = array_slice($images, 0, 8);
            }

            // Size
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
