<?php
declare(strict_types=1);

/**
 * printers_enabled.php — enabled printers + the largest bed envelope across
 * them, for the viewer's fit checker.
 *
 * GET -> {ok, printers:[{name,nickname,x,y,z}], max:{x,y,z}}
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/auth.php';
if (!auth_check()) { http_response_code(401); exit; }
header('Content-Type: application/json');

$rows = db()->query('SELECT name, nickname, brand, bed_x, bed_y, bed_z FROM printers WHERE enabled = 1')
            ->fetchAll(PDO::FETCH_ASSOC);

$printers = [];
$mx = 0; $my = 0; $mz = 0;
foreach ($rows as $r) {
    $printers[] = [
        'name'     => $r['name'],
        'nickname' => $r['nickname'] ?? '',
        'brand'    => $r['brand'] ?? '',
        'x'        => (int) $r['bed_x'],
        'y'        => (int) $r['bed_y'],
        'z'        => (int) $r['bed_z'],
    ];
    $mx = max($mx, (int) $r['bed_x']);
    $my = max($my, (int) $r['bed_y']);
    $mz = max($mz, (int) $r['bed_z']);
}

echo json_encode(['ok' => true, 'printers' => $printers, 'max' => ['x' => $mx, 'y' => $my, 'z' => $mz]]);
