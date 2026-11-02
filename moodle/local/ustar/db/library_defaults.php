<?php
defined('MOODLE_INTERNAL') || die();
/** Metadata only: no publication, purchase, route fact or user-specific role assignment. */
function local_ustar_library_defaults(): void {
    global $DB;
    update_capabilities('local_ustar');
    \local_ustar\position_access::ensure_roles();
    foreach (['library_rail'=>1,'library_store_enabled'=>0,'library_pricemin'=>1,'library_pricemax'=>25] as $key=>$value) {
        if (get_config('local_ustar',$key) === false) { set_config($key,$value,'local_ustar'); }
    }
    $roles=['ustar_superadmin'=>['librarypublish','librarygrant','libraryassess'],
        'ustar_hr'=>['librarypublish','librarygrant','libraryassess'],
        'ustar_hrd'=>['librarypublish','librarygrant','libraryassess'], 'ustar_manager'=>['librarygrant']];
    foreach ($roles as $shortname=>$caps) {
        $roleid=(int)$DB->get_field('role','id',['shortname'=>$shortname]);
        if (!$roleid) { continue; }
        foreach ($caps as $cap) { assign_capability('local/ustar:'.$cap,CAP_ALLOW,$roleid,context_system::instance()->id); }
    }
}
