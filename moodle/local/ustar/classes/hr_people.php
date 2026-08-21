<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * USTAR HR People domain service.
 *
 * Owns employee create/update + position assignment +
 * immediate Learning Assignment synchronization.
 */
class hr_people {

    public static function save(
        array $input,
        int $actorid
    ): array {
        global $DB, $CFG;

        $context =
            \context_system::instance();

        require_capability(
            'local/ustar:hrmanage',
            $context
        );


        $userid =
            (int)($input['userid'] ?? 0);

        $username =
            clean_param(
                trim(
                    (string)($input['username'] ?? '')
                ),
                PARAM_USERNAME
            );

        $firstname =
            clean_param(
                trim(
                    (string)($input['firstname'] ?? '')
                ),
                PARAM_NOTAGS
            );

        $lastname =
            clean_param(
                trim(
                    (string)($input['lastname'] ?? '')
                ),
                PARAM_NOTAGS
            );

        $email =
            clean_param(
                trim(
                    (string)($input['email'] ?? '')
                ),
                PARAM_EMAIL
            );

        $positionid =
            clean_param(
                trim(
                    (string)($input['positionid'] ?? '')
                ),
                PARAM_ALPHANUMEXT
            );

        $suspended =
            !empty($input['suspended']);

        $password =
            (string)($input['password'] ?? '');


        $accounttype =
            clean_param(
                trim(
                    (string)(
                        $input['accounttype']
                        ?? accounts::TYPE_EMPLOYEE
                    )
                ),
                PARAM_ALPHANUMEXT
            );

        if (!in_array($accounttype, accounts::types(), true)) {
            throw new \invalid_parameter_exception(
                'Неизвестный тип учётной записи USTAR'
            );
        }


        if (
            $username === ''
            ||
            $firstname === ''
            ||
            $lastname === ''
            ||
            $email === ''
        ) {
            throw new \invalid_parameter_exception(
                'Заполните логин, имя, фамилию и email'
            );
        }


        /*
         * Validate USTAR position.
         */
        $structure =
            structure::get(
                structure::NAME_STRUCTURE
            );

        $positionmap = [];

        foreach (
            $structure['positions'] ?? []
            as $position
        ) {
            $positionmap[
                $position['id']
            ] = $position;
        }


        if (
            $positionid !== ''
            &&
            !isset(
                $positionmap[$positionid]
            )
        ) {
            throw new \invalid_parameter_exception(
                'Неизвестная должность USTAR'
            );
        }


        /*
         * Unique username/email.
         */
        $existingusername =
            $DB->get_record(
                'user',
                [
                    'username' =>
                        $username,

                    'mnethostid' =>
                        $CFG->mnet_localhost_id,

                    'deleted' =>
                        0,
                ],
                'id'
            );

        if (
            $existingusername
            &&
            (int)$existingusername->id !== $userid
        ) {
            throw new \invalid_parameter_exception(
                'Такой логин уже существует'
            );
        }


        $existingemail =
            $DB->get_record(
                'user',
                [
                    'email' =>
                        $email,

                    'deleted' =>
                        0,
                ],
                'id'
            );

        if (
            $existingemail
            &&
            (int)$existingemail->id !== $userid
        ) {
            throw new \invalid_parameter_exception(
                'Такой email уже используется'
            );
        }


        require_once(
            $CFG->dirroot
            .
            '/user/lib.php'
        );


        if ($userid > 0) {

            $target =
                $DB->get_record(
                    'user',
                    [
                        'id' =>
                            $userid,

                        'deleted' =>
                            0,
                    ],
                    '*',
                    MUST_EXIST
                );


            /*
             * HR must not mutate platform administrators,
             * themselves, or protected USTAR admins.
             */
            if (
                is_siteadmin($target)
                ||
                $target->id === $actorid
                ||
                has_capability(
                    'local/ustar:admin',
                    $context,
                    $target->id
                )
            ) {
                throw new \required_capability_exception(
                    $context,
                    'local/ustar:hrmanage',
                    'nopermissions',
                    ''
                );
            }


            $oldposition =
                people::position_id(
                    $userid
                );


            $oldaccounttype =
                accounts::type_of(
                    $userid
                );


            user_update_user(
                (object)[
                    'id' =>
                        $userid,

                    'username' =>
                        $username,

                    'firstname' =>
                        $firstname,

                    'lastname' =>
                        $lastname,

                    'email' =>
                        $email,

                    'suspended' =>
                        $suspended
                            ? 1
                            : 0,
                ],
                false,
                false
            );


            people::set_position_id(
                $userid,
                $positionid
            );


            accounts::set_type(
                $userid,
                $accounttype
            );


            if ($oldaccounttype !== $accounttype) {
                people::log_action(
                    $actorid,
                    $userid,
                    'account_type_changed',
                    [
                        'oldaccounttype' => $oldaccounttype,
                        'accounttype' => $accounttype,
                    ]
                );
            }


            people::log_action(
                $actorid,
                $userid,
                'person_updated',
                [
                    'oldpositionid' =>
                        $oldposition,

                    'positionid' =>
                        $positionid,

                    'suspended' =>
                        $suspended,


                    'oldaccounttype' =>
                        $oldaccounttype,

                    'accounttype' =>
                        $accounttype,
                ]
            );


            $savedid =
                $userid;

        } else {

            if ($password === '') {
                throw new \invalid_parameter_exception(
                    'Для нового сотрудника нужен временный пароль'
                );
            }


            $user =
                (object)[
                    'auth' =>
                        'manual',

                    'confirmed' =>
                        1,

                    'mnethostid' =>
                        $CFG->mnet_localhost_id,

                    'username' =>
                        $username,

                    'password' =>
                        $password,

                    'firstname' =>
                        $firstname,

                    'lastname' =>
                        $lastname,

                    'email' =>
                        $email,

                    'suspended' =>
                        $suspended
                            ? 1
                            : 0,
                ];


            $savedid =
                (int)user_create_user(
                    $user,
                    true,
                    false
                );


            set_user_preference(
                'auth_forcepasswordchange',
                1,
                $savedid
            );


            people::set_position_id(
                $savedid,
                $positionid
            );


            accounts::set_type(
                $savedid,
                $accounttype
            );


            people::log_action(
                $actorid,
                $savedid,
                'person_created',
                [
                    'positionid' =>
                        $positionid,

                    'accounttype' =>
                        $accounttype,
                ]
            );
        }


        /*
         * Project the selected position into the protected USTAR workspace role.
         * Manual executive/admin assignments are never touched.
         */
        try {
            $accessresult = position_access::sync_user($savedid);
            people::log_action(
                $actorid,
                $savedid,
                'position_access_synced',
                [
                    'positionid' => $positionid,
                    'targetrole' => $accessresult['targetrole'] ?? '',
                ]
            );
        } catch (\Throwable $e) {
            people::log_action(
                $actorid,
                $savedid,
                'position_access_sync_failed',
                [
                    'positionid' => $positionid,
                    'message' => $e->getMessage(),
                ]
            );
        }


        /*
         * Immediate position-derived Moodle access.
         *
         * A temporary course configuration problem must not
         * roll back the employee identity itself.
         */
        try {

            $assignmentresult =
                assignment::sync_user(
                    $savedid
                );


            people::log_action(
                $actorid,
                $savedid,
                'assignment_synced',
                [
                    'positionid' =>
                        $positionid,

                    'status' =>
                        $assignmentresult[
                            'status'
                        ] ?? '',

                    'enrolled' =>
                        array_values(
                            array_map(
                                static fn($course) =>
                                    (int)$course['id'],
                                $assignmentresult[
                                    'enrolled'
                                ] ?? []
                            )
                        ),
                ]
            );

        } catch (\Throwable $e) {

            $assignmentresult = [
                'ok' =>
                    false,

                'status' =>
                    'sync_error',

                'message' =>
                    $e->getMessage(),

                'enrolled' =>
                    [],
            ];


            people::log_action(
                $actorid,
                $savedid,
                'assignment_sync_failed',
                [
                    'positionid' =>
                        $positionid,

                    'message' =>
                        $e->getMessage(),
                ]
            );
        }


        return [
            'userid' =>
                $savedid,

            'assignment' =>
                $assignmentresult,
        ];
    }
}
