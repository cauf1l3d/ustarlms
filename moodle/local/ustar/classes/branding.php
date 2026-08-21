<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * Site-wide USTAR visual presets + safe custom palette.
 */
final class branding {
    public static function presets(): array {
        return [
            'gold_light' => [
                'id' => 'gold_light',
                'name' => 'USTAR Gold',
                'description' => 'Тёплый фирменный светлый набор.',
                'accent' => '#FBC502',
                'accentStrong' => '#E0AD00',
                'canvas' => '#F6F5F1',
                'surface' => '#FFFFFF',
                'surfaceMuted' => '#EFEEE9',
                'text' => '#282727',
                'textSecondary' => '#5B5855',
                'darkCanvas' => '#222120',
                'darkSurface' => '#2E2C2A',
                'darkText' => '#F6F5F1',
                'darkTextSecondary' => '#C9C6C1',
            ],
            'graphite_gold' => [
                'id' => 'graphite_gold',
                'name' => 'Graphite Gold',
                'description' => 'Строже и контрастнее для руководства.',
                'accent' => '#F3C515',
                'accentStrong' => '#C99E00',
                'canvas' => '#ECEBE8',
                'surface' => '#F9F9F7',
                'surfaceMuted' => '#E2E1DD',
                'text' => '#20201F',
                'textSecondary' => '#53514E',
                'darkCanvas' => '#171716',
                'darkSurface' => '#242321',
                'darkText' => '#F7F6F2',
                'darkTextSecondary' => '#C9C7C1',
            ],
            'deep_navy' => [
                'id' => 'deep_navy',
                'name' => 'Deep Navy',
                'description' => 'Деловой сине-графитовый набор.',
                'accent' => '#E9C229',
                'accentStrong' => '#D0A90E',
                'canvas' => '#F1F4F7',
                'surface' => '#FFFFFF',
                'surfaceMuted' => '#E7ECF1',
                'text' => '#172433',
                'textSecondary' => '#506073',
                'darkCanvas' => '#101A25',
                'darkSurface' => '#172432',
                'darkText' => '#F3F6FA',
                'darkTextSecondary' => '#BDC8D4',
            ],
            'warm_stone' => [
                'id' => 'warm_stone',
                'name' => 'Warm Stone',
                'description' => 'Спокойная тёплая нейтральная палитра.',
                'accent' => '#E7B62B',
                'accentStrong' => '#C49316',
                'canvas' => '#F4F0E9',
                'surface' => '#FFFDF9',
                'surfaceMuted' => '#ECE5DA',
                'text' => '#312C27',
                'textSecondary' => '#6E645B',
                'darkCanvas' => '#26221F',
                'darkSurface' => '#312C28',
                'darkText' => '#FBF6EE',
                'darkTextSecondary' => '#D5CBC0',
            ],
            'forest' => [
                'id' => 'forest',
                'name' => 'Forest',
                'description' => 'Глубокий зелёный с фирменным золотом.',
                'accent' => '#F0C83D',
                'accentStrong' => '#D3AA1B',
                'canvas' => '#F0F4F0',
                'surface' => '#FCFEFC',
                'surfaceMuted' => '#E3EAE3',
                'text' => '#1D2C23',
                'textSecondary' => '#53645A',
                'darkCanvas' => '#132019',
                'darkSurface' => '#1D2A22',
                'darkText' => '#F1F6F2',
                'darkTextSecondary' => '#C1CEC5',
            ],
            'clean_neutral' => [
                'id' => 'clean_neutral',
                'name' => 'Clean Neutral',
                'description' => 'Минималистичный нейтральный интерфейс.',
                'accent' => '#E8B900',
                'accentStrong' => '#C99C00',
                'canvas' => '#F5F6F7',
                'surface' => '#FFFFFF',
                'surfaceMuted' => '#ECEEF0',
                'text' => '#202326',
                'textSecondary' => '#5D646B',
                'darkCanvas' => '#1C1F22',
                'darkSurface' => '#292D31',
                'darkText' => '#F5F6F7',
                'darkTextSecondary' => '#C7CCD1',
            ],
        ];
    }

    private static function hex(string $value, string $fallback): string {
        $value = strtoupper(trim($value));
        return preg_match('/^#[0-9A-F]{6}$/', $value) ? $value : $fallback;
    }

    public static function current(): array {
        $raw = structure::get(structure::NAME_BRANDING);
        $presets = self::presets();
        $presetid = (string)($raw['themePreset'] ?? 'gold_light');
        $base = $presets[$presetid] ?? $presets['gold_light'];

        $custom = !empty($raw['themeCustom']);
        if ($custom) {
            foreach ([
                'accent', 'accentStrong', 'canvas', 'surface', 'surfaceMuted',
                'text', 'textSecondary', 'darkCanvas', 'darkSurface', 'darkText', 'darkTextSecondary'
            ] as $key) {
                if (isset($raw[$key])) {
                    $base[$key] = self::hex((string)$raw[$key], $base[$key]);
                }
            }
        }

        $base['id'] = $custom ? 'custom' : ($base['id'] ?? $presetid);
        $base['themePreset'] = $presetid;
        $base['themeCustom'] = $custom;
        $base['homeBannerUrl'] = (string)($raw['homeBannerUrl'] ?? '');
        $base['brandName'] = (string)($raw['brandName'] ?? 'USTAR');
        return $base;
    }

