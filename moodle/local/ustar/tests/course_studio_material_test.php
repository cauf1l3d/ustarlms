<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();

#[\PHPUnit\Framework\Attributes\CoversClass(course_studio_material::class)]
final class course_studio_material_test extends \advanced_testcase {
    protected function setUp(): void { parent::setUp();$this->resetAfterTest(true);$this->setAdminUser(); }
    private function native(): array {
        global $USER;
        return material_studio::save(0,['kind'=>'scorm','title'=>'Порядок работы',
            'scormpages'=>[['title'=>'Начало','body'=>'<p>Учебный материал</p>']]],$USER->id);
    }
    public function test_native_source_opens_one_shared_draft_and_keeps_original_scope(): void {
        global $DB,$USER;
        $source=$this->native();$id=$source['id'];
        $DB->set_field('local_ustar_content','status','published',['id'=>$id]);
        $DB->delete_records('local_ustar_content_access',['contentid'=>$id]);
        $DB->insert_record('local_ustar_content_access',(object)['contentid'=>$id,'scopetype'=>'position','scopeid'=>17,
            'active'=>1,'timecreated'=>time(),'createdby'=>$USER->id]);
        $before=$DB->get_record('local_ustar_content',['id'=>$id]);
        $first=course_studio_material::open($id,$USER->id);
        $again=course_studio_material::open($id,$USER->id);
        $this->assertSame('project',$first['kind']);$this->assertSame($first['contentid'],$again['contentid']);
        $this->assertNotSame($id,$first['contentid']);
        $this->assertEquals($before,$DB->get_record('local_ustar_content',['id'=>$id]));
        $this->assertSame(2,$DB->count_records('local_ustar_content'));
        $this->assertSame(1,$DB->count_records_select('local_ustar_workflow_events',
            'entitytype=:type AND entityid=:draft AND '.$DB->sql_compare_text('reason').'=:source',
            ['type'=>'studio_material_open','draft'=>$first['contentid'],'source'=>(string)$id]));
        $scope=$DB->get_record('local_ustar_content_access',['contentid'=>$first['contentid']], '*',MUST_EXIST);
        $this->assertSame('position',$scope->scopetype);$this->assertSame(17,(int)$scope->scopeid);
        $project=course_studio::get($first['code'],$USER->id);
        $this->assertStringContainsString('Учебный материал',$project['course']->data->pages[0]->html);
    }
    public function test_visual_draft_opens_in_place_without_copy(): void {
        global $USER,$DB;
        $p=course_studio_source::from_pages('Черновик','DRAFT-OPEN',[['title'=>'Экран','body'=>'Материал']],static fn()=>[]);
        $saved=course_studio::save($p,0,0,'create-draft',$USER->id);
        $opened=course_studio_material::open($saved['contentid'],$USER->id);
        $this->assertSame($saved['contentid'],$opened['contentid']);$this->assertSame('DRAFT-OPEN',$opened['code']);
        $this->assertSame(1,$DB->count_records('local_ustar_content'));
    }
    public function test_renamed_native_package_still_opens_the_exact_editable_source(): void {
        global $DB,$USER;
        $item=$this->native();material_studio::build_scorm($item['id'],$USER->id);
        $DB->set_field('local_ustar_content','title','Обновлённое название',['id'=>$item['id']]);
        $opened=course_studio_material::open($item['id'],$USER->id);
        $this->assertSame('project',$opened['kind']);
        $project=course_studio::get($opened['code'],$USER->id)['course'];
        $this->assertSame('Обновлённое название',$project->data->title);
        $this->assertStringContainsString('Учебный материал',$project->data->pages[0]->html);
    }
    public function test_published_visual_source_and_runtime_remain_unchanged(): void {
        global $USER,$DB;
        $p=course_studio_source::from_pages('Курс','PUBLISHED-OPEN',[['title'=>'Экран','body'=>'Материал']],static fn()=>[]);
        $saved=course_studio::save($p,0,0,'create-published',$USER->id);
        $ready=course_studio::prepare($saved['code'],$saved['revision'],$saved['contentmodified'],$USER->id);
        $DB->set_field('local_ustar_content','status','published',['id'=>$saved['contentid']]);
        $original=$DB->get_record('local_ustar_content',['id'=>$saved['contentid']]);
        $opened=course_studio_material::open($saved['contentid'],$USER->id);
        $this->assertNotSame($saved['contentid'],$opened['contentid']);
        $this->assertSame($ready['cmid'],(int)$DB->get_field('local_ustar_content','cmid',['id'=>$saved['contentid']]));
        $this->assertEquals($original,$DB->get_record('local_ustar_content',['id'=>$saved['contentid']]));
        $this->assertSame('draft',course_studio::get($opened['code'],$USER->id)['status']);
    }
    public function test_learner_cannot_import_a_material_source(): void {
        $source=$this->native();$learner=$this->getDataGenerator()->create_user();$this->setUser($learner);
        $this->expectException(\required_capability_exception::class);course_studio_material::open($source['id'],$learner->id);
    }
    public function test_assessment_is_not_silently_converted_to_a_course(): void {
        global $USER;
        $item=material_studio::save(0,['kind'=>'assessment','title'=>'Проверка','questions'=>'Вопрос | Да | Нет | 1'],$USER->id);
        $this->expectException(\invalid_parameter_exception::class);course_studio_material::open($item['id'],$USER->id);
    }
    public function test_file_source_token_prevents_import_of_a_changed_material(): void {
        global $USER,$DB;
        // The refusal rolls back its real request transaction. The next call models
        // a new HTTP request, outside PHPUnit's enclosing transaction.
        $this->preventResetByRollback();
        $id=$DB->insert_record('local_ustar_content',(object)['title'=>'Инструкция','type'=>'document',
            'sourcekind'=>content::SOURCE_FILE,'status'=>'published','timemodified'=>123]);
        $version=$DB->insert_record('local_ustar_content_versions',(object)['contentid'=>$id,'versionno'=>1,
            'status'=>'published','iscurrent'=>1]);
        get_file_storage()->create_file_from_string((object)['contextid'=>\context_system::instance()->id,
            'component'=>'local_ustar','filearea'=>'content_version','itemid'=>$version,'filepath'=>'/',
            'filename'=>'Инструкция.txt','mimetype'=>'text/plain'],'Текст инструкции');
        $source=course_studio_material::open($id,$USER->id);$this->assertSame('file',$source['kind']);
        $project=course_studio_source::from_pages('Инструкция','FILE-IMPORT',[['title'=>'Экран','body'=>'Текст инструкции']],static fn()=>[]);
        $DB->set_field('local_ustar_content','timemodified',124,['id'=>$id]);
        try { course_studio_material::complete($id,$project,$source['sourceToken'],$USER->id);$this->fail('stale source accepted'); }
        catch (course_studio_conflict $e) { $this->assertSame(1,$DB->count_records('local_ustar_content')); }
        $fresh=course_studio_material::open($id,$USER->id);
        $first=course_studio_material::complete($id,$project,$fresh['sourceToken'],$USER->id);
        $again=course_studio_material::open($id,$USER->id);
        $this->assertSame($first['contentid'],$again['contentid']);$this->assertSame(2,$DB->count_records('local_ustar_content'));
        $this->assertSame('published',$DB->get_field('local_ustar_content','status',['id'=>$id]));
        $this->assertFalse($DB->record_exists('local_ustar_content_access',['contentid'=>$first['contentid']]));
    }
    public function test_moodle_page_images_are_resolved_from_its_own_file_area(): void {
        global $USER,$DB;
        $course=$this->getDataGenerator()->create_course();
        $page=$this->getDataGenerator()->create_module('page',['course'=>$course->id,'name'=>'Тема',
            'content'=>'<p>Учебный текст<img src="@@PLUGINFILE@@/picture.png" alt="Схема"></p>']);
        $bytes=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jZLkAAAAASUVORK5CYII=');
        get_file_storage()->create_file_from_string((object)['contextid'=>\context_module::instance($page->cmid)->id,
            'component'=>'mod_page','filearea'=>'content','itemid'=>0,'filepath'=>'/','filename'=>'picture.png',
            'mimetype'=>'image/png'],$bytes);
        $id=$DB->insert_record('local_ustar_content',(object)['title'=>'Тема','type'=>'article',
            'sourcekind'=>content::SOURCE_MOODLE,'status'=>'published','courseid'=>$course->id,'cmid'=>$page->cmid]);
        $opened=course_studio_material::open($id,$USER->id);$project=course_studio::get($opened['code'],$USER->id)['course'];
        $this->assertCount(1,(array)$project->assets);
        $this->assertStringContainsString('assets/material-', $project->data->pages[0]->html);
        $this->assertStringNotContainsString('@@PLUGINFILE@@',$project->data->pages[0]->html);
        $assets=(array)$project->assets;$asset=reset($assets);$this->assertSame(hash('sha256',$bytes),$asset->hash);
        $this->assertSame($bytes,course_studio::asset($asset->hash,$USER->id)->get_content());
    }
    public function test_archived_scorm_opens_a_shared_draft_without_restoring_or_replacing_original(): void {
        global $DB,$USER;
        $item=$this->native();material_studio::build_scorm($item['id'],$USER->id);
        $DB->set_field('local_ustar_content','status','archived',['id'=>$item['id']]);
        $before=$DB->get_record('local_ustar_content',['id'=>$item['id']]);
        $this->assertTrue(course_studio_material::supported($before));
        $first=course_studio_material::open($item['id'],$USER->id);
        $again=course_studio_material::open($item['id'],$USER->id);
        $this->assertNotSame($item['id'],$first['contentid']);
        $this->assertSame($first['contentid'],$again['contentid']);
        $this->assertEquals($before,$DB->get_record('local_ustar_content',['id'=>$item['id']]));
        $draft=course_studio::get($first['code'],$USER->id);
        $this->assertSame('draft',$draft['status']);
        $this->assertStringContainsString('Учебный материал',$draft['course']->data->pages[0]->html);
    }
    public function test_native_moodle_scorm_keeps_package_and_original_activity_when_opened_for_editing(): void {
        global $DB,$USER;
        $course=$this->getDataGenerator()->create_course();
        $scorm=$this->getDataGenerator()->create_module('scorm',['course'=>$course->id]);
        $context=\context_module::instance($scorm->cmid);
        $fs=get_file_storage();$fs->delete_area_files($context->id,'mod_scorm','package',0);
        $file=$fs->create_file_from_string(['contextid'=>$context->id,'component'=>'mod_scorm','filearea'=>'package',
            'itemid'=>0,'filepath'=>'/','filename'=>'legacy.zip','mimetype'=>'application/zip'],'fixture-package-bytes');
        $id=$DB->insert_record('local_ustar_content',(object)['title'=>'Архивный SCORM','type'=>'scorm',
            'sourcekind'=>content::SOURCE_MOODLE,'status'=>'archived','courseid'=>$course->id,'cmid'=>$scorm->cmid]);
        $before=$DB->get_record('scorm',['id'=>$scorm->id]);
        $opened=course_studio_material::open($id,$USER->id);
        $this->assertSame('file',$opened['kind']);$this->assertSame('scorm',$opened['format']);
        $this->assertSame('legacy.zip',$opened['filename']);
        $this->assertSame($file->get_contenthash(),course_studio_material::file($id,$USER->id)->get_contenthash());
        $this->assertEquals($before,$DB->get_record('scorm',['id'=>$scorm->id]));
        $project=course_studio_source::from_pages('Изменено','LEGACY-EDIT',[['title'=>'Экран','body'=>'Изменено']],static fn()=>[]);
        $draft=course_studio_material::complete($id,$project,$opened['sourceToken'],$USER->id);
        $this->assertNotSame($id,$draft['contentid']);$this->assertSame('archived',$DB->get_field('local_ustar_content','status',['id'=>$id]));
        $this->assertEquals($before,$DB->get_record('scorm',['id'=>$scorm->id]));
    }
    public function test_archived_source_still_refuses_a_learner(): void {
        global $DB;
        $source=$this->native();$DB->set_field('local_ustar_content','status','archived',['id'=>$source['id']]);
        $learner=$this->getDataGenerator()->create_user();$this->setUser($learner);
        $this->expectException(\required_capability_exception::class);
        course_studio_material::open($source['id'],$learner->id);
    }

}
