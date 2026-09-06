<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * Remediation evidence for a logical route point.
 *
 * The lifecycle references point/version, never a SCORM id or content id.
 * Requirement-specific mechanics stay here and can be replaced independently.
 */
final class route_point_evidence_provider {

    /** @return array<string,mixed> */
    public static function state(\stdClass $runtime, \stdClass $policy, string $positionid): array {
        global $DB;

        $pointid = (int)$runtime->remediationpointid;
        $versionid = (int)$runtime->remediationversionid;
        $cutoff = (int)$runtime->failurecutoff;

        if ($pointid <= 0 || $versionid <= 0 || $cutoff <= 0) {
            return [
                'configured' => false,
                'satisfied' => false,
                'title' => '',
                'requirements' => [],
                'next' => null,
                'completedat' => 0,
            ];
        }

        $point = $DB->get_record('local_ustar_route_points', ['id' => $pointid, 'active' => 1], '*', IGNORE_MISSING);
        $version = $DB->get_record('local_ustar_route_versions', ['id' => $versionid, 'pointid' => $pointid], '*', IGNORE_MISSING);
        if (!$point || !$version) {
            return [
                'configured' => false,
                'satisfied' => false,
                'title' => '',
                'requirements' => [],
                'next' => null,
                'completedat' => 0,
            ];
        }

        // The pinned remediation point must still belong to the assessment's
        // route family/route. A later new version is allowed; this runtime keeps
        // the exact version it started with.
        $assessmentpoint = $DB->get_record('local_ustar_route_points', ['id' => (int)$policy->pointid], 'id,routeid', MUST_EXIST);
        if ((int)$assessmentpoint->routeid !== (int)$point->routeid) {
            return [
                'configured' => false,
                'satisfied' => false,
                'title' => format_string((string)$version->title),
                'requirements' => [],
                'next' => null,
                'completedat' => 0,
            ];
        }

        if (route_scope::available() && !route_scope::point_applies($pointid, $positionid)) {
            return [
                'configured' => false,
                'satisfied' => false,
                'title' => format_string((string)$version->title),
                'requirements' => [],
                'next' => null,
                'completedat' => 0,
            ];
        }

        $requirements = route_model::requirements_for_version($version);
        $rows = [];
        $requiredcount = 0;
        $freshcount = 0;
        $latest = 0;
        $next = null;

        foreach ($requirements as $requirement) {
            if (empty($requirement['required'])) {
                continue;
            }
            $requiredcount++;
            $evidence = self::requirement_evidence(
                $requirement,
                (int)$runtime->userid,
                $pointid,
                $versionid,
                (int)$runtime->id,
                $positionid
            );
            $evidence['fresh'] = !empty($evidence['configured'])
                && (int)$evidence['completedat'] > $cutoff;
            if (!empty($evidence['fresh'])) {
                $freshcount++;
                $latest = max($latest, (int)$evidence['completedat']);
            } else if ($next === null && !empty($evidence['action'])) {
                $next = $evidence['action'];
            }
            $rows[] = $evidence;
        }

        return [
            'configured' => $requiredcount > 0 && count(array_filter($rows, static fn(array $r): bool => !empty($r['configured']))) === $requiredcount,
            'satisfied' => $requiredcount > 0 && $freshcount === $requiredcount,
            'title' => format_string((string)$version->title),
            'requirements' => $rows,
            'next' => $next,
            'completedat' => $latest,
        ];
    }

