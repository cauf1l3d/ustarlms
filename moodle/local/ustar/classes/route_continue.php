<?php

namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * Route continuation UI and guards.
 *
 * @package local_ustar
 */
final class route_continue {
    /** Prevent duplicate output when both modern and legacy footer paths fire. */
    private static bool $rendered = false;

    /** @var array<int,bool>|null */
    private static ?array $publishedroutecmids = null;

    /**
     * Return all Moodle CM ids referenced by published USTAR route versions.
     *
     * @return array<int,bool>
     */
    private static function published_route_cmids(): array {
        global $DB;

        if (self::$publishedroutecmids !== null) {
            return self::$publishedroutecmids;
        }

        self::$publishedroutecmids = [];

        $versions = $DB->get_records(
            'local_ustar_route_versions',
            ['status' => 'published'],
            '',
            'id,requirementsjson'
        );

        foreach ($versions as $version) {
            $requirements = json_decode((string)$version->requirementsjson, true);

            if (!is_array($requirements)) {
                continue;
            }

            foreach ($requirements as $requirement) {
                if (
                    (string)($requirement['type'] ?? '') === 'cm'
                    && !empty($requirement['sourceid'])
                ) {
                    self::$publishedroutecmids[(int)$requirement['sourceid']] = true;
                }
            }
        }

        return self::$publishedroutecmids;
    }

    /**
     * Render Continue on passive route activities.
     */
    public static function footer_button(): string {
        global $DB, $PAGE, $USER;

        if (self::$rendered || !isloggedin() || isguestuser() || empty($USER->id)) {
            return '';
        }

        $cmid = 0;

        if (!empty($PAGE->cm) && !empty($PAGE->cm->id)) {
            $cmid = (int)$PAGE->cm->id;
        } else if (
            !empty($PAGE->context)
            && (int)$PAGE->context->contextlevel === CONTEXT_MODULE
        ) {
            $cmid = (int)$PAGE->context->instanceid;
        }

        if ($cmid <= 0) {
            return '';
        }

        if (empty(self::published_route_cmids()[$cmid])) {
            return '';
        }

        $cm = $DB->get_record(
            'course_modules',
            ['id' => $cmid, 'deletioninprogress' => 0],
            'id,module,completion,completionview'
        );

        if (!$cm) {
            return '';
        }

        $modname = (string)$DB->get_field(
            'modules',
            'name',
            ['id' => (int)$cm->module]
        );

        // Only passive/view-only resources get this UI.
        if (!in_array($modname, ['page', 'resource', 'book', 'folder'], true)) {
            return '';
        }

        // Moodle core: completion=2 automatic; completionview=1 view required.
        if ((int)$cm->completion !== 2 || empty($cm->completionview)) {
            return '';
        }

        self::$rendered = true;

        $url = new \moodle_url('/local/ustar/continue.php', [
            'cmid' => $cmid,
            'sesskey' => sesskey(),
        ]);

        $button = \html_writer::link(
            $url,
            'Завершить просмотр и продолжить →',
            [
                'class' => 'btn ustar-route-continue-button',
                'style' =>
                    'display:inline-flex;' .
                    'align-items:center;' .
                    'justify-content:center;' .
                    'min-height:54px;' .
                    'padding:0 32px;' .
                    'border:1px solid #e3aa00;' .
                    'border-radius:12px;' .
                    'background:#f5b800;' .
                    'color:#172333;' .
                    'font-size:16px;' .
                    'font-weight:700;' .
                    'line-height:1.2;' .
                    'text-decoration:none;' .
                    'box-shadow:0 4px 12px rgba(0,0,0,.08);'
            ]
        );

        return \html_writer::div(
            $button,
            'ustar-route-continue-wrap',
            [
                'data-ustar-route-continue' => 'hook-v2',
                'style' =>
                    'max-width:1000px;' .
                    'margin:28px auto 40px;' .
                    'padding:24px 16px 4px;' .
                    'border-top:1px solid #e5e7eb;' .
                    'text-align:center;'
            ]
        );
    }

    /**
     * Ensure the clicked CM belongs to a non-future route point.
     */
    public static function assert_reachable_cm(array $route, int $cmid): void {
        $points = array_values($route["points"] ?? []);
        $currentid = (int)($route["currentpoint"]["id"] ?? 0);
        $currentindex = null;

        if ($currentid > 0) {
            foreach ($points as $index => $point) {
                if ((int)($point["id"] ?? 0) === $currentid) {
                    $currentindex = (int)$index;
                    break;
                }
            }
        }

        foreach ($points as $index => $point) {
            $version = route_model::current_published_version((int)$point["id"]);

            if (!$version) {
                continue;
            }

            foreach (route_model::requirements_for_version($version) as $requirement) {
                if (
                    (string)($requirement["type"] ?? "") === "cm"
                    && (int)($requirement["sourceid"] ?? 0) === $cmid
                ) {
                    // The page may have become completed before Continue is clicked.
                    // Current and previous route points are valid; future points are not.
                    if (
                        empty($point["locked"])
                        || (
                            $currentindex !== null
                            && (int)$index <= $currentindex
                        )
                    ) {
                        return;
                    }

                    break 2;
                }
            }
        }

        throw new \moodle_exception(
            "Эта активность не является доступной точкой вашего маршрута"
        );
    }


}
