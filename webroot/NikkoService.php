<?php
declare(strict_types=1);

/**
 * NikkoService
 *
 * Catalog browsing, category list, and ZIP download for
 * nikkoindustriesmembership.com — a flat-fee, unlimited-download membership
 * library (WordPress + WooCommerce/MemberPress + Elementor). Every product on
 * the site is accessible to any active member; there is no per-model purchase
 * and no API. Everything here is plain HTML scraping over an authenticated
 * session cookie, the same shape as Cults3D's cookie-based free-model path.
 *
 * Auth: paste the `PHPSESSID` (and optionally `wordpress_logged_in_*`) cookie
 * values from a logged-in browser tab. No token, no API key — this site has
 * no API at all.
 *
 * Download flow (confirmed via live HAR capture):
 *   1. GET /product/{slug}/  (with session cookie)
 *      -> page HTML embeds a Shopify "Digital Downloads" landing-page link:
 *         https://delivery.shopifyapps.com/-/{token1}/{token2}
 *   2. GET https://delivery.shopifyapps.com/-/{token1}/{token2}/download
 *      -> 302 redirect to a signed, time-limited URL (Google Cloud Storage or
 *         S3 — Shopify load-balances across both; treat both the same).
 *   3. GET the signed URL -> the actual .zip file.
 *
 * The two tokens are per-product (confirmed across two different products in
 * testing) and are embedded directly in the product page's own markup for any
 * member with an active session — they are not tied to a specific past order,
 * so no purchase/order history page is needed or exists.
 */
final class NikkoService
{
    private const BASE  = 'https://nikkoindustriesmembership.com';
    private const LIBRARY_PATH = '/nikko-industry-library/';
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    public string $lastError = '';
    public int    $lastTotal = 0;

    private string $cookie;
    private string $deliverySessionCookie = '';

    /**
     * Accepts either two separate values (PHPSESSID, wordpress_logged_in_*)
     * which are combined into a Cookie header internally, or — for backward
     * compatibility with anything that already passes a full pre-built header
     * — a single string in $phpSessId containing the whole thing (detected by
     * the presence of an '=' before any ';', i.e. it already looks like a
     * Cookie header rather than a bare session ID).
     */
    public function __construct(?string $phpSessId = null, ?string $wpLoggedIn = null)
    {
        if ($phpSessId !== null && $wpLoggedIn === null && str_contains($phpSessId, '=')) {
            // Whole header passed in directly.
            $this->cookie = trim($phpSessId);
            return;
        }

        $sess  = trim($phpSessId ?? (function_exists('cfg') ? (string) cfg('nikko_phpsessid') : ''));
        $login = trim($wpLoggedIn ?? (function_exists('cfg') ? (string) cfg('nikko_wp_logged_in') : ''));

        $parts = [];
        if ($sess !== '')  $parts[] = 'PHPSESSID=' . $sess;
        if ($login !== '') {
            // WordPress's auth check validates against a specific cookie name
            // — wordpress_logged_in_{hash}, where the hash suffix is derived
            // from the site URL — not a generic "wordpress_logged_in". A bare
            // value with no name has nothing correct to attach to, so require
            // the full "name=value" pair here rather than guessing a name that
            // WordPress won't recognize.
            if (str_contains($login, '=')) {
                $parts[] = $login;
            }
        }
        $this->cookie = implode('; ', $parts);
    }

    public function isAuthed(): bool
    {
        return $this->cookie !== '';
    }

    /**
     * Scrape the category sidebar off the library page. Cached by the caller
     * if desired — this is a single page fetch, but categories rarely change.
     *
     * @return array<string,string> slug => label
     */
    public function categories(): array
    {
        $this->lastError = '';
        $html = $this->getHtml(self::BASE . self::LIBRARY_PATH);
        if ($html === null) {
            return ['' => 'All Models'];
        }

        $out = ['' => 'All Models'];
        // Category sidebar links: /product-category/{slug}/
        if (preg_match_all(
            '#href="https://nikkoindustriesmembership\.com/product-category/([a-z0-9\-]+)/?"[^>]*>\s*([^<]+?)\s*<#i',
            $html,
            $m
        )) {
            foreach ($m[1] as $i => $slug) {
                $label = trim(html_entity_decode($m[2][$i], ENT_QUOTES));
                $slug  = strtolower($slug);
                if ($slug !== '' && $label !== '' && !isset($out[$slug])) {
                    $out[$slug] = $label;
                }
            }
        }
        return $out;
    }

