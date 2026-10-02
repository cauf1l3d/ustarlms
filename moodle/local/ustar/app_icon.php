<?php
define('NO_MOODLE_COOKIES', true);
require_once(__DIR__ . '/../../config.php');
$size = optional_param('size', 192, PARAM_INT);
if (!in_array($size, [180, 192, 512], true)) { http_response_code(400); exit; }
// Pre-rendered opaque PNGs preserve the supplied artwork and avoid runtime GD conversion.
$source = $CFG->dirroot . '/theme/ustar/pix/brand/app-icon-20261002-' . $size . '.png';
if (!is_readable($source)) { http_response_code(503); exit; }
header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');
readfile($source);
