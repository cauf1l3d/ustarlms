<?php
namespace local_ustar\task;

defined('MOODLE_INTERNAL') || die();

final class renew_acting_assignments extends \core\task\scheduled_task {
    public function get_name(): string {
        return 'USTAR: продление временных и.о.';
    }

    private static function month_end_after(int $timestamp): int {
        $base=max(time(),$timestamp);
        $dt=(new \DateTimeImmutable('@'.$base))->setTimezone(new \DateTimeZone('Europe/Moscow'));
        return $dt->modify('first day of next month')
            ->modify('last day of this month')->setTime(23,59,59)->getTimestamp();
    }

    public function execute(): void {
        global $DB;
        $now=time();$threshold=$now+(3*DAYSECS);
        $rows=$DB->get_records_select(
            'local_ustar_assignments',
            "assignmenttype='acting' AND status='active' AND autorenew=1
             AND effectiveto IS NOT NULL AND effectiveto>0 AND effectiveto<=:threshold",
            ['threshold'=>$threshold],
            'id ASC'
        );
        $users=[];
        foreach($rows as $row){
            $row->effectiveto=self::month_end_after((int)$row->effectiveto);
            $row->timemodified=$now;$row->usermodified=0;
            $DB->update_record('local_ustar_assignments',$row);
            $users[(int)$row->userid]=true;
        }
        if($rows){
            \local_ustar\organization_model::rebuild_reporting();
            foreach(array_keys($users) as $uid)\local_ustar\position_access::sync_user((int)$uid);
        }
        mtrace('USTAR acting renewals: '.count($rows));
    }
}
