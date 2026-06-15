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
     * Strategy 1: GraphQL product query for the file URL field.
     * Strategy 2: Scrape the product page for the download href.
     * Returns '' and sets $lastError on failure.
     */
    public function getDownloadUrl(string $modelId, string $slug): string
    {
        $this->lastError = '';

        if (!$this->isAuthed()) {
            $this->lastError = 'STLFlix token not set.';
            return '';
        }

        // Strategy 1: GraphQL — query product files.
        $gqlSlug = $slug !== '' ? $slug : $modelId;
        $query   = <<<GQL
        {
          products(filters: { slug: { eq: "{$gqlSlug}" } }, pagination: { pageSize: 1 }) {
            data {
              id
              attributes {
                name
                slug
                zip_file { data { attributes { url } } }
                files { data { attributes { url name } } }
              }
            }
          }
        }
        GQL;

        $data = $this->graphql($query);
        if ($data !== null) {
            $attrs = $data['products']['data'][0]['attributes'] ?? [];

            // Prefer explicit zip_file field.
            $zipUrl = (string) ($attrs['zip_file']['data']['attributes']['url'] ?? '');
            if ($zipUrl !== '') {
                return $this->absoluteUrl($zipUrl);
            }

            // Fall back to first file in files array.
            foreach (($attrs['files']['data'] ?? []) as $f) {
                $u = (string) ($f['attributes']['url'] ?? '');
                if ($u !== '' && str_ends_with(strtolower($u), '.zip')) {
                    return $this->absoluteUrl($u);
                }
            }
        }

        // Strategy 2: Scrape the platform product page for a .zip link.
        $pageSlug = $slug !== '' ? $slug : $modelId;
        $pageUrl  = self::PLATFORM . '/product/' . rawurlencode($pageSlug);
        $html     = $this->getHtml($pageUrl);
        if ($html === '') {
            // lastError already set by getHtml
            return '';
        }

        // Look for static.stlflix.com/*.zip href in the page.
        if (preg_match('~https://static\.stlflix\.com/[^\s"\'<>]+\.zip~i', $html, $m)) {
            return $m[0];
        }

        // Look for any *.zip download link.
        if (preg_match('~href="([^"]+\.zip[^"]*)"~i', $html, $m)) {
            return $this->absoluteUrl($m[1]);
        }

        $this->lastError = 'Could not resolve download URL for STLFlix model "' . $pageSlug . '".';
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

    private function getHtml(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_USERAGENT      => self::UA,
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml,*/*',
                'Authorization: Bearer ' . $this->token,
                'Referer: ' . self::PLATFORM . '/',
            ],
        ]);
        $body = curl_exec($ch);
        $st   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false || $err !== '') {
            $this->lastError = 'Page fetch error: ' . $err;
            return '';
        }
        if ($st >= 400) {
            $this->lastError = 'HTTP ' . $st . ' fetching product page.';
            return '';
        }
        return (string) $body;
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
