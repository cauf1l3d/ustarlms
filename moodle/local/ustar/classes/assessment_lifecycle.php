<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * Generic, provider-independent assessment lifecycle.
 *
 * USTAR owns cycle/remediation/escalation state. Assessment engines keep their
 * own attempts and grades; learning engines keep their own completion facts.
 */
final class assessment_lifecycle {
    public const STATUS_ACTIVE = 'active';
    public const STATUS_AWAITING = 'awaiting_manual_grade';
    public const STATUS_MANAGER_REVIEW_REQUIRED = 'manager_review_required';
    public const STATUS_HRD_REVIEW_REQUIRED = 'hrd_review_required';
    public const STATUS_REMEDIATION_REQUIRED = 'remediation_required';
    public const STATUS_REMEDIATION_PROGRESS = 'remediation_in_progress';
    public const STATUS_REOPENED = 'reopened';
    public const STATUS_PASSED = 'passed';
    public const STATUS_EXHAUSTED = 'exhausted';

    public static function available(): bool {
        global $DB;
        $dbman = $DB->get_manager();
        return $dbman->table_exists(new \xmldb_table('local_ustar_assess_policy'))
            && $dbman->table_exists(new \xmldb_table('local_ustar_assess_runtime'));
    }

    public static function policy_for_version(int $versionid): ?\stdClass {
        global $DB;
        if (!self::available() || $versionid <= 0) {
            return null;
        }
        return $DB->get_record('local_ustar_assess_policy', [
            'versionid' => $versionid,
            'active' => 1,
        ]) ?: null;
    }

    /**
     * Called only for the current reachable route point.
     * @return array<string,mixed>|null
     */
    public static function sync_route_point(
        int $userid,
        string $positionid,
        \stdClass $point,
        \stdClass $version
    ): ?array {
        $policy = self::policy_for_version((int)$version->id);
        if (!$policy) {
            return null;
        }
        $runtime = self::sync_policy_user($policy, $userid, $positionid, true);
        return $runtime ? self::route_view($runtime, $policy, $positionid) : null;
    }

