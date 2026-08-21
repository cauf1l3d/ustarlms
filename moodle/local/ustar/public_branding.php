<?php
// Public, read-only endpoint for the pre-authentication USTAR login screen.
define('NO_MOODLE_COOKIES', true);
require_once(__DIR__ . '/../../../config.php');

use local_ustar\structure;

$branding = structure::get(structure::NAME_BRANDING);
$allowed = [
    'brandName', 'tagline', 'primary', 'accent', 'accentSoft', 'bg', 'surface',
    'text', 'muted', 'success', 'warning', 'radius', 'logoUrl',
    'sidebarHeroUrl', 'sidebarHeroFit', 'sidebarHeroPosition', 'sidebarHeroHeight',
    'sidebarHeroOverlay', 'loginHeroUrl', 'loginHeroFit', 'loginHeroPosition',
    'loginHeroOverlay', 'loginEyebrow', 'loginTitle', 'loginSubtitle',
];
$out = [];
foreach ($allowed as $key) {
    if (array_key_exists($key, $branding)) {
        $out[$key] = $branding[$key];
    }
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60, stale-while-revalidate=300');
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
