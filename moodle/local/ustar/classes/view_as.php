<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();
final class view_as {
    public static function can_use(?int $userid=null): bool { global $USER; $uid=$userid??(int)$USER->id; return is_siteadmin($uid)||has_capability('local/ustar:viewas',\context_system::instance(),$uid)||has_capability('local/ustar:admin',\context_system::instance(),$uid); }
    public static function position_id(): string { global $SESSION; return self::can_use() ? trim((string)($SESSION->ustar_view_position??'')) : ''; }
    public static function active(): bool { return self::position_id()!==''; }
    public static function set(string $positionid): void { global $SESSION; if(!self::can_use()) throw new \required_capability_exception(\context_system::instance(),'local/ustar:viewas','nopermissions','');
        $valid=false; foreach(structure::get(structure::NAME_STRUCTURE)['positions']??[] as $p) if((string)$p['id']===$positionid){$valid=true;break;} if(!$valid)throw new \invalid_parameter_exception('Должность не найдена'); $SESSION->ustar_view_position=$positionid; }
    public static function clear(): void { global $SESSION; unset($SESSION->ustar_view_position); }
    public static function assert_writable(): void { if(self::active()) throw new \moodle_exception('В режиме «Просмотр как» изменение данных заблокировано. Сначала выйдите из режима просмотра.'); }
}
