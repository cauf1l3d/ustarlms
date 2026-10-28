<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();

#[\PHPUnit\Framework\Attributes\CoversClass(material_catalog::class)]
final class material_catalog_test extends \advanced_testcase {
    protected function setUp(): void { parent::setUp();$this->resetAfterTest(true);$this->setAdminUser(); }
    private function item(string $title,array $extra=[]): int {
        global $DB;
        return $DB->insert_record('local_ustar_content',(object)array_merge([
            'title'=>$title,'type'=>'document','status'=>'published','sourcekind'=>content::SOURCE_FILE],$extra));
    }
    public function test_bounded_pages_keep_deterministic_order_filters_and_selected_detail(): void {
        $folder=$this->item('Папка',['type'=>'folder','sourcekind'=>'folder']);
        $ids=[];for($i=0;$i<125;$i++)$ids[]=$this->item(sprintf('Инструкция %03d',$i),['parentid'=>$folder,'summary'=>'Учебный текст']);
        $outside=$this->item('Вне папки');
        $first=material_catalog::read(['parent'=>$folder]);$second=material_catalog::read(['parent'=>$folder],1);
        $this->assertCount(60,$first['records']);$this->assertCount(60,$second['records']);
        $this->assertSame(125,$first['total']);$this->assertSame($ids[0],(int)reset($first['records'])->id);
        $this->assertSame($ids[60],(int)reset($second['records'])->id);
        $last=material_catalog::read(['parent'=>$folder],9999);$this->assertSame(2,$last['page']);$this->assertCount(5,$last['records']);
        $found=material_catalog::read(['parent'=>$folder,'q'=>'Инструкция 124']);$this->assertSame(1,$found['total']);
        $detail=material_catalog::read(['parent'=>$folder,'q'=>'no match','status'=>'archived'],0,$outside);
        $this->assertSame([$outside],array_keys($detail['records']),'direct detail is independent of catalogue filters and page');
        $this->assertSame(0,material_catalog::read(['parent'=>$folder,'status'=>'draft'])['total']);
    }
    public function test_file_metadata_is_batched_and_uses_the_current_version(): void {
        global $DB;
        $records=[];$context=\context_system::instance()->id;
        for($i=0;$i<60;$i++){
            $id=$this->item('Документ '.$i);$records[$id]=$DB->get_record('local_ustar_content',['id'=>$id]);
            foreach([1,2] as $n){
                $v=$DB->insert_record('local_ustar_content_versions',(object)['contentid'=>$id,'versionno'=>$n,'iscurrent'=>1]);
                get_file_storage()->create_file_from_string((object)['contextid'=>$context,'component'=>'local_ustar',
                    'filearea'=>'content_version','itemid'=>$v,'filepath'=>'/','filename'=>$n===2?'Актуальный.docx':'Старый.pdf'],'metadata fixture');
            }
        }
        $before=$DB->perf_get_queries();$labels=material_catalog::file_labels($records,$context);$queries=$DB->perf_get_queries()-$before;
        $this->assertLessThanOrEqual(2,$queries,'metadata query count must not grow with the number of rows');
        $this->assertCount(60,$labels);
        foreach($labels as $label){$this->assertSame('Актуальный.docx',$label['filename']);$this->assertSame('doc',$label['class']);}
    }
    public function test_resource_uses_its_main_file_instead_of_first_alphabetical_asset(): void {
        global $DB;
        $course=$this->getDataGenerator()->create_course();$resource=$this->getDataGenerator()->create_module('resource',['course'=>$course->id]);
        $context=\context_module::instance($resource->cmid)->id;$fs=get_file_storage();$fs->delete_area_files($context,'mod_resource','content');
        foreach([['a-image.png',0],['Main.pdf',1]] as [$name,$order])$fs->create_file_from_string((object)[
            'contextid'=>$context,'component'=>'mod_resource','filearea'=>'content','itemid'=>0,'filepath'=>'/','filename'=>$name,'sortorder'=>$order],'file');
        $id=$this->item('PDF',['sourcekind'=>content::SOURCE_MOODLE,'courseid'=>$course->id,'cmid'=>$resource->cmid]);
        $record=$DB->get_record('local_ustar_content',['id'=>$id]);$record->modname='resource';
        $label=material_catalog::file_labels([$id=>$record],\context_system::instance()->id)[$id];
        $this->assertSame('Main.pdf',$label['filename']);$this->assertSame('pdf',$label['class']);
    }
    public function test_editing_drafts_are_not_reported_as_duplicate_publications(): void {
        global $DB;
        $a=$this->item('Порядок');$draft=$this->item('Порядок',['status'=>'draft']);
        $records=$DB->get_records('local_ustar_content');$this->assertSame([],material_catalog::duplicates($records));
        $b=$this->item('Порядок');$flags=material_catalog::duplicates($DB->get_records('local_ustar_content'));
        $this->assertTrue($flags[$a]);$this->assertTrue($flags[$b]);$this->assertArrayNotHasKey($draft,$flags);
    }
}
