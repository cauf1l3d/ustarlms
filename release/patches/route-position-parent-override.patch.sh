#!/bin/bash

docker exec -i ustar_moodle php <<'PHP'

<<'PHP'
<?php

$studioFile = '/var/www/html/public/local/ustar/route_studio.php';
$scopeFile = '/var/www/html/public/local/ustar/classes/route_scope.php';
$templateFile = '/var/www/html/public/local/ustar/templates/route_studio.mustache';

$stamp = date('Ymd_His');

foreach ([$studioFile, $scopeFile, $templateFile] as $file) {
    if (!is_file($file)) {
        throw new RuntimeException("FILE NOT FOUND: {$file}");
    }

    if (!copy($file, $file . '.bak_scope_override_' . $stamp)) {
        throw new RuntimeException("BACKUP FAILED: {$file}");
    }
}

function replaceOnce(string $text, string $old, string $new, string $label): string {
    $count = substr_count($text, $old);

    if ($count !== 1) {
        throw new RuntimeException(
            $label . ': expected exactly 1 match, found ' . $count
        );
    }

    return str_replace($old, $new, $text);
}

function replaceBetween(
    string $text,
    string $startMarker,
    string $endMarker,
    string $replacement,
    string $label
): string {
    $start = strpos($text, $startMarker);

    if ($start === false) {
        throw new RuntimeException($label . ': start marker not found');
    }

    $end = strpos($text, $endMarker, $start);

    if ($end === false) {
        throw new RuntimeException($label . ': end marker not found');
    }

    return
        substr($text, 0, $start)
        . $replacement
        . substr($text, $end);
}

/*
 * ============================================================
 * 1. route_scope.php
 * ============================================================
 */

$scope = file_get_contents($scopeFile);

$scope = replaceOnce(
    $scope,
    "    public const CONFIRMED = 'confirmed';",
    "    public const CONFIRMED = 'confirmed';\n    public const REVERTED = 'reverted';",
    'route_scope constants'
);

/*
 * If a confirmed position override exists for a source point,
 * the source point must disappear only for that position.
 */
