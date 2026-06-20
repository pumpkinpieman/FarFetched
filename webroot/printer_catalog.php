<?php
declare(strict_types=1);

/**
 * printer_catalog.php — starter catalog of popular printers with real build
 * volumes (mm). Used to seed "My Printers" so users pick from a list instead of
 * typing bed sizes. Images are added later (a follow-up will download + cache
 * a .png per printer); for now `image` is a placeholder key.
 *
 * Bed dimensions are the usable build volume X×Y×Z in millimetres.
 */

function printer_catalog(): array
{    return [
        // --- Bambu Lab ---
        ['name' => 'Bambu Lab A1',        'brand' => 'Bambu Lab', 'x' => 256, 'y' => 256, 'z' => 256],
        ['name' => 'Bambu Lab A1 mini',   'brand' => 'Bambu Lab', 'x' => 180, 'y' => 180, 'z' => 180],
        ['name' => 'Bambu Lab P1P',       'brand' => 'Bambu Lab', 'x' => 256, 'y' => 256, 'z' => 256],
        ['name' => 'Bambu Lab P1S',       'brand' => 'Bambu Lab', 'x' => 256, 'y' => 256, 'z' => 256],
        ['name' => 'Bambu Lab X1 Carbon', 'brand' => 'Bambu Lab', 'x' => 256, 'y' => 256, 'z' => 256],
        ['name' => 'Bambu Lab X1E',       'brand' => 'Bambu Lab', 'x' => 256, 'y' => 256, 'z' => 256],
        ['name' => 'Bambu Lab H2D',       'brand' => 'Bambu Lab', 'x' => 350, 'y' => 320, 'z' => 325],

        // --- Creality ---
        ['name' => 'Creality Ender-3 V3',  'brand' => 'Creality', 'x' => 220, 'y' => 220, 'z' => 250],
        ['name' => 'Creality K1',          'brand' => 'Creality', 'x' => 220, 'y' => 220, 'z' => 250],
        ['name' => 'Creality K1C',         'brand' => 'Creality', 'x' => 220, 'y' => 220, 'z' => 250],
        ['name' => 'Creality K1 Max',      'brand' => 'Creality', 'x' => 300, 'y' => 300, 'z' => 300],
        ['name' => 'Creality K2 Plus',     'brand' => 'Creality', 'x' => 350, 'y' => 350, 'z' => 350],
        ['name' => 'Creality Ender-5 S1',  'brand' => 'Creality', 'x' => 220, 'y' => 220, 'z' => 280],
        ['name' => 'Creality CR-10 SE',    'brand' => 'Creality', 'x' => 220, 'y' => 220, 'z' => 265],

        // --- Prusa ---
        ['name' => 'Prusa MK4S',           'brand' => 'Prusa',    'x' => 250, 'y' => 210, 'z' => 220],
        ['name' => 'Prusa MINI+',          'brand' => 'Prusa',    'x' => 180, 'y' => 180, 'z' => 180],
        ['name' => 'Prusa XL',             'brand' => 'Prusa',    'x' => 360, 'y' => 360, 'z' => 360],
        ['name' => 'Prusa CORE One',       'brand' => 'Prusa',    'x' => 250, 'y' => 220, 'z' => 270],

        // --- Qidi ---
        ['name' => 'Qidi Q1 Pro',          'brand' => 'Qidi',     'x' => 245, 'y' => 245, 'z' => 245],
        ['name' => 'Qidi Plus4',           'brand' => 'Qidi',     'x' => 305, 'y' => 305, 'z' => 280],
        ['name' => 'Qidi X-Max 3',         'brand' => 'Qidi',     'x' => 325, 'y' => 325, 'z' => 315],

        // --- Anycubic ---
        ['name' => 'Anycubic Kobra 3',     'brand' => 'Anycubic', 'x' => 250, 'y' => 250, 'z' => 260],
        ['name' => 'Anycubic Kobra 2 Pro', 'brand' => 'Anycubic', 'x' => 220, 'y' => 220, 'z' => 250],

        // --- Elegoo ---
        ['name' => 'Elegoo Neptune 4',     'brand' => 'Elegoo',   'x' => 225, 'y' => 225, 'z' => 265],
        ['name' => 'Elegoo Neptune 4 Pro', 'brand' => 'Elegoo',   'x' => 225, 'y' => 225, 'z' => 265],
        ['name' => 'Elegoo Centauri Carbon','brand'=> 'Elegoo',   'x' => 256, 'y' => 256, 'z' => 256],
    ];
}

/**
 * Local image override: if webroot/img/printers/<Printer Name>.png exists, use
 * it; else '' (caller falls back to a brand-tinted icon). Lets the user drop in
 * real photos one at a time with zero code changes.
 */
function printer_image_url(string $name): string
{
    $safe = preg_replace('/[^A-Za-z0-9 .+_-]/', '', $name);
    $file = __DIR__ . '/img/printers/' . $safe . '.png';
    return is_file($file) ? ('img/printers/' . rawurlencode($safe) . '.png') : '';
}

/** Brand accent colour for the fallback icon. */
function printer_brand_color(string $brand): string
{
    $map = [
        'bambu lab' => '#00ae42', 'creality' => '#0a7cff', 'prusa' => '#fa6831',
        'qidi' => '#7b4dff', 'anycubic' => '#00b3a4', 'elegoo' => '#e23b3b',
    ];
    return $map[strtolower($brand)] ?? '#8a7d6b';
}

/** Inline SVG printer silhouette, tinted by brand. */
function printer_icon_svg(string $brand): string
{
    $c = printer_brand_color($brand);
    return '<svg viewBox="0 0 48 48" width="46" height="46" fill="none" aria-hidden="true">'
        . '<rect x="9" y="7" width="30" height="28" rx="3" stroke="' . $c . '" stroke-width="2"/>'
        . '<rect x="14" y="13" width="20" height="13" rx="1.5" fill="' . $c . '" opacity="0.18"/>'
        . '<line x1="14" y1="30" x2="34" y2="30" stroke="' . $c . '" stroke-width="2"/>'
        . '<rect x="20" y="35" width="8" height="5" rx="1" fill="' . $c . '"/>'
        . '<line x1="13" y1="40" x2="35" y2="40" stroke="' . $c . '" stroke-width="2" stroke-linecap="round"/>'
        . '</svg>';
}