    /**
     * Synchronise one employee against authoritative provider facts.
     *
     * When $createifempty=false, a runtime is created only after at least one
     * real provider attempt exists. This lets manager pages reconcile legacy
     * failures without creating empty rows for every future assessment.
     */
    public static function sync_policy_user(
        \stdClass $policy,
        int $userid,
        string $positionid = '',
        bool $createifempty = false
    ): ?\stdClass {
        global $DB;

        if (!self::available() || $userid <= 1 || empty($policy->active)) {
            return null;
        }
        if ($positionid === '') {
            $resolved = structure::resolve_user($userid);
            $positionid = (string)($resolved['position']['id'] ?? '');
        }
        if ($positionid === '' || !self::policy_applies_to_user($policy, $userid, $positionid)) {
            return null;
        }

        $provider = assessment_provider_factory::for_policy($policy);
        $prestate = $provider->inspect($userid, $policy);
        $runtime = $DB->get_record('local_ustar_assess_runtime', [
            'userid' => $userid,
            'pointid' => (int)$policy->pointid,
            'versionid' => (int)$policy->versionid,
        ]);

        if (!$runtime && !$createifempty && (int)$prestate['totalattempts'] <= 0) {
            return null;
        }

        $factory = \core\lock\lock_config::get_lock_factory('local_ustar');
        $lock = $factory->get_lock(
            'assessment-lifecycle:' . $userid . ':' . (int)$policy->id,
            10
        );
        if (!$lock) {
            throw new \moodle_exception('Не удалось получить блокировку жизненного цикла аттестации.');
        }

        try {
            $providerstate = $provider->inspect($userid, $policy);
            $runtime = $DB->get_record('local_ustar_assess_runtime', [
                'userid' => $userid,
                'pointid' => (int)$policy->pointid,
                'versionid' => (int)$policy->versionid,
            ]);

            if (!$runtime) {
                $now = time();
                $runtimeid = (int)$DB->insert_record('local_ustar_assess_runtime', (object)[
                    'userid' => $userid,
                    'pointid' => (int)$policy->pointid,
                    'versionid' => (int)$policy->versionid,
                    'policyid' => (int)$policy->id,
                    'cycle' => 1,
                    'attemptsused' => (int)$providerstate['totalattempts'],
                    'status' => self::STATUS_ACTIVE,
                    'failurecutoff' => null,
                    'remediationpointid' => !empty($policy->remediationpointid) ? (int)$policy->remediationpointid : null,
                    'remediationversionid' => null,
                    'remediationstartedat' => null,
                    'remediationcompletedat' => null,
                    'unlockedattemptlimit' => max(1, (int)$policy->attemptspercycle),
                    'managerid' => self::direct_manager_id($userid) ?: null,
                    'managerescalatedat' => null,
                    'hrdescalatedat' => null,
                    'lastattemptid' => !empty($providerstate['lastattemptid']) ? (int)$providerstate['lastattemptid'] : null,
                    'timecreated' => $now,
                    'timemodified' => $now,
                ]);
                $runtime = $DB->get_record('local_ustar_assess_runtime', ['id' => $runtimeid], '*', MUST_EXIST);
            }

            $changed = false;
            $now = time();
            $runtime->attemptsused = (int)$providerstate['totalattempts'];
            $runtime->lastattemptid = !empty($providerstate['lastattemptid'])
                ? (int)$providerstate['lastattemptid']
                : $runtime->lastattemptid;

            // Keep the current reporting line authoritative. If an employee is
            // transferred to another manager while remediation is active, the
            // live alert follows the employee instead of remaining on the old
            // manager's dashboard. Historical escalation events stay immutable.
            $managerid = self::direct_manager_id($userid);
            if ($managerid > 0 && (int)($runtime->managerid ?? 0) !== $managerid) {
                $runtime->managerid = $managerid;
                $changed = true;
            }

            // A pass always wins, including a pass after remediation.
            if (!empty($providerstate['passed'])) {
                if ((string)$runtime->status !== self::STATUS_PASSED) {
                    $runtime->status = self::STATUS_PASSED;
                    $runtime->remediationcompletedat = $runtime->remediationcompletedat ?: null;
                    self::event($runtime, 'assess_passed', 'Аттестация пройдена', [
                        'cycle' => (int)$runtime->cycle,
                        'attempts' => (int)$providerstate['totalattempts'],
                        'bestscore' => (float)$providerstate['bestscore'],
                        'passscore' => (float)$providerstate['passscore'],
                    ]);
                    $changed = true;
                }
            } else if ((int)$providerstate['pendingattempts'] > 0) {
                if ((string)$runtime->status !== self::STATUS_AWAITING) {
                    $runtime->status = self::STATUS_AWAITING;
                    $changed = true;
                }
            } else if (in_array((string)$runtime->status, [
                self::STATUS_REMEDIATION_REQUIRED,
                self::STATUS_REMEDIATION_PROGRESS,
            ], true)) {
                $evidence = route_point_evidence_provider::state($runtime, $policy, $positionid);
                if (!empty($evidence['satisfied'])) {
                    $nextcycle = (int)$runtime->cycle + 1;
                    if ($nextcycle <= (int)$policy->maxcycles) {
                        $newlimit = $nextcycle * max(1, (int)$policy->attemptspercycle);
                        $provider->unlock_attempt_limit($userid, $policy, $newlimit);
                        $runtime->cycle = $nextcycle;
                        $runtime->status = self::STATUS_REOPENED;
                        $runtime->remediationcompletedat = (int)$evidence['completedat'];
                        $runtime->unlockedattemptlimit = $newlimit;
                        self::event($runtime, 'assess_remediation_done', 'Обязательное переобучение завершено', [
                            'cycle' => $nextcycle - 1,
                            'completedat' => (int)$evidence['completedat'],
                            'remediationpointid' => (int)$runtime->remediationpointid,
                            'remediationversionid' => (int)$runtime->remediationversionid,
                        ]);
                        self::event($runtime, 'assess_cycle_reopened', 'Открыт дополнительный цикл аттестации', [
                            'cycle' => $nextcycle,
                            'attemptlimit' => $newlimit,
                        ]);
                        $changed = true;
                    }
                }
            } else if (
                (string)$runtime->status !== self::STATUS_EXHAUSTED
                && (string)$runtime->status !== self::STATUS_MANAGER_REVIEW_REQUIRED
                && (string)$runtime->status !== self::STATUS_HRD_REVIEW_REQUIRED
            ) {
                $cycle = max(1, (int)$runtime->cycle);
                $percycle = max(1, (int)$policy->attemptspercycle);
                $limit = $cycle * $percycle;
                $totalattempts = (int)$providerstate['totalattempts'];

                if ($totalattempts >= $limit) {
                    $cutoff = self::cycle_cutoff($providerstate, $limit);

                    if ($cycle < max(1, (int)$policy->maxcycles)) {
                        $reviewstatus = $cycle === 1
                            ? self::STATUS_MANAGER_REVIEW_REQUIRED
                            : self::STATUS_HRD_REVIEW_REQUIRED;
                        $reviewevent = $cycle === 1
                            ? 'assess_manager_review_required'
                            : 'assess_hrd_review_required';
                        $reviewreason = $cycle === 1
                            ? 'Требуется решение руководителя о переобучении'
                            : 'Требуется решение HRD о повторном переобучении';

                        $runtime->status = $reviewstatus;
                        $runtime->failurecutoff = $cutoff;
                        $runtime->remediationpointid = (int)$policy->remediationpointid;
                        $runtime->remediationversionid = null;
                        $runtime->remediationstartedat = null;
                        $runtime->remediationcompletedat = null;
                        $runtime->unlockedattemptlimit = $limit;

                        self::event($runtime, 'assess_cycle_exhausted', 'Пакет попыток аттестации исчерпан', [
                            'cycle' => $cycle,
                            'attempts' => $totalattempts,
                            'attemptlimit' => $limit,
                            'bestscore' => (float)$providerstate['bestscore'],
                            'passscore' => (float)$providerstate['passscore'],
                        ]);
                        self::event($runtime, $reviewevent, $reviewreason, [
                            'cycle' => $cycle,
                            'cutoff' => $cutoff,
                            'remediationpointid' => (int)$runtime->remediationpointid,
                            'remediationversionid' => (int)$runtime->remediationversionid,
                        ]);

                        self::escalate_for_cycle($runtime, $policy, $cycle, $providerstate);
                        $changed = true;
                    } else {
                        $runtime->status = self::STATUS_EXHAUSTED;
                        $runtime->failurecutoff = $cutoff;
                        self::event($runtime, 'assess_exhausted', 'Все разрешённые циклы аттестации исчерпаны', [
                            'cycle' => $cycle,
                            'attempts' => $totalattempts,
                            'bestscore' => (float)$providerstate['bestscore'],
                            'passscore' => (float)$providerstate['passscore'],
                        ]);
                        self::escalate_for_cycle($runtime, $policy, $cycle, $providerstate);
                        $changed = true;
                    }
                } else {
                    $targetstatus = ((string)$runtime->status === self::STATUS_REOPENED && $totalattempts === (($cycle - 1) * $percycle))
                        ? self::STATUS_REOPENED
                        : self::STATUS_ACTIVE;
                    if ((string)$runtime->status !== $targetstatus) {
                        $runtime->status = $targetstatus;
                        $changed = true;
                    }
                }
            }

            $runtime->timemodified = $now;
            // attemptsused/lastattempt are also canonical runtime projections;
            // persist them even when no state transition happened.
            $DB->update_record('local_ustar_assess_runtime', $runtime);
            return $DB->get_record('local_ustar_assess_runtime', ['id' => (int)$runtime->id], '*', MUST_EXIST);
        } finally {
            $lock->release();
        }
    }