    public static function save_preset(string $presetid): array {
        require_capability('local/ustar:admin', \context_system::instance());
        $presets = self::presets();
        if (!isset($presets[$presetid])) {
            throw new \invalid_parameter_exception('Unknown USTAR theme preset');
        }

        $raw = structure::get(structure::NAME_BRANDING);
        $raw['themePreset'] = $presetid;
        $raw['themeCustom'] = false;
        foreach ($presets[$presetid] as $key => $value) {
            if ($key !== 'id' && $key !== 'name' && $key !== 'description') {
                $raw[$key] = $value;
            }
        }
        structure::save(structure::NAME_BRANDING, $raw);
        return self::current();
    }

    private static function channel_to_linear(int $channel): float {
        $value = $channel / 255;
        return $value <= 0.04045
            ? $value / 12.92
            : pow(($value + 0.055) / 1.055, 2.4);
    }

    private static function luminance(string $hex): float {
        $hex = ltrim(self::hex($hex, '#000000'), '#');
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        return 0.2126 * self::channel_to_linear($r)
            + 0.7152 * self::channel_to_linear($g)
            + 0.0722 * self::channel_to_linear($b);
    }

    public static function contrast_ratio(string $foreground, string $background): float {
        $l1 = self::luminance($foreground);
        $l2 = self::luminance($background);
        $lighter = max($l1, $l2);
        $darker = min($l1, $l2);
        return ($lighter + 0.05) / ($darker + 0.05);
    }

    private static function validate_contrast(array $palette): void {
        $checks = [
            ['text', 'canvas', 'основной текст / фон'],
            ['text', 'surface', 'основной текст / карточки'],
            ['textSecondary', 'surface', 'вторичный текст / карточки'],
            ['darkText', 'darkCanvas', 'dark: основной текст / фон'],
            ['darkText', 'darkSurface', 'dark: основной текст / карточки'],
            ['darkTextSecondary', 'darkSurface', 'dark: вторичный текст / карточки'],
        ];
        foreach ($checks as [$foreground, $background, $label]) {
            $ratio = self::contrast_ratio((string)$palette[$foreground], (string)$palette[$background]);
            if ($ratio < 4.5) {
                throw new \invalid_parameter_exception(
                    'Недостаточный контраст «' . $label . '»: ' . number_format($ratio, 2) . ':1. Требуется не менее 4.5:1.'
                );
            }
        }
    }

    public static function save_custom(array $values): array {
        require_capability('local/ustar:admin', \context_system::instance());
        $current = self::current();
        $raw = structure::get(structure::NAME_BRANDING);
        foreach ([
            'accent', 'accentStrong', 'canvas', 'surface', 'surfaceMuted',
            'text', 'textSecondary', 'darkCanvas', 'darkSurface', 'darkText', 'darkTextSecondary'
        ] as $key) {
            $raw[$key] = self::hex((string)($values[$key] ?? ''), (string)$current[$key]);
        }
        self::validate_contrast($raw);
        $raw['themeCustom'] = true;
        structure::save(structure::NAME_BRANDING, $raw);
        return self::current();
    }

    public static function inline_css(): string {
        $c = self::current();
        $vars = [
            '--u-brand' => $c['accent'],
            '--u-brand-strong' => $c['accentStrong'],
            '--u-brand-soft' => 'color-mix(in srgb, ' . $c['accent'] . ' 13%, transparent)',
            '--u-canvas' => $c['canvas'],
            '--u-canvas-flat' => $c['canvas'],
            '--u-surface' => $c['surface'],
            '--u-surface-raised' => $c['surface'],
            '--u-surface-muted' => $c['surfaceMuted'],
            '--u-text-primary' => $c['text'],
            '--u-text-secondary' => $c['textSecondary'],
        ];

        $dark = [
            '--u-canvas' => $c['darkCanvas'],
            '--u-canvas-flat' => $c['darkCanvas'],
            '--u-surface' => $c['darkSurface'],
            '--u-surface-raised' => $c['darkSurface'],
            '--u-surface-muted' => $c['darkSurface'],
            '--u-text-primary' => $c['darkText'],
            '--u-text-secondary' => $c['darkTextSecondary'],
            '--u-text-muted' => $c['darkTextSecondary'],
        ];

        $css = ':root{';
        foreach ($vars as $key => $value) {
            $css .= $key . ':' . $value . ';';
        }
        $css .= '}html[data-ustar-theme="dark"]{';
        foreach ($dark as $key => $value) {
            $css .= $key . ':' . $value . ';';
        }
        $css .= '}';

        return $css;
    }
}
