<?php
namespace local_ustar\connect;
defined('MOODLE_INTERNAL') || die();

/** Revalidate object scope even when a transport/action receipt already exists. */
final class bot_guards {
    public static function verify(array $guard,int $userid): void {
        global $DB;
        if(!$guard){return;}
        receipt_actor::require_active($userid);
        foreach($guard['tasks']??[] as $id) {
            $task=$DB->get_record('local_ustar_learning_tasks',['id'=>(int)$id]);
            if(!$task || !\local_ustar\task_workspace\service::can_view_record($task,$userid)){
                throw new \InvalidArgumentException('forbidden_scope');
            }
        }
        foreach($guard['employees']??[] as $id) {
            if(!\local_ustar\department_learning::can_view($userid,(int)$id)) {
                throw new \InvalidArgumentException('forbidden_scope');
            }
        }
        if(isset($guard['staffing'])) {
            $visible=\local_ustar\staffing_requests::list_for($userid);
            foreach((array)$guard['staffing'] as $id){
                if(!in_array((int)$id,array_map('intval',array_column($visible,'id')),true)){
                    throw new \InvalidArgumentException('forbidden_scope');
                }
            }
        }
        if(isset($guard['attempt'])){
            bot_actor::run($userid,static function()use($guard):void {
                \local_ustar\hr_access::require_grader();\local_ustar\route_quiz_grading::detail((int)$guard['attempt']);
            });
        }
        foreach(array_merge((array)($guard['adaptation']??[]),$guard['adaptations']??[]) as $id){
            $a=$DB->get_record('local_ustar_adaptations',['id'=>(int)$id]);
            if(!$a || ((int)$a->userid!==$userid && (int)$a->managerid!==$userid && !\local_ustar\adaptation_service::is_hrd_actor($userid))){
                throw new \InvalidArgumentException('forbidden_scope');
            }
        }
    }
    public static function form(\stdClass $d,array $form): void {
        self::verify(self::form_guard($form),(int)$d->userid);
    }
    public static function form_guard(array $form): array {
        $context=$form['context']??[];$commit=$form['commit']??'';
        $guard=[];
        if(isset($context['id']) && str_starts_with($commit,'work.') && !str_starts_with($commit,'work.checklist')){
            $guard['tasks']=[(int)$context['id']];
        }
        if(isset($context['attempt'])){$guard['attempt']=(int)$context['attempt'];}
        if(isset($context['id']) && str_starts_with($commit,'people.request.')){$guard['staffing']=(int)$context['id'];}
        if(isset($context['id']) && str_starts_with($commit,'people.adaptation.')){
            $guard['adaptation']=(int)$context['id'];
        }
        return $guard;
    }
}
