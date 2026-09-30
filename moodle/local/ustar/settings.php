<?php

defined('MOODLE_INTERNAL') || die();

// USTAR admin: employee learning-history reset.
if ($hassiteconfig) {
    $ADMIN->add('localplugins', new admin_externalpage('local_ustar_rewards','USTAR · Управление геймификацией',
        new moodle_url('/local/ustar/reward_control.php'),'moodle/site:config'));
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_ustar_feed_publishers',
        'USTAR · Издатели и модераторы Ленты',
        new moodle_url('/local/ustar/admin_feed_roles.php'),
        'moodle/site:config'
    ));
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_ustar_user_history_reset',
        'USTAR · Очистка учебной истории',
        new moodle_url('/local/ustar/admin_user_history_reset.php'),
        'moodle/site:config'
    ));
}
