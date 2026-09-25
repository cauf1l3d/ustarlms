<?php
defined('MOODLE_INTERNAL') || die();

/** Synthetic, stable-ID fixtures. Never uses production numeric identifiers. */
final class local_ustar_generator extends component_generator_base {
    public function create_route(array $data = []): stdClass {
        return $this->insert('local_ustar_routes', $data + [
            'positionid' => 'fixture_' . random_string(10), 'name' => 'Fixture route',
            'routekind' => 'position', 'active' => 1,
        ]);
    }

    public function create_point(stdClass $route, array $data = []): stdClass {
        return $this->insert('local_ustar_route_points', $data + [
            'routeid' => $route->id, 'pointkey' => 'fixture_' . random_string(10),
            'phase' => 'adaptation', 'sortorder' => 10, 'active' => 1,
        ]);
    }

    public function create_version(stdClass $point, array $data = []): stdClass {
        return $this->insert('local_ustar_route_versions', $data + [
            'pointid' => $point->id, 'versionno' => 1, 'title' => 'Fixture point',
            'requirementsjson' => '[]', 'status' => 'published', 'renewalpolicy' => 'keep',
        ]);
    }

    public function create_progress(stdClass $user, stdClass $point, stdClass $version, array $data = []): stdClass {
        return $this->insert('local_ustar_route_progress', $data + [
            'userid' => $user->id, 'pointid' => $point->id, 'versionid' => $version->id,
            'status' => 'complete', 'completedat' => time(), 'evidencejson' => '{}',
        ]);
    }

    private function insert(string $table, array $data): stdClass {
        global $DB;
        $record = (object)($data + ['timecreated' => time(), 'timemodified' => time()]);
        $record->id = $DB->insert_record($table, $record);
        return $DB->get_record($table, ['id' => $record->id], '*', MUST_EXIST);
    }
}
