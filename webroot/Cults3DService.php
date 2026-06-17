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
    private string $userAgent;

    public function __construct(?string $username = null, ?string $apiKey = null)
    {
        $this->username = trim($username ?? (function_exists('cfg') ? (string) cfg('cults3d_username') : ''));
        $this->apiKey   = trim($apiKey   ?? (function_exists('cfg') ? (string) cfg('cults3d_token')    : ''));
        // Session cookies used only for the web download flow (the public API
        // never exposes a download URL). Browse/search still use the API key.
        $this->sessionId   = function_exists('cfg') ? trim((string) cfg('cults3d_session'))      : '';
        $this->cfClearance = function_exists('cfg') ? trim((string) cfg('cults3d_cf_clearance')) : '';
        // cf_clearance is bound to the User-Agent that created it. The worker's
        // UA must match the browser that produced the cookie or Cloudflare
        // returns a 403 challenge. Let the user paste their exact UA; fall back
        // to the built-in default if unset.
        $ua = function_exists('cfg') ? trim((string) cfg('cults3d_user_agent')) : '';
        $this->userAgent = $ua !== '' ? $ua : self::UA;
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
    /**
     * Path to a curl-impersonate binary, if installed. curl-impersonate mimics a
     * real browser's TLS/JA3 fingerprint, which is required to get past
     * Cloudflare's bot detection (plain PHP curl is fingerprinted and 403'd even
     * with a valid cf_clearance cookie + matching UA).
     *
     * The chosen browser MUST match the browser the user pulled their
     * cf_clearance cookie from, so this is user-selectable.
     */
    private function impersonateBin(): string
    {
        static $bin = null;
        if ($bin !== null) return $bin;

        // Explicit override wins.
        $override = (string) (function_exists('cfg') ? cfg('curl_impersonate_bin') : '');
        if ($override !== '' && is_executable($override)) { return $bin = $override; }

        // Map the user's selected browser to candidate wrapper names. Each maps
        // to several versions so we tolerate whichever the image actually has.
        $browser = strtolower((string) (function_exists('cfg') ? cfg('cults3d_browser') : ''));
        $byBrowser = [
            'chrome'  => ['curl_chrome116', 'curl_chrome110', 'curl_chrome104', 'curl_chrome100'],
            'edge'    => ['curl_edge101', 'curl_edge99', 'curl_chrome116'],
            'firefox' => ['curl_ff117', 'curl_ff109', 'curl_ff102', 'curl_ff100'],
            'safari'  => ['curl_safari17_0', 'curl_safari15_5', 'curl_safari15_3'],
        ];
        $names = $byBrowser[$browser] ?? array_merge(...array_values($byBrowser));

        foreach ($names as $n) {
            $p = '/usr/local/bin/' . $n;
            if (is_executable($p)) { return $bin = $p; }
        }
        // Last-resort generic wrappers.
        foreach (['/usr/local/bin/curl-impersonate-chrome', '/usr/local/bin/curl-impersonate'] as $p) {
            if (is_executable($p)) { return $bin = $p; }
        }
        return $bin = '';
    }

    /**
     * Fetch a Cloudflare-gated URL via curl-impersonate (browser TLS fingerprint).
     * Returns ['ok'=>bool, 'status'=>int, 'headers'=>string, 'body'=>string,
     *          'location'=>string]. Falls back to plain curl if the binary is
     * missing (which will likely 403 on Cloudflare, but keeps things working on
     * non-gated requests).
     *
     * @param array<int,string> $extraHeaders
     */
    private function cfFetch(string $url, string $method = 'GET', string $postBody = '', array $extraHeaders = []): array
    {
        $bin = $this->impersonateBin();
        $headers = array_merge([
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9',
            $this->sessionCookieHeader(),
        ], $extraHeaders);

        if ($bin === '') {
            // No impersonate binary — fall back to the legacy curl path.
            return $this->cfFetchPlain($url, $method, $postBody, $headers);
        }

        // Build the curl-impersonate CLI invocation. -i includes response headers
        // so we can read the redirect Location without following it.
        $args = [$bin, '-sS', '-i', '--max-time', '60', '-X', $method];
        foreach ($headers as $h) {
            if (trim($h) === '') continue;
            $args[] = '-H';
            $args[] = $h;
        }
        if ($method === 'POST') {
            $args[] = '--data';
            $args[] = $postBody;
        }
        $args[] = $url;

        $cmd = implode(' ', array_map('escapeshellarg', $args));
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            return $this->cfFetchPlain($url, $method, $postBody, $headers);
        }
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        $out = (string) $out;
        // Split headers/body on the first blank line. Handle multiple header
        // blocks (e.g. a 100-continue or redirect chain) by taking the last one.
        $parts = preg_split("/\r?\n\r?\n/", $out, 2);
        $headerBlock = $parts[0] ?? '';
        $body        = $parts[1] ?? '';
        // If headers contained a redirect with its own body separator, the real
        // body may still be in $body; that's fine for our scrape needs.

        $status = 0;
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $headerBlock, $sm)) {
            $status = (int) $sm[1];
        }
        $location = '';
        if (preg_match('/^location:\s*(.+)$/im', $headerBlock, $lm)) {
            $location = trim($lm[1]);
        }

        return [
            'ok'       => $status > 0 && $status < 400,
            'status'   => $status,
            'headers'  => $headerBlock,
            'body'     => $body,
            'location' => $location,
        ];
    }

    /**
     * Legacy plain-curl fallback used only when curl-impersonate isn't present.
     * @param array<int,string> $headers
     */
    private function cfFetchPlain(string $url, string $method, string $postBody, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_ENCODING       => 'gzip, deflate, br',
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_HTTPHEADER     => array_merge(['User-Agent: ' . $this->userAgent], $headers),
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postBody);
        }
        $resp = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hlen   = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $resp = is_string($resp) ? $resp : '';
        $headerBlock = substr($resp, 0, $hlen);
        $body        = substr($resp, $hlen);
        $location = '';
        if (preg_match('/^location:\s*(.+)$/im', $headerBlock, $lm)) {
            $location = trim($lm[1]);
        }
        return [
            'ok'       => $status > 0 && $status < 400,
            'status'   => $status,
            'headers'  => $headerBlock,
            'body'     => $body,
            'location' => $location,
        ];
    }

    /** True when a curl-impersonate binary is available. */
    public function hasImpersonate(): bool
    {
        return $this->impersonateBin() !== '';
    }

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
                'User-Agent: ' . $this->userAgent,
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
        $resp = $this->cfFetch($modelUrl, 'GET');
        $html = $resp['body'];
        $code = $resp['status'];

        if (!is_string($html) || $html === '') {
            $this->lastError = 'Could not load Cults3D model page (HTTP ' . $code . ').';
            return '';
        }
        if (stripos($html, 'Just a moment') !== false || stripos($html, 'challenge-platform') !== false) {
            $this->lastError = $this->hasImpersonate()
                ? 'Cloudflare challenged the request even with curl-impersonate — re-paste a fresh cf_clearance + matching User-Agent.'
                : 'Cloudflare blocked the request. Install curl-impersonate (see Dockerfile) so FarFetched can pass the TLS check.';
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
        $oResp = $this->cfFetch($orderUrl, 'GET');
        $oHtml = $oResp['body'];
        if (!is_string($oHtml) || !preg_match('#(/en/downloads/\d+\?creation=[^"\'\s]+)#', $oHtml, $dm)) {
            $this->lastError = 'Could not find a download link on the Cults3D order page.';
            return '';
        }
        $downloadPath = html_entity_decode($dm[1], ENT_QUOTES);

        // 4) GET the download link -> 302 to the signed CloudFront URL.
        $dlResp  = $this->cfFetch(self::WEB . $downloadPath, 'GET');
        $headers = $dlResp['headers'];
        $signed  = '';
        if (preg_match('/^location:\s*(\S+)/im', $headers, $lm)) {
            $signed = trim($lm[1]);
        }

        // Cults3D serves signed download URLs from several CDNs/buckets
        // (download.cults3d.com / CloudFront, *.scw.cloud / Scaleway S3, etc).
        // Accept any absolute URL that carries a signature query and is not a
        // redirect back into the site (e.g. /en/log-in-choice on a dead session).
        $isSigned = $signed !== ''
            && preg_match('#^https?://#i', $signed)
            && stripos($signed, 'cults3d.com/en/') === false
            && (stripos($signed, 'signature=') !== false
                || stripos($signed, 'x-amz-signature=') !== false
                || stripos($signed, 'expires=') !== false);

        if (!$isSigned) {
            $hint = (stripos($signed, 'log-in') !== false)
                ? ' Session expired — re-paste _session_id in Settings.'
                : '';
            $this->lastError = 'Cults3D did not return a signed download URL (HTTP ' . $code . ').' . $hint;
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
        $resp = $this->cfFetch($url, 'POST', 'authenticity_token=' . rawurlencode($csrf), [
            'Content-Type: application/x-www-form-urlencoded',
            'Origin: ' . self::WEB,
            'Referer: ' . self::WEB . '/en/3d-model/_/' . rawurlencode($slug),
        ]);
        $code = $resp['status'];
        $loc  = $resp['location'];

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

    /**
     * Full web-session download for a free model. Cults3D may serve EITHER a
     * multi-file ZIP or a single raw file (e.g. one .stl), from any of several
     * CDN hosts. Returns the absolute saved path on success, or '' on failure.
     *
     * $destDir is the directory to save into; the on-disk filename comes from
     * the server's Content-Disposition (falling back to the slug).
     */
    public function downloadAllViaSession(string $slug, string $destDir, ?callable $onProgress = null): string
    {
        $signed = $this->resolveSessionDownloadUrl($slug);
        if ($signed === '') {
            return ''; // lastError already set
        }
        return $this->downloadCdnFile($signed, $destDir, $slug, $onProgress);
    }

    /**
     * Download a signed CDN URL into $destDir. Determines the filename and type
     * from the response (Content-Disposition / Content-Type), saving a .zip or
     * a raw model file as appropriate. No API auth headers; explicit
     * gzip/deflate/br (no zstd) + HTTP/1.1. Returns saved path or ''.
     */
    private function downloadCdnFile(string $url, string $destDir, string $slug, ?callable $onProgress = null): string
    {
        $this->lastError = '';
        if (!is_dir($destDir) && !@mkdir($destDir, 0775, true) && !is_dir($destDir)) {
            $this->lastError = 'Cannot create destination dir: ' . $destDir;
            return '';
        }
        $tmp = $destDir . '/.cults3d-download.part';
        $fh  = @fopen($tmp, 'wb');
        if ($fh === false) { $this->lastError = 'Cannot open temp file.'; return ''; }

        $cdHeader = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fh,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_FAILONERROR    => true,
            CURLOPT_ENCODING       => 'gzip, deflate, br',
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_HTTPHEADER     => [
                'User-Agent: ' . $this->userAgent,
                'Accept: */*',
            ],
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$cdHeader) {
                if (stripos($line, 'content-disposition:') === 0) $cdHeader .= $line;
                return strlen($line);
            },
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
        $ctype  = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $cerr   = curl_error($ch);
        curl_close($ch);
        fclose($fh);

        if ($ok === false || $status >= 400) {
            @unlink($tmp);
            $this->lastError = $cerr !== '' ? 'Download error: ' . $cerr : 'HTTP ' . $status;
            return '';
        }

        // Reject obvious error bodies (HTML/XML/JSON saved as a file).
        if (stripos($ctype, 'text/html') !== false
            || stripos($ctype, 'application/xml') !== false
            || stripos($ctype, 'application/json') !== false) {
            @unlink($tmp);
            $this->lastError = 'CDN returned ' . $ctype . ' instead of a file '
                . '(signed URL expired or session stale).';
            return '';
        }

        // Determine the real filename. Priority:
        //   1) Content-Disposition header (most authoritative)
        //   2) the basename in the signed URL path (CDN URLs carry the real
        //      filename, e.g. .../figura.stl?Expires=... or .../model.zip?X-Amz=)
        //   3) slug + extension guessed from the magic bytes
        $fname = '';
        if ($cdHeader !== '') {
            // Handle both filename="x.stl" and filename*=UTF-8''x.stl forms.
            if (preg_match('/filename\*=(?:UTF-8\'\')?"?([^";\r\n]+)"?/i', $cdHeader, $fm)) {
                $fname = rawurldecode(trim($fm[1]));
            } elseif (preg_match('/filename="?([^";\r\n]+?)"?\s*(?:;|$)/i', $cdHeader, $fm)) {
                $fname = trim($fm[1]);
            }
        }
        if ($fname === '') {
            // Pull the basename out of the signed URL path (strip the query).
            $path = (string) parse_url($url, PHP_URL_PATH);
            $base = $path !== '' ? rawurldecode(basename($path)) : '';
            // Only trust it if it has a sensible model/file extension.
            if ($base !== '' && preg_match('/\.(stl|3mf|obj|zip|step|stp|gcode|ply|scad|7z|rar)$/i', $base)) {
                $fname = $base;
            }
        }
        if ($fname === '') {
            // Last resort: slug + extension guessed from the magic bytes.
            $magic = '';
            if (($vf = @fopen($tmp, 'rb')) !== false) { $magic = (string) fread($vf, 2); fclose($vf); }
            $fname = preg_replace('/[^A-Za-z0-9._-]+/', '_', $slug) . ($magic === 'PK' ? '.zip' : '.bin');
        }
        $fname = basename($fname); // strip any path components

        $destPath = $destDir . '/' . $fname;
        if (!@rename($tmp, $destPath)) {
            @unlink($tmp);
            $this->lastError = 'Could not finalize file (rename failed).';
            return '';
        }
        return $destPath;
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
                'User-Agent: ' . $this->userAgent,
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
