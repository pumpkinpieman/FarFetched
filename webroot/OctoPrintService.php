<?php
declare(strict_types=1);

/**
 * OctoPrintService — thin wrapper over the OctoPrint REST API for a single
 * printer's OctoPrint instance.
 *
 * Auth: OctoPrint uses an API key passed in the `X-Api-Key` header. Each
 * FarFetched printer stores its own base URL + API key, so one service instance
 * is constructed per printer.
 *
 * Endpoints used:
 *   GET  /api/version           — connection test / identify
 *   GET  /api/connection        — printer connection state
 *   GET  /api/printer           — temps + flags (printing/operational/…)
 *   GET  /api/job               — current job + progress
 *   POST /api/files/local       — upload a file (multipart), optional auto-print
 *   POST /api/job               — start/cancel/pause/resume the active job
 *   POST /api/printer/command   — raw gcode (not exposed here)
 *
 * All methods return structured arrays; network/HTTP errors throw RuntimeException
 * so callers (octoprint_action.php) can translate to a clean JSON error.
 */
final class OctoPrintService
{
    private string $base;       // normalised base URL, no trailing slash
    private string $apiKey;
    private int $timeout;

    public function __construct(string $baseUrl, string $apiKey, int $timeout = 20)
    {
        $base = trim($baseUrl);
        // Accept "host:port" without scheme; default to http.
        if ($base !== '' && !preg_match('#^https?://#i', $base)) {
            $base = 'http://' . $base;
        }
        $this->base    = rtrim($base, '/');
        $this->apiKey  = trim($apiKey);
        $this->timeout = max(5, $timeout);
    }

    public function isConfigured(): bool
    {
        return $this->base !== '' && $this->apiKey !== '';
    }

    /* ----------------------------------------------------------------- *
     * Low-level request helper
     * ----------------------------------------------------------------- */