    /** @return array<string,mixed> */
    public static function route_view(\stdClass $runtime, \stdClass $policy, string $positionid): array {
        $provider = assessment_provider_factory::for_policy($policy);
        $state = $provider->inspect((int)$runtime->userid, $policy);
        $verifiedcompletedat = 0;
        foreach ($state['finalized'] ?? [] as $attempt) {
            if ((float)($attempt['score'] ?? 0) + 0.000001 >= (float)$state['passscore']) {
                $at = (int)($attempt['finalizedat'] ?? $attempt['timefinish'] ?? 0);
                if ($at > 0 && ($verifiedcompletedat === 0 || $at < $verifiedcompletedat)) { $verifiedcompletedat = $at; }
            }
        }
        $status = (string)$runtime->status;
        $percycle = max(1, (int)$policy->attemptspercycle);
        $cycle = max(1, (int)$runtime->cycle);
        $currentlimit = $cycle * $percycle;
        $view = [
            'managed' => true,
            'verifiedcompletedat' => $verifiedcompletedat,
            'status' => $status,
            'statuslabel' => 'Сейчас',
            'launchurl' => $provider->launch_url($policy),
            'canlaunch' => true,
            'actionlabel' => 'Продолжить',
            'actionlabelshort' => 'Продолжить',
            'attemptsused' => (int)$state['totalattempts'],
            'attemptlimit' => $currentlimit,
            'cycle' => $cycle,
            'maxcycles' => (int)$policy->maxcycles,
            'bestscore' => self::score((float)$state['bestscore']),
            'maxscore' => self::score((float)$state['maxscore'], 0),
            'passscore' => self::score((float)$state['passscore'], 1),
            'remediation' => false,
            'awaiting' => false,
            'reopened' => false,
            'exhausted' => false,
            'remediationtitle' => '',
            'nextattemptstart' => 0,
            'nextattemptend' => 0,
            'managerescalated' => !empty($runtime->managerescalatedat),
            'managerreview' => false,
            'hrdreview' => false,
        ];

        if ($status === self::STATUS_AWAITING) {
            $view['statuslabel'] = 'Ожидает проверки';
            $view['canlaunch'] = false;
            $view['launchurl'] = '';
            $view['awaiting'] = true;
            $view['actionlabel'] = 'Ожидает проверки';
            $view['actionlabelshort'] = 'Ожидает проверки';
        } else if ($status === self::STATUS_MANAGER_REVIEW_REQUIRED) {
            $view['statuslabel'] = 'Ожидается решение руководителя';
            $view['managerreview'] = true;
            $view['canlaunch'] = false;
            $view['launchurl'] = '';
            $view['actionlabel'] = 'Ожидается решение руководителя';
            $view['actionlabelshort'] = 'Ожидается решение руководителя';
        } else if ($status === self::STATUS_HRD_REVIEW_REQUIRED) {
            $view['statuslabel'] = 'Ожидается решение HRD';
            $view['hrdreview'] = true;
            $view['canlaunch'] = false;
            $view['launchurl'] = '';
            $view['actionlabel'] = 'Ожидается решение HRD';
            $view['actionlabelshort'] = 'Ожидается решение HRD';
        } else if (in_array($status, [self::STATUS_REMEDIATION_REQUIRED, self::STATUS_REMEDIATION_PROGRESS], true)) {
            $evidence = route_point_evidence_provider::state($runtime, $policy, $positionid);
            $view['statuslabel'] = $status === self::STATUS_REMEDIATION_PROGRESS ? 'Переобучение начато' : 'Требуется переобучение';
            $view['remediation'] = true;
            $view['remediationtitle'] = (string)$evidence['title'];
            $view['launchurl'] = (new \moodle_url('/local/ustar/assessment_remediation_launch.php', ['runtimeid' => (int)$runtime->id]))->out(false);
            $view['canlaunch'] = !empty($evidence['configured']) && !empty($evidence['next']);
            $view['actionlabel'] = 'Повторить материал →';
            $view['actionlabelshort'] = 'Повторить материал';
            $view['nextattemptstart'] = $cycle * $percycle + 1;
            $view['nextattemptend'] = min((int)$policy->maxcycles * $percycle, ($cycle + 1) * $percycle);
        } else if ($status === self::STATUS_REOPENED) {
            $view['statuslabel'] = 'Дополнительные попытки открыты';
            $view['reopened'] = true;
            $view['actionlabel'] = 'Начать попытку ' . ((int)$state['totalattempts'] + 1) . ' →';
            $view['actionlabelshort'] = 'Начать попытку ' . ((int)$state['totalattempts'] + 1);
        } else if ($status === self::STATUS_EXHAUSTED) {
            $view['statuslabel'] = 'Требует решения HRD';
            $view['exhausted'] = true;
            $view['canlaunch'] = false;
            $view['launchurl'] = '';
            $view['actionlabel'] = 'Попытки исчерпаны';
            $view['actionlabelshort'] = 'Попытки исчерпаны';
        }

        return $view;
    }

