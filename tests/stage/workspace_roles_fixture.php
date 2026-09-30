<?php
if (getenv('USTAR_STAGE_PREFIX') !== 'pr63_') { throw new RuntimeException('ISOLATED_PREFIX_REQUIRED'); }
define('CLI_SCRIPT',true);
require '/stage/moodle/config.php';
$phase=$argv[1]??'';
$context=context_system::instance();
foreach (['ustar_hr','ustar_hrd','ustar_superadmin'] as $shortname) {
    $role=$DB->get_field('role','id',['shortname'=>$shortname]);
    if ($phase==='before') {
        if (!$role) { $role=create_role($shortname,$shortname,'Synthetic regression'); }
        if ($DB->record_exists('capabilities',['name'=>'local/ustar:taskescalation'])) { throw new RuntimeException('NEW_CAPABILITY_MUST_NOT_EXIST_YET'); }
    } else {
        if (!$role) { throw new RuntimeException('CORPORATE_ROLE_MISSING'); }
        $caps=$shortname==='ustar_superadmin'?['taskescalation']:['hr','viewteam','gradeassessments','requeststaff'];
        foreach ($caps as $cap) {
            if (!$DB->record_exists('role_capabilities',['roleid'=>$role,'contextid'=>$context->id,'capability'=>'local/ustar:'.$cap,'permission'=>CAP_ALLOW])) {
                throw new RuntimeException('MISSING_GRANT:'.$shortname.':'.$cap);
            }
        }
    }
}
if ($phase==='after' && (int)get_config('local_ustar','version')!==2026093002) { throw new RuntimeException('WRONG_VERSION'); }
echo 'CORPORATE_ROLE_UPGRADE_'.$phase.'=OK'.PHP_EOL;