    /**
     * @param string $method GET|POST
     * @param string $path   e.g. /api/version
     * @param array|null $json  JSON body (for POST); null = no body
     * @return array{status:int, body:mixed} decoded JSON body (or raw string)
     */
    private function request(string $method, string $path, ?array $json = null): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('OctoPrint not configured (missing URL or API key).');
        }

        $url = $this->base . $path;
        $ch  = curl_init($url);

        $headers = [
            'X-Api-Key: ' . $this->apiKey,
            'Accept: application/json',
        ];

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_CUSTOMREQUEST  => $method,
            // OctoPrint is often self-hosted with a self-signed cert; allow it.
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ];

        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($json, JSON_UNESCAPED_SLASHES);
        }

        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);

        $raw    = curl_exec($ch);
        $errno  = curl_errno($ch);
        $err    = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new RuntimeException('Connection failed: ' . $err);
        }

        $body = $raw;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $body = $decoded;
            }
        }

        if ($status === 401 || $status === 403) {
            throw new RuntimeException('OctoPrint rejected the API key (HTTP ' . $status . ').');
        }
        if ($status >= 400) {
            $msg = is_array($body) && isset($body['error']) ? $body['error'] : ('HTTP ' . $status);
            throw new RuntimeException('OctoPrint error: ' . $msg);
        }

        return ['status' => $status, 'body' => $body];
    }

    /* ----------------------------------------------------------------- *
     * Public API
     * ----------------------------------------------------------------- */

    /** Connection test — returns version info or throws. */
    public function testConnection(): array
    {
        $r = $this->request('GET', '/api/version');
        $b = is_array($r['body']) ? $r['body'] : [];
        return [
            'ok'      => true,
            'version' => $b['server'] ?? ($b['text'] ?? 'unknown'),
            'api'     => $b['api'] ?? '',
        ];
    }

    /** Combined status: printer flags, temps, and current job progress. */
    public function status(): array
    {
        $out = ['ok' => true, 'online' => false];

        // Printer state (may 409 if printer not connected to OctoPrint — treat
        // as "reachable but printer offline" rather than an error).
        try {
            $p = $this->request('GET', '/api/printer');
            $pb = is_array($p['body']) ? $p['body'] : [];
            $out['online'] = true;
            $out['state']  = $pb['state']['text'] ?? 'Unknown';
            $flags = $pb['state']['flags'] ?? [];
            $out['flags'] = [
                'operational' => (bool) ($flags['operational'] ?? false),
                'printing'    => (bool) ($flags['printing'] ?? false),
                'paused'      => (bool) ($flags['paused'] ?? false),
                'error'       => (bool) ($flags['error'] ?? false),
            ];
            $temps = $pb['temperature'] ?? [];
            $out['temps'] = [
                'tool0' => [
                    'actual' => $temps['tool0']['actual'] ?? null,
                    'target' => $temps['tool0']['target'] ?? null,
                ],
                'bed' => [
                    'actual' => $temps['bed']['actual'] ?? null,
                    'target' => $temps['bed']['target'] ?? null,
                ],
            ];
        } catch (RuntimeException $e) {
            // Printer subsystem not ready; server may still be reachable.
            $out['state'] = 'Offline';
            $out['flags'] = ['operational' => false, 'printing' => false, 'paused' => false, 'error' => false];
        }

        // Current job + progress.
        try {
            $j  = $this->request('GET', '/api/job');
            $jb = is_array($j['body']) ? $j['body'] : [];
            $out['job'] = [
                'file'      => $jb['job']['file']['name'] ?? null,
                'completion'=> isset($jb['progress']['completion']) ? round((float) $jb['progress']['completion'], 1) : null,
                'printTime' => $jb['progress']['printTime'] ?? null,
                'printTimeLeft' => $jb['progress']['printTimeLeft'] ?? null,
            ];
        } catch (RuntimeException $e) {
            $out['job'] = null;
        }

        return $out;
    }

    /**
     * Upload a local file to OctoPrint (location: local).
     * @param string $filePath absolute path to the file on disk
     * @param bool   $autoPrint start printing immediately after upload (gcode only)
     */
    public function uploadFile(string $filePath, bool $autoPrint = false): array
    {
        if (!is_file($filePath)) {
            throw new RuntimeException('File not found: ' . $filePath);
        }
        if (!$this->isConfigured()) {
            throw new RuntimeException('OctoPrint not configured.');
        }

        $url = $this->base . '/api/files/local';
        $ch  = curl_init($url);

        $post = [
            'file'   => new CURLFile($filePath, mime_content_type($filePath) ?: 'application/octet-stream', basename($filePath)),
            'select' => $autoPrint ? 'true' : 'false',
            'print'  => $autoPrint ? 'true' : 'false',
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 600, // large files
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $post,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER     => ['X-Api-Key: ' . $this->apiKey, 'Accept: application/json'],
        ]);

        $raw    = curl_exec($ch);
        $errno  = curl_errno($ch);
        $err    = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new RuntimeException('Upload failed: ' . $err);
        }
        if ($status === 401 || $status === 403) {
            throw new RuntimeException('OctoPrint rejected the API key during upload.');
        }
        if ($status >= 400) {
            throw new RuntimeException('Upload rejected (HTTP ' . $status . ').');
        }

        $body = json_decode((string) $raw, true) ?: [];
        return [
            'ok'   => true,
            'name' => $body['files']['local']['name'] ?? basename($filePath),
            'autoPrint' => $autoPrint,
        ];
    }

    /* ----- Print control (acts on the currently selected job) ----- */

    public function startPrint(): array  { $this->request('POST', '/api/job', ['command' => 'start']);  return ['ok' => true, 'action' => 'start']; }
    public function cancelPrint(): array { $this->request('POST', '/api/job', ['command' => 'cancel']); return ['ok' => true, 'action' => 'cancel']; }
    public function pausePrint(): array  { $this->request('POST', '/api/job', ['command' => 'pause', 'action' => 'pause']);  return ['ok' => true, 'action' => 'pause']; }
    public function resumePrint(): array { $this->request('POST', '/api/job', ['command' => 'pause', 'action' => 'resume']); return ['ok' => true, 'action' => 'resume']; }

    /**
     * Select a previously-uploaded file and optionally start it.
     * @param string $name file name as stored in OctoPrint (location local)
     */
    public function selectAndPrint(string $name, bool $print = true): array
    {
        $this->request('POST', '/api/files/local/' . rawurlencode($name), [
            'command' => 'select',
            'print'   => $print,
        ]);
        return ['ok' => true, 'selected' => $name, 'printing' => $print];
    }
}