    public static function authorize_remediation(int $actorid, int $runtimeid): \stdClass {
        global $DB;

        $runtime = $DB->get_record('local_ustar_assess_runtime', ['id' => $runtimeid], '*', MUST_EXIST);
        $policy = $DB->get_record('local_ustar_assess_policy', ['id' => (int)$runtime->policyid, 'active' => 1], '*', MUST_EXIST);
        $status = (string)$runtime->status;

        $ismanagerreview = $status === self::STATUS_MANAGER_REVIEW_REQUIRED;
        $ishrdreview = $status === self::STATUS_HRD_REVIEW_REQUIRED;
        if (!$ismanagerreview && !$ishrdreview) {
            throw new \moodle_exception('Эта аттестация сейчас не ожидает согласования переобучения.');
        }

        $context = \context_system::instance();
        if ($ismanagerreview) {
            $managerid = self::direct_manager_id((int)$runtime->userid);
            if ($actorid !== $managerid && !is_siteadmin($actorid)) {
                throw new \moodle_exception('Переобучение первого уровня может согласовать только прямой руководитель.');
            }
            $approvallevel = 'direct_manager';
            $eventtype = 'assess_remediation_authorized';
            $reason = 'Руководитель согласовал переобучение';
        } else {
            $canhrd = is_siteadmin($actorid)
                || has_capability('local/ustar:hrmanage', $context, $actorid)
                || has_capability('local/ustar:admin', $context, $actorid);
            if (!$canhrd) {
                throw new \moodle_exception('Повторное переобучение после второго пакета попыток может согласовать только HRD.');
            }
            $approvallevel = 'hrd';
            $eventtype = 'assess_hrd_remediation_approved';
            $reason = 'HRD согласовал повторное переобучение';
        }

        $remediationversion = route_model::current_published_version((int)$policy->remediationpointid);
        if (!$remediationversion) {
            throw new \moodle_exception('Для переобучения нет опубликованной версии материала.');
        }

        $now = time();
        $runtime->status = self::STATUS_REMEDIATION_REQUIRED;
        $runtime->failurecutoff = $now;
        $runtime->remediationversionid = (int)$remediationversion->id;
        $runtime->remediationstartedat = null;
        $runtime->remediationcompletedat = null;
        $runtime->timemodified = $now;
        $DB->update_record('local_ustar_assess_runtime', $runtime);

        self::event($runtime, $eventtype, $reason, [
            'cycle' => (int)$runtime->cycle,
            'approverid' => $actorid,
            'approvallevel' => $approvallevel,
            'cutoff' => $now,
            'remediationpointid' => (int)$runtime->remediationpointid,
            'remediationversionid' => (int)$runtime->remediationversionid,
        ], $actorid);

        return $DB->get_record('local_ustar_assess_runtime', ['id' => (int)$runtime->id], '*', MUST_EXIST);
    }

