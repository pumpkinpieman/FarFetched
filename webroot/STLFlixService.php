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
    private const GRAPHQL  = 'https://k8s.stlflix.com/graphql';
    private const LIBRARY  = 'https://painel.stlflix.com/api/library/custom-library';
    private const PLATFORM = 'https://platform.stlflix.com';
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
        $query = <<<'GQL'
        {
          categories(pagination: { pageSize: 100 }, sort: "name:asc") {
            data { id attributes { name slug } }
          }
        }
        GQL;
        $data = $this->graphql($query);
        if ($data === null) return ['' => 'All Models'];

        $out = ['' => 'All Models'];
        foreach (($data['categories']['data'] ?? []) as $c) {
            $id   = (string) ($c['id'] ?? '');
            $name = (string) ($c['attributes']['name'] ?? '');
            if ($id !== '' && $name !== '') $out[$id] = $name;
        }
        return $out;
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
     * Resolve the CDN ZIP download URL for a model.
     *
     * STLFlix's download flow (reverse-engineered from DevTools):
     *   1. POST k8s.stlflix.com/graphql — a "download" mutation that logs the
     *      conversion and returns the CDN file URL.
     *   2. GET static.stlflix.com/<Name_hash>.zip — no auth, just Referer cookie.
     *
     * We try several known mutation/query shapes. The static CDN URL requires
     * no Authorization header — only Referer: https://platform.stlflix.com/.
     */
    public function getDownloadUrl(string $modelId, string $slug): string
    {
        $this->lastError = '';

        if (!$this->isAuthed()) {
            $this->lastError = 'STLFlix token not set.';
            return '';
        }

        $idInt = is_numeric($modelId) ? (int) $modelId : 0;

        // --- Attempt 1: createDownload / download mutation (common Strapi pattern) ---
        $mutations = [];

        if ($idInt > 0) {
            $mutations[] = <<<GQL
            mutation {
              createDownload(data: { product: {$idInt} }) {
                data { attributes { file { data { attributes { url } } } } }
              }
            }
            GQL;

            $mutations[] = <<<GQL
            mutation {
              download(productId: {$idInt}) {
                url
                file_url
                download_url
              }
            }
            GQL;

            $mutations[] = <<<GQL
            mutation {
              createConversion(data: { product: {$idInt}, conversion_type: "download" }) {
                data { attributes { file { data { attributes { url } } } } }
              }
            }
            GQL;
        }

        // --- Attempt 2: product query with every plausible file field ---
        $filterById   = $idInt > 0 ? "filters: { id: { eq: {$idInt} } }" : '';
        $filterBySlug = $slug !== '' ? "filters: { slug: { eq: \"{$slug}\" } }" : '';

        foreach (array_filter([$filterById, $filterBySlug]) as $filter) {
            $mutations[] = <<<GQL
            {
              products({$filter}, pagination: { pageSize: 1 }) {
                data {
                  id
                  attributes {
                    pack       { data { attributes { url name } } }
                    zip_file   { data { attributes { url name } } }
                    file       { data { attributes { url name } } }
                    files      { data { attributes { url name } } }
                    download   { data { attributes { url name } } }
                    downloads  { data { attributes { url name } } }
                    model_file { data { attributes { url name } } }
                    assets     { data { attributes { url name } } }
                  }
                }
              }
            }
            GQL;
        }

        foreach ($mutations as $gql) {
            $result = $this->graphql($gql);
            if ($result === null) continue;

            $url = $this->extractFirstZipUrl($result);
            if ($url !== '') return $url;
        }

        // --- Attempt 3: painel.stlflix.com download endpoint ---
        // The library API may expose a per-product download URL.
        foreach (array_filter([$idInt > 0 ? (string)$idInt : '', $slug]) as $key) {
            $endpoints = [
                self::LIBRARY . '?product_id=' . rawurlencode($key),
                'https://painel.stlflix.com/api/download?product_id=' . rawurlencode($key),
                'https://painel.stlflix.com/api/products/' . rawurlencode($key) . '/download',
            ];
            foreach ($endpoints as $ep) {
                $data = $this->getJson($ep);
                if (!is_array($data)) continue;
                $url = $this->extractFirstZipUrl($data);
                if ($url !== '') return $url;
            }
        }

        $label = $slug !== '' ? $slug : $modelId;
        $this->lastError = 'Could not resolve download URL for STLFlix model "' . $label . '". '
            . 'The download mutation field name is unknown — capture the GraphQL Request '
            . 'payload from DevTools when clicking Download on a product page and report it.';
        return '';
    }

    /**
     * Walk any decoded JSON array and return the first https://*.zip URL found.
     * Handles both Strapi relation shapes and flat arrays.
     */
    private function extractFirstZipUrl(array $data): string
    {
        // Strapi file-relation field names to check (single and multi).
        $fileFields = ['pack','zip_file','file','files','download','downloads','model_file','assets','url'];

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
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_USERAGENT      => self::UA,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Authorization: Bearer ' . $this->token,
            ],
        ]);
        $body = curl_exec($ch);
        $st   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $st >= 400) return null;
        $json = json_decode((string) $body, true);
        return is_array($json) ? $json : null;
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
