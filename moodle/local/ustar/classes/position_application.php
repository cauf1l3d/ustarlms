<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** Read-only publication review for one stable position ID. */
final class position_application {
    public static function present(string $positionid, array $required, array $skillmap,
            ?\stdClass $published): array {
        global $DB;
        require_capability('local/ustar:hr', \context_system::instance());

        $code = standard_model::position_code($positionid);
        $history = [];
        $versions = $DB->get_records_sql(
            'SELECT v.id, v.versionno, v.status, v.effectivedate, v.timecreated,
                    u.firstname, u.lastname
               FROM {local_ustar_standards} s
               JOIN {local_ustar_standard_ver} v ON v.standardid = s.id
          LEFT JOIN {user} u ON u.id = v.createdby
              WHERE s.code = :code
           ORDER BY v.versionno DESC, v.id DESC',
            ['code' => $code], 0, 20
        );
        foreach ($versions as $version) {
            $history[] = [
                'version' => (int)$version->versionno,
                'state' => (int)($published->id ?? 0) === (int)$version->id ? 'Действует'
                    : ((string)$version->status === standard_model::STATUS_DRAFT ? 'Черновик' : 'Архив'),
                'date' => userdate((int)($version->effectivedate ?: $version->timecreated)),
                'author' => $version->firstname !== null ? fullname($version) : 'Учётная запись удалена',
            ];
        }

        $missing = [];
        foreach (standard_model::missing_position_sources($positionid, $required) as $skillid) {
            $missing[] = ['name' => (string)($skillmap[$skillid]['name'] ?? $skillid)];
        }

        $publishedmatrix = [];
        if ($published) {
            $items = json_decode((string)$published->requirementsjson, true);
            if (is_array($items)) {
                foreach ($items as $item) {
                    if (($item['sourcekind'] ?? '') === 'ustar_skill') {
                        $publishedmatrix[(string)$item['sourceid']] = (int)($item['targetlevel'] ?? 0);
                    }
                }
            }
        }
        return [
            'history' => $history,
            'hashistory' => !empty($history),
            'missing' => $missing,
            'hasmissing' => !empty($missing),
            'hasrequirements' => !empty($required),
            'haspendingchanges' => $published && !hash_equals(standard_model::matrix_hash($publishedmatrix),
                standard_model::matrix_hash($required)),
        ];
    }
}