    /** @return array<string,mixed> */
    public static function start_remediation(int $userid, int $runtimeid): array {
        global $DB;
        $runtime = $DB->get_record('local_ustar_assess_runtime', ['id' => $runtimeid, 'userid' => $userid], '*', MUST_EXIST);
        $policy = $DB->get_record('local_ustar_assess_policy', ['id' => (int)$runtime->policyid, 'active' => 1], '*', MUST_EXIST);
        $resolved = structure::resolve_user($userid);
        $positionid = (string)($resolved['position']['id'] ?? '');
        if ($positionid === '') {
            throw new \moodle_exception('Не удалось определить должность сотрудника.');
        }

        $runtime = self::sync_policy_user($policy, $userid, $positionid, true);
        if (!$runtime || !in_array((string)$runtime->status, [self::STATUS_REMEDIATION_REQUIRED, self::STATUS_REMEDIATION_PROGRESS], true)) {
            return ['kind' => 'url', 'url' => assessment_provider_factory::for_policy($policy)->launch_url($policy)];
        }

        $state = route_point_evidence_provider::state($runtime, $policy, $positionid);
        if (!empty($state['satisfied'])) {
            $runtime = self::sync_policy_user($policy, $userid, $positionid, true);
            return ['kind' => 'url', 'url' => assessment_provider_factory::for_policy($policy)->launch_url($policy)];
        }
        if (empty($state['configured']) || empty($state['next'])) {
            throw new \moodle_exception('Переобучение настроено некорректно: нет доступного обязательного материала.');
        }

        if ((string)$runtime->status === self::STATUS_REMEDIATION_REQUIRED) {
            $runtime->status = self::STATUS_REMEDIATION_PROGRESS;
            if (empty($runtime->remediationstartedat)) {
                $runtime->remediationstartedat = time();
            }
            $runtime->timemodified = time();
            $DB->update_record('local_ustar_assess_runtime', $runtime);
            self::event($runtime, 'assess_remediation_started', 'Сотрудник начал обязательное переобучение', [
                'cycle' => (int)$runtime->cycle,
                'remediationpointid' => (int)$runtime->remediationpointid,
                'remediationversionid' => (int)$runtime->remediationversionid,
            ]);
        }

        $next = $state['next'];
        if ((string)$next['kind'] === 'content') {
            $contentid = (int)$next['contentid'];
            learning_events::record_route_open(
                $userid,
                $contentid,
                (int)$runtime->remediationpointid,
                (int)$runtime->remediationversionid
            );
            if ((string)($next['completionmode'] ?? 'open') === 'open') {
                self::event($runtime, 'assess_content_opened', 'Нативный материал повторно открыт для переобучения', [
                    'contentid' => $contentid,
                    'cycle' => (int)$runtime->cycle,
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

    public static function validate_remediation_context(int $userid, array $context): \stdClass {
        global $DB;
        $runtimeid = (int)($context['runtimeid'] ?? 0);
        if ($runtimeid <= 0) {
            throw new \invalid_parameter_exception('Нет контекста переобучения.');
        }
        $runtime = $DB->get_record('local_ustar_assess_runtime', ['id' => $runtimeid, 'userid' => $userid], '*', MUST_EXIST);
        if (!in_array((string)$runtime->status, [self::STATUS_REMEDIATION_REQUIRED, self::STATUS_REMEDIATION_PROGRESS], true)) {
            throw new \moodle_exception('Переобучение уже не активно.');
        }
        if ((int)$runtime->remediationpointid !== (int)($context['pointid'] ?? 0)
            || (int)$runtime->remediationversionid !== (int)($context['versionid'] ?? 0)) {
            throw new \moodle_exception('Контекст переобучения устарел.');
        }
        return $runtime;
    }

    /** @return array<string,mixed> */
    public static function confirm_scorm_remediation(
        int $userid,
        int $runtimeid,
        int $completedat,
        int $attemptno
    ): array {
        global $DB;
        $runtime = $DB->get_record('local_ustar_assess_runtime', ['id' => $runtimeid, 'userid' => $userid], '*', MUST_EXIST);
        if ($completedat <= (int)$runtime->failurecutoff) {
            throw new \moodle_exception('Для допуска требуется новое прохождение материала после неудачной аттестации.');
        }
        self::event($runtime, 'assess_scorm_completed', 'Повторное прохождение legacy SCORM подтверждено', [
            'cycle' => (int)$runtime->cycle,
            'attempt' => $attemptno,
            'completedat' => $completedat,
            'remediationpointid' => (int)$runtime->remediationpointid,
            'remediationversionid' => (int)$runtime->remediationversionid,
        ]);

        $policy = $DB->get_record('local_ustar_assess_policy', ['id' => (int)$runtime->policyid], '*', MUST_EXIST);
        $resolved = structure::resolve_user($userid);
        $positionid = (string)($resolved['position']['id'] ?? '');
        $runtime = self::sync_policy_user($policy, $userid, $positionid, true);
        return self::route_view($runtime, $policy, $positionid);
    }

    /** @return array{alerts:array<int,array<string,mixed>>,count:int} */
    public static function manager_alerts(int $managerid): array {
        global $DB;
        if (!self::available() || $managerid <= 0) {
            return ['alerts' => [], 'count' => 0];
        }

        // Reconcile direct reports from provider facts so an auto-graded third
        // failure is visible to the manager even if the employee has not opened
        // the route page again yet.
        $reports = $DB->get_records('local_ustar_reporting', ['managerid' => $managerid], 'userid ASC');
        $allpolicies = $DB->get_records('local_ustar_assess_policy', ['active' => 1], 'id ASC');
        $policies = [];
        foreach ($allpolicies as $policy) {
            $currentversion = route_model::current_published_version((int)$policy->pointid);
            if ($currentversion && (int)$currentversion->id === (int)$policy->versionid) {
                $policies[] = $policy;
            }
        }
        foreach ($reports as $report) {
            $userid = (int)$report->userid;
            $resolved = structure::resolve_user($userid);
            $positionid = (string)($resolved['position']['id'] ?? '');
            if ($positionid === '') {
                continue;
            }
            foreach ($policies as $policy) {
                try {
                    self::sync_policy_user($policy, $userid, $positionid, false);
                } catch (\Throwable $e) {
                    debugging('USTAR assessment manager reconciliation failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
                }
            }
        }

        [$insql, $params] = $DB->get_in_or_equal([
            self::STATUS_MANAGER_REVIEW_REQUIRED,
            self::STATUS_HRD_REVIEW_REQUIRED,
            self::STATUS_REMEDIATION_REQUIRED,
            self::STATUS_REMEDIATION_PROGRESS,
            self::STATUS_EXHAUSTED,
        ], SQL_PARAMS_NAMED, 'as');
        $params['managerid'] = $managerid;
        $runtimes = $DB->get_records_select(
            'local_ustar_assess_runtime',
            "managerid=:managerid AND status {$insql}",
            $params,
            'timemodified DESC,id DESC'
        );

        $alerts = [];
        foreach ($runtimes as $runtime) {
            $user = $DB->get_record('user', ['id' => (int)$runtime->userid, 'deleted' => 0], 'id,firstname,lastname', IGNORE_MISSING);
            $policy = $DB->get_record('local_ustar_assess_policy', ['id' => (int)$runtime->policyid, 'active' => 1], '*', IGNORE_MISSING);
            $version = $DB->get_record('local_ustar_route_versions', ['id' => (int)$runtime->versionid], 'id,title', IGNORE_MISSING);
            if (!$user || !$policy || !$version) {
                continue;
            }
            $currentversion = route_model::current_published_version((int)$runtime->pointid);
            if (!$currentversion || (int)$currentversion->id !== (int)$runtime->versionid) {
                continue;
            }
            $resolved = structure::resolve_user((int)$runtime->userid);
            $positionid = (string)($resolved['position']['id'] ?? '');
            if ($positionid === '' || !self::policy_applies_to_user($policy, (int)$runtime->userid, $positionid)) {
                continue;
            }
            $currentmanagerid = self::direct_manager_id((int)$runtime->userid);
            if ($currentmanagerid !== $managerid) {
                continue;
            }

            $provider = assessment_provider_factory::for_policy($policy);
            $state = $provider->inspect((int)$runtime->userid, $policy);
            $position = (string)($resolved['position']['name'] ?? $resolved['position']['title'] ?? '—');

            $statuskey = 'warning';
            $statuslabel = 'Требуется переобучение';
            if ((string)$runtime->status === self::STATUS_MANAGER_REVIEW_REQUIRED) {
                $statuskey = 'danger';
                $statuslabel = 'Ожидает решения руководителя';
            } else if ((string)$runtime->status === self::STATUS_HRD_REVIEW_REQUIRED) {
                $statuskey = 'danger';
                $statuslabel = 'Эскалировано HRD · решение HRD';
            } else if ((string)$runtime->status === self::STATUS_REMEDIATION_PROGRESS) {
                $statuskey = 'info';
                $statuslabel = 'На обязательном переобучении';
            } else if ((string)$runtime->status === self::STATUS_EXHAUSTED) {
                $statuskey = 'danger';
                $statuslabel = 'Попытки исчерпаны · требуется HRD';
            }

            $alerts[] = [
                'userid' => (int)$runtime->userid,
                'fullname' => fullname($user),
                'position' => $position,
                'assessment' => format_string((string)$version->title),
                'attempts' => (int)$state['totalattempts'],
                'attemptlimit' => max(1, (int)$runtime->cycle) * max(1, (int)$policy->attemptspercycle),
                'bestscore' => self::score((float)$state['bestscore']),
                'maxscore' => self::score((float)$state['maxscore'], 0),
                'passscore' => self::score((float)$state['passscore'], 1),
                'statuskey' => $statuskey,
                'statuslabel' => $statuslabel,
                'runtimeid' => (int)$runtime->id,
                'canmanagerauthorize' => (string)$runtime->status === self::STATUS_MANAGER_REVIEW_REQUIRED,
                'detailurl' => (new \moodle_url('/local/ustar/team_learning.php', ['userid' => (int)$runtime->userid]))->out(false),
            ];
        }

        return ['alerts' => $alerts, 'count' => count($alerts)];
    }

    /** @return array{alerts:array<int,array<string,mixed>>,count:int} */
    public static function hrd_alerts(int $viewerid): array {
        global $DB;

        $context = \context_system::instance();
        $allowed = is_siteadmin($viewerid)
            || has_capability('local/ustar:hrmanage', $context, $viewerid)
            || has_capability('local/ustar:admin', $context, $viewerid);
        if (!$allowed || !self::available()) {
            return ['alerts' => [], 'count' => 0];
        }

        $existing = $DB->get_records('local_ustar_assess_runtime', [], 'id ASC');
        foreach ($existing as $existingruntime) {
            $policy = $DB->get_record('local_ustar_assess_policy', [
                'id' => (int)$existingruntime->policyid,
                'active' => 1,
            ], '*', IGNORE_MISSING);
            if (!$policy) {
                continue;
            }

            $currentversion = route_model::current_published_version((int)$policy->pointid);
            if (!$currentversion || (int)$currentversion->id !== (int)$policy->versionid) {
                continue;
            }

            $resolved = structure::resolve_user((int)$existingruntime->userid);
            $positionid = (string)($resolved['position']['id'] ?? '');
            if ($positionid === '') {
                continue;
            }

            try {
                self::sync_policy_user($policy, (int)$existingruntime->userid, $positionid, false);
            } catch (\Throwable $e) {
                debugging('USTAR assessment HRD reconciliation failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        [$insql, $params] = $DB->get_in_or_equal([
            self::STATUS_HRD_REVIEW_REQUIRED,
            self::STATUS_EXHAUSTED,
        ], SQL_PARAMS_NAMED, 'hs');

        $runtimes = $DB->get_records_select(
            'local_ustar_assess_runtime',
            "status {$insql}",
            $params,
            'timemodified DESC,id DESC'
        );

        $alerts = [];
        foreach ($runtimes as $runtime) {
            $user = $DB->get_record('user', [
                'id' => (int)$runtime->userid,
                'deleted' => 0,
            ], 'id,firstname,lastname', IGNORE_MISSING);
            $policy = $DB->get_record('local_ustar_assess_policy', [
                'id' => (int)$runtime->policyid,
                'active' => 1,
            ], '*', IGNORE_MISSING);
            $version = $DB->get_record('local_ustar_route_versions', [
                'id' => (int)$runtime->versionid,
            ], 'id,title', IGNORE_MISSING);
            if (!$user || !$policy || !$version) {
                continue;
            }

            $currentversion = route_model::current_published_version((int)$runtime->pointid);
            if (!$currentversion || (int)$currentversion->id !== (int)$runtime->versionid) {
                continue;
            }

            $resolved = structure::resolve_user((int)$runtime->userid);
            $positionid = (string)($resolved['position']['id'] ?? '');
            if ($positionid === '' || !self::policy_applies_to_user($policy, (int)$runtime->userid, $positionid)) {
                continue;
            }

            $provider = assessment_provider_factory::for_policy($policy);
            $state = $provider->inspect((int)$runtime->userid, $policy);
            $status = (string)$runtime->status;

            $alerts[] = [
                'userid' => (int)$runtime->userid,
                'fullname' => fullname($user),
                'position' => (string)($resolved['position']['name'] ?? $resolved['position']['title'] ?? '—'),
                'assessment' => format_string((string)$version->title),
                'attempts' => (int)$state['totalattempts'],
                'attemptlimit' => max(1, (int)$runtime->cycle) * max(1, (int)$policy->attemptspercycle),
                'bestscore' => self::score((float)$state['bestscore']),
                'maxscore' => self::score((float)$state['maxscore'], 0),
                'passscore' => self::score((float)$state['passscore'], 1),
                'statuskey' => $status === self::STATUS_HRD_REVIEW_REQUIRED ? 'danger' : 'warning',
                'statuslabel' => $status === self::STATUS_HRD_REVIEW_REQUIRED
                    ? 'Ожидает решения HRD'
                    : 'Все циклы аттестации исчерпаны',
                'runtimeid' => (int)$runtime->id,
                'canhrdauthorize' => $status === self::STATUS_HRD_REVIEW_REQUIRED,
            ];
        }

        return ['alerts' => $alerts, 'count' => count($alerts)];
    }

    /**
     * Carry lifecycle policy to a new version of the same logical assessment.
     *
     * Route versions are immutable. A policy is therefore version-bound too.
     * We clone policy only when the new version still contains a recognised
     * assessment provider; normal learning points are ignored.
     */
    public static function inherit_policy_for_version(
        ?\stdClass $previous,
        \stdClass $created,
        int $actorid
    ): void {
        global $DB;

        if (!self::available() || !$previous || (int)$created->id <= 0) {
            return;
        }
        if ($DB->record_exists('local_ustar_assess_policy', ['versionid' => (int)$created->id])) {
            return;
        }

        $oldpolicy = $DB->get_record('local_ustar_assess_policy', [
            'versionid' => (int)$previous->id,
            'active' => 1,
        ]);
        if (!$oldpolicy) {
            // Draft chains may contain intermediate versions created before a
            // policy editor/save. Inherit from the nearest earlier version that
            // actually owns a policy rather than silently dropping lifecycle.
            $oldpolicy = $DB->get_record_sql(
                "SELECT p.*
                   FROM {local_ustar_assess_policy} p
                   JOIN {local_ustar_route_versions} v ON v.id = p.versionid
                  WHERE p.pointid = :pointid
                    AND p.active = 1
                    AND v.versionno < :versionno
               ORDER BY v.versionno DESC, v.id DESC",
                [
                    'pointid' => (int)$created->pointid,
                    'versionno' => (int)$created->versionno,
                ],
                IGNORE_MULTIPLE
            );
        }
        if (!$oldpolicy) {
            return;
        }

        $provider = self::provider_config_for_version($created);
        if (!$provider) {
            // A provider-type migration (for example Moodle Quiz -> future
            // native USTAR Assessment) must be configured explicitly instead
            // of silently inheriting an incompatible technical reference.
            return;
        }

        $assessmentpoint = $DB->get_record(
            'local_ustar_route_points',
            ['id' => (int)$created->pointid, 'active' => 1],
            'id,routeid',
            MUST_EXIST
        );
        $remediationpointid = (int)($oldpolicy->remediationpointid ?? 0);
        if ($remediationpointid > 0) {
            $remediationpoint = $DB->get_record(
                'local_ustar_route_points',
                ['id' => $remediationpointid, 'active' => 1],
                'id,routeid',
                IGNORE_MISSING
            );
            if (!$remediationpoint || (int)$remediationpoint->routeid !== (int)$assessmentpoint->routeid) {
                $remediationpointid = 0;
            }
        }

        $now = time();
        $DB->insert_record('local_ustar_assess_policy', (object)[
            'pointid' => (int)$created->pointid,
            'versionid' => (int)$created->id,
            'providerkind' => (string)$provider['kind'],
            'providerref' => (string)$provider['ref'],
            'attemptspercycle' => max(1, (int)$oldpolicy->attemptspercycle),
            'maxcycles' => max(1, (int)$oldpolicy->maxcycles),
            'remediationpointid' => $remediationpointid > 0 ? $remediationpointid : null,
            'cycle1escalation' => (string)$oldpolicy->cycle1escalation,
            'cycle2escalation' => (string)$oldpolicy->cycle2escalation,
            'active' => 1,
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => max(0, $actorid),
        ]);
    }

    /** @return array{kind:string,ref:string}|null */
    private static function provider_config_for_version(\stdClass $version): ?array {
        global $DB;
        foreach (route_model::requirements_for_version($version) as $requirement) {
            if ((string)($requirement['type'] ?? '') !== 'cm' || empty($requirement['required'])) {
                continue;
            }
            $cmid = (int)($requirement['sourceid'] ?? 0);
            $cm = $DB->get_record(
                'course_modules',
                ['id' => $cmid, 'deletioninprogress' => 0],
                'id,module',
                IGNORE_MISSING
            );
            if (!$cm) {
                continue;
            }
            $modname = (string)$DB->get_field('modules', 'name', ['id' => (int)$cm->module]);
            if ($modname === 'quiz') {
                return ['kind' => 'moodle_quiz', 'ref' => 'cm:' . $cmid];
            }
        }
        return null;
    }

    private static function policy_applies_to_user(\stdClass $policy, int $userid, string $positionid): bool {
        global $DB;
        $point = $DB->get_record('local_ustar_route_points', ['id' => (int)$policy->pointid, 'active' => 1], 'id,routeid', IGNORE_MISSING);
        if (!$point) {
            return false;
        }
        $route = $DB->get_record('local_ustar_routes', ['id' => (int)$point->routeid, 'active' => 1], '*', IGNORE_MISSING);
        if (!$route) {
            return false;
        }
        if ((string)($route->routekind ?? '') === route_family::KIND_PARENT && route_scope::available()) {
            return route_scope::point_applies((int)$point->id, $positionid);
        }
        return (string)($route->positionid ?? '') === $positionid;
    }

    private static function direct_manager_id(int $userid): int {
        global $DB;
        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_ustar_reporting'))) {
            return 0;
        }
        return (int)($DB->get_field('local_ustar_reporting', 'managerid', ['userid' => $userid]) ?: 0);
    }

    /** @param array<string,mixed> $providerstate */
    private static function cycle_cutoff(array $providerstate, int $limit): int {
        if (!empty($providerstate['finalized'][$limit]['finalizedat'])) {
            return (int)$providerstate['finalized'][$limit]['finalizedat'];
        }
        if (!empty($providerstate['finalized'][$limit]['timefinish'])) {
            return (int)$providerstate['finalized'][$limit]['timefinish'];
        }
        return max(1, (int)($providerstate['lastattemptat'] ?? time()));
    }

    /** @param array<string,mixed> $providerstate */
    private static function escalate_for_cycle(\stdClass $runtime, \stdClass $policy, int $cycle, array $providerstate): void {
        $target = $cycle === 1
            ? (string)$policy->cycle1escalation
            : (string)$policy->cycle2escalation;
        $now = time();

        if ($target === 'direct_manager' && empty($runtime->managerescalatedat)) {
            if (empty($runtime->managerid)) {
                $runtime->managerid = self::direct_manager_id((int)$runtime->userid) ?: null;
            }
            if (!empty($runtime->managerid)) {
                $runtime->managerescalatedat = $now;
                self::event($runtime, 'assess_manager_escalated', 'Руководителю передана эскалация по аттестации', [
                    'cycle' => $cycle,
                    'managerid' => (int)$runtime->managerid,
                    'attempts' => (int)$providerstate['totalattempts'],
                    'bestscore' => (float)$providerstate['bestscore'],
                    'passscore' => (float)$providerstate['passscore'],
                ]);
            }
        } else if ($target === 'hrd' && empty($runtime->hrdescalatedat)) {
            $runtime->hrdescalatedat = $now;
            self::event($runtime, 'assess_hrd_escalated', 'Аттестация эскалирована HRD', [
                'cycle' => $cycle,
                'attempts' => (int)$providerstate['totalattempts'],
                'bestscore' => (float)$providerstate['bestscore'],
                'passscore' => (float)$providerstate['passscore'],
            ]);
        }
    }

    /** @param array<string,mixed> $details */
    private static function event(\stdClass $runtime, string $eventtype, string $reason, array $details, int $actorid = 0): int {
        global $DB;
        return (int)$DB->insert_record('local_ustar_workflow_events', (object)[
            'entitytype' => 'assessment_runtime',
            'entityid' => (int)$runtime->id,
            'eventtype' => $eventtype,
            'actorid' => $actorid > 0 ? $actorid : (int)$runtime->userid,
            'reason' => $reason,
            'detailsjson' => json_encode(array_merge([
                'userid' => (int)$runtime->userid,
                'pointid' => (int)$runtime->pointid,
                'versionid' => (int)$runtime->versionid,
                'policyid' => (int)$runtime->policyid,
            ], $details), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'timecreated' => time(),
        ]);
    }

    private static function score(float $value, int $decimals = 1): string {
        return number_format($value, $decimals, ',', '');
    }
}

