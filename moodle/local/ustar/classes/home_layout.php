<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();

/** Personal presentation settings; visibility never grants product capabilities. */
final class home_layout {
    public static function blocks(int $userid): array {
        $blocks = ['hero'=>'Приветствие','next'=>'Следующее действие','tasks'=>'Мои задачи',
            'checklists'=>'Мои чек-листы','notes'=>'Личные заметки','links'=>'Быстрые переходы',
            'achievements'=>'Достижения'];
        if (has_capability('local/ustar:viewteam', \context_system::instance(), $userid)) { $blocks['team']='Моя команда'; }
        if (has_capability('local/ustar:managecompetition', \context_system::instance(), $userid)) { $blocks['competition']='Соревнования'; }
        return $blocks;
    }
    public static function defaults(int $userid): array {
        $items = []; $i=0;
        foreach (self::blocks($userid) as $id=>$label) {
            $items[$id]=['order'=>$i++,'span'=>in_array($id,['tasks','checklists','notes'],true)?4:12,
                'visible'=>!in_array($id,['achievements','team','competition'],true),'style'=>'plain'];
        }
        return ['revision'=>0,'items'=>$items];
    }
    public static function read(int $userid): array {
        global $DB;
        $data=json_decode((string)$DB->get_field('user_preferences','value',['userid'=>$userid,'name'=>'ustar_home_layout']),true);
        $out=self::defaults($userid);$out['revision']=(int)($data['revision']??0);
        foreach ($out['items'] as $id=>&$item) { if (isset($data['items'][$id])) { $item=array_merge($item,$data['items'][$id]); } }
        return $out;
    }
    public static function save(int $userid,int $revision,array $input): array {
        global $USER;
        view_as::assert_writable();
        if ((int)$USER->id!==$userid || !team_access::active_actor($userid)) {
            throw new \required_capability_exception(\context_system::instance(),'local/ustar:use','nopermissions','');
        }
        $allowed=self::blocks($userid);$items=[];
        foreach ($input as $id=>$item) {
            if (!isset($allowed[$id]) || !is_array($item) || !is_int($item['order']??null)
                    || $item['order']<0 || $item['order']>100 || !in_array($item['span']??0,[4,6,12],true)
                    || !is_bool($item['visible']??null) || !in_array($item['style']??'',['plain','soft','accent'],true)) {
                throw new \invalid_parameter_exception('Некорректная настройка блока главной.');
            }
            $items[$id]=array_intersect_key($item,array_flip(['order','span','visible','style']));
        }
        $lock=\core\lock\lock_config::get_lock_factory('local_ustar')->get_lock('home-layout:'.$userid,10);
        if (!$lock) { throw new \moodle_exception('Настройки сейчас сохраняются.'); }
        try {
            $current=self::read($userid);
            if ($current['revision']!==$revision) { throw new \moodle_exception('Настройки изменены в другой вкладке. Обновите страницу.'); }
            $data=['revision'=>$revision+1,'items'=>array_merge($current['items'],$items)];
            set_user_preference('ustar_home_layout',json_encode($data),$userid);return $data;
        } finally { $lock->release(); }
    }
}
