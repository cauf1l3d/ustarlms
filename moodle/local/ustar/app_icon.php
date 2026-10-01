<?php
define('NO_MOODLE_COOKIES', true);
require_once(__DIR__ . '/../../config.php');
$size = optional_param('size', 192, PARAM_INT);
if (!in_array($size, [180, 192, 512], true)) { http_response_code(400); exit; }
$source = $CFG->dirroot . '/theme/ustar/pix/brand/ustar-app-icon.png';
if (!is_readable($source) || !function_exists('imagecreatefrompng')) { http_response_code(503); exit; }
$original = imagecreatefrompng($source);
$icon = imagecreatetruecolor($size, $size);
imagecopyresampled($icon, $original, 0, 0, 0, 0, $size, $size, imagesx($original), imagesy($original));
header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');
imagepng($icon);
imagedestroy($original);
imagedestroy($icon);