    /** @return array<string,mixed> */
    private static function requirement_evidence(
        array $requirement,
        int $userid,
        int $pointid,
        int $versionid,
        int $runtimeid,
        string $positionid
    ): array {
        global $DB;

        $type = (string)($requirement['type'] ?? '');
        $label = (string)($requirement['label'] ?? '');
        $base = [
            'type' => $type,
            'label' => $label,
            'configured' => true,
            'completedat' => 0,
            'action' => null,
        ];

        if ($type === 'cm') {
            $cmid = (int)($requirement['sourceid'] ?? 0);
            $row = $DB->get_record_sql(
                "SELECT cm.id,cm.course,cm.instance,cm.completion,cm.completionview,m.name AS modname
                   FROM {course_modules} cm
                   JOIN {modules} m ON m.id=cm.module
                  WHERE cm.id=:cmid AND cm.deletioninprogress=0",
                ['cmid' => $cmid],
                IGNORE_MISSING
            );
            if (!$row) {
                $base['configured'] = false;
                return $base;
            }

            $modname = (string)$row->modname;
            $name = '';
            if (in_array($modname, ['page', 'quiz', 'scorm', 'lesson', 'resource', 'forum'], true)) {
                $name = (string)$DB->get_field($modname, 'name', ['id' => (int)$row->instance]);
            }
            $base['label'] = $label !== '' ? $label : ($name !== '' ? $name : ('Активность #' . $cmid));

            $completion = $DB->get_record('course_modules_completion', [
                'coursemoduleid' => $cmid,
                'userid' => $userid,
            ], 'completionstate,timemodified', IGNORE_MISSING);
            if ($completion && (int)$completion->completionstate > 0) {
                $base['completedat'] = (int)$completion->timemodified;
            }

            if ($modname === 'scorm') {
                $latest = (int)$DB->get_field_sql(
                    "SELECT COALESCE(MAX(v.timemodified),0)
                       FROM {scorm_scoes_value} v
                       JOIN {scorm_attempt} a ON a.id=v.attemptid
                       JOIN {scorm_element} e ON e.id=v.elementid
                      WHERE a.scormid=:scormid
                        AND a.userid=:userid
                        AND e.element IN ('cmi.core.lesson_status','cmi.completion_status')
                        AND LOWER(v.value) IN ('completed','passed')",
                    ['scormid' => (int)$row->instance, 'userid' => $userid]
                );
                $base['completedat'] = max((int)$base['completedat'], $latest);
                $base['action'] = [
                    'kind' => 'scorm',
                    'cmid' => $cmid,
                    'scormid' => (int)$row->instance,
                    'label' => $base['label'],
                ];
                return $base;
            }

            if ($modname === 'page') {
                $viewedat = (int)$DB->get_field_sql(
                    "SELECT COALESCE(MAX(timecreated),0)
                       FROM {logstore_standard_log}
                      WHERE userid=:userid
                        AND contextlevel=:contextlevel
                        AND contextinstanceid=:cmid
                        AND eventname=:eventname",
                    [
                        'userid' => $userid,
                        'contextlevel' => CONTEXT_MODULE,
                        'cmid' => $cmid,
                        'eventname' => '\\mod_page\\event\\course_module_viewed',
                    ]
                );
                $base['completedat'] = max((int)$base['completedat'], $viewedat);
            }

            $base['action'] = [
                'kind' => 'moodle_cm',
                'cmid' => $cmid,
                'modname' => $modname,
                'label' => $base['label'],
                'url' => (new \moodle_url('/mod/' . $modname . '/view.php', ['id' => $cmid]))->out(false),
            ];
            return $base;
        }

        if ($type === 'course') {
            $courseid = (int)($requirement['sourceid'] ?? 0);
            $course = $DB->get_record('course', ['id' => $courseid], 'id,fullname', IGNORE_MISSING);
            if (!$course) {
                $base['configured'] = false;
                return $base;
            }
            $base['label'] = $label !== '' ? $label : format_string((string)$course->fullname);
            $completed = $DB->get_field('course_completions', 'timecompleted', ['course' => $courseid, 'userid' => $userid]);
            $base['completedat'] = $completed ? (int)$completed : 0;
            $base['action'] = [
                'kind' => 'course',
                'courseid' => $courseid,
                'label' => $base['label'],
                'url' => (new \moodle_url('/course/view.php', ['id' => $courseid]))->out(false),
            ];
            return $base;
        }

        if ($type === 'content') {
            $contentid = (int)($requirement['sourceid'] ?? 0);
            $content = $DB->get_record('local_ustar_content', ['id' => $contentid], '*', IGNORE_MISSING);
            if (!$content || (string)$content->status !== content::STATUS_PUBLISHED || !content::can_access_record($content, $userid)) {
                $base['configured'] = false;
                return $base;
            }
            $base['label'] = $label !== '' ? $label : format_string((string)$content->title);
            $events = $DB->get_records('local_ustar_workflow_events', [
                'entitytype' => 'assessment_runtime',
                'entityid' => $runtimeid,
                'eventtype' => 'assess_content_opened',
            ], 'timecreated DESC');
            foreach ($events as $event) {
                $details = json_decode((string)$event->detailsjson, true);
                if ((int)($details['contentid'] ?? 0) === $contentid) {
                    $base['completedat'] = (int)$event->timecreated;
                    break;
                }
            }
            $base['action'] = [
                'kind' => 'content',
                'contentid' => $contentid,
                'completionmode' => (string)($requirement['completionmode'] ?? 'open'),
                'label' => $base['label'],
            ];
            return $base;
        }

        if ($type === 'assessment') {
            $key = (string)($requirement['sourcekey'] ?? '');
            $attempt = development_assessment::completion_for_user($key, $userid);
            $base['completedat'] = $attempt ? (int)$attempt->submittedat : 0;
            $base['label'] = $label !== '' ? $label : $key;
            $base['action'] = [
                'kind' => 'url',
                'label' => $base['label'],
                'url' => (new \moodle_url('/local/ustar/development_assessment.php', ['assessment' => $key, 'fromroute' => 1]))->out(false),
            ];
            return $base;
        }

        if ($type === 'native') {
            $key = (string)($requirement['sourcekey'] ?? '');
            $fact = native_learning::fact($userid, $pointid, $versionid, $key);
            $base['completedat'] = $fact ? (int)$fact->timecreated : 0;
            $base['label'] = $label !== '' ? $label : 'Нативная активность USTAR';
            $base['action'] = [
                'kind' => 'url',
                'label' => $base['label'],
                'url' => native_learning::url_for($key),
            ];
            return $base;
        }

        // Required skill/previous_adaptation are valid route requirements, but
        // they are not repeatable learning actions. Such a remediation policy
        // must be corrected in Route Studio instead of being guessed at runtime.
        $base['configured'] = false;
        return $base;
    }
}