    /**
     * Browse the library catalog, optionally filtered by category and/or a
     * keyword (keyword filtering is done client-side against scraped titles —
     * the site's own search box is a separate AJAX endpoint we don't rely on,
     * since pagination-by-page-number covers everything the catalog has).
     *
     * @return array<int,array<string,mixed>>
     */
    public function search(string $query = '', int $limit = 20, int $offset = 0, string $categorySlug = ''): array
    {
        $this->lastError = '';
        $this->lastTotal = 0;

        // WordPress paginates in fixed pages of ~20; map offset -> page number.
        $perPage = 20;
        $page    = (int) floor($offset / $perPage) + 1;

        $url = $categorySlug !== ''
            ? self::BASE . '/product-category/' . rawurlencode($categorySlug) . '/'
            : self::BASE . self::LIBRARY_PATH;
        if ($page > 1) {
            $url = rtrim($url, '/') . '/page/' . $page . '/';
        }

        $html = $this->getHtml($url);
        if ($html === null) {
            return [];
        }

        $this->lastTotal = $this->extractTotal($html);
        $items = $this->extractListing($html);

        if ($query !== '') {
            $needle = mb_strtolower($query);
            $items = array_values(array_filter($items, static function ($it) use ($needle) {
                return mb_strpos(mb_strtolower($it['name']), $needle) !== false;
            }));
        }

        return array_slice($items, 0, $limit);
    }

    /** Parse "Showing 1–20 of 2587 results" out of the listing page. */
    private function extractTotal(string $html): int
    {
        if (preg_match('/of\s+([\d,]+)\s+results/i', $html, $m)) {
            return (int) str_replace(',', '', $m[1]);
        }
        return 0;
    }

    /**
     * Pull product entries off a listing/category page. Confirmed against a
     * live page capture — this theme is a custom component (not stock
     * WooCommerce or Elementor's product-grid widget), so the markup doesn't
     * match either's usual conventions:
     *
     *   <div class="products-list-item ...">
     *     <a href=".../product/{slug}/" class="products-list-item__image-wrapper">
     *       <div class="products-list-item__image" style="background-image: url(...);">
     *     <div class="products-list-item__tags ...">
     *       <a href=".../product-category/{slug}/">{Label}</a>, <a ...>{Label}</a>
     *     <a href=".../product/{slug}/" class="products-list-item__title">
     *       ... <span class="products-list-item__title-text-content" ...>{Title}<style>...</style></span>
     *
     * Each card's title text is immediately followed by an inline <style>
     * block (a per-card marquee animation) before the closing </span> — the
     * capture below stops at the first '<' so that style block is excluded
     * automatically.
     *
     * @return array<int,array<string,mixed>>
     */
    private function extractListing(string $html): array
    {
        $out = [];

        if (!preg_match_all(
            '#<div class="products-list-item(?:\s[^"]*)?">(.*?)</div>\s*</div>\s*</div>#is',
            $html,
            $blocks
        )) {
            return $out;
        }

        foreach ($blocks[1] as $block) {
            if (!preg_match(
                '#href="https://nikkoindustriesmembership\.com/product/([a-z0-9\-]+)/?"\s+class="products-list-item__image-wrapper"#i',
                $block,
                $slugM
            )) {
                continue;
            }
            $slug = strtolower($slugM[1]);

            $name = '';
            if (preg_match(
                '#class="products-list-item__title-text-content[^"]*"[^>]*>([^<]+)#i',
                $block,
                $nm
            )) {
                $name = trim(html_entity_decode($nm[1], ENT_QUOTES));
            }
            if ($name === '') {
                $name = $this->titleFromSlug($slug);
            }

            $thumb = '';
            if (preg_match(
                '#class="products-list-item__image"\s+style="background-image:\s*url\(([^)]+)\)#i',
                $block,
                $tm
            )) {
                $thumb = $this->absoluteUrl(trim($tm[1], "'\" "));
            }

            $cats = [];
            if (preg_match(
                '#class="products-list-item__tags[^"]*"[^>]*>(.*?)</div>#is',
                $block,
                $tagBlockM
            )) {
                if (preg_match_all('#<a[^>]+href="[^"]*product-category/[a-z0-9\-]+/?"[^>]*>\s*([^<]+?)\s*</a>#i', $tagBlockM[1], $cm)) {
                    foreach ($cm[1] as $label) {
                        $label = trim(html_entity_decode($label, ENT_QUOTES));
                        if ($label !== '') $cats[] = $label;
                    }
                }
            }

            $out[] = [
                'id'      => $slug,
                'slug'    => $slug,
                'name'    => $name,
                'creator' => $cats !== [] ? implode(', ', array_slice($cats, 0, 2)) : 'Nikko Industries',
                'thumb'   => $thumb,
                'images'  => $thumb !== '' ? [$thumb] : [],
                'size'    => 0,
                'source'  => 'nikko',
            ];
        }

        return $out;
    }

