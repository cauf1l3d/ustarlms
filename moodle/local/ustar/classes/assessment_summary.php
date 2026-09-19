<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();

/** Read-only assessment projection; no lifecycle sync, role/enrolment writes or attempt unlocks. */
final class assessment_summary {
    public static function for_employee(int $viewerid, int $userid, array $points): array {
        global $DB;
        if (!department_learning::can_view($viewerid, $userid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/ustar:viewteam', 'nopermissions', '');
        }
        $result = [];
        foreach ($points as $point) {
            $versionid = (int)($point['versionid'] ?? 0);
            if ($versionid <= 0) { continue; }
            $version = $DB->get_record('local_ustar_route_versions', ['id' => $versionid], '*', MUST_EXIST);
            $policy = assessment_lifecycle::policy_for_version($versionid);
            $seen = [];
            foreach (route_model::requirements_for_version($version) as $requirement) {
                if (($requirement['type'] ?? '') !== 'cm') { continue; }
                $cmid = (int)($requirement['sourceid'] ?? 0);
                if ($cmid <= 0 || isset($seen[$cmid])) { continue; }
                $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, IGNORE_MISSING);
                if (!$cm) { continue; }
                $seen[$cmid] = true;
                $managed = $policy && $policy->providerkind === 'moodle_quiz'
                    && $policy->providerref === 'cm:'.$cmid;
                $querypolicy = $managed ? $policy : (object)[
                    'providerkind' => 'moodle_quiz', 'providerref' => 'cm:'.$cmid,
                    'versionid' => $versionid, 'pointid' => (int)$point['id'],
                ];
                try {
                    $state = (new moodle_quiz_assessment_provider())->inspect($userid, $querypolicy);
                    $runtime = $managed ? $DB->get_record('local_ustar_assess_runtime', [
                        'userid' => $userid, 'pointid' => (int)$point['id'], 'versionid' => $versionid,
                    ]) : false;
                    $row = self::present($state, $runtime ?: null, $managed ? $policy : null);
                    $row['title'] = (string)$point['title'];
                    $row['quizname'] = format_string((string)$cm->name);
                    $row['versionlabel'] = 'Версия точки '.(int)$version->versionno;
                    $result[] = $row;
                } catch (\Throwable $e) {
                    debugging('USTAR assessment summary unavailable: '.get_class($e), DEBUG_DEVELOPER);
                    $result[] = ['title' => (string)$point['title'], 'quizname' => format_string((string)$cm->name),
                        'versionlabel' => 'Версия точки '.(int)$version->versionno,
                        'statuslabel' => 'Результаты временно недоступны', 'error' => true];
                }
            }
        }
        return $result;
    }

    /** Presentation of facts only. History before cutoff is labelled, never assigned an invented version. */
    public static function present(array $state, ?\stdClass $runtime, ?\stdClass $policy): array {
        $configured = !array_key_exists('configured', $state) || $state['configured'];
        $label = !$configured ? 'Проверьте проходной балл и шкалу оценки'
            : (!empty($state['passed']) ? 'Пройдена'
            : ((int)$state['pendingattempts'] > 0 ? 'Ожидает проверки'
            : ((int)$state['totalattempts'] > 0 ? 'Не пройдена' : 'Не начата')));
        if ($configured && empty($state['passed']) && empty($state['pendingattempts'])
                && !empty($state['inprogressattempts'])) { $label = 'В процессе'; }
        $history = array_reverse($state['history'] ?? []);
        $latest = null;
        foreach ($history as $item) { if (!empty($item['eligible'])) { $latest = $item; break; } }
        $rows = [];
        foreach (array_slice($history, 0, 50) as $item) {
            $pending = $item['score'] === null;
            $rows[] = [
                'number' => (int)$item['attemptno'],
                'date' => self::date((int)$item['timefinish']),
                'score' => $pending ? 'Ожидает проверки' : self::number((float)$item['score']).' / '.self::number((float)$state['maxscore']),
                'result' => empty($item['eligible']) ? 'До обязательного обновления; не учитывается'
                    : ($pending ? 'Ожидает проверки' : (!$configured ? 'Порог не настроен'
                    : ((float)$item['score'] + 0.000001 >= (float)$state['passscore'] ? 'Пройдена' : 'Не пройдена'))),
            ];
        }
        $cycle = max(1, (int)($runtime->cycle ?? 1));
        $percycle = $policy ? max(1, (int)$policy->attemptspercycle) : 0;
        $workflow = '';
        if ($runtime && empty($state['passed'])) {
            $workflow = match ((string)$runtime->status) {
                'manager_review_required' => 'Требуется решение руководителя',
                'hrd_review_required' => 'Требуется решение HRD',
                'remediation_required' => 'Переобучение назначено',
                'remediation_in_progress' => 'Переобучение в процессе',
                'reopened' => 'Переобучение завершено; открыт следующий цикл',
                'exhausted' => 'Разрешённые циклы исчерпаны',
                default => '',
            };
        }
        return [
            'statuslabel' => $label, 'error' => false,
            'lastscore' => !$latest ? 'Нет попытки' : ($latest['score'] === null ? 'Ожидает проверки'
                : self::number((float)$latest['score']).' / '.self::number((float)$state['maxscore'])),
            'lastdate' => $latest ? self::date((int)$latest['timefinish']) : '—',
            'bestscore' => (int)$state['finalizedattempts'] > 0 ? self::number((float)$state['bestscore']).' / '.self::number((float)$state['maxscore']) : '—',
            'passscore' => $configured ? self::number((float)$state['passscore']) : 'Не настроен',
            'attemptslabel' => $policy ? 'Цикл '.$cycle.': '.max(0, (int)$state['totalattempts'] - ($cycle - 1)*$percycle).' из '.$percycle.' попыток'
                : 'Завершённых попыток: '.(int)$state['totalattempts'],
            'workflow' => $workflow,
            'history' => $rows, 'hashistory' => !empty($rows),
            'historylimited' => count($history) > 50,
            'cutofflabel' => !empty($state['cutoff']) ? 'Учитываются попытки, начатые с '.self::date((int)$state['cutoff']) : '',
        ];
    }
    private static function number(float $value): string { return rtrim(rtrim(number_format($value, 2, ',', ''), '0'), ','); }
    private static function date(int $timestamp): string { return $timestamp > 0 ? userdate($timestamp, '%d.%m.%Y %H:%M') : '—'; }
}
