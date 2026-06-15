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
     * Resolve the CDN ZIP download URL for a model via GraphQL.
     *
     * Probes several Strapi v4 field name candidates in a single query;
     * picks the first URL ending in .zip. No page scraping — platform.stlflix.com
     * is a Next.js SSR app that redirect-loops on server-side requests.
     *
     * Returns '' and sets $lastError on failure.
     */
    public function getDownloadUrl(string $modelId, string $slug): string
    {
        $this->lastError = '';

        if (!$this->isAuthed()) {
            $this->lastError = 'STLFlix token not set.';
            return '';
        }

        // Probe by slug first, then by numeric id if slug fails.
        $candidates = [];
        if ($slug !== '' && $slug !== $modelId) {
            $candidates[] = ['field' => 'slug', 'val' => $slug];
        }
        if (is_numeric($modelId)) {
            $candidates[] = ['field' => 'id', 'val' => $modelId];
        }
        if ($candidates === []) {
            $candidates[] = ['field' => 'slug', 'val' => $modelId];
        }

        foreach ($candidates as ['field' => $field, 'val' => $val]) {
            $filter = $field === 'id'
                ? "filters: { id: { eq: {$val} } }"
                : "filters: { slug: { eq: \"{$val}\" } }";

            // Query all plausible Strapi file-field names in one round-trip.
            $query = <<<GQL
            {
              products({$filter}, pagination: { pageSize: 1 }) {
                data {
                  id
                  attributes {
                    name
                    slug
                    pack      { data { attributes { url name } } }
                    zip_file  { data { attributes { url name } } }
                    file      { data { attributes { url name } } }
                    files     { data { attributes { url name } } }
                    downloads { data { attributes { url name } } }
                    assets    { data { attributes { url name } } }
                  }
                }
              }
            }
            GQL;

            $data = $this->graphql($query);
            if ($data === null) continue;

            $attrs = $data['products']['data'][0]['attributes'] ?? null;
            if ($attrs === null) continue;

            // Check every field candidate — return first .zip URL found.
            foreach (['pack', 'zip_file', 'file', 'files', 'downloads', 'assets'] as $key) {
                $entry = $attrs[$key] ?? null;
                if ($entry === null) continue;

                // Single-relation: { data: { attributes: { url } } }
                // Multi-relation:  { data: [ { attributes: { url } } ] }
                $items = isset($entry['data'][0]) ? $entry['data'] : [$entry['data'] ?? null];
                foreach ($items as $item) {
                    if (!is_array($item)) continue;
                    $u = (string) ($item['attributes']['url'] ?? '');
                    if ($u !== '') {
                        return $this->absoluteUrl($u);
                    }
                }
            }
        }

        // Last resort: ask the library endpoint for this specific model and
        // look for a download_url / file_url field the normalize() drops.
        $libUrl  = self::LIBRARY . '?product_id=' . rawurlencode($modelId);
        $libData = $this->getJson($libUrl);
        if (is_array($libData)) {
            array_walk_recursive($libData, static function ($v, $k) use (&$found): void {
                if (
                    $found === null
                    && is_string($v)
                    && str_ends_with(strtolower($v), '.zip')
                    && str_starts_with($v, 'http')
                ) {
                    $found = $v;
                }
            });
            if (!empty($found)) return (string) $found;
        }

        $label = $slug !== '' ? $slug : $modelId;
        $this->lastError = 'Could not resolve download URL for STLFlix model "' . $label . '". '
            . 'GraphQL returned no file fields — field names may have changed. '
            . 'Check k8s.stlflix.com/graphql introspection.';
        return '';
    }

    /**
     * Stream a remote URL to a local file path.
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

        $written = 0;
        $ch      = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 600,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_USERAGENT      => self::UA,
            CURLOPT_HTTPHEADER     => [
                'Referer: ' . self::PLATFORM . '/',
                'Authorization: Bearer ' . $this->token,
            ],
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