    private function titleFromSlug(string $slug): string
    {
        return ucwords(str_replace('-', ' ', $slug));
    }

    /**
     * Resolve the download URL(s) for a product by slug. Scrapes the product
     * page for the embedded Shopify Digital Downloads landing-page link, then
     * follows it to the signed storage URL.
     *
     * @return string[]
     */
    public function getDownloadUrls(string $modelId, string $slug): array
    {
        $this->lastError = '';
        $useSlug = $slug !== '' ? $slug : $modelId;

        $productUrl = self::BASE . '/product/' . rawurlencode($useSlug) . '/';
        $html = $this->getHtml($productUrl);
        if ($html === null) {
            return [];
        }

        // The download landing page is embedded as a plain link/form action:
        // https://delivery.shopifyapps.com/-/{token1}/{token2}
        if (!preg_match(
            '#https://delivery\.shopifyapps\.com/-/([0-9a-f]{16})/([0-9a-f]{16})#i',
            $html,
            $m
        )) {
            $this->lastError = 'No download link found on product page — '
                . 'either the membership session is not active, or this product has no attached file.';
            return [];
        }

        $landingUrl = 'https://delivery.shopifyapps.com/-/' . $m[1] . '/' . $m[2];

        // The /download endpoint requires a ?download={exact filename} query
        // param — confirmed via live capture (the button's underlying form on
        // the landing page is method="get" action=".../download", and the
        // submitted button value is the literal filename). Fetch the landing
        // page first: it both carries that filename in the form, AND issues a
        // delivery_app_session cookie that the /download request must echo back
        // (captured inside fetchPublic, replayed inside resolveSignedUrl).
        $landingHtml = $this->fetchPublic($landingUrl);
        if ($landingHtml === null) {
            // fetchPublic() already set a specific lastError (HTTP status,
            // curl error) — keep it rather than overwriting with something
            // vaguer, since that detail is what actually narrows down issues
            // like Cloudflare bot-blocking vs. a dead/expired link.
            if ($this->lastError === '') {
                $this->lastError = 'Could not load the Shopify Digital Downloads landing page.';
            }
            return [];
        }

        // <button type="submit" ... name="download" value="Exact File Name.zip">
        if (!preg_match('#name="download"\s+value="([^"]+)"#i', $landingHtml, $fm)) {
            $this->lastError = 'Could not find a filename on the Shopify Digital Downloads landing page.';
            return [];
        }
        $filename = html_entity_decode($fm[1], ENT_QUOTES);

        // Match the browser's exact wire format: the form submits this as a GET
        // query param, which application/x-www-form-urlencoded encodes spaces
        // as '+' (not %20). Shopify's endpoint has proven picky, so mirror it.
        $downloadUrl = $landingUrl . '/download?download=' . str_replace('%20', '+', rawurlencode($filename));
        $signedUrl = $this->resolveSignedUrl($downloadUrl, $landingUrl);
        if ($signedUrl === '') {
            return [];
        }

        return [$signedUrl];
    }

