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

        if (self::$rendered || view_as::active() || !isloggedin() || isguestuser() || empty($USER->id)) {
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

        $quizreview = $modname === 'quiz' && $PAGE->url->get_path() === '/mod/quiz/review.php';
        // Quiz review continues only after Moodle confirms a passing result; no grade is forced.
        if (!$quizreview && !in_array($modname, ['page', 'resource', 'book', 'folder'], true)) {
            return '';
        }

        // Moodle core: completion=2 automatic; completionview=1 view required.
        if (!$quizreview && ((int)$cm->completion !== 2 || empty($cm->completionview))) {
            return '';
        }

        self::$rendered = true;

        $url = new \moodle_url('/local/ustar/continue.php');
        $form = \html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false)])
            . \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'cmid', 'value' => $cmid])
            . \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()])
            . \html_writer::tag('button', $quizreview ? 'Проверить результат и продолжить →' : 'Изучено, продолжить →',
                ['type' => 'submit', 'class' => 'btn btn-primary ustar-route-continue-button'])
            . \html_writer::end_tag('form');
        return \html_writer::div($form, 'ustar-route-continue-wrap', [
            'data-ustar-route-continue' => 'post-v1',
            'data-cmid' => $cmid,
            'style' => 'display:flex;justify-content:flex-end;margin:24px 0;'
        ]) . \html_writer::tag('script', '', ['src' => (new \moodle_url('/local/ustar/route_continue.js',
            ['v' => '20260911']))->out(false)]);
    }

    /** One authoritative route destination, also when a point contains several materials. */
    public static function next_url(int $userid, string $avoidpath = '', int $avoidcmid = 0): \moodle_url {
        $fallback = new \moodle_url('/local/ustar/route.php');
        view_as::assert_writable();
        $resolved = structure::resolve_user($userid);
        $positionid = (string)($resolved['position']['id'] ?? '');
        if ($positionid === '' || !empty(adaptation_service::route_card($userid)['blocked'])) {
            return $fallback;
        }
        return self::destination(route_model::for_user($positionid, $userid), $avoidpath, $avoidcmid);
    }

    /** Validate a native POST against the same published route used for navigation. */
    public static function assert_native_reachable(int $userid, string $factkey): void {
        view_as::assert_writable();
        $resolved = structure::resolve_user($userid);
        $positionid = (string)($resolved['position']['id'] ?? '');
        if ($positionid === '' || !empty(adaptation_service::route_card($userid)['blocked'])) {
            throw new \moodle_exception('Сначала завершите предыдущие шаги маршрута.');
        }
        $route = route_model::read_only_snapshot($positionid, $userid);
        foreach ($route['points'] ?? [] as $point) {
            if (!empty($point['locked'])) { continue; }
            $version = route_model::current_published_version((int)$point['id']);
            if (!$version) { continue; }
            foreach (route_model::requirements_for_version($version) as $requirement) {
                if (($requirement['type'] ?? '') === 'native' && ($requirement['sourcekey'] ?? '') === $factkey) {
                    return;
                }
            }
        }
        throw new \moodle_exception('Этот шаг ещё недоступен в вашем маршруте.');
    }

    public static function destination(array $route, string $avoidpath = '', int $avoidcmid = 0): \moodle_url {
        $fallback = new \moodle_url('/local/ustar/route.php');
        $point = $route['currentpoint'] ?? null;
        if (!$point || empty($point['canlaunch']) || empty($point['launchurl'])) { return $fallback; }
        $url = new \moodle_url((string)$point['launchurl']);
        if ($avoidpath !== '' && $url->get_path() === $avoidpath) {
            return new \moodle_url('/local/ustar/route.php', ['continueerror' => 1]);
        }
        $cmid = (int)($url->get_param('cmid') ?? $url->get_param('id') ?? 0);
        if ($avoidcmid > 0 && $cmid === $avoidcmid
                && preg_match('~/(mod/[^/]+/view|local/ustar/(activity|scorm)_launch)\.php$~', $url->get_path())) {
            return new \moodle_url('/local/ustar/route.php', ['continueerror' => 1]);
        }
        return $url;
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

