<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * Small shared visual helper. SVG is generated server-side and contains no user input.
 */
final class ui {
    private const ICONS = [
        'profile' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c.8-4.1 3.5-6 8-6s7.2 1.9 8 6"/>',
        'message' => '<path d="M4 5h16v11H8l-4 4z"/><path d="M8 9h8M8 12h5"/>',
        'bell' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/>',
        'game' => '<path d="M8 8h8a5 5 0 0 1 4.7 6.7l-1 2.8a2.5 2.5 0 0 1-4.4.6L14 16h-4l-1.3 2.1a2.5 2.5 0 0 1-4.4-.6l-1-2.8A5 5 0 0 1 8 8z"/><path d="M8 12h4M10 10v4"/><circle cx="16.5" cy="11.5" r=".7"/><circle cx="18.5" cy="13.5" r=".7"/>',
        'trophy' => '<path d="M8 4h8v4a4 4 0 0 1-8 0z"/><path d="M8 6H4v1a4 4 0 0 0 4 4M16 6h4v1a4 4 0 0 1-4 4M12 12v5M8 21h8M9 17h6"/>',
        'check' => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="m8 9 2 2 4-4M8 15h8"/>',
        'team' => '<circle cx="9" cy="8" r="3"/><circle cx="17" cy="10" r="2.5"/><path d="M3 20c.6-4 2.7-6 6-6 3.2 0 5.4 2 6 6M14 15c3.6-.5 6 1.2 7 4.5"/>',
        'executive' => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/><path d="m4 7 6-4 6 6 5-5"/>',
        'palette' => '<path d="M12 3a9 9 0 1 0 0 18h1.5a2 2 0 0 0 0-4H12a2 2 0 0 1 0-4h4a5 5 0 0 0 0-10z"/><circle cx="7.5" cy="9" r=".8"/><circle cx="10" cy="6.5" r=".8"/><circle cx="14" cy="6" r=".8"/>',
        'route' => '<circle cx="6" cy="5" r="2"/><circle cx="18" cy="19" r="2"/><path d="M6 7v5a3 3 0 0 0 3 3h6a3 3 0 0 1 3 3M12 5h6M15 2l3 3-3 3"/>',
        'knowledge' => '<path d="M3 5h7a2 2 0 0 1 2 2v13a2 2 0 0 0-2-2H3z"/><path d="M21 5h-7a2 2 0 0 0-2 2v13a2 2 0 0 1 2-2h7z"/>',
        'spark' => '<path d="m12 2 1.7 5.3L19 9l-5.3 1.7L12 16l-1.7-5.3L5 9l5.3-1.7z"/><path d="m19 15 .8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z"/>',
        'workspace' => '<rect x="3" y="4" width="7" height="6" rx="1"/><rect x="14" y="4" width="7" height="6" rx="1"/><rect x="8.5" y="15" width="7" height="6" rx="1"/><path d="M6.5 10v2h11v-2M12 12v3"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.8 2.8-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6V21h-4v-.1a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1L4.2 17l.1-.1a1.7 1.7 0 0 0 .3-1.9A1.7 1.7 0 0 0 3 14v-4a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9L4.2 7 7 4.2l.1.1a1.7 1.7 0 0 0 1.9.3A1.7 1.7 0 0 0 10 3h4a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1L19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9A1.7 1.7 0 0 0 21 10v4a1.7 1.7 0 0 0-1.6 1z"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        'book' => '<path d="M4 4h14a2 2 0 0 1 2 2v14H6a2 2 0 0 1-2-2z"/><path d="M4 17a3 3 0 0 1 3-3h13"/>',
        'star' => '<path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-2.9-5.6 2.9 1.1-6.2L3 9.6l6.2-.9z"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'arrow' => '<path d="M5 12h14M14 7l5 5-5 5"/>',
    ];

    public static function icon(string $name, string $class = 'u-icon'): string {
        $body = self::ICONS[$name] ?? self::ICONS['spark'];
        return '<svg class="' . s($class) . '" viewBox="0 0 24 24" aria-hidden="true">' . $body . '</svg>';
    }

    public static function initials(string $firstname, string $lastname): string {
        $value = '';
        if (trim($firstname) !== '') {
            $value .= \core_text::strtoupper(\core_text::substr(trim($firstname), 0, 1));
        }
        if (trim($lastname) !== '') {
            $value .= \core_text::strtoupper(\core_text::substr(trim($lastname), 0, 1));
        }
        return $value !== '' ? $value : 'U';
    }
}
