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

    public function __construct(?string $cookie = null)
    {
        $this->cookie = trim($cookie ?? (function_exists('cfg') ? (string) cfg('nikko_cookie') : ''));
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
     * Pull product entries off a listing/category page. Each WooCommerce
     * product block in this theme renders as an <li class="product"> with a
     * permalink, a title, an <img>, and category links — extracted via
     * targeted regex since there is no JSON endpoint to hit instead.
     *
     * @return array<int,array<string,mixed>>
     */
    private function extractListing(string $html): array
    {
        $out = [];

        // Split on product permalinks; each /product/{slug}/ anchor starts a
        // new card in this theme's markup.
        if (!preg_match_all('#<li[^>]+class="[^"]*\bproduct\b[^"]*"[^>]*>(.*?)</li>#is', $html, $blocks)) {
            return $out;
        }

        foreach ($blocks[1] as $block) {
            if (!preg_match('#href="https://nikkoindustriesmembership\.com/product/([a-z0-9\-]+)/?"#i', $block, $slugM)) {
                continue;
            }
            $slug = strtolower($slugM[1]);

            $name = '';
            if (preg_match('#<h2[^>]*class="[^"]*woocommerce-loop-product__title[^"]*"[^>]*>(.*?)</h2>#is', $block, $nm)) {
                $name = trim(html_entity_decode(strip_tags($nm[1]), ENT_QUOTES));
            } elseif (preg_match('#<img[^>]+alt="([^"]+)"#i', $block, $nm)) {
                $name = trim(html_entity_decode($nm[1], ENT_QUOTES));
            }
            if ($name === '') {
                $name = $this->titleFromSlug($slug);
            }

            $thumb = '';
            if (preg_match('#<img[^>]+(?:data-src|src)="([^"]+)"#i', $block, $tm)) {
                $thumb = $this->absoluteUrl($tm[1]);
            }

            $cats = [];
            if (preg_match_all('#product-category/([a-z0-9\-]+)/?"[^>]*>\s*([^<]+?)\s*<#i', $block, $cm)) {
                foreach ($cm[2] as $label) {
                    $label = trim(html_entity_decode($label, ENT_QUOTES));
                    if ($label !== '') $cats[] = $label;
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

        $downloadUrl = 'https://delivery.shopifyapps.com/-/' . $m[1] . '/' . $m[2] . '/download';
        $signedUrl = $this->resolveSignedUrl($downloadUrl);
        if ($signedUrl === '') {
            return [];
        }

        return [$signedUrl];
    }

    /** Follow the /download redirect (no cookie needed here) to the signed storage URL. */
    private function resolveSignedUrl(string $downloadUrl): string
    {
        $ch = curl_init($downloadUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER         => true,
            CURLOPT_NOBODY         => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_USERAGENT      => self::UA,
        ]);
        $resp = curl_exec($ch);
        $st   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
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
        if (!preg_match('/^Location:\s*(.+)$/mi', (string) $resp, $lm)) {
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
