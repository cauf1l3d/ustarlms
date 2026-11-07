<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();
trait library_fixture {
    private function employee(): \stdClass {
        global $DB;$u=$this->getDataGenerator()->create_user();
        $DB->insert_record('local_ustar_employment',(object)['userid'=>$u->id,'status'=>'active','source'=>'fixture','usermodified'=>0,'timecreated'=>time(),'timemodified'=>time()]);
        return $u;
    }
    private function product(string $title='Bolt',int $parent=0,string $attributes='{}',string $type='product'): int {
        global $DB;
        return (int)$DB->insert_record('local_ustar_catalog',(object)['title'=>$title,'parentid'=>$parent?:null,'itemtype'=>$type,
            'attributesjson'=>$attributes,'active'=>1,'summary'=>'Fixture summary','timecreated'=>time(),'timemodified'=>time()]);
    }
    private function offer(string $policy='paid',int $price=10,?int $product=null): \stdClass {
        global $DB;
        $id=(int)$DB->insert_record('local_ustar_library_offer',(object)['itemtype'=>'catalog','itemid'=>$product??$this->product(),
            'title'=>'Fixture knowledge','summary'=>'Summary','pricecoin'=>$price,'accesspolicy'=>$policy,'gatepointid'=>0,
            'visibility'=>'published','sortorder'=>0,'timecreated'=>time(),'timemodified'=>time(),'usermodified'=>0]);
        return library_offer::get($id);
    }
    private function grant(\stdClass $offer,\stdClass $user,array $extra=[]): int {
        global $DB;
        return (int)$DB->insert_record('local_ustar_library_grant',(object)($extra+['offerid'=>$offer->id,'userid'=>$user->id,
            'source'=>'admin','grantedby'=>get_admin()->id,'ledgerid'=>0,'validfrom'=>time()-1,'validto'=>0,
            'revokedat'=>0,'revokerid'=>0,'refundedat'=>0,'comment'=>'Fixture','idempotencykey'=>bin2hex(random_bytes(16)),'timecreated'=>time()]));
    }
    private function definition(int $count=2,int $pass=100,int $attempts=3): int {
        global $DB;$questions=[];for($i=0;$i<$count;$i++){$questions[]=['text'=>'Question '.$i,'options'=>['True','False'],'correct'=>0];}
        return (int)$DB->insert_record('local_ustar_library_assessment',(object)['sourcekey'=>'manual:'.random_string(20),'targettype'=>'topic','targetkey'=>'Fixture',
            'title'=>'Fixture assessment','questionsjson'=>json_encode($questions),'questioncount'=>$count,'passpercent'=>$pass,'maxattempts'=>$attempts,
            'status'=>'published','revision'=>1,'inputhash'=>'','timecreated'=>time(),'timemodified'=>time(),'usermodified'=>0]);
    }
    private function contentitem(bool $audience=true): int {
        global $DB;
        $id=(int)$DB->insert_record('local_ustar_content',(object)['title'=>'Fixture material','type'=>'document','status'=>'published','sourcekind'=>'ustar_file']);
        if ($audience) { $DB->insert_record('local_ustar_content_access',(object)['contentid'=>$id,'scopetype'=>'all','active'=>1,'createdby'=>0]); }
        return $id;
    }
}
