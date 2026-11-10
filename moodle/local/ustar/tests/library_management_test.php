<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();
require_once(__DIR__.'/fixtures/library_fixture.php');
#[\PHPUnit\Framework\Attributes\CoversClass(library_manage_reader::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(library_manage_form::class)]
final class library_management_test extends \advanced_testcase {
    use library_fixture;
    protected function setUp(): void {parent::setUp();$this->resetAfterTest(true);$this->setAdminUser();accounts::ensure_profile_field();}

    public function test_subject_search_and_selected_binding_past_500(): void {
        $actor=(int)get_admin()->id;$last=0;
        for ($i=0;$i<501;$i++) { $last=$this->product(sprintf('A material %04d',$i)); }
        global $DB;$DB->set_field('local_ustar_catalog','title','Z oldest material',['id'=>$last]);
        $this->product('Z folder',0,'{}','group');
        $first=library_manage_reader::subjects($actor,'catalog','',0,'catalog:'.$last);
        $selected=array_values(array_filter($first['items'],static fn($row)=>$row['selected']));
        $this->assertSame('catalog:'.$last,$selected[0]['value']);$this->assertCount(1,$selected);$this->assertTrue($first['hasmore']);
        $found=library_manage_reader::subjects($actor,'catalog','oldest',0);
        $this->assertSame(['catalog:'.$last],array_column($found['items'],'value'));$this->assertFalse($found['hasmore']);
        $end=library_manage_reader::subjects($actor,'catalog','',10);
        $this->assertCount(1,$end['items']);$this->assertSame('catalog:'.$last,$end['items'][0]['value']);
        $this->assertFalse($end['hasmore'],'Excluded folder must not create another page');
    }
    public function test_history_finds_oldest_101st_and_search_escapes_wildcards(): void {
        global $DB;$actor=(int)get_admin()->id;$user=$this->employee();$offer=$this->offer('assigned',0);$first=0;
        for($i=0;$i<101;$i++) { $id=$this->grant($offer,$user);$first=$first?:$id; }
        $end=library_manage_reader::history($actor,'',5,time());
        $this->assertCount(1,$end['items']);$this->assertSame($first,$end['items'][0]['id']);$this->assertFalse($end['hasmore']);
        $special=$this->offer('assigned',0);$DB->set_field('local_ustar_library_offer','title','Literal %_ title',['id'=>$special->id]);$g=$this->grant($special,$user);
        $found=library_manage_reader::history($actor,'%_',0,time());
        $this->assertSame([$g],array_column($found['items'],'id'),'SQL wildcards are literal user input');
    }
    public function test_denied_history_rows_do_not_create_a_visible_next_page(): void {
        global $DB;$actor=(int)get_admin()->id;$user=$this->employee();$closed=$this->employee();$offer=$this->offer('assigned',0);
        for($i=0;$i<20;$i++) { $this->grant($offer,$user); }
        for($i=0;$i<101;$i++) { $this->grant($offer,$closed); }
        $DB->set_field('local_ustar_employment','status','suspended',['userid'=>$closed->id]);
        $page=library_manage_reader::history($actor,'',0,time());
        $this->assertCount(20,$page['items']);$this->assertFalse($page['hasmore']);
    }
    public function test_picker_cannot_authorise_a_later_suspended_target(): void {
        global $DB;$actor=(int)get_admin()->id;$user=$this->employee();$offer=$this->offer('assigned',0);
        $page=library_manage_reader::targets($actor,$user->lastname,0);$this->assertContains((int)$user->id,array_column($page['items'],'value'));
        $DB->set_field('local_ustar_employment','status','suspended',['userid'=>$user->id]);
        $this->assertSame([],library_manage_reader::targets($actor,$user->lastname,0)['items']);
        try {library_grant::assign($offer->id,$user->id,$actor,'','Fixture',bin2hex(random_bytes(16)));$this->fail('Stale picker granted suspended target');}
        catch(\moodle_exception $e) {$this->assertSame(0,$DB->count_records('local_ustar_library_grant'));}
    }
    public function test_nonadmin_capability_without_scope_cannot_list_foreign_people_or_history(): void {
        $leader=$this->employee();$target=$this->employee();$offer=$this->offer('assigned',0);$this->grant($offer,$target);
        $role=create_role('Management fixture','managementfixture','');$ctx=\context_system::instance();
        assign_capability('local/ustar:use', CAP_ALLOW, $role, $ctx->id);
        assign_capability('local/ustar:librarygrant', CAP_ALLOW, $role, $ctx->id);
        role_assign($role,$leader->id,$ctx->id);$this->setUser($leader);
        $this->assertTrue(library_access::can_grant($leader->id));
        $this->assertSame([],library_manage_reader::history($leader->id,'',0,time())['items']);
        $this->assertFalse(library_manage_reader::history($leader->id,'',0,time())['hasmore']);
        $this->assertSame([],library_manage_reader::targets($leader->id,$target->lastname,0)['items']);
        $this->assertSame([],library_manage_reader::subjects($leader->id,'catalog','',0)['items']);
    }
    public function test_view_as_cannot_use_management_readers(): void {
        $tree=structure::get(structure::NAME_STRUCTURE);$tree['departments'][]=['id'=>'management_fixture','name'=>'Fixture'];
        $tree['positions'][]=['id'=>'management_fixture_job','name'=>'Fixture role','department'=>'management_fixture','level'=>1];structure::save(structure::NAME_STRUCTURE,$tree);
        view_as::set('management_fixture_job');
        try {
            $actor=(int)get_admin()->id;
            $this->assertSame([],library_manage_reader::subjects($actor,'catalog','',0)['items']);
            $this->assertSame([],library_manage_reader::targets($actor,'',0)['items']);
            $this->assertSame([],library_manage_reader::history($actor,'',0,time())['items']);
        } finally {view_as::clear();}
    }
    public function test_status_boundaries_are_presentation_only(): void {
        $now=1700000000;$base=['validfrom'=>$now,'validto'=>0,'revokedat'=>0,'refundedat'=>0];
        foreach([[$base,'active'],[array_replace($base,['validfrom'=>$now+1]),'future'],[array_replace($base,['validto'=>$now]),'expired'],
            [array_replace($base,['validto'=>$now+1]),'active'],[array_replace($base,['revokedat'=>1]),'revoked'],
            [array_replace($base,['revokedat'=>1,'refundedat'=>1]),'refunded']] as [$grant,$expected]) {
            $record=(object)$grant;$copy=clone $record;$this->assertSame($expected,library_manage_reader::grant_status($record,$now)['statuskey']);$this->assertEquals($copy,$record);
        }
    }
    public function test_rejected_form_projection_keeps_token_and_missing_correct_choice(): void {
        $rows=[5=>['text'=>'Typed question','options'=>['A','B'],'correct'=>[]]];
        $view=library_manage_form::assessment(null,['title'=>'Typed','expectedrevision'=>3,'questions'=>$rows,'passpercent'=>101]);
        $this->assertSame('Typed',$view['checktitle']);$this->assertSame('3',$view['checkrevision']);$this->assertSame('101',$view['passpercent']);
        $this->assertSame('5',$view['questionfields'][0]['index']);$this->assertEmpty(array_filter($view['questionfields'][0]['options'],static fn($o)=>$o['checked']));
        $malformed=library_manage_form::offer(null,['title'=>['invalid'],'summary'=>['invalid']]);$badpolicy=library_manage_form::offer(null,['accesspolicy'=>['invalid'],'visibility'=>['invalid']]);$this->assertSame('',$badpolicy['accesspolicy']);$this->assertSame('',$badpolicy['visibility']);$this->assertSame('',$malformed['title']);$this->assertSame('',$malformed['summary']);
    }
    public function test_canonical_question_codec_rejects_middle_blank_without_changing_answer(): void {
        $rows=[7=>['text'=>'Moved question','options'=>['A','B','','','',''],'correct'=>1],2=>['text'=>'Another question','options'=>['C','D'],'correct'=>0]];
        $result=library_assessment::questions(library_assessment::editor_questions($rows));
        $this->assertSame(['Moved question','Another question'],array_column($result,'text'));$this->assertSame([1,0],array_column($result,'correct'));
        $this->expectException(\moodle_exception::class);library_assessment::editor_questions([['text'=>'Gap','options'=>['A','','C'],'correct'=>2]]);
    }
    public function test_checks_beyond_first_100_are_searchable_and_paged(): void {
        global $DB;$actor=(int)get_admin()->id;$first=0;
        for($i=0;$i<101;$i++) {$id=$this->definition();$first=$first?:$id;}
        $DB->set_field('local_ustar_library_assessment','title','Oldest unique assessment',['id'=>$first]);
        $found=library_manage_reader::checks($actor,'Oldest unique',0);$this->assertSame([$first],array_map('intval',array_column($found['items'],'id')));$this->assertFalse($found['hasmore']);
        $end=library_manage_reader::checks($actor,'',5);$this->assertCount(1,$end['items']);$this->assertSame($first,(int)$end['items'][0]->id);
    }
}
