<?php
declare(strict_types=1);

/**
 * filament_catalog.php — static reference data for the filament inventory.
 *
 * Not user data (that lives in filament_type / filament_spool). This just powers
 * dropdowns, autocomplete, and sensible defaults when adding a spool. All of it
 * is overridable — "custom-allowed" means the user can type anything.
 */

/**
 * Material presets: default density (g/cm³) + typical temps. Density is the one
 * that matters for length↔grams math later; temps are hints only.
 */
function filament_materials(): array
{
    return [
        'PLA'   => ['density' => 1.24, 'nozzle' => 210, 'bed' => 60],
        'PLA+'  => ['density' => 1.24, 'nozzle' => 215, 'bed' => 60],
        'PETG'  => ['density' => 1.27, 'nozzle' => 240, 'bed' => 80],
        'ABS'   => ['density' => 1.04, 'nozzle' => 245, 'bed' => 100],
        'ASA'   => ['density' => 1.07, 'nozzle' => 250, 'bed' => 100],
        'TPU'   => ['density' => 1.21, 'nozzle' => 225, 'bed' => 45],
        'Nylon' => ['density' => 1.14, 'nozzle' => 260, 'bed' => 80],
        'PC'    => ['density' => 1.20, 'nozzle' => 270, 'bed' => 110],
        'HIPS'  => ['density' => 1.04, 'nozzle' => 240, 'bed' => 100],
        'PVA'   => ['density' => 1.23, 'nozzle' => 200, 'bed' => 60],
        'PLA-CF'  => ['density' => 1.29, 'nozzle' => 220, 'bed' => 60],
        'PETG-CF' => ['density' => 1.30, 'nozzle' => 250, 'bed' => 80],
        'Other' => ['density' => 1.24, 'nozzle' => 0,   'bed' => 0],
    ];
}

/** Common brands for autocomplete (free text still allowed). */
function filament_brands(): array
{
    return [
        'Prusament', 'Polymaker', 'Hatchbox', 'eSUN', 'Overture', 'SUNLU',
        'Bambu Lab', ' Inland', 'Atomic', 'ColorFabb', 'Fillamentum',
        'MatterHackers', 'Proto-pasta', 'Creality', 'Elegoo', 'Anycubic',
        'Prusa', '3DXTech', 'FormFutura', 'Numakers', 'Generic',
    ];
}

/** A small palette of common named colors -> hex, for the color picker. */
function filament_colors(): array
{
    return [
        'Black'   => '#1a1a1a', 'White'   => '#f5f5f5', 'Gray'    => '#8a8a8a',
        'Silver'  => '#c0c0c0', 'Red'     => '#c0392b', 'Orange'  => '#e67e22',
        'Yellow'  => '#f1c40f', 'Green'   => '#27ae60', 'Blue'    => '#2980b9',
        'Navy'    => '#1f3a5f', 'Purple'  => '#8e44ad', 'Pink'    => '#e84393',
        'Brown'   => '#795548', 'Beige'   => '#d8c3a5', 'Gold'    => '#d4af37',
        'Natural' => '#eae0c8', 'Clear'   => '#dfe6e9',
    ];
}

/** Valid spool statuses. */
function filament_statuses(): array
{
    return ['active', 'sealed', 'low', 'empty', 'archived'];
}
