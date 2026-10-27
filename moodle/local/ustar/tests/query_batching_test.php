<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();

#[\PHPUnit\Framework\Attributes\CoversClass(feed_article_resolver::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(competition::class)]
final class query_batching_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();
    }

    public function test_rss_batch_preserves_multiple_statuses_and_source_scope_in_one_read(): void {
        global $DB;
        foreach ([[101,'pending'],[101,'pending'],[101,'done'],[101,'disabled'],
                  [102,'retry'],[102,'done'],[104,'done']] as $i => [$sourceid,$status]) {
            // No foreign keys link sourceitem to posts/sources; only the relevant SQL rows are needed here.
            $DB->insert_record('local_ustar_feed_sourceitem', (object)[
                'sourceid'=>$sourceid, 'postid'=>900+$i, 'guidhash'=>hash('sha256','batch-'.$i),
                'externalguid'=>'batch-'.$i, 'externalurl'=>'https://example.invalid/'.$i,
                'title'=>'Batch fixture', 'enrichstatus'=>$status, 'publishedat'=>time(), 'timecreated'=>time(),
            ]);
        }
        $before = $DB->perf_get_reads();
        $rows = feed_article_resolver::source_stats_batch([101,102,103,101]);
        $this->assertSame(1, $DB->perf_get_reads()-$before);
        $this->assertSame([101,102,103], array_keys($rows));
        $this->assertSame(['pending'=>2,'retry'=>0,'done'=>1,'failed'=>0,'limited'=>0,'feed'=>0,'disabled'=>1], $rows[101]);
        $this->assertSame(['pending'=>0,'retry'=>1,'done'=>1,'failed'=>0,'limited'=>0,'feed'=>0], $rows[102]);
        $this->assertSame(['pending'=>0,'retry'=>0,'done'=>0,'failed'=>0,'limited'=>0,'feed'=>0], $rows[103]);
        foreach ([101,102,103] as $id) {
            $this->assertSame(feed_article_resolver::source_stats($id), $rows[$id]);
        }
        $before = $DB->perf_get_reads();
        $this->assertSame([], feed_article_resolver::source_stats_batch([]));
        $this->assertSame($before, $DB->perf_get_reads());
    }

    public function test_closed_operator_names_are_batched_without_changing_snapshot_rows(): void {
        global $DB, $USER;
        $dept = competition::department_options()[0]['id'];
        $id = competition::create_draft('batch_closed','Closed fixture',$dept,time()-100,time()+100,1,(int)$USER->id);
        $ruleid = (int)$DB->get_field('local_ustar_comp_rules','id',['competitionid'=>$id]);
        for ($i=1; $i<=20; $i++) {
            $user = $this->getDataGenerator()->create_user(['firstname'=>'Batch'.$i,'lastname'=>'Employee']);
            $participantid = $DB->insert_record('local_ustar_comp_participants',(object)[
                'competitionid'=>$id,'userid'=>$user->id,'publiclabel'=>'Old label'.$i,
                'audiencekey'=>$dept,'status'=>'active','joinedat'=>time(),'timecreated'=>time(),
            ]);
            $DB->insert_record('local_ustar_comp_results',(object)[
                'competitionid'=>$id,'participantid'=>$participantid,'ruleversionid'=>$ruleid,
                'rankno'=>$i<=2 ? 1 : $i,'points'=>100-$i,'tiekey'=>'batch-'.$i,
                'status'=>'final','finalizedat'=>time(),
            ]);
        }
        $DB->set_field('local_ustar_competitions','status','closed',['id'=>$id]);
        $before = $DB->perf_get_reads();
        $rows = competition::operator_season($id,(int)$USER->id)['scores'];
        $reads = $DB->perf_get_reads()-$before;
        $this->assertCount(20, $rows);
        $this->assertLessThanOrEqual(10, $reads, 'Closed operator reads must not grow once per snapshot participant');
        $this->assertSame('Batch1 Employee', $rows[0]->displayname);
        $this->assertSame('Batch20 Employee', $rows[19]->displayname);
        $this->assertSame([1,1,3], array_map(static fn($row)=>(int)$row->rank,array_slice($rows,0,3)));
        $this->assertSame(['id','rank','points','displayname','userid'], array_keys((array)$rows[0]));
    }
}
