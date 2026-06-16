<?php
declare(strict_types=1);

/**
 * Cults3DService
 *
 * Cults3D GraphQL API client.
 * Docs: https://cults3d.com/en/pages/graphql
 *
 * Auth: HTTP Basic — base64(username:api_key)
 *   cults3d.com → Account → Settings → API → Generate key
 *   Stored as: cults3d_username + cults3d_token (api key)
 *
 * Endpoint: POST https://cults3d.com/graphql
 * Rate limit: ~60 req/30s, ~500 req/day
 */
final class Cults3DService
{
    private const ENDPOINT = 'https://cults3d.com/graphql';
    private const UA        = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:151.0) Gecko/20100101 Firefox/151.0';
    private const CDN       = 'files.cults3d.com';
    private const WEB       = 'https://cults3d.com';

    public string $lastError = '';
    public int    $lastTotal = 0;

    private string $username;
    private string $apiKey;
    private string $sessionId;
    private string $cfClearance;

    public function __construct(?string $username = null, ?string $apiKey = null)
    {
        $this->username = trim($username ?? (function_exists('cfg') ? (string) cfg('cults3d_username') : ''));
        $this->apiKey   = trim($apiKey   ?? (function_exists('cfg') ? (string) cfg('cults3d_token')    : ''));
        // Session cookies used only for the web download flow (the public API
        // never exposes a download URL). Browse/search still use the API key.
        $this->sessionId   = function_exists('cfg') ? trim((string) cfg('cults3d_session'))      : '';
        $this->cfClearance = function_exists('cfg') ? trim((string) cfg('cults3d_cf_clearance')) : '';
    }

    /** Whether the web-download session cookie is configured. */
    public function hasSession(): bool
    {
        return $this->sessionId !== '';
    }

    public function isAuthed(): bool
    {
        return $this->username !== '' && $this->apiKey !== '';
    }

    private function authHeader(): string
    {
        return 'Authorization: Basic ' . base64_encode($this->username . ':' . $this->apiKey);
    }

    /**
     * Search or browse creations.
     * @return array<int,array<string,mixed>>
     */
    public function search(
        string $query    = '',
        int    $limit    = 20,
        int    $page     = 1,
        bool   $freeOnly = false,
        string $category = ''
    ): array {
        $this->lastError = '';
        $this->lastTotal = 0;

        if (!$this->isAuthed()) {
            $this->lastError = 'Cults3D credentials not set (paste username + API key in Settings).';
            return [];
        }

        // Two separate endpoints: keyword search vs browse
        if (trim($query) !== '') {
            // Keyword search: creationsSearchBatch(query:, limit:, offset:)
            $args   = [];
            $args[] = 'query: ' . json_encode(trim($query));
            if ($limit > 0) $args[] = 'limit: ' . min(20, $limit);
            if ($page > 1)  $args[] = 'offset: ' . (($page - 1) * min(20, $limit));

            $argStr = '(' . implode(', ', $args) . ')';
            $gql = <<<GQL
            {
              creationsSearchBatch{$argStr} {
                results {
                  id
                  slug
                  name
                  illustrationImageUrl
                  price { cents }
                  category { slug }
                  creator { nick }
                }
              }
            }
            GQL;
            $data  = $this->gql($gql);
            if ($data === null) return [];
            $items = $data['creationsSearchBatch']['results'] ?? [];
        } else {
            // Browse: creationsBatch(limit:, offset:, onlyFree:, categorySlug:)
            $args = [];
            if ($freeOnly)        $args[] = 'onlyFree: true';
            if ($category !== '') $args[] = 'categorySlugEn: ' . json_encode($category);
            if ($limit > 0)       $args[] = 'limit: ' . min(20, $limit);
            if ($page > 1)        $args[] = 'offset: ' . (($page - 1) * min(20, $limit));

            $argStr = $args !== [] ? '(' . implode(', ', $args) . ')' : '';
            $gql = <<<GQL
            {
              creationsBatch{$argStr} {
                results {
                  id
                  slug
                  name
                  illustrationImageUrl
                  price { cents }
                  category { slug }
                  creator { nick }
                }
              }
            }
            GQL;
            $data  = $this->gql($gql);
            if ($data === null) return [];
            $items = $data['creationsBatch']['results'] ?? [];
        }

        $this->lastTotal = count($items); // Cults doesn't return total counts
        return $this->normalize($items);
    }

    /**
     * List available categories (slugs + labels).
     * @return array<string,string>
     */
    public function categories(): array
    {
        if (!$this->isAuthed()) return [];
        $data = $this->gql('{ categories { slug name } }');
        if ($data === null) return [];
        $out = ['' => 'All Models'];
        foreach (($data['categories'] ?? []) as $c) {
            $slug = (string)($c['slug'] ?? '');
            $name = (string)($c['name'] ?? '');
            if ($slug !== '' && $name !== '') $out[$slug] = $name;
        }
        return $out;
    }

    /**
     * Get download URLs for a creation's files.
     *
     * Cults3D's `Creation` type has no `files` field. Downloadable files live
     * under `blueprints`, each exposing fileName / fileUrl / fileExtension.
     *
     * @return array<int,array{name:string,url:string,size:int}>
     */
    public function getFiles(string $slug): array
    {
        $this->lastError = '';
        if (!$this->isAuthed()) return [];

        // Pull price (pre-formatted, in the creation's own currency) and
        // openPriced alongside blueprints so we can distinguish a paid-but-
        // unowned model (fileUrl withheld by the API) from a model that
        // genuinely has no files.
        $gql = '{ creation(slug: ' . json_encode($slug) . ') { '
             . 'price { cents formatted } openPriced '
             . 'blueprints { fileName fileExtension fileUrl } } }';
        $data = $this->gql($gql);
        if ($data === null) return [];

        $creation   = $data['creation'] ?? null;
        if (!is_array($creation)) {
            $this->lastError = 'Cults3D creation not found for "' . $slug . '".';
            return [];
        }

        $blueprints = $creation['blueprints'] ?? [];
        $out = [];
        $hadBlueprintWithoutUrl = false;
        foreach ($blueprints as $bp) {
            $url = (string) ($bp['fileUrl'] ?? '');
            if ($url === '') { $hadBlueprintWithoutUrl = true; continue; }

            $name = (string) ($bp['fileName'] ?? '');
            if ($name === '') {
                // Synthesize a name from URL basename if the API omits it.
                $name = basename(parse_url($url, PHP_URL_PATH) ?: 'file');
            }
            // Ensure the extension is present on the filename.
            $ext = strtolower((string) ($bp['fileExtension'] ?? ''));
            if ($ext !== '' && !preg_match('/\.' . preg_quote($ext, '/') . '$/i', $name)) {
                $name .= '.' . $ext;
            }

            $out[] = [
                'name' => $name,
                'url'  => $url,
                'size' => 0, // Blueprint type exposes no size field.
            ];
        }

        if ($out === []) {
            // Blueprints exist but their URLs are withheld. This normally means
            // a paid model you don't own — Cults3D only populates fileUrl for
            // free models or ones you've purchased. A pay-what-you-want model
            // with a zero floor (cents == 0) is still FREE to download, so only
            // a positive price counts as "paid".
            $cents     = (int) ($creation['price']['cents'] ?? 0);
            $formatted = trim((string) ($creation['price']['formatted'] ?? ''));
            if ($hadBlueprintWithoutUrl && $cents > 0) {
                // Prefer Cults3D's own localized price string (correct currency
                // symbol/format); fall back to a generic label if absent.
                $price = $formatted !== '' ? $formatted : ($cents . ' cents');
                $this->lastError = 'Paid Cults3D model (' . $price . ') — download URL is '
                    . 'only available after purchase, so it cannot be fetched.';
            } elseif ($hadBlueprintWithoutUrl) {
                $this->lastError = 'Cults3D withheld the download URL for this model '
                    . '(it may require purchase or additional access).';
            } else {
                $this->lastError = 'No downloadable blueprints found for this creation.';
            }
        }
        return $out;
    }

    /**
     * Download a file to disk.
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

    /** Public wrapper for use by settings validation. */
    public function gqlPublic(string $query): ?array
    {
        return $this->gql($query);
    }

    /** @return array<string,mixed>|null */
    private function gql(string $query): ?array
    {
        $ch   = $this->baseCurl(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST       => true,
            CURLOPT_POSTFIELDS => json_encode(['query' => $query]),
        ]);
        $body = curl_exec($ch);
        $st   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($body === false) { $this->lastError = 'Network error: ' . $cerr; return null; }
        if ($st === 401 || $st === 403) {
            $this->lastError = 'Cults3D auth failed (HTTP ' . $st . ') — check username and API key in Settings.';
            return null;
        }
        if ($st >= 400) {
            $this->lastError = 'Cults3D HTTP ' . $st;
            return null;
        }
        $json = json_decode((string)$body, true);
        if (!is_array($json)) { $this->lastError = 'Non-JSON response from Cults3D.'; return null; }
        if (!empty($json['errors'])) {
            $this->lastError = (string)($json['errors'][0]['message'] ?? 'GraphQL error');
            return null;
        }
        return $json['data'] ?? null;
    }

    /**
     * Build the Cookie header for authenticated web (session) requests.
     */
    private function sessionCookieHeader(): string
    {
        $parts = [];
        if ($this->cfClearance !== '') $parts[] = 'cf_clearance=' . $this->cfClearance;
        $parts[] = 'age_gate=true';
        if ($this->sessionId !== '')   $parts[] = '_session_id=' . $this->sessionId;
        return 'Cookie: ' . implode('; ', $parts);
    }

    /**
     * A cURL handle for authenticated web requests (browser-like headers +
     * session cookies). Does NOT auto-follow redirects so callers can inspect
     * the 302 Location themselves.
     */
    private function webCurl(string $url): \CurlHandle
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
            // Explicit gzip/deflate/br only. An empty CURLOPT_ENCODING advertises
            // zstd too, which some libcurl builds can't decode — the response
            // then comes back empty / the request appears to hang.
            CURLOPT_ENCODING       => 'gzip, deflate, br',
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_HTTPHEADER     => [
                'User-Agent: ' . self::UA,
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9',
                $this->sessionCookieHeader(),
            ],
        ]);
        return $ch;
    }

    /**
     * Resolve a model slug to a single downloadable ZIP URL using the
     * authenticated web flow (the public API never exposes file URLs).
     *
     * Flow, mirroring the browser:
     *   1. GET model page          -> scrape Rails CSRF (authenticity_token)
     *   2. POST /en/free_orders    -> 302 to /en/orders/{orderId}
     *   3. GET  /en/orders/{id}    -> scrape /en/downloads/{id}?creation=slug
     *   4. GET  /en/downloads/{id} -> 302 to signed CloudFront .zip URL
     *
     * Returns the signed CloudFront URL, or '' on failure (sets lastError).
     * Note: only works for FREE models (free_orders). Paid models need a
     * purchase and are out of scope.
     */
    public function resolveSessionDownloadUrl(string $slug): string
    {
        $this->lastError = '';
        if (!$this->hasSession()) {
            $this->lastError = 'No Cults3D session cookie set (add _session_id in Settings).';
            return '';
        }

        // 1) Resolve the exact model page URL via GraphQL (gives the correct
        //    category segment), then fetch it for the Rails CSRF token.
        $modelUrl = $this->lookupModelUrl($slug);
        if ($modelUrl === '') {
            // Fallback: category-agnostic path (Cults3D usually redirects it).
            $modelUrl = self::WEB . '/en/3d-model/_/' . rawurlencode($slug);
        }
        $ch   = $this->webCurl($modelUrl);
        $html = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($html) || $html === '') {
            $this->lastError = 'Could not load Cults3D model page (HTTP ' . $code . ').';
            return '';
        }
        if (!preg_match('/name="csrf-token" content="([^"]+)"/', $html, $m)) {
            $this->lastError = 'Cults3D session invalid or expired (no CSRF token on page). Re-paste _session_id in Settings.';
            return '';
        }
        $csrf = html_entity_decode($m[1], ENT_QUOTES);

        // 2) POST free_orders to "acquire" the free model -> 302 to the order.
        $orderUrl = $this->postFreeOrder($slug, $csrf);
        if ($orderUrl === '') {
            // lastError already set
            return '';
        }

        // 3) GET the order page; scrape the /en/downloads/{id}?creation=slug link.
        $ch    = $this->webCurl($orderUrl);
        $oHtml = curl_exec($ch);
        curl_close($ch);
        if (!is_string($oHtml) || !preg_match('#(/en/downloads/\d+\?creation=[^"\'\s]+)#', $oHtml, $dm)) {
            $this->lastError = 'Could not find a download link on the Cults3D order page.';
            return '';
        }
        $downloadPath = html_entity_decode($dm[1], ENT_QUOTES);

        // 4) GET the download link -> 302 to the signed CloudFront URL.
        //    With FOLLOWLOCATION off, read the Location header from the response.
        $ch = $this->webCurl(self::WEB . $downloadPath);
        curl_setopt($ch, CURLOPT_HEADER, true);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hlen = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $headers = is_string($resp) ? substr($resp, 0, $hlen) : '';
        $signed  = '';
        if (preg_match('/^location:\s*(\S+)/im', $headers, $lm)) {
            $signed = trim($lm[1]);
        }

        if ($signed === '' || stripos($signed, 'download.cults3d.com') === false) {
            $this->lastError = 'Cults3D did not return a signed download URL (HTTP ' . $code . ').';
            return '';
        }
        return $signed;
    }

    /**
     * POST /en/free_orders to acquire a free model. Returns the absolute order
     * URL from the 302 Location, or '' on failure.
     */
    private function postFreeOrder(string $slug, string $csrf): string
    {
        $url = self::WEB . '/en/free_orders?creation_slug=' . rawurlencode($slug);
        $ch  = $this->webCurl($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, 'authenticity_token=' . rawurlencode($csrf));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'User-Agent: ' . self::UA,
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9',
            'Content-Type: application/x-www-form-urlencoded',
            'Origin: ' . self::WEB,
            'Referer: ' . self::WEB . '/en/3d-model/_/' . rawurlencode($slug),
            $this->sessionCookieHeader(),
        ]);
        curl_setopt($ch, CURLOPT_HEADER, true);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hlen = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $headers = is_string($resp) ? substr($resp, 0, $hlen) : '';
        $loc = '';
        if (preg_match('/^location:\s*(\S+)/im', $headers, $lm)) {
            $loc = trim($lm[1]);
        }

        if ($loc === '' || stripos($loc, '/orders/') === false) {
            $this->lastError = 'Cults3D free-order step failed (HTTP ' . $code . '). '
                . 'The model may be paid, or the session/cf_clearance cookie expired.';
            return '';
        }
        // Location may be absolute already.
        return str_starts_with($loc, 'http') ? $loc : self::WEB . $loc;
    }

    /**
     * Look up a model's exact web URL via the GraphQL `url` field, used when
     * the /_/ category placeholder doesn't resolve.
     */
    private function lookupModelUrl(string $slug): string
    {
        if (!$this->isAuthed()) return '';
        $gql = '{ creation(slug: ' . json_encode($slug) . ') { url } }';
        $data = $this->gql($gql);
        return (string) ($data['creation']['url'] ?? '');
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
                $this->authHeader(),
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: ' . self::UA,
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

            // Hard filter: exclude anything in the "naughties" category.
            $catSlug = strtolower((string)($it['category']['slug'] ?? ''));
            if (str_contains($catSlug, 'naughti')) continue;

            $thumb  = (string)($it['illustrationImageUrl'] ?? '');
            $images = $thumb !== '' ? [$thumb] : [];

            // Browse/search results don't carry per-file sizes; left as 0.
            $size = 0;

            $id   = (string)($it['id']   ?? '');
            $slug = (string)($it['slug'] ?? '');
            $name = (string)($it['name'] ?? 'Untitled');

            // Cults3D's API sometimes returns a base64 global node ID in the
            // format base64("Creation/<slug>"). Decode and extract the slug.
            if ($id !== '' && !ctype_digit($id)) {
                $decoded = base64_decode($id, true);
                if ($decoded !== false && str_contains($decoded, '/')) {
                    $real = substr($decoded, strrpos($decoded, '/') + 1);
                    if ($real !== '') {
                        $id = $real;
                        if ($slug === '') {
                            $slug = $real;
                        }
                    }
                }
            }

            $out[] = [
                'id'      => $id,
                'slug'    => $slug,
                'name'    => $name,
                'creator' => (string)($it['creator']['nick'] ?? 'unknown'),
                'thumb'   => $thumb,
                'images'  => array_values(array_filter($images)),
                'size'    => $size,
                'price'   => (int)($it['price']['cents'] ?? 0),
                'source'  => 'cults3d',
            ];
        }
        return $out;
    }
}
