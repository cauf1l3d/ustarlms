<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * Explicit USTAR account semantics for operational metrics.
 *
 * Identity remains owned by Moodle. This class only classifies whether an
 * account represents a workforce employee or a non-workforce service/test
 * account. Access permissions are never derived from this value.
 */
class accounts {
    public const FIELD = 'ustar_account_type';

    public const TYPE_EMPLOYEE = 'employee';
    public const TYPE_SERVICE = 'service';
    public const TYPE_TEST = 'test';

    /** @return string[] */
    public static function types(): array {
        return [
            self::TYPE_EMPLOYEE,
            self::TYPE_SERVICE,
            self::TYPE_TEST,
        ];
    }

    /** @return array<string,string> */
    public static function labels(): array {
        return [
            self::TYPE_EMPLOYEE => 'Сотрудник',
            self::TYPE_SERVICE => 'Сервисная учётная запись',
            self::TYPE_TEST => 'Тестовая учётная запись',
        ];
    }

    /**
     * Return an explicit account type. Missing/empty values conservatively
     * default to employee so an upgrade cannot silently remove real people
     * from operational figures.
     */
    public static function type_of(int $userid): string {
        global $DB;

        if ($userid <= 0) {
            return self::TYPE_EMPLOYEE;
        }

        $fieldid = (int)$DB->get_field(
            'user_info_field',
            'id',
            ['shortname' => self::FIELD]
        );

        if (!$fieldid) {
            return self::TYPE_EMPLOYEE;
        }

        $raw = $DB->get_field(
            'user_info_data',
            'data',
            ['userid' => $userid, 'fieldid' => $fieldid]
        );

        $value = trim((string)$raw);

        return in_array($value, self::types(), true)
            ? $value
            : self::TYPE_EMPLOYEE;
    }

    /**
     * Whether an account participates in workforce/compliance metrics.
     * This intentionally does not modify access permissions.
     */
    public static function participates(int $userid): bool {
        global $DB;

        if ($userid <= 0 || is_siteadmin($userid)) {
            return false;
        }

        $user = $DB->get_record(
            'user',
            ['id' => $userid],
            'id,deleted,suspended',
            IGNORE_MISSING
        );

        if (!$user || !empty($user->deleted) || !empty($user->suspended)) {
            return false;
        }

        return self::type_of($userid) === self::TYPE_EMPLOYEE;
    }

    /**
     * Set an account type explicitly.
     */
    public static function set_type(int $userid, string $type): void {
        global $DB;

        if (!in_array($type, self::types(), true)) {
            throw new \invalid_parameter_exception('Неизвестный тип учётной записи USTAR');
        }

        if (!$DB->record_exists('user', ['id' => $userid, 'deleted' => 0])) {
            throw new \invalid_parameter_exception('Пользователь не найден');
        }

        $fieldid = (int)$DB->get_field(
            'user_info_field',
            'id',
            ['shortname' => self::FIELD]
        );

        if (!$fieldid) {
            throw new \moodle_exception('Профиль-поле ustar_account_type не установлено');
        }

        $record = $DB->get_record(
            'user_info_data',
            ['userid' => $userid, 'fieldid' => $fieldid]
        );

        if ($record) {
            $record->data = $type;
            $record->dataformat = 0;
            $DB->update_record('user_info_data', $record);
        } else {
            $DB->insert_record('user_info_data', (object)[
                'userid' => $userid,
                'fieldid' => $fieldid,
                'data' => $type,
                'dataformat' => 0,
            ]);
        }
    }

    /**
     * Create the locked Moodle profile field used by this class.
     * Safe to call repeatedly from install/upgrade.
     */
    public static function ensure_profile_field(): int {
        global $DB;

        $existing = $DB->get_record(
            'user_info_field',
            ['shortname' => self::FIELD]
        );

        if ($existing) {
            if ((string)$existing->datatype !== 'menu') {
                throw new \coding_exception(
                    'Existing ustar_account_type profile field is not a menu field'
                );
            }

            $existing->locked = 1;
            $existing->visible = 0;
            $existing->required = 0;
            $existing->signup = 0;
            $existing->forceunique = 0;
            $existing->defaultdata = self::TYPE_EMPLOYEE;
            $existing->defaultdataformat = 0;
            $existing->param1 = implode("\n", self::types());
            $DB->update_record('user_info_field', $existing);

            return (int)$existing->id;
        }

        $category = $DB->get_record(
            'user_info_category',
            ['name' => 'USTAR'],
            '*',
            IGNORE_MISSING
        );

        if (!$category) {
            $maxsort = (int)$DB->get_field_sql(
                'SELECT COALESCE(MAX(sortorder), 0) FROM {user_info_category}'
            );

            $categoryid = (int)$DB->insert_record(
                'user_info_category',
                (object)[
                    'name' => 'USTAR',
                    'sortorder' => $maxsort + 1,
                ]
            );
        } else {
            $categoryid = (int)$category->id;
        }

        $maxfieldsort = (int)$DB->get_field_sql(
            'SELECT COALESCE(MAX(sortorder), 0) FROM {user_info_field} WHERE categoryid = :categoryid',
            ['categoryid' => $categoryid]
        );

        return (int)$DB->insert_record(
            'user_info_field',
            (object)[
                'shortname' => self::FIELD,
                'name' => 'Тип учётной записи USTAR',
                'datatype' => 'menu',
                'description' => 'Участие учётной записи в кадровых и compliance-показателях USTAR.',
                'descriptionformat' => FORMAT_PLAIN,
                'categoryid' => $categoryid,
                'sortorder' => $maxfieldsort + 1,
                'required' => 0,
                'locked' => 1,
                'visible' => 0,
                'forceunique' => 0,
                'signup' => 0,
                'defaultdata' => self::TYPE_EMPLOYEE,
                'defaultdataformat' => 0,
                'param1' => implode("\n", self::types()),
                'param2' => '',
                'param3' => '',
                'param4' => '',
                'param5' => '',
            ]
        );
    }
}
