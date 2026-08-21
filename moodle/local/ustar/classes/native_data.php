<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * Thin native-page adapter around the already-audited USTAR external services.
 *
 * The old Next.js frontend and Moodle-native pages therefore keep using the
 * same business/security logic instead of duplicating it.
 */
final class native_data {
    public static function decode(string $classname, mixed ...$args): array {
        if (!class_exists($classname) || !is_callable([$classname, 'execute'])) {
            throw new \coding_exception('USTAR data provider is not callable: ' . $classname);
        }

        $result = $classname::execute(...$args);
        $json = $result['json'] ?? '{}';
        $data = json_decode((string)$json, true);

        if (!is_array($data)) {
            throw new \coding_exception('USTAR data provider returned invalid JSON: ' . $classname);
        }

        return $data;
    }

    public static function dashboard(): array {
        return self::decode(\local_ustar\external\get_dashboard::class);
    }

    public static function games(): array {
        return self::decode(\local_ustar\external\get_games::class);
    }

    public static function game_question(int $gameid): array {
        return self::decode(\local_ustar\external\get_game_question::class, $gameid);
    }

    public static function submit_game_answer(int $questionid, int $option): array {
        return self::decode(\local_ustar\external\submit_game_answer::class, $questionid, $option);
    }

    public static function checklists(): array {
        return self::decode(\local_ustar\external\get_checklists::class);
    }

    public static function submit_checklist(string $id, array $answers, string $comment = ''): array {
        return self::decode(
            \local_ustar\external\submit_checklist::class,
            $id,
            json_encode($answers, JSON_UNESCAPED_UNICODE),
            $comment
        );
    }

    public static function team(): array {
        return self::decode(\local_ustar\external\get_team::class);
    }

    public static function executive(): array {
        return self::decode(\local_ustar\external\executive_get_dashboard::class);
    }

    public static function hr_workspace(): array {
        return self::decode(\local_ustar\external\hr_get_workspace::class);
    }

    public static function admin_games(): array {
        return self::decode(\local_ustar\external\admin_get_games::class);
    }

    public static function save_game(array $game): array {
        return self::decode(
            \local_ustar\external\admin_save_game::class,
            json_encode($game, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    public static function hr_checklists(): array {
        return self::decode(\local_ustar\external\hr_get_checklists::class);
    }

    public static function save_checklists(array $definitions): array {
        return self::decode(
            \local_ustar\external\hr_save_checklists::class,
            json_encode($definitions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }
}
