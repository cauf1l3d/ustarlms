<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** Explicit employment/approval state, independent from the Moodle user row. */
final class employment {
    public const PENDING = 'pending';
    public const ACTIVE = 'active';
    public const SUSPENDED = 'suspended';
    public const TERMINATED = 'terminated';

    public static function statuses(): array {
        return [self::PENDING, self::ACTIVE, self::SUSPENDED, self::TERMINATED];
    }

    /**
     * Missing rows preserve pre-migration behaviour. Once a row exists it is
     * authoritative and never falls back to Moodle confirmed/suspended flags.
     */
    public static function resolve(int $userid): array {
        global $DB;
        $record = $DB->get_record('local_ustar_employment', ['userid' => $userid], '*', IGNORE_MISSING);
        if (!$record) {
            return ['userid' => $userid, 'status' => self::ACTIVE, 'source' => 'legacy',
                'explicit' => false, 'approvedby' => 0, 'approvedat' => 0];
        }
        return ['userid' => $userid, 'status' => (string)$record->status,
            'source' => (string)$record->source, 'explicit' => true,
            'approvedby' => (int)($record->approvedby ?? 0),
            'approvedat' => (int)($record->approvedat ?? 0)];
    }

    public static function is_active(int $userid): bool {
        return self::resolve($userid)['status'] === self::ACTIVE;
    }

    public static function learning_allowed(int $userid): bool {
        return self::is_active($userid) && accounts::learning_enabled($userid);
    }

    /** Idempotently lower a newly self-registered account to pending. */
    public static function register_pending(int $userid): void {
        global $DB;
        if ($DB->record_exists('local_ustar_employment', ['userid' => $userid])) return;
        $now = time();
        $DB->insert_record('local_ustar_employment', (object)[
            'userid' => $userid, 'status' => self::PENDING, 'source' => 'self_registration',
            'approvedby' => null, 'approvedat' => null, 'timecreated' => $now,
            'timemodified' => $now, 'usermodified' => $userid,
        ]);
    }

    /** Approve a pending registration only inside the actor's explicit team scope. */
    public static function approve_registration(int $userid, int $actorid, string $positionid): void {
        global $DB, $USER;
        if ((int)$USER->id !== $actorid || !capabilities::has($actorid, capabilities::TEAM_READ)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/ustar:viewteam', 'nopermissions', '');
        }
        $scope = organization_model::manager_scope($actorid);
        if (empty($scope['allowed']) || !in_array($positionid,
                array_column($scope['positions'] ?? [], 'id'), true)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/ustar:viewteam', 'nopermissions', '');
        }
        if (!$DB->record_exists('user', ['id' => $userid, 'deleted' => 0])
                || self::resolve($userid)['status'] !== self::PENDING) {
            throw new \invalid_parameter_exception('Регистрация уже активирована или недоступна.');
        }
        view_as::assert_writable();
        self::persist($userid, self::ACTIVE, $actorid, 'registration_approval');
    }

    public static function set_status(int $userid, string $status, int $actorid, string $source = 'manual'): void {
        global $DB, $USER;
        if (!in_array($status, self::statuses(), true)) {
            throw new \invalid_parameter_exception('Неизвестный статус занятости USTAR');
        }
        if (!$DB->record_exists('user', ['id' => $userid, 'deleted' => 0])) {
            throw new \invalid_parameter_exception('Пользователь не найден');
        }
        if ($actorid <= 0 || (int)($USER->id ?? 0) !== $actorid
                || (!is_siteadmin($actorid)
                    && !has_capability('local/ustar:hrmanage', \context_system::instance(), $actorid))) {
            throw new \required_capability_exception(
                \context_system::instance(), 'local/ustar:hrmanage', 'nopermissions', '');
        }
        if (class_exists('\\local_ustar\\view_as')) {
            view_as::assert_writable();
        }
        self::persist($userid, $status, $actorid, $source);
    }

    private static function persist(int $userid, string $status, int $actorid, string $source): void {
        global $DB;
        $now = time();
        $existing = $DB->get_record('local_ustar_employment', ['userid' => $userid], '*', IGNORE_MISSING);
        $approval = $status === self::ACTIVE;
        $record = (object)[
            'userid' => $userid, 'status' => $status, 'source' => $source,
            'approvedby' => $approval ? $actorid : null,
            'approvedat' => $approval ? $now : null,
            'timemodified' => $now, 'usermodified' => $actorid,
        ];
        if ($existing) {
            $record->id = (int)$existing->id;
            $DB->update_record('local_ustar_employment', $record);
        } else {
            $record->timecreated = $now;
            $DB->insert_record('local_ustar_employment', $record);
        }
    }
}
