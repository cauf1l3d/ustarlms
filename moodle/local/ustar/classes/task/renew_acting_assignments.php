<?php
namespace local_ustar\task;

defined('MOODLE_INTERNAL') || die();

final class renew_acting_assignments extends \core\task\scheduled_task {
    public function get_name(): string {
        return 'USTAR: продление временных и.о.';
    }

    private static function month_end_after(int $timestamp): int {
        $base=max(time(),$timestamp);
        $timezone=(string)(get_config('local_ustar','acting_renew_timezone') ?: 'Europe/Moscow');
        $dt=(new \DateTimeImmutable('@'.$base))->setTimezone(new \DateTimeZone($timezone));
        return $dt->modify('last day of next month')->setTime(23,59,59)->getTimestamp();
    }

    public function execute(): void {
        global $DB;
        $now=time();$threshold=$now+(3*DAYSECS);
        $rows=$DB->get_records_select(
            'local_ustar_assignments',
            "assignmenttype='acting' AND status='active' AND autorenew=1
             AND (effectivefrom=0 OR effectivefrom<=:nowfrom)
             AND effectiveto>:nowto AND effectiveto<=:threshold",
            ['nowfrom'=>$now,'nowto'=>$now,'threshold'=>$threshold],
            'id ASC'
        );
        $renewed=0;
        foreach($rows as $row){
            $tx=$DB->start_delegated_transaction();
            try {
                $at=time();
                $current=$DB->get_record_sql('SELECT * FROM {local_ustar_assignments} WHERE id=:id FOR UPDATE',
                    ['id'=>(int)$row->id],MUST_EXIST);
                if ((string)$current->status==='active' && (int)$current->autorenew===1
                        && (int)$current->effectiveto>$at && (int)$current->effectiveto<=$at+(3*DAYSECS)
                        && ((int)$current->effectivefrom===0 || (int)$current->effectivefrom<=$at)
                        && \local_ustar\organization_model::staff_place((int)$current->staffplaceid,$at)) {
                    $nextend=self::month_end_after((int)$current->effectiveto);
                    if ($nextend>(int)$current->effectiveto
                            && \local_ustar\organization_model::staff_place((int)$current->staffplaceid,$nextend-1)) {
                        $previous=(int)$current->effectiveto;
                        $current->effectiveto=$nextend;
                        $current->timemodified=$at;$current->usermodified=0;
                        $DB->update_record('local_ustar_assignments',$current);
                        self::audit((int)$current->id,'acting_renewed',$at,[
                            'old_effectiveto'=>$previous,'new_effectiveto'=>$nextend,
                            'timezone'=>(string)(get_config('local_ustar','acting_renew_timezone') ?: 'Europe/Moscow'),
                        ]);
                        $renewed++;
                    }
                }
                $tx->allow_commit();
            } catch (\Throwable $e) {$tx->rollback($e);}
        }
        // Expired active rows retain their history, but cannot keep an active
        // lifecycle status or be revived by a late retry of this task.
        $expired=0;
        foreach($DB->get_records_select('local_ustar_assignments',
                "assignmenttype='acting' AND status='active' AND effectiveto>0 AND effectiveto<=:now",
                ['now'=>$now],'id ASC','*',0,500) as $row) {
            $tx=$DB->start_delegated_transaction();
            try {
                $current=$DB->get_record_sql('SELECT * FROM {local_ustar_assignments} WHERE id=:id FOR UPDATE',
                    ['id'=>(int)$row->id],MUST_EXIST);
                if ((string)$current->status==='active' && (int)$current->effectiveto>0
                        && (int)$current->effectiveto<=$now) {
                    $current->status='ended';$current->autorenew=0;
                    $current->timemodified=$now;$current->usermodified=0;
                    $DB->update_record('local_ustar_assignments',$current);
                    self::audit((int)$current->id,'acting_expired',$now,[
                        'effectiveto'=>(int)$current->effectiveto,
                    ]);
                    $expired++;
                }
                $tx->allow_commit();
            } catch (\Throwable $e) {$tx->rollback($e);}
        }
        if($renewed || $expired){
            \local_ustar\organization_model::rebuild_reporting();
        }
        mtrace('USTAR acting renewals: '.$renewed.', expired: '.$expired);
    }

    private static function audit(int $id,string $event,int $now,array $details): void {
        global $DB;
        $DB->insert_record('local_ustar_workflow_events',(object)[
            'entitytype'=>'acting_assignment','entityid'=>$id,'eventtype'=>$event,
            'actorid'=>0,'reason'=>'scheduled lifecycle',
            'detailsjson'=>json_encode($details,JSON_UNESCAPED_UNICODE),'timecreated'=>$now,
        ]);
    }
}
