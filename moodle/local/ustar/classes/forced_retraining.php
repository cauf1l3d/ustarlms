<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * Manual forced retraining workflow.
 *
 * Automatic assessment escalation remains owned by assessment_lifecycle. Manual
 * assignments are immutable workflow events and reuse the same route evidence
 * and assessment providers with a new cutoff so historical completions do not
 * satisfy a new assignment.
 */
final class forced_retraining {
    private const ENTITY = 'forced_retraining';
    private const EVENT_ASSIGNED = 'forced_retraining_assigned';
    private const EVENT_COMPLETED = 'forced_retraining_completed';
    private const EVENT_CANCELLED = 'forced_retraining_cancelled';

    public static function available(): bool {
        global $DB;
        $dbman = $DB->get_manager();
        return $dbman->table_exists(new \xmldb_table('local_ustar_workflow_events'))
            && $dbman->table_exists(new \xmldb_table('local_ustar_assess_policy'));
    }

    public static function can_manage(int $actorid): bool {
        if (!self::available() || $actorid <= 0) {
            return false;
        }
        try {
            $scope = self::management_scope($actorid);
        } catch (\Throwable $e) {
            return false;
        }
        return !empty($scope['allowed']) && !empty($scope['userids']);
    }

    /** @return array<int,array<string,mixed>> */
    public static function targets(int $actorid): array {
        global $DB;
        $scope = self::management_scope($actorid);
        $ids = array_values(array_unique(array_filter(array_map('intval', $scope['userids'] ?? []))));
        $ids = array_values(array_filter($ids, static fn(int $id): bool => $id > 1 && $id !== $actorid));
        if (!$ids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'fru');
        $users = $DB->get_records_select('user', "id {$insql} AND deleted=0 AND suspended=0", $params, 'lastname ASC, firstname ASC', 'id,firstname,lastname');
        $out = [];
        foreach ($users as $user) {
            if (!accounts::participates((int)$user->id)) {
                continue;
            }
            $resolved = structure::resolve_user((int)$user->id);
            $out[] = [
                'id' => (int)$user->id,
                'fullname' => fullname($user),
                'position' => (string)($resolved['position']['name'] ?? $resolved['position']['title'] ?? '—'),
                'department' => (string)($resolved['department']['name'] ?? $resolved['department']['title'] ?? ''),
            ];
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    public static function topics_for_user(int $actorid, int $userid): array {
        global $DB;
        self::assert_target_allowed($actorid, $userid);
        $resolved = structure::resolve_user($userid);
        $positionid = (string)($resolved['position']['id'] ?? '');
        if ($positionid === '') {
            return [];
        }
        $activepolicyids = [];
        foreach (self::active_assignments($userid, false) as $assignment) {
            $activepolicyids[(int)$assignment['policyid']] = true;
        }
        $out = [];
        foreach ($DB->get_records('local_ustar_assess_policy', ['active' => 1], 'id ASC') as $policy) {
            if (isset($activepolicyids[(int)$policy->id])) {
                continue;
            }
            $topic = self::topic_for_policy($policy, $positionid);
            if ($topic) {
                $out[] = $topic;
            }
        }
        usort($out, static fn(array $a, array $b): int => strcasecmp((string)$a['materialtitle'], (string)$b['materialtitle']));
        return $out;
    }

    /**
     * @param int[] $policyids
     * @return int[] assignment event ids
     */
    public static function assign(int $actorid, int $userid, array $policyids, string $reason): array {
        global $DB;
        self::assert_target_allowed($actorid, $userid);
        $reason = trim($reason);
        if ($reason === '') {
            throw new \moodle_exception('Укажите причину принудительного переобучения.');
        }
        $policyids = array_values(array_unique(array_filter(array_map('intval', $policyids))));
        if (!$policyids) {
            throw new \moodle_exception('Выберите хотя бы одну тему переобучения.');
        }

        $resolved = structure::resolve_user($userid);
        $positionid = (string)($resolved['position']['id'] ?? '');
        if ($positionid === '') {
            throw new \moodle_exception('У сотрудника не определена должность USTAR.');
        }

        $alreadyactive = [];
        foreach (self::active_assignments($userid, false) as $assignment) {
            $alreadyactive[(int)$assignment['policyid']] = true;
        }

        $now = time();
        $ids = [];
        $transaction = $DB->start_delegated_transaction();
        foreach ($policyids as $policyid) {
            if (isset($alreadyactive[$policyid])) {
                continue;
            }
            $policy = $DB->get_record('local_ustar_assess_policy', ['id' => $policyid, 'active' => 1], '*', MUST_EXIST);
            $topic = self::topic_for_policy($policy, $positionid);
            if (!$topic) {
                throw new \moodle_exception('Одна из выбранных тем не относится к текущей должности сотрудника.');
            }
            $provider = assessment_provider_factory::for_policy($policy);
            $assessment = $provider->inspect($userid, $policy);
            $details = [
                'policyid' => (int)$policy->id,
                'assessmentpointid' => (int)$policy->pointid,
                'assessmentversionid' => (int)$policy->versionid,
                'remediationpointid' => (int)$topic['remediationpointid'],
                'remediationversionid' => (int)$topic['remediationversionid'],
                'positionid' => $positionid,
                'cutoff' => $now,
                'baselineattempts' => (int)($assessment['totalattempts'] ?? 0),
                'attemptspercycle' => max(1, (int)$policy->attemptspercycle),
                'materialtitle' => (string)$topic['materialtitle'],
                'assessmenttitle' => (string)$topic['assessmenttitle'],
                'source' => 'manual',
            ];
            $id = (int)$DB->insert_record('local_ustar_workflow_events', (object)[
                'entitytype' => self::ENTITY,
                'entityid' => $userid,
                'eventtype' => self::EVENT_ASSIGNED,
                'actorid' => $actorid,
                'reason' => $reason,
                'detailsjson' => self::json($details),
                'timecreated' => $now,
            ]);
            $ids[] = $id;
        }
        $transaction->allow_commit();
        if (!$ids) {
            throw new \moodle_exception('Все выбранные темы уже находятся в активном принудительном переобучении.');
        }
        return $ids;
    }

    /** @return array<int,array<string,mixed>> */
    public static function cards_for_user(int $userid): array {
        $resolved = structure::resolve_user($userid);
        $positionid = (string)($resolved['position']['id'] ?? '');
        $cards = [];
        foreach (self::active_assignments($userid, true) as $assignment) {
            $state = self::state($assignment, $positionid, true);
            if (!empty($state['completed'])) {
                continue;
            }
            $cards[] = $state;
        }
        usort($cards, static function(array $a, array $b): int {
            return ((int)$a['assignedat'] <=> (int)$b['assignedat']) ?: ((int)$a['id'] <=> (int)$b['id']);
        });
        return $cards;
    }

    /** @return array<int,array<string,mixed>> */
    public static function active_for_manager(int $actorid, int $userid): array {
        self::assert_target_allowed($actorid, $userid);
        $resolved = structure::resolve_user($userid);
        $positionid = (string)($resolved['position']['id'] ?? '');
        $out = [];
        foreach (self::active_assignments($userid, true) as $assignment) {
            $state = self::state($assignment, $positionid, true);
            if (empty($state['completed'])) {
                $out[] = $state;
            }
        }
        return $out;
    }

    /** @return array<string,mixed> */
    public static function launch(int $userid, int $assignmentid): array {
        global $DB;
        $assignment = self::assignment($userid, $assignmentid);
        if (!empty($assignment['closed'])) {
            return ['kind' => 'url', 'url' => (new \moodle_url('/local/ustar/route.php'))->out(false)];
        }
        $positionid = (string)($assignment['positionid'] ?? '');
        $state = self::state($assignment, $positionid, true);
        if (!empty($state['completed'])) {
            return ['kind' => 'url', 'url' => (new \moodle_url('/local/ustar/route.php'))->out(false)];
        }

        $policy = $DB->get_record('local_ustar_assess_policy', ['id' => (int)$assignment['policyid']], '*', MUST_EXIST);
        if (empty($state['materialdone'])) {
            $runtime = self::evidence_runtime($assignment);
            $evidence = route_point_evidence_provider::state($runtime, $policy, $positionid, self::ENTITY);
            if (empty($evidence['configured']) || empty($evidence['next'])) {
                throw new \moodle_exception('Для выбранной темы нет доступного обязательного материала.');
            }
            $next = $evidence['next'];
            if ((string)($next['kind'] ?? '') === 'content') {
                $contentid = (int)($next['contentid'] ?? 0);
                learning_events::record_route_open(
                    $userid,
                    $contentid,
                    (int)$assignment['remediationpointid'],
                    (int)$assignment['remediationversionid']
                );
                if ((string)($next['completionmode'] ?? 'open') === 'open') {
                    self::event($assignmentid, $userid, 'assess_content_opened', $userid, 'Материал принудительного переобучения открыт', [
                        'contentid' => $contentid,
                    ]);
                }
                $url = content::open_url($contentid, $userid);
                if (!$url) {
                    throw new \moodle_exception('Материал сейчас невозможно открыть.');
                }
                return ['kind' => 'url', 'url' => $url->out(false)];
            }
            return $next;
        }

        if (!empty($state['attemptsexhausted'])) {
            throw new \moodle_exception('Дополнительный пакет попыток исчерпан. Требуется новое решение руководителя или HRD.');
        }

        $provider = assessment_provider_factory::for_policy($policy);
        $limit = (int)$assignment['baselineattempts'] + max(1, (int)$assignment['attemptspercycle']);
        $provider->unlock_attempt_limit($userid, $policy, $limit);
        $assessment = $provider->inspect($userid, $policy);
        $cmid = (int)($assessment['cmid'] ?? 0);
        if ($cmid <= 0) {
            throw new \moodle_exception('Не удалось определить аттестацию для переобучения.');
        }
        return [
            'kind' => 'assessment',
            'cmid' => $cmid,
            'url' => (new \moodle_url('/mod/quiz/view.php', ['id' => $cmid]))->out(false),
        ];
    }

    /** @return array<string,mixed> */
    private static function state(array $assignment, string $positionid, bool $reconcile): array {
        global $DB;
        $policy = $DB->get_record('local_ustar_assess_policy', ['id' => (int)$assignment['policyid']], '*', IGNORE_MISSING);
        if (!$policy) {
            return self::broken_state($assignment, 'Политика аттестации больше недоступна.');
        }

        $runtime = self::evidence_runtime($assignment);
        $evidence = route_point_evidence_provider::state($runtime, $policy, $positionid, self::ENTITY);
        $provider = assessment_provider_factory::for_policy($policy);
        $assessment = $provider->inspect((int)$assignment['userid'], $policy);
        $cutoff = (int)$assignment['cutoff'];
        $passedat = 0;
        $passedattemptid = 0;
        $freshfinalized = 0;
        foreach ($assessment['finalized'] ?? [] as $attempt) {
            $finalizedat = (int)($attempt['finalizedat'] ?? $attempt['timefinish'] ?? 0);
            if ($finalizedat <= $cutoff) {
                continue;
            }
            $freshfinalized++;
            if ((float)($attempt['score'] ?? 0) + 0.000001 >= (float)($assessment['passscore'] ?? 0)) {
                if ($passedat === 0 || $finalizedat < $passedat) {
                    $passedat = $finalizedat;
                    $passedattemptid = (int)($attempt['attemptid'] ?? 0);
                }
            }
        }

        if ($passedat > 0) {
            if ($reconcile) {
                self::complete_once($assignment, $passedat, $passedattemptid);
            }
            return array_merge($assignment, [
                'completed' => true,
                'materialdone' => true,
                'statuskey' => 'complete',
                'statuslabel' => 'Переобучение завершено',
                'canlaunch' => false,
                'launchurl' => '',
            ]);
        }

        $materialdone = !empty($evidence['satisfied']);
        $newattempts = max(0, (int)($assessment['totalattempts'] ?? 0) - (int)$assignment['baselineattempts']);
        $percycle = max(1, (int)$assignment['attemptspercycle']);
        $pending = (int)($assessment['pendingattempts'] ?? 0) > 0 && $newattempts > $freshfinalized;
        $exhausted = $materialdone && !$pending && $newattempts >= $percycle;

        if (!$materialdone) {
            $statuskey = 'material';
            $statuslabel = 'Требуется повторить материал';
            $actionlabel = 'Повторить материал';
            $canlaunch = !empty($evidence['configured']) && !empty($evidence['next']);
        } else if ($pending) {
            $statuskey = 'awaiting';
            $statuslabel = 'Результат ожидает проверки';
            $actionlabel = 'Ожидает проверки';
            $canlaunch = false;
        } else if ($exhausted) {
            $statuskey = 'exhausted';
            $statuslabel = 'Пакет попыток исчерпан';
            $actionlabel = 'Требуется решение';
            $canlaunch = false;
        } else {
            $statuskey = 'assessment';
            $statuslabel = 'Требуется повторная аттестация';
            $actionlabel = 'Пройти аттестацию';
            $canlaunch = true;
        }

        return array_merge($assignment, [
            'completed' => false,
            'materialdone' => $materialdone,
            'materialconfigured' => !empty($evidence['configured']),
            'statuskey' => $statuskey,
            'statuslabel' => $statuslabel,
            'actionlabel' => $actionlabel,
            'canlaunch' => $canlaunch,
            'launchurl' => $canlaunch ? (new \moodle_url('/local/ustar/forced_retraining_launch.php', ['id' => (int)$assignment['id']]))->out(false) : '',
            'attemptsused' => $newattempts,
            'attemptslimit' => $percycle,
            'attemptsexhausted' => $exhausted,
            'bestscore' => self::score((float)($assessment['bestscore'] ?? 0)),
            'passscore' => self::score((float)($assessment['passscore'] ?? 0)),
            'maxscore' => self::score((float)($assessment['maxscore'] ?? 0)),
            'materialtitle' => (string)($evidence['title'] ?: $assignment['materialtitle']),
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    private static function active_assignments(int $userid, bool $includeclosed): array {
        global $DB;
        if (!self::available()) {
            return [];
        }
        $events = $DB->get_records('local_ustar_workflow_events', [
            'entitytype' => self::ENTITY,
            'entityid' => $userid,
        ], 'id ASC');
        $items = [];
        foreach ($events as $event) {
            $details = json_decode((string)$event->detailsjson, true);
            if (!is_array($details)) {
                $details = [];
            }
            if ((string)$event->eventtype === self::EVENT_ASSIGNED) {
                $items[(int)$event->id] = array_merge($details, [
                    'id' => (int)$event->id,
                    'userid' => $userid,
                    'actorid' => (int)$event->actorid,
                    'reason' => (string)$event->reason,
                    'assignedat' => (int)$event->timecreated,
                    'closed' => false,
                    'completedat' => 0,
                ]);
                continue;
            }
            $assignmentid = (int)($details['assignmentid'] ?? 0);
            if ($assignmentid <= 0 || empty($items[$assignmentid])) {
                continue;
            }
            if ((string)$event->eventtype === self::EVENT_COMPLETED) {
                $items[$assignmentid]['closed'] = true;
                $items[$assignmentid]['completedat'] = (int)$event->timecreated;
            } else if ((string)$event->eventtype === self::EVENT_CANCELLED) {
                $items[$assignmentid]['closed'] = true;
                $items[$assignmentid]['cancelled'] = true;
            }
        }
        if (!$includeclosed) {
            $items = array_filter($items, static fn(array $item): bool => empty($item['closed']));
        }
        return array_values($items);
    }

    /** @return array<string,mixed> */
    private static function assignment(int $userid, int $assignmentid): array {
        foreach (self::active_assignments($userid, true) as $assignment) {
            if ((int)$assignment['id'] === $assignmentid) {
                return $assignment;
            }
        }
        throw new \moodle_exception('Назначение принудительного переобучения не найдено.');
    }

    /** @return array<string,mixed>|null */
    private static function topic_for_policy(\stdClass $policy, string $positionid): ?array {
        global $DB;
        $assessmentversion = route_model::current_published_version((int)$policy->pointid);
        if (!$assessmentversion || (int)$assessmentversion->id !== (int)$policy->versionid) {
            return null;
        }
        $remediationpointid = (int)($policy->remediationpointid ?? 0);
        if ($remediationpointid <= 0) {
            return null;
        }
        if (route_scope::available()) {
            if (!route_scope::point_applies((int)$policy->pointid, $positionid)
                || !route_scope::point_applies($remediationpointid, $positionid)) {
                return null;
            }
        }
        $remediationversion = route_model::current_published_version($remediationpointid);
        if (!$remediationversion) {
            return null;
        }
        $assessmentpoint = $DB->get_record('local_ustar_route_points', ['id' => (int)$policy->pointid], 'id,routeid', IGNORE_MISSING);
        $remediationpoint = $DB->get_record('local_ustar_route_points', ['id' => $remediationpointid], 'id,routeid', IGNORE_MISSING);
        if (!$assessmentpoint || !$remediationpoint || (int)$assessmentpoint->routeid !== (int)$remediationpoint->routeid) {
            return null;
        }
        return [
            'policyid' => (int)$policy->id,
            'remediationpointid' => $remediationpointid,
            'remediationversionid' => (int)$remediationversion->id,
            'materialtitle' => format_string((string)$remediationversion->title),
            'assessmenttitle' => format_string((string)$assessmentversion->title),
            'label' => format_string((string)$remediationversion->title) . ' → ' . format_string((string)$assessmentversion->title),
        ];
    }

    private static function assert_target_allowed(int $actorid, int $userid): void {
        if ($userid <= 1 || $actorid <= 0 || $userid === $actorid) {
            throw new \moodle_exception('Недопустимый сотрудник для назначения переобучения.');
        }
        $scope = self::management_scope($actorid);
        $ids = array_map('intval', $scope['userids'] ?? []);
        if (!in_array($userid, $ids, true)) {
            throw new \required_capability_exception(\context_system::instance(), 'local/ustar:viewteam', 'nopermissions', '');
        }
    }

    /** @return array<string,mixed> */
    private static function management_scope(int $actorid): array {
        if (!self::available()) {
            throw new \moodle_exception('Механика принудительного переобучения недоступна.');
        }
        $context = \context_system::instance();
        $companywrite = is_siteadmin($actorid)
            || has_capability('local/ustar:admin', $context, $actorid)
            || has_capability('local/ustar:hrmanage', $context, $actorid)
            || has_capability('local/ustar:executive', $context, $actorid);
        $scope = $companywrite ? team_access::learning_scope($actorid) : organization_model::manager_scope($actorid);
        if (empty($scope['allowed'])) {
            throw new \required_capability_exception($context, 'local/ustar:viewteam', 'nopermissions', '');
        }
        return $scope;
    }

    private static function evidence_runtime(array $assignment): \stdClass {
        return (object)[
            'id' => (int)$assignment['id'],
            'userid' => (int)$assignment['userid'],
            'remediationpointid' => (int)$assignment['remediationpointid'],
            'remediationversionid' => (int)$assignment['remediationversionid'],
            'failurecutoff' => (int)$assignment['cutoff'],
        ];
    }

    private static function complete_once(array $assignment, int $completedat, int $attemptid): void {
        global $DB;
        $assignmentid = (int)$assignment['id'];
        $events = $DB->get_records('local_ustar_workflow_events', [
            'entitytype' => self::ENTITY,
            'entityid' => (int)$assignment['userid'],
            'eventtype' => self::EVENT_COMPLETED,
        ], 'id ASC');
        foreach ($events as $event) {
            $details = json_decode((string)$event->detailsjson, true);
            if ((int)($details['assignmentid'] ?? 0) === $assignmentid) {
                return;
            }
        }
        self::event($assignmentid, (int)$assignment['userid'], self::EVENT_COMPLETED, (int)$assignment['userid'], 'Принудительное переобучение подтверждено новой успешной аттестацией', [
            'completedat' => $completedat,
            'attemptid' => $attemptid,
        ]);
    }

    /** @param array<string,mixed> $details */
    private static function event(int $assignmentid, int $userid, string $eventtype, int $actorid, string $reason, array $details): int {
        global $DB;
        $details['assignmentid'] = $assignmentid;
        return (int)$DB->insert_record('local_ustar_workflow_events', (object)[
            'entitytype' => self::ENTITY,
            'entityid' => $userid,
            'eventtype' => $eventtype,
            'actorid' => $actorid,
            'reason' => $reason,
            'detailsjson' => self::json($details),
            'timecreated' => time(),
        ]);
    }

    /** @return array<string,mixed> */
    private static function broken_state(array $assignment, string $message): array {
        return array_merge($assignment, [
            'completed' => false,
            'materialdone' => false,
            'statuskey' => 'broken',
            'statuslabel' => $message,
            'canlaunch' => false,
            'launchurl' => '',
            'attemptsused' => 0,
            'attemptslimit' => max(1, (int)($assignment['attemptspercycle'] ?? 3)),
            'attemptsexhausted' => false,
            'bestscore' => '0',
            'passscore' => '0',
            'maxscore' => '0',
        ]);
    }

    private static function score(float $score): string {
        $rounded = round($score, 1);
        return abs($rounded - round($rounded)) < 0.000001 ? (string)(int)round($rounded) : number_format($rounded, 1, '.', '');
    }

    /** @param array<string,mixed> $data */
    private static function json(array $data): string {
        return (string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
