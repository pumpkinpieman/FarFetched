<?php
declare(strict_types=1);

/**
 * STLFlixService
 *
 * Categories, library browsing, and ZIP download for platform.stlflix.com.
 * Auth: paste the `jwt` value from the logged-in STLFlix browser session.
 *
 * Download flow:
 *   1. getDownloadUrl($modelId, $slug) — resolves the static.stlflix.com CDN ZIP URL
 *   2. downloadToFile($url, $dest, $progress) — streams the ZIP to disk
 */
final class STLFlixService
{
    private const GRAPHQL      = 'https://k8s.stlflix.com/graphql';
    private const PRODUCT_FILE = 'https://k8s.stlflix.com/api/product/product-file';
    private const LIBRARY      = 'https://painel.stlflix.com/api/library/custom-library';
    private const PLATFORM     = 'https://platform.stlflix.com';
    private const UA       = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) FarFetched/1.0';

    public string $lastError = '';
    public int    $lastTotal = 0;

    private string $token;

    public function __construct(?string $token = null)
    {
        $this->token = trim($token ?? (function_exists('cfg') ? (string) cfg('stlflix_token') : ''));
        $this->token = preg_replace('/^Bearer\\s+/i', '', $this->token) ?? $this->token;
        $this->token = preg_replace('/^jwt=/', '', $this->token) ?? $this->token;
    }

    public function isAuthed(): bool
    {
        return $this->token !== '';
    }

    /** @return array<string,string> id => label */
    public function categories(): array
    {
        // Try GraphQL with 'categories' collection.
        $query = <<<'GQL'
        {
          categories(pagination: { pageSize: 100 }, sort: "name:asc") {
            data { id attributes { name slug } }
          }
        }
        GQL;
        $data = $this->graphql($query);
        if ($data !== null && !empty($data['categories']['data'])) {
            $out = ['' => 'All Models'];
            foreach ($data['categories']['data'] as $c) {
                $id   = (string) ($c['id'] ?? '');
                $name = (string) ($c['attributes']['name'] ?? '');
                if ($id !== '' && $name !== '') $out[$id] = $name;
            }
            return $out;
        }

        // Fallback: the platform sidebar categories as observed in the UI.
        // These match what platform.stlflix.com/explore shows.
        return [
            ''   => 'All Models',
            'bs' => 'Best Sellers',
            'uf' => 'Usefull',
            'ta' => 'Toys & Articulated',
            'hd' => 'Home & Decor',
            'rc' => 'RPG & Cosplay',
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function search(string $query = '', int $limit = 20, int $offset = 0, string $categoryId = ''): array
    {
        $this->lastError = '';
        $this->lastTotal = 0;

        if (!$this->isAuthed()) {
            $this->lastError = 'STLFlix token not set (paste jwt in Settings).';
            return [];
        }

        $payload = [
            'interval'    => 'ALL_TIME',
            'miniatures'  => false,
            'filament'    => false,
            'category_id' => $categoryId !== '' ? (int) $categoryId : null,
        ];
        $json = $this->postJson(self::LIBRARY, $payload);
        if (!is_array($json)) return [];

        $rows = $this->normalize($json);
        if ($query !== '') {
            $needle = strtolower($query);
            $rows   = array_values(array_filter($rows, static function (array $m) use ($needle): bool {
                return str_contains(strtolower((string) $m['name']), $needle)
                    || str_contains(strtolower((string) $m['creator']), $needle);
            }));
        }

        $this->lastTotal = count($rows);
        return array_slice($rows, $offset, $limit);
    }

    /**
     * Resolve all CDN download URLs for a product.
     * Returns array of URLs (may be multiple files per product).
     * @return string[]
     */
    public function getDownloadUrls(string $modelId, string $slug): array
    {
        $this->lastError = '';

        if (!$this->isAuthed()) {
            $this->lastError = 'STLFlix token not set.';
            return [];
        }

        $idInt   = is_numeric($modelId) ? (int) $modelId : 0;
        $useSlug = $slug !== '' ? $slug : $modelId;

        $fidStr = $this->resolveFid($idInt, $useSlug);
        if ($fidStr === '') return [];

        $urls = [];
        foreach (explode(',', $fidStr) as $fid) {
            $fid = trim($fid);
            if ($fid === '') continue;

            // Direct URL shortcut from resolveFid.
            if (str_starts_with($fid, '__URL__:')) {
                $urls[] = substr($fid, 8);
                continue;
            }

            $result = $this->postJson(self::PRODUCT_FILE, ['fid' => $fid]);
            if (!is_array($result)) continue;

            $url = $this->extractFirstZipUrl($result);
            if ($url === '') {
                foreach (['url', 'download_url', 'file_url', 'link', 'path', 'file', 'zip'] as $k) {
                    $v = $result[$k] ?? null;
                    if (is_string($v) && $v !== '') { $url = $this->absoluteUrl($v); break; }
                }
            }
            if ($url !== '') $urls[] = $url;
        }

        if (empty($urls)) {
            $this->lastError = 'product-file API returned no URLs for fids: ' . $fidStr;
        }
        return $urls;
    }

    /** @deprecated Use getDownloadUrls() */
    public function getDownloadUrl(string $modelId, string $slug): string
    {
        $urls = $this->getDownloadUrls($modelId, $slug);
        return $urls[0] ?? '';
    }

    /**
     * Resolve all file fids for a product.
     *
     * Confirmed field structure on Product type:
     *   files: [ComponentPageFiles]  — array, each has file { data { id, attributes { url } } }
     *   stl_file: UploadFileEntityResponse  — single file relation
     *   bambu_file: UploadFileEntityResponse — single Bambu Lab file
     *   prusa_file: String — plain string, not a relation
     *
     * Returns comma-separated fid list (e.g. "29732,28295"), or
     * "__URL__:<url>" if a direct URL is found, or '' on failure.
     */
    private function resolveFid(int $idInt, string $slug): string
    {
        $filters = [];
        if ($idInt > 0) $filters[] = "filters: { id: { eq: {$idInt} } }";
        if ($slug !== '') $filters[] = "filters: { slug: { eq: \"{$slug}\" } }";

        foreach ($filters as $filter) {
            $query = <<<GQL
            {
              products({$filter}, pagination: { pageSize: 1 }) {
                data {
                  id
                  attributes {
                    files      { id file { data { id attributes { url } } } }
                    stl_file   { data { id attributes { url } } }
                    bambu_file { data { id attributes { url } } }
                    prusa_file
                  }
                }
              }
            }
            GQL;

            $data = $this->graphql($query);
            if ($data === null) continue;

            $attrs = $data['products']['data'][0]['attributes'] ?? null;
            if (!is_array($attrs)) continue;

            $fids = [];

            // Primary: files[] array — each entry has file.data.id
            foreach (($attrs['files'] ?? []) as $component) {
                $fid = (string) ($component['file']['data']['id'] ?? '');
                $url = (string) ($component['file']['data']['attributes']['url'] ?? '');
                if ($fid !== '' && is_numeric($fid)) {
                    $fids[] = $fid;
                } elseif ($url !== '') {
                    $fids[] = '__URL__:' . $this->absoluteUrl($url);
                }
            }

            if ($fids !== []) {
                if (function_exists('logln')) logln('  STLFlix fids from files[]: ' . implode(', ', $fids));
                return implode(',', $fids);
            }

            // Fallback: stl_file, bambu_file single relations
            foreach (['stl_file', 'bambu_file'] as $key) {
                $entry = $attrs[$key]['data'] ?? null;
                if (!is_array($entry)) continue;
                $fid = (string) ($entry['id'] ?? '');
                if ($fid !== '' && is_numeric($fid)) {
                    if (function_exists('logln')) logln('  STLFlix fid=' . $fid . ' from ' . $key);
                    return $fid;
                }
                $u = (string) ($entry['attributes']['url'] ?? '');
                if ($u !== '') return '__URL__:' . $this->absoluteUrl($u);
            }

            // prusa_file is a plain string
            $prusaFile = (string) ($attrs['prusa_file'] ?? '');
            if ($prusaFile !== '' && str_starts_with($prusaFile, 'http')) {
                return '__URL__:' . $prusaFile;
            }
        }

        $this->lastError = 'Could not find fid for "' . $slug . '". '
            . 'files[], stl_file, and bambu_file all null for this product.';
        return '';
    }

    /**
     * Walk decoded JSON and return the first numeric string that looks like a Strapi file ID.
     * Handles Next.js pageProps nesting: { pageProps: { product: { ... } } }
     * Also catches fid/file_id/fileId keys anywhere in the tree.
     */
    /**
     * Stream a remote URL to a local file path.
     * The static.stlflix.com CDN requires no Authorization — just Referer.
     * $progressFn: callable(int $bytes) — called periodically with cumulative byte count.
     */
    public function downloadToFile(string $url, string $dest, ?callable $progressFn = null): bool
    {
        $this->lastError = '';

        $fh = @fopen($dest, 'wb');
        if ($fh === false) {
            $this->lastError = 'Cannot open dest for writing: ' . $dest;
            return false;
        }

        // The CDN (static.stlflix.com / CloudFront) serves ZIPs publicly —
        // no Authorization needed, only a Referer from platform.stlflix.com.
        $isCdn    = str_contains($url, 'static.stlflix.com');
        $headers  = ['Referer: ' . self::PLATFORM . '/'];
        if (!$isCdn && $this->token !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        $written = 0;
        $ch      = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 600,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_USERAGENT      => self::UA,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_WRITEFUNCTION  => static function ($ch, string $data) use ($fh, &$written, $progressFn): int {
                $n = fwrite($fh, $data);
                if ($n === false) return -1;
                $written += $n;
                if ($progressFn !== null) $progressFn($written);
                return $n;
            },
        ]);

        curl_exec($ch);
        $st  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fh);

        if ($err !== '') {
            $this->lastError = 'cURL error: ' . $err;
            @unlink($dest);
            return false;
        }
        if ($st === 401 || $st === 403) {
            $this->lastError = 'STLFlix auth failed (HTTP ' . $st . ') — re-paste jwt in Settings.';
            @unlink($dest);
            return false;
        }
        if ($st >= 400) {
            $this->lastError = 'HTTP ' . $st . ' downloading ' . basename($dest);
            @unlink($dest);
            return false;
        }
        if ($written === 0) {
            $this->lastError = 'Empty response — possible auth issue.';
            @unlink($dest);
            return false;
        }

        return true;
    }

    public function validate(): bool
    {
        if (!$this->isAuthed()) {
            $this->lastError = 'No STLFlix token stored.';
            return false;
        }
        $query = '{ me { id username email } }';
        return $this->graphql($query) !== null;
    }

    /**
     * Return the field names on the ProductEntity type via introspection.
     * Use for debugging when getDownloadUrl returns no URL.
     * @return string[]
     */
    public function introspectProductFields(): array
    {
        $query = <<<'GQL'
        {
          __type(name: "ProductEntityResponse") {
            fields { name }
          }
        }
        GQL;
        $data = $this->graphql($query);
        $fields = [];
        foreach (($data['__type']['fields'] ?? []) as $f) {
            $fields[] = (string) ($f['name'] ?? '');
        }
        if ($fields === []) {
            // Try the attributes wrapper type.
            $q2 = '{ __type(name: "Product") { fields { name } } }';
            $d2 = $this->graphql($q2);
            foreach (($d2['__type']['fields'] ?? []) as $f) {
                $fields[] = (string) ($f['name'] ?? '');
            }
        }
        return array_filter($fields);
    }

    // ---- Private helpers ---------------------------------------------------

    /** @return array<string,mixed>|null */
    private function graphql(string $query): ?array
    {
        $json = $this->postJson(self::GRAPHQL, ['query' => $query]);
        if (!is_array($json)) return null;
        if (!empty($json['errors'])) {
            $this->lastError = (string) ($json['errors'][0]['message'] ?? 'STLFlix GraphQL error');
            return null;
        }
        return is_array($json['data'] ?? null) ? $json['data'] : null;
    }

    /** @return mixed */
    private function postJson(string $url, array $payload)
    {
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: ' . self::UA,
        ];
        if ($this->token !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $body = curl_exec($ch);
        $st   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            $this->lastError = 'STLFlix network error: ' . $err;
            return null;
        }
        if ($st === 401 || $st === 403) {
            $this->lastError = 'STLFlix auth failed (HTTP ' . $st . ') - re-paste jwt in Settings.';
            return null;
        }
        if ($st >= 400) {
            $this->lastError = 'STLFlix HTTP ' . $st;
            return null;
        }
        $json = json_decode((string) $body, true);
        if ($json === null && json_last_error() !== JSON_ERROR_NONE) {
            $this->lastError = 'Non-JSON response from STLFlix.';
            return null;
        }
        return $json;
    }

    /** GET a URL and return decoded JSON array, or null on error. */
    private function getJson(string $url): ?array
    {
        $isPlatform = str_contains($url, 'platform.stlflix.com');
        $headers    = ['Accept: application/json'];
        if ($isPlatform) {
            // Next.js JSON endpoints need the Referer; no auth header.
            $headers[] = 'Referer: ' . self::PLATFORM . '/';
        } else {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_USERAGENT      => self::UA,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $body = curl_exec($ch);
        $st   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $st >= 400) return null;
        $json = json_decode((string) $body, true);
        return is_array($json) ? $json : null;
    }

    private function extractFirstZipUrl(array $data): string
    {
        $found = null;
        array_walk_recursive($data, static function ($v) use (&$found): void {
            if (
                $found === null
                && is_string($v)
                && stripos($v, '.zip') !== false
                && (str_starts_with($v, 'http') || str_starts_with($v, '//'))
            ) {
                $found = $v;
            }
        });
        return isset($found) && is_string($found) ? $this->absoluteUrl($found) : '';
    }

    private function absoluteUrl(string $url): string
    {
        if (str_starts_with($url, 'http')) return $url;
        if (str_starts_with($url, '//'))  return 'https:' . $url;
        if (str_starts_with($url, '/'))   return self::PLATFORM . $url;
        return self::PLATFORM . '/' . $url;
    }

    /** @param array<int,mixed> $items */
    private function normalize(array $items): array
    {
        $out = [];
        foreach ($items as $row) {
            if (!is_array($row)) continue;
            $p = $row['product']['data'] ?? $row['data'] ?? $row;
            if (!is_array($p)) continue;
            $a = $p['attributes'] ?? [];
            if (!is_array($a)) continue;

            $id    = (string) ($p['id'] ?? '');
            $name  = (string) ($a['name'] ?? 'Untitled');
            $slug  = (string) ($a['slug'] ?? $id);
            $thumb = (string) ($a['thumbnail']['data']['attributes']['url'] ?? '');
            $cats  = [];
            foreach (($a['categories']['data'] ?? []) as $c) {
                $label = (string) ($c['attributes']['name'] ?? '');
                if ($label !== '') $cats[] = $label;
            }

            $out[] = [
                'id'      => $id,
                'slug'    => $slug,
                'name'    => $name,
                'creator' => $cats !== [] ? implode(', ', array_slice($cats, 0, 2)) : 'STLFlix',
                'thumb'   => $thumb,
                'images'  => $thumb !== '' ? [$thumb] : [],
                'size'    => 0,
                'source'  => 'stlflix',
            ];
        }
        return $out;
    }
}
