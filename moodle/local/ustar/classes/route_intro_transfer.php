<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** Copy six selected published introduction points as unpublished drafts. */
final class route_intro_transfer {
    /** Read-only inventory and dependency validation. Never completes or publishes learning. */
    public static function preview(int $sourceid, int $targetid, array $pointids): array {
        global $DB;
        $source = $DB->get_record('local_ustar_routes', ['id' => $sourceid, 'active' => 1], '*', MUST_EXIST);
        $target = $DB->get_record('local_ustar_routes', ['id' => $targetid, 'active' => 1], '*', MUST_EXIST);
        if ($sourceid === $targetid) {
            throw new \invalid_parameter_exception('Выберите другой маршрут для переноса');
        }
        if ((string)$target->routekind === 'position' && !empty($target->familyid)) {
            throw new \invalid_parameter_exception('Для семьи выберите её общий маршрут');
        }
        $pointids = array_map('intval', $pointids);
        if (count($pointids) !== 6 || count(array_unique($pointids)) !== 6 || min($pointids) <= 0) {
            throw new \invalid_parameter_exception('Выберите ровно шесть разных опубликованных шагов');
        }
        $selected = array_fill_keys($pointids, true);
        $rows = [];
        $nativekeys = [];
        $skills = structure::get(structure::NAME_STRUCTURE)['skills'] ?? [];
        $skillids = array_column($skills, 'id');
        $nativeids = array_column(native_learning::authoring_options(), 'id');
        foreach (route_model::points($sourceid) as $point) {
            if (!isset($selected[(int)$point->id])) { continue; }
            $version = route_model::current_published_version((int)$point->id);
            if (!$version) {
                throw new \moodle_exception('У выбранного шага нет действующей опубликованной версии');
            }
            $requirements = route_model::requirements_for_version($version);
            if (!$requirements) {
                throw new \moodle_exception('Опубликованный шаг без условий нельзя перенести');
            }
            $issues = [];
            foreach ($requirements as $requirement) {
                $kind = (string)($requirement['type'] ?? '');
                $id = (int)($requirement['sourceid'] ?? 0);
                $key = (string)($requirement['sourcekey'] ?? '');
                if ($kind === 'native') {
                    if (!in_array($key, $nativeids, true) || isset($nativekeys[$key])) {
                        $issues[] = 'Нативная активность недоступна или повторяется: ' . $key;
                    }
                    $nativekeys[$key] = true;
                } else if ($kind === 'content') {
                    $item = $DB->get_record('local_ustar_content', ['id' => $id], 'id,status,type');
                    if (!$item || (string)$item->status !== content::STATUS_PUBLISHED || (string)$item->type === 'folder') {
                        $issues[] = 'Материал не опубликован: ' . $id;
                    }
                } else if ($kind === 'course') {
                    if (!$DB->record_exists('course', ['id' => $id])) { $issues[] = 'Курс недоступен: ' . $id; }
                } else if ($kind === 'cm') {
                    if (!$DB->record_exists('course_modules', ['id' => $id, 'deletioninprogress' => 0])) {
                        $issues[] = 'Moodle-активность недоступна: ' . $id;
                    }
                } else if ($kind === 'assessment') {
                    if (!development_assessment::published($key)) { $issues[] = 'Профиль не опубликован: ' . $key; }
                } else if ($kind === 'skill') {
                    if (!in_array($key, $skillids, true)) { $issues[] = 'Навык отсутствует: ' . $key; }
                } else if ($kind !== 'previous_adaptation') {
                    $issues[] = 'Неизвестный тип требования: ' . $kind;
                }
            }
            $copykey = 'intro_' . substr(sha1($sourceid . ':' . (int)$point->id), 0, 18);
            $existing = route_model::find_point($targetid, $copykey);
            if ($existing && empty($existing->active)) {
                $issues[] = 'Перенесённый ранее шаг архивирован. Возобновите его вручную.';
            }
            $rows[] = ['pointid' => (int)$point->id, 'versionid' => (int)$version->id,
                'title' => (string)$version->title, 'phase' => (string)$point->phase,
                'summary' => (string)$version->summary, 'requirements' => $requirements,
                'renewalpolicy' => (string)$version->renewalpolicy,
                'validdays' => (int)$version->validdays, 'copykey' => $copykey,
                'existing' => $existing ? (int)$existing->id : 0, 'issues' => $issues];
        }
        if (count($rows) !== 6) {
            throw new \invalid_parameter_exception('Один или несколько шагов не принадлежат исходному маршруту');
        }
        $fingerprint = hash('sha256', $sourceid . ':' . $targetid . ':' . implode(',', array_map(
            static fn(array $row): string => $row['pointid'] . '/' . $row['versionid'], $rows)));
        return ['source' => $source, 'target' => $target, 'rows' => $rows, 'fingerprint' => $fingerprint,
            'ready' => !array_filter($rows, static fn(array $row): bool => !empty($row['issues']))];
    }

    /** All six drafts are inserted in a single transaction under the target route lock. */
    public static function create_drafts(int $sourceid, int $targetid, array $pointids,
            string $expected, int $actorid): array {
        global $DB;
        $factory = \core\lock\lock_config::get_lock_factory('local_ustar_routes');
        $lock = $factory->get_lock('route:' . $targetid, 10);
        if (!$lock) { throw new \moodle_exception('Маршрут занят. Повторите попытку.'); }
        try {
            $transaction = $DB->start_delegated_transaction();
            try {
                $preview = self::preview($sourceid, $targetid, $pointids);
                if (!hash_equals($preview['fingerprint'], $expected) || !$preview['ready']) {
                    throw new \moodle_exception('Источник изменился или содержит недоступные материалы. Обновите предпросмотр.');
                }
                $sort = 0;
                foreach (route_model::points($targetid) as $point) {
                    $sort = max($sort, (int)$point->sortorder);
                }
                $created = [];
                foreach ($preview['rows'] as $row) {
                    if ($row['existing']) { $created[] = $row['existing']; continue; }
                    $sort += 10;
                    $point = route_model::add_point($targetid, $row['copykey'], $row['phase'], $sort,
                        ['title' => $row['title'], 'summary' => $row['summary'],
                            'requirements' => $row['requirements'], 'renewalpolicy' => $row['renewalpolicy'],
                            'validdays' => $row['validdays'], 'status' => route_model::STATUS_DRAFT,
                            'effectivedate' => 0], $actorid);
                    if (route_scope::available() && (string)$preview['target']->routekind === 'parent') {
                        $DB->insert_record('local_ustar_route_scope', (object)[
                            'pointid' => (int)$point->id, 'scopeid' => route_scope::ALL,
                            'state' => route_scope::CONFIRMED, 'sourcekind' => 'manual',
                            'reason' => 'Перенос вводного блока из маршрута ' . $sourceid,
                            'active' => 1, 'timecreated' => time(), 'timemodified' => time(),
                            'usermodified' => $actorid]);
                    }
                    $created[] = (int)$point->id;
                }
                $transaction->allow_commit();
                return $created;
            } catch (\Throwable $e) { $transaction->rollback($e); }
        } finally { $lock->release(); }
        throw new \coding_exception('Не удалось перенести вводный блок');
    }
}