    /**
     * Fetch the Shopify delivery-app landing page. Sends no Nikko membership
     * cookie (this domain doesn't use it) but DOES set a Referer and captures
     * the delivery_app_session cookie from the response, which the subsequent
     * /download request must replay — see resolveSignedUrl().
     */
    private function fetchPublic(string $url): ?string
    {
        // Clear any session cookie captured on a previous call so a stale one
        // from an earlier job can't leak into this request if this response
        // doesn't issue a fresh Set-Cookie. The worker reuses one instance
        // across all queued jobs.
        $this->deliverySessionCookie = '';
        $headerText = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_USERAGENT      => self::UA,
            CURLOPT_REFERER        => self::BASE . '/',
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9',
                // Shopify's Digital Downloads app returns a 404 (not 403) to
                // requests lacking these — it checks that the hit is a genuine
                // top-level browser navigation, not a programmatic fetch.
                // Confirmed: identical URL/tokens 404 without these, 200 with.
                'Sec-Fetch-Dest: document',
                'Sec-Fetch-Mode: navigate',
                'Sec-Fetch-Site: cross-site',
                'Upgrade-Insecure-Requests: 1',
            ],
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$headerText): int {
                $headerText .= $line;
                return strlen($line);
            },
        ]);
        $body = curl_exec($ch);
        $st   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false || $st >= 400) {
            $this->lastError = 'Landing page fetch failed (HTTP ' . $st . (($err !== '') ? ', ' . $err : '') . ').';
            return null;
        }

        // Confirmed via live capture: Shopify's delivery app issues a
        // delivery_app_session cookie (Cloudflare-fronted) on this first hit,
        // and a real browser carries it forward to the /download request.
        // Without it, the second request looks like a cold, refererless hit
        // straight to a deep download URL — likely why it 404s without this.
        if (preg_match('/^Set-Cookie:\s*(delivery_app_session=[^;]+)/mi', $headerText, $cm)) {
            $this->deliverySessionCookie = trim($cm[1]);
        }

        return (string) $body;
    }

    /**
     * Resolve the /download endpoint to its signed storage URL. Replays the
     * delivery_app_session cookie from the landing-page fetch and sets the
     * Referer to the landing page itself — both match what a real browser
     * sends, and a request missing either gets 404'd by Cloudflare even with
     * correct tokens + filename.
     */
    private function resolveSignedUrl(string $downloadUrl, string $refererUrl): string
    {
        // A plain HEAD request (CURLOPT_NOBODY) 404s on this endpoint — Shopify's
        // delivery app apparently doesn't implement HEAD, only GET. Use a real
        // GET but stop reading after the headers via a write-function that
        // returns 0 once they're captured, so we never pull the (irrelevant,
        // since we don't follow the redirect ourselves) response body.
        $headerText = '';
        $headers = [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9',
            // Same navigation-header requirement as the landing page (see
            // fetchPublic). This request's referer is the landing page on the
            // same delivery.shopifyapps.com origin, so Sec-Fetch-Site is
            // same-origin here rather than cross-site.
            'Sec-Fetch-Dest: document',
            'Sec-Fetch-Mode: navigate',
            'Sec-Fetch-Site: same-origin',
            'Upgrade-Insecure-Requests: 1',
        ];
        if ($this->deliverySessionCookie !== '') {
            $headers[] = 'Cookie: ' . $this->deliverySessionCookie;
        }
        $ch = curl_init($downloadUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_USERAGENT      => self::UA,
            CURLOPT_REFERER        => $refererUrl,
            CURLOPT_HTTPHEADER     => $headers,
            CURLINFO_HEADER_OUT    => true,
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$headerText): int {
                $headerText .= $line;
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $data): int {
                // Discard body, but report it as "written" so curl doesn't
                // treat this as a failed transfer.
                return strlen($data);
            },
        ]);
        curl_exec($ch);
        $st  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $sentHeaders = (string) curl_getinfo($ch, CURLINFO_HEADER_OUT);
        $err = curl_error($ch);
        curl_close($ch);

        // One-shot diagnostic: dump exactly what we sent + the status we got,
        // so a 404 can be diffed against a known-good manual curl. Written to a
        // world-readable temp file; remove this block once the flow is solid.
        @file_put_contents('/tmp/nikko_download_debug.txt',
            "URL: $downloadUrl\nSTATUS: $st\n\n--- REQUEST HEADERS PHP SENT ---\n$sentHeaders\n\n--- RESPONSE HEADERS ---\n$headerText\n");

        if ($err !== '') {
            $this->lastError = 'Network error resolving download link: ' . $err;
            return '';
        }
        if ($st === 401 || $st === 403) {
            $this->lastError = 'Nikko download link rejected (HTTP ' . $st . ') — link may have expired.';
            return '';
        }
        if ($st !== 302 && $st !== 301) {
            $this->lastError = 'Expected a redirect from delivery.shopifyapps.com, got HTTP ' . $st . '.';
            return '';
        }
        if (!preg_match('/^Location:\s*(.+)$/mi', $headerText, $lm)) {
            $this->lastError = 'Redirect had no Location header.';
            return '';
        }
        return trim($lm[1]);
    }

    /**
     * Stream a signed storage URL to disk. No auth header needed — the
     * signature in the query string is the credential, same shape as
     * Printables' signed CDN links.
     */
    public function downloadToFile(string $url, string $dest, ?callable $progressFn = null): bool
    {
        $this->lastError = '';
        $fh = fopen($dest, 'wb');
        if ($fh === false) {
            $this->lastError = 'Could not open destination file: ' . $dest;
            return false;
        }

        $written = 0;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 0,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_USERAGENT      => self::UA,
            CURLOPT_FILE           => $fh,
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
            $this->lastError = 'Signed URL rejected (HTTP ' . $st . ') — it may have expired (24h TTL); the job will re-resolve on retry.';
            @unlink($dest);
            return false;
        }
        if ($st >= 400) {
            $this->lastError = 'HTTP ' . $st . ' downloading ' . basename($dest);
            @unlink($dest);
            return false;
        }
        if ($written === 0) {
            $this->lastError = 'Empty response.';
            @unlink($dest);
            return false;
        }

        return true;
    }

    public function validate(): bool
    {
        if (!$this->isAuthed()) {
            $this->lastError = 'No Nikko session cookie stored.';
            return false;
        }
        $html = $this->getHtml(self::BASE . '/my-account/');
        if ($html === null) {
            return false;
        }
        // A logged-out session redirects /my-account/ to the login form, which
        // renders a "Username or email address" field. Active members instead
        // see account nav (Orders / Downloads / Addresses / Logout).
        return stripos($html, 'Log out') !== false || stripos($html, 'Logout') !== false;
    }

    // ---- Private helpers ---------------------------------------------------

    private function getHtml(string $url): ?string
    {
        if (!$this->isAuthed()) {
            $this->lastError = 'No Nikko session cookie set (paste in Settings).';
            return null;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_USERAGENT      => self::UA,
            CURLOPT_HTTPHEADER     => [
                'Cookie: ' . $this->cookie,
                'Accept: text/html,application/xhtml+xml',
            ],
        ]);
        $body = curl_exec($ch);
        $st   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            $this->lastError = 'Nikko network error: ' . $err;
            return null;
        }
        if ($st === 401 || $st === 403) {
            $this->lastError = 'Nikko auth failed (HTTP ' . $st . ') — re-paste session cookie in Settings.';
            return null;
        }
        if ($st >= 400) {
            $this->lastError = 'Nikko HTTP ' . $st;
            return null;
        }
        // A login-wall response (cookie expired) renders the login form instead
        // of the catalog/page content — detect it explicitly so the worker can
        // halt cleanly rather than treating an empty scrape as "no files."
        if (stripos((string) $body, 'name="username"') !== false
            && stripos((string) $body, 'woocommerce-form-login') !== false) {
            $this->lastError = 'Nikko session expired — re-paste session cookie in Settings.';
            return null;
        }
        return (string) $body;
    }

    private function absoluteUrl(string $url): string
    {
        if (str_starts_with($url, 'http')) return $url;
        if (str_starts_with($url, '//'))   return 'https:' . $url;
        if (str_starts_with($url, '/'))    return self::BASE . $url;
        return self::BASE . '/' . $url;
    }
}
