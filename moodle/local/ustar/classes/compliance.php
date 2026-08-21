<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * Mandatory-knowledge compliance aggregation.
 *
 * All calculations are based on current persisted state:
 * published content + current published version + active access rules +
 * current employee position/department + current-version acknowledgement.
 */
class compliance {

    /**
     * Mandatory knowledge for one employee.
     *
     * @return array<string,mixed>
     */
    public static function for_user(int $userid): array {
        global $DB;

        $result = [
            'userid' => $userid,
            'participates' => false,
            'assigned' => 0,
            'acknowledged' => 0,
            'pending' => 0,
            'percent' => 0,
            'items' => [],
        ];

        if (!accounts::participates($userid)) {
            return $result;
        }

        $scope = content::user_scope($userid);
        $positionid = trim((string)$scope['positionid']);
        $departmentid = trim((string)$scope['departmentid']);

        if ($positionid === '') {
            return $result;
        }

        $result['participates'] = true;

        $contents = $DB->get_records(
            'local_ustar_content',
            [
                'status' => content::STATUS_PUBLISHED,
                'ackrequired' => 1,
            ],
            'sortorder ASC, title ASC, id ASC'
        );

        if (!$contents) {
            return $result;
        }

        $contentids = array_map('intval', array_keys($contents));
        [$contentsql, $contentparams] = $DB->get_in_or_equal(
            $contentids,
            SQL_PARAMS_NAMED,
            'compliancecontent'
        );

        $rulesbycontent = [];
        foreach ($DB->get_records_select(
            'local_ustar_content_access',
            "active = 1 AND contentid {$contentsql}",
            $contentparams,
            'id ASC'
        ) as $rule) {
            $rulesbycontent[(int)$rule->contentid][] = $rule;
        }

        $versionsbycontent = [];
        foreach ($DB->get_records_select(
            'local_ustar_content_versions',
            "iscurrent = 1 AND status = :published AND contentid {$contentsql}",
            ['published' => content::STATUS_PUBLISHED] + $contentparams,
            'contentid ASC, versionno DESC, id DESC'
        ) as $version) {
            $cid = (int)$version->contentid;
            if (!isset($versionsbycontent[$cid])) {
                $versionsbycontent[$cid] = $version;
            }
        }

        $versionids = array_values(array_map(
            static fn($version): int => (int)$version->id,
            $versionsbycontent
        ));

        $acksbyversion = [];
        if ($versionids) {
            [$versionsql, $versionparams] = $DB->get_in_or_equal(
                $versionids,
                SQL_PARAMS_NAMED,
                'complianceversion'
            );

            foreach ($DB->get_records_select(
                'local_ustar_content_ack',
                "userid = :userid AND versionid {$versionsql}",
                ['userid' => $userid] + $versionparams,
                'acktime DESC, id DESC'
            ) as $ack) {
                $acksbyversion[(int)$ack->versionid] = $ack;
            }
        }

        $items = [];
        foreach ($contents as $item) {
            $contentid = (int)$item->id;
            $version = $versionsbycontent[$contentid] ?? null;

            if (!$version) {
                continue;
            }

            if (!self::rules_match(
                $rulesbycontent[$contentid] ?? [],
                $positionid,
                $departmentid
            )) {
                continue;
            }

            $ack = $acksbyversion[(int)$version->id] ?? null;
            $acked = (bool)$ack;
            $openurl = content::open_url($contentid, $userid);

            $items[] = [
                'contentid' => $contentid,
                'title' => (string)$item->title,
                'category' => (string)$item->category,
                'type' => (string)$item->type,
                'versionid' => (int)$version->id,
                'versionlabel' => $version->versionlabel ?: ('v' . $version->versionno),
                'acked' => $acked,
                'pending' => !$acked,
                'acktime' => $ack ? (int)$ack->acktime : 0,
                'ackmethod' => $ack ? (string)$ack->method : '',
                'url' => $openurl ? $openurl->out(false) : '',
            ];
        }

        usort($items, static function(array $a, array $b): int {
            if ($a['acked'] !== $b['acked']) {
                return $a['acked'] ? 1 : -1;
            }
            return strcasecmp($a['title'], $b['title']);
        });

        $assigned = count($items);
        $acknowledged = count(array_filter(
            $items,
            static fn(array $item): bool => !empty($item['acked'])
        ));
        $pending = max(0, $assigned - $acknowledged);

        return [
            'userid' => $userid,
            'participates' => true,
            'assigned' => $assigned,
            'acknowledged' => $acknowledged,
            'pending' => $pending,
            'percent' => $assigned > 0
                ? (int)round(($acknowledged / $assigned) * 100)
                : 0,
            'items' => $items,
        ];
    }

    /**
     * Published mandatory knowledge derived for a position.
     * Used by Position Models; no user-specific acknowledgement is involved.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function for_position(string $positionid): array {
        global $DB;

        $positionid = trim($positionid);
        if ($positionid === '') {
            return [];
        }

        $structure = structure::get(structure::NAME_STRUCTURE);
        $positions = people::position_map($structure);
        $position = $positions[$positionid] ?? null;
        if (!$position) {
            return [];
        }

        $departmentid = trim((string)($position['department'] ?? ''));

        $contents = $DB->get_records(
            'local_ustar_content',
            [
                'status' => content::STATUS_PUBLISHED,
                'ackrequired' => 1,
            ],
            'sortorder ASC, title ASC, id ASC'
        );

        $out = [];
        foreach ($contents as $item) {
            $version = content::current_version((int)$item->id);
            if (!$version || $version->status !== content::STATUS_PUBLISHED) {
                continue;
            }

            $rules = $DB->get_records(
                'local_ustar_content_access',
                ['contentid' => $item->id, 'active' => 1],
                'id ASC'
            );

            if (!self::rules_match($rules, $positionid, $departmentid)) {
                continue;
            }

            $out[] = [
                'contentid' => (int)$item->id,
                'title' => (string)$item->title,
                'category' => (string)$item->category,
                'type' => (string)$item->type,
                'versionid' => (int)$version->id,
                'versionlabel' => $version->versionlabel ?: ('v' . $version->versionno),
                'url' => (new \moodle_url('/local/ustar/materials.php', [
                    'contentid' => (int)$item->id,
                    'status' => 'all',
                    'theme' => 'ustar',
                ]))->out(false),
            ];
        }

        return $out;
    }

    /**
     * True when at least one active access rule matches the role scope.
     * No rules deliberately means no access.
     *
     * @param array<int,\stdClass> $rules
     */
    private static function rules_match(
        array $rules,
        string $positionid,
        string $departmentid
    ): bool {
        if (!$rules) {
            return false;
        }

        foreach ($rules as $rule) {
            $type = trim((string)$rule->scopetype);
            $scopeid = trim((string)$rule->scopeid);

            if ($type === 'all') {
                return true;
            }

            if ($type === 'position' && $scopeid !== '' && $scopeid === $positionid) {
                return true;
            }

            if ($type === 'department' && $scopeid !== '' && $scopeid === $departmentid) {
                return true;
            }
        }

        return false;
    }
}
