<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** Public installation metadata; no authenticated response is stored by the worker. */
final class mobile_app {
    public const VERSION = '2026100102';

    public static function base_path(): string {
        global $CFG;
        return rtrim((string)parse_url($CFG->wwwroot, PHP_URL_PATH), '/') . '/';
    }

    public static function manifest(): array {
        global $CFG;
        $base = rtrim($CFG->wwwroot, '/');
        return [
            'id' => self::base_path(), 'name' => 'USTAR Академия', 'short_name' => 'USTAR',
            'lang' => 'ru', 'start_url' => $base . '/local/ustar/home.php',
            'scope' => self::base_path(), 'display' => 'standalone',
            'background_color' => '#f4f2ec', 'theme_color' => '#2b2b2b',
            'icons' => array_map(static fn($size) => [
                'src' => $base . '/local/ustar/app_icon.php?size=' . $size . '&v=' . self::VERSION,
                'sizes' => $size . 'x' . $size, 'type' => 'image/png', 'purpose' => 'any',
            ], [192, 512]),
        ];
    }

    public static function head_html(): string {
        $manifest = new \moodle_url('/local/ustar/app_manifest.php', ['v' => self::VERSION]);
        $icon = new \moodle_url('/local/ustar/app_icon.php', ['size' => 180, 'v' => self::VERSION]);
        $script = new \moodle_url('/local/ustar/app.js', ['v' => self::VERSION]);
        return \html_writer::tag('script', '', ['src' => $script->out(false), 'defer' => 'defer'])
            . \html_writer::empty_tag('link', ['rel' => 'manifest', 'href' => $manifest->out(false)])
            . \html_writer::empty_tag('link', ['rel' => 'apple-touch-icon', 'href' => $icon->out(false)])
            . \html_writer::empty_tag('meta', ['name' => 'theme-color', 'content' => '#2b2b2b'])
            . \html_writer::empty_tag('meta', ['name' => 'apple-mobile-web-app-capable', 'content' => 'yes'])
            . \html_writer::empty_tag('meta', ['name' => 'apple-mobile-web-app-title', 'content' => 'USTAR']);
    }
}