$scope = replaceOnce(
    $scope,
<<<'OLD'
        if (!self::available()) {
            return true;
        }

        $rows = $DB->get_records(
OLD,
<<<'NEW'
        if (!self::available()) {
            return true;
        }

        /*
         * TARGET position override:
         * an override is another physical point in the same parent route,
         * scoped only to one position and linked through sourcepointid.
         *
         * When such an override is confirmed for this position,
         * suppress the source point for this position only.
         */
        $sourcepoint = $DB->get_record(
            'local_ustar_route_points',
            ['id' => $pointid],
            'id,routeid',
            IGNORE_MISSING
        );

        if ($sourcepoint) {
            $overrideexists = $DB->record_exists_sql(
                '
                    SELECT 1
                      FROM {local_ustar_route_points} op
                      JOIN {local_ustar_route_scope} os
                        ON os.pointid = op.id
                     WHERE op.routeid = :routeid
                       AND op.sourcepointid = :sourcepointid
                       AND op.inheritstate = :override
                       AND op.active = 1
                       AND os.scopeid = :positionid
                       AND os.state = :confirmed
                       AND os.active = 1
                ',
                [
                    'routeid' => (int)$sourcepoint->routeid,
                    'sourcepointid' => $pointid,
                    'override' => route_family::OVERRIDE,
                    'positionid' => $positionid,
                    'confirmed' => self::CONFIRMED,
                ]
            );

            if ($overrideexists) {
                return false;
            }
        }

        $rows = $DB->get_records(
NEW,
    'route_scope override suppression'
);

$scope = replaceOnce(
    $scope,
<<<'OLD'
    /**
     * Human-readable confirmed scope values for Route Studio.
     */
    public static function confirmed_scope_ids(int $pointid): array {
OLD,
<<<'NEW'
    /**
     * Create or update one applicability row.
     *
     * Keeping a reverted row active is intentional:
     * an active non-confirmed row prevents the legacy
     * "no rows means all" compatibility fallback.
     */
    public static function set_scope_state(
        int $pointid,
        string $scopeid,
        string $state,
        int $actorid,
        string $sourcekind = 'studio',
        string $reason = ''
    ): \stdClass {
        global $DB;

        if (!self::available()) {
            throw new \moodle_exception(
                'Таблица применимости маршрутов недоступна'
            );
        }

        $scopeid = clean_param(
            $scopeid,
            PARAM_ALPHANUMEXT
        );

        $state = clean_param(
            $state,
            PARAM_ALPHANUMEXT
        );

        if ($scopeid === '' || $state === '') {
            throw new \invalid_parameter_exception(
                'Некорректная применимость маршрута'
            );
        }

        $now = time();

        $row = $DB->get_record(
            'local_ustar_route_scope',
            [
                'pointid' => $pointid,
                'scopeid' => $scopeid,
            ],
            '*',
            IGNORE_MISSING
        );

        if ($row) {
            $row->state = $state;
            $row->sourcekind = $sourcekind;
            $row->reason = $reason;
            $row->active = 1;
            $row->timemodified = $now;
            $row->usermodified = $actorid;

            $DB->update_record(
                'local_ustar_route_scope',
                $row
            );

        } else {
            $id = $DB->insert_record(
                'local_ustar_route_scope',
                (object)[
                    'pointid' => $pointid,
                    'scopeid' => $scopeid,
                    'state' => $state,
                    'sourcekind' => $sourcekind,
                    'reason' => $reason,
                    'active' => 1,
                    'timecreated' => $now,
                    'timemodified' => $now,
                    'usermodified' => $actorid,
                ]
            );

            $row = $DB->get_record(
                'local_ustar_route_scope',
                ['id' => $id],
                '*',
                MUST_EXIST
            );
        }

        return $row;
    }

    /**
     * Human-readable confirmed scope values for Route Studio.
     */
    public static function confirmed_scope_ids(int $pointid): array {
NEW,
    'route_scope state writer'
);


/*
 * ============================================================
 * 2. route_studio.php
 * ============================================================
 */

$studio = file_get_contents($studioFile);

/*
 * Family parent and family position tabs both write to the one
 * physical parent route.
 *
 * Position routes remain membership records only.
 */
$targetStart =
<<<'START'
if (
    $familyid > 0
START;

$targetEnd =
<<<'END'

$anchor = '';
END;

$newTarget =
<<<'NEW'
if (
    $familyid > 0
) {
    $route =
        \local_ustar\route_family::parent_route(
            $familyid
        );

    if (!$route) {
        throw new moodle_exception(
            'У семьи маршрутов нет общего маршрута'
        );
    }

    if ($viewmode === 'position') {
        if ($positionid === '') {
            throw new invalid_parameter_exception(
                'Не выбрана должность'
            );
        }

        $memberexists =
            $DB->record_exists(
                'local_ustar_routes',
                [
                    'familyid' => $familyid,
                    'positionid' => $positionid,
                    'routekind' => 'position',
                    'active' => 1,
                ]
            );

        if (!$memberexists) {
            throw new invalid_parameter_exception(
                'Должность не относится к выбранной семье'
            );
        }
    }

} else {
    if ($positionid === '') {
        throw new invalid_parameter_exception(
            'Не выбрана должность'
        );
    }

    $route =
        \local_ustar\route_model::ensure_route(
            $positionid,
            $actorid
        );
    }

NEW;

$studio = replaceBetween(
    $studio,
    $targetStart,
    $targetEnd,
    $newTarget,
    'studio editing target'
);

/*
 * Every new family point gets explicit scope.
 */
$studio = replaceOnce(
    $studio,
<<<'OLD'
        $anchor =
            '#point-'
            . (int)$created->id;
OLD,
<<<'NEW'
        if (
            $familyid > 0
            &&
            \local_ustar\route_scope::available()
        ) {
            $newscopeid =
                $viewmode === 'position'
                &&
                $positionid !== ''
                ? $positionid
                : \local_ustar\route_scope::ALL;

            \local_ustar\route_scope::set_scope_state(
                (int)$created->id,
                $newscopeid,
                \local_ustar\route_scope::CONFIRMED,
                $actorid,
                'studio',
                $viewmode === 'position'
                    ? 'Создано в маршруте должности'
                    : 'Создано в общем маршруте'
            );
        }

        $anchor =
            '#point-'
            . (int)$created->id;
NEW,
    'studio add point scope'
);

/*
 * Direct editing in a position tab is safe only when the physical
 * point belongs exclusively to this position.
 *
 * Shared/common points must first become an override.
 */
$studio = replaceOnce(
    $studio,
<<<'OLD'
        if (
            (string)($point->inheritstate ?? '')
            === \local_ustar\route_family::INHERITED
        ) {
OLD,
<<<'NEW'
        if (
            $familyid > 0
            &&
            $viewmode === 'position'
        ) {
            $scopeids =
                \local_ustar\route_scope::confirmed_scope_ids(
                    $pointid
                );

            if (!$scopeids) {
                $scopeids = [
                    \local_ustar\route_scope::ALL,
                ];
            }

            $scopeids =
                array_values(
                    array_unique(
                        $scopeids
                    )
                );

            $exclusiveposition =
                count($scopeids) === 1
                &&
                (string)$scopeids[0] === $positionid;

            if (!$exclusiveposition) {
                throw new moodle_exception(
                    'Этот шаг используется несколькими должностями. '
                    . 'Сначала выберите «Изменить для этой должности».'
                );
            }
        }

        if (
            (string)($point->inheritstate ?? '')
            === \local_ustar\route_family::INHERITED
        ) {
NEW,
    'studio position save guard'
);

/*
 * Replace old materialised-child make_override.
 */
$makeStart =
"} else if (\$action === 'make_override') {";

$makeEnd =
"} else if (\$action === 'revert_parent') {";

$newMake =
<<<'NEW'
} else if ($action === 'make_override') {
    $pointid =
        required_param(
            'pointid',
            PARAM_INT
        );

    $point =
        $DB->get_record(
            'local_ustar_route_points',
            [
                'id' => $pointid,
                'routeid' => (int)$route->id,
            ],
            '*',
            MUST_EXIST
        );

    if (
        $familyid > 0
        &&
        $viewmode === 'position'
    ) {
        if ($positionid === '') {
            throw new invalid_parameter_exception(
                'Не выбрана должность'
            );
        }

        /*
         * Reuse a historical override for this position when one
         * already exists, including a previously reverted one.
         */
        $overridepoint = null;

        $candidates =
            $DB->get_records(
                'local_ustar_route_points',
                [
                    'routeid' => (int)$route->id,
                    'sourcepointid' => (int)$point->id,
                    'inheritstate' =>
                        \local_ustar\route_family::OVERRIDE,
                    'active' => 1,
                ],
                'id DESC'
            );

        foreach ($candidates as $candidate) {
            if (
                $DB->record_exists(
                    'local_ustar_route_scope',
                    [
                        'pointid' => (int)$candidate->id,
                        'scopeid' => $positionid,
                        'active' => 1,
                    ]
                )
            ) {
                $overridepoint = $candidate;
                break;
            }
        }

        if (!$overridepoint) {
            $latest =
                \local_ustar\route_model::latest_version(
                    (int)$point->id
                );

            $requirements =
                $latest
                ? \local_ustar\route_model::requirements_for_version(
                    $latest
                )
                : [];

            $versiondata = [
                'title' =>
                    $latest
                    ? (string)$latest->title
                    : 'Точка маршрута',

                'summary' =>
                    $latest
                    ? (string)$latest->summary
                    : '',

                'requirements' =>
                    $requirements,

                'renewalpolicy' =>
                    $latest
                    ? (string)$latest->renewalpolicy
                    : \local_ustar\route_model::RENEW_KEEP,

                'validdays' =>
                    $latest
                    ? (int)$latest->validdays
                    : 0,

                'status' =>
                    $latest
                    ? (string)$latest->status
                    : \local_ustar\route_model::STATUS_DRAFT,

                'effectivedate' =>
                    $latest
                    ? (int)$latest->effectivedate
                    : 0,
            ];

            $overridepoint =
                \local_ustar\route_model::add_point(
                    (int)$route->id,
                    'override_'
                        . substr(
                            sha1(
                                (int)$point->id
                                . ':'
                                . $positionid
                                . ':'
                                . microtime(true)
                            ),
                            0,
                            12
                        ),
                    (string)$point->phase,
                    (int)$point->sortorder,
                    $versiondata,
                    $actorid
                );

            $overridepoint->sourcepointid =
                (int)$point->id;

            $overridepoint->inheritstate =
                \local_ustar\route_family::OVERRIDE;

            $overridepoint->sourceversionid =
                $latest
                ? (int)$latest->id
                : null;

            $overridepoint->timemodified =
                max(
                    time(),
                    (int)$overridepoint->timemodified + 1
                );

            $overridepoint->usermodified =
                $actorid;

            $DB->update_record(
                'local_ustar_route_points',
                $overridepoint
            );
        }

        \local_ustar\route_scope::set_scope_state(
            (int)$overridepoint->id,
            $positionid,
            \local_ustar\route_scope::CONFIRMED,
            $actorid,
            'studio_override',
            'Переопределение для должности'
        );

        $anchor =
            '#point-'
            . (int)$overridepoint->id;

        $editafter =
            (int)$overridepoint->id;

    } else {
        /*
         * Preserve the old mechanism outside TARGET family position
         * mode for backward compatibility.
         */
        \local_ustar\route_family::make_override(
            (int)$point->id,
            $actorid
        );

        $anchor =
            '#point-'
            . $pointid;

        $editafter =
            $pointid;
    }

NEW;

$studio = replaceBetween(
    $studio,
    $makeStart,
    $makeEnd,
    $newMake,
    'studio make override'
);

/*
 * Replace old materialised-child revert.
 */
$revertStart =
"} else if (\$action === 'revert_parent') {";

$revertEnd =
"} else if (\$action === 'archive_point') {";

$newRevert =
<<<'NEW'
} else if ($action === 'revert_parent') {
    $pointid =
        required_param(
            'pointid',
            PARAM_INT
        );

    $point =
        $DB->get_record(
            'local_ustar_route_points',
            [
                'id' => $pointid,
                'routeid' => (int)$route->id,
            ],
            '*',
            MUST_EXIST
        );

    if (
        $familyid > 0
        &&
        $viewmode === 'position'
    ) {
        if (
            (int)($point->sourcepointid ?? 0) <= 0
            ||
            (string)($point->inheritstate ?? '')
                !== \local_ustar\route_family::OVERRIDE
        ) {
            throw new moodle_exception(
                'Эта точка не является переопределением должности'
            );
        }

        \local_ustar\route_scope::set_scope_state(
            (int)$point->id,
            $positionid,
            \local_ustar\route_scope::REVERTED,
            $actorid,
            'studio_override',
            'Возврат к общему варианту'
        );

        $anchor =
            '#point-'
            . (int)$point->sourcepointid;

    } else {
        \local_ustar\route_family::revert_to_parent(
            (int)$point->id,
            $actorid
        );

        $anchor =
            '#point-'
            . $pointid;
    }

NEW;

$studio = replaceBetween(
    $studio,
    $revertStart,
    $revertEnd,
    $newRevert,
    'studio revert override'
);

/*
 * Position UI:
 * - common/shared point => "Изменить для этой должности"
 * - exclusive position point => direct edit
 * - override => direct edit + revert
 */
$studio = replaceOnce(
    $studio,
<<<'OLD'
    $point['canedit'] = !$resolvedview;

    // Legacy materialised inheritance actions are retired.
    // Scope/override controls will be added on top of the one
    // physical parent point in the next Studio step.
    $point['canoverride'] = false;
    $point['canrevert'] = false;
OLD,
<<<'NEW'
    $scopeidsunique =
        array_values(
            array_unique(
                $confirmedids
            )
        );

    $exclusiveposition =
        $resolvedview
        &&
        count($scopeidsunique) === 1
        &&
        (string)$scopeidsunique[0] === $positionid;

    $positionoverride =
        $resolvedview
        &&
        (int)($point['sourcepointid'] ?? 0) > 0
        &&
        $state === \local_ustar\route_family::OVERRIDE
        &&
        in_array(
            $positionid,
            $scopeidsunique,
            true
        );

    $point['canedit'] =
        !$resolvedview
        ||
        $exclusiveposition;

    $point['canoverride'] =
        $resolvedview
        &&
        !$exclusiveposition
        &&
        !$positionoverride;

    $point['canrevert'] =
        $positionoverride;
NEW,
    'studio point controls'
);

/*
 * File upload should work while editing a real position-specific
 * point inside the resolved family view.
 */
$studio = replaceOnce(
    $studio,
<<<'OLD'
    $point['canupload'] =
        empty($route['isparent'])
        &&
        $positionid !== '';
OLD,
<<<'NEW'
    $point['canupload'] =
        $positionid !== ''
        &&
        (
            $resolvedview
            ||
            empty($route['isparent'])
        );
NEW,
    'studio upload permission'
);

/*
 * Position route can add its own scoped points.
 */
$studio = replaceOnce(
    $studio,
<<<'OLD'
    'caneditroute' =>
        !$resolvedview,
OLD,
<<<'NEW'
    'caneditroute' =>
        !$resolvedview
        ||
        (
            $selectedfamily !== null
            &&
            $positionid !== ''
        ),
NEW,
    'studio can edit route'
);

/*
 * Correct wording in resolved position mode.
 */
$studio = replaceOnce(
    $studio,
<<<'OLD'
if ($resolvedview) {
    $route['canreorder'] = false;
    $route['routekicker'] = 'Просмотр маршрута должности';
    $route['routebadge'] = 'Итоговый маршрут';
    $route['routedescription'] =
        'Это вычисляемый маршрут сотрудника: общий маршрут семьи '
        . 'с учётом только подтверждённой применимости шагов для выбранной должности.';
OLD,
<<<'NEW'
if ($resolvedview) {
    $route['canreorder'] = false;
    $route['routekicker'] = 'Маршрут должности';
    $route['routebadge'] = 'Итоговый маршрут';
    $route['routedescription'] =
        'Общие и разделяемые шаги можно изменить только для этой должности '
        . 'через отдельное переопределение. Должностные шаги редактируются напрямую.';

    $route['addkicker'] =
        'Новый шаг должности';

    $route['addtitle'] =
        'Добавить шаг только для этой должности';
NEW,
    'studio resolved wording'
);


/*
 * ============================================================
 * 3. Template wording
 * ============================================================
 */

$template = file_get_contents($templateFile);

$template = replaceOnce(
    $template,
    '{{#isparent}}Новый общий шаг автоматически появится во всех должностных маршрутах семьи.{{/isparent}}{{^isparent}}Этот шаг относится только к выбранной должности и не изменяет общий маршрут семьи.{{/isparent}}',
    '{{#resolvedposition}}Этот шаг относится только к выбранной должности и не изменяет общий маршрут семьи.{{/resolvedposition}}{{^resolvedposition}}{{#isparent}}Новый общий шаг автоматически появится во всех должностных маршрутах семьи.{{/isparent}}{{^isparent}}Этот шаг относится только к выбранной должности.{{/isparent}}{{/resolvedposition}}',
    'template add description'
);


/*
 * ============================================================
 * Write only after every validation/replacement succeeded.
 * ============================================================
 */

if (file_put_contents($scopeFile, $scope) === false) {
    throw new RuntimeException('WRITE FAILED: route_scope.php');
}

if (file_put_contents($studioFile, $studio) === false) {
    throw new RuntimeException('WRITE FAILED: route_studio.php');
}

if (file_put_contents($templateFile, $template) === false) {
    throw new RuntimeException('WRITE FAILED: route_studio.mustache');
}

echo "PATCH OK\n";
echo "BACKUP STAMP: {$stamp}\n";
PHP

docker exec ustar_moodle php -l /var/www/html/public/local/ustar/classes/route_scope.php
docker exec ustar_moodle php -l /var/www/html/public/local/ustar/route_studio.php
