<?php
namespace local_ustar\connect;
defined('MOODLE_INTERNAL') || die();

/** Bounded private image chunks; only an active photo question can receive a file. */
final class bot_upload {
    public const MAX_BYTES=524288;
    private static function capacity(int $userid): void {
        $dir=make_temp_directory('ustar-connect-bot');$own=0;$total=0;$seen=0;
        foreach(new \DirectoryIterator($dir) as $entry) {
            if($entry->isDot()){continue;}
            if(++$seen>512){throw new \InvalidArgumentException('rate_limited');}
            if(!$entry->isFile() || $entry->isLink() || !preg_match('/\A[0-9a-f]{64}\.json\z/',$entry->getFilename())){continue;}
            $meta=json_decode(file_get_contents($entry->getPathname()),true);
            if(is_array($meta) && (int)($meta['expires']??0)<time()) {
                $part=substr($entry->getPathname(),0,-5).'.part';
                if(is_file($part) && !is_link($part)){unlink($part);}unlink($entry->getPathname());continue;
            }
            $total++;if(is_array($meta) && (int)($meta['userid']??0)===$userid){$own++;}
        }
        if($own>=2 || $total>=128){throw new \InvalidArgumentException('rate_limited');}
    }
    private static function path(string $token): string {
        if(!preg_match('/\Abf4_[A-Za-z0-9_-]{43}\z/',$token)){throw new \InvalidArgumentException('invalid_bot_upload');}
        $dir=make_temp_directory('ustar-connect-bot');
        if(is_link($dir)){throw new \InvalidArgumentException('invalid_bot_upload');}
        chmod($dir,0700);
        return $dir.'/'.hash('sha256',$token);
    }
    public static function chunk(identity $sender,array $p): array {
        global $CFG;
        if(($CFG->ustar_connect_bot_enabled??false)!==true){throw new \InvalidArgumentException('bot_disabled');}
        if(!is_int($p['auth_date']) || $p['auth_date']<time()-120 || $p['auth_date']>time()+30
                || $sender->provider!=='telegram' || !preg_match('/\A[1-9][0-9]{0,15}\z/',$p['event'])
                || strlen($p['chunk'])>8000){throw new \InvalidArgumentException('invalid_bot_upload');}
        return identity_service::locked(function()use($sender,$p):array {
            $id=bot_actor::active($sender);
            if(!$id){throw new \InvalidArgumentException('actor_unavailable');}
            return bot_actor::run($id,function()use($sender,$p,$id):array {
                $d=bot_store::dialog($sender,$id,bot_actor::link($sender));$f=bot_store::data($d);
                if($d->phase!=='form.input' || ($f['fields'][$f['index']]['type']??'')!=='photo'){
                    throw new \InvalidArgumentException('invalid_bot_upload');
                }
                if($p['part']==='start') {
                    if($p['upload']!=='' || $p['chunk']!=='' || !preg_match('/\A[A-Za-z0-9_-]{1,256}\z/',$p['file'])){throw new \InvalidArgumentException('invalid_bot_upload');}
                    if(count(get_file_storage()->get_area_files(\context_user::instance($id)->id,'local_ustar','connectbotdraft',$d->id,'',false))>=5){throw new \InvalidArgumentException('invalid_bot_upload');}
                    self::capacity($id);
                    $token='bf4_'.rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');$base=self::path($token);
                    $meta=['userid'=>$id,'authority'=>$d->authority,'revision'=>(int)$d->revision,'next'=>1,'size'=>0,
                        'event'=>$p['event'],'expires'=>time()+300,'file'=>$p['file']];
                    $mask=umask(0077);
                    try {
                        $h=fopen($base.'.part','x');if(!$h){throw new \InvalidArgumentException('invalid_bot_upload');}fclose($h);
                        file_put_contents($base.'.json',json_encode($meta,JSON_THROW_ON_ERROR),LOCK_EX);
                    }finally{umask($mask);}
                    return ['upload'=>$token,'next'=>1,'complete'=>false];
                }
                $base=self::path($p['upload']);
                foreach(['.json','.part'] as $suffix){if(is_link($base.$suffix)||!is_file($base.$suffix)){throw new \InvalidArgumentException('upload_expired');}}
                $meta=json_decode(file_get_contents($base.'.json'),true,16,JSON_THROW_ON_ERROR);
                if($meta['userid']!==$id || !hash_equals($meta['authority'],$d->authority)
                        || $meta['revision']!==(int)$d->revision || $meta['event']!==$p['event'] || $meta['expires']<=time()){
                    throw new \InvalidArgumentException('upload_expired');
                }
                if($p['file']!==''){throw new \InvalidArgumentException('invalid_bot_upload');}
                if($p['part']==='finish') {
                    if($p['chunk']!=='' || !$meta['size'] || filesize($base.'.part')!==$meta['size']){throw new \InvalidArgumentException('invalid_bot_upload');}
                    $image=@getimagesize($base.'.part');$ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
                    if(!$image || !isset($ext[$image['mime']]) || $image[0]*$image[1]>10000000){throw new \InvalidArgumentException('invalid_bot_upload');}
                    get_file_storage()->create_file_from_pathname([
                        'contextid'=>\context_user::instance($id)->id,'component'=>'local_ustar','filearea'=>'connectbotdraft',
                        'itemid'=>$d->id,'filepath'=>'/','filename'=>hash('sha256',$p['upload']).'.'.$ext[$image['mime']],
                        'userid'=>$id,'mimetype'=>$image['mime'],'source'=>json_encode(['authority'=>$d->authority,'expires'=>time()+900,
                            'telegram_file_id'=>$meta['file'],'identityhash'=>$sender->key()],JSON_THROW_ON_ERROR)],$base.'.part');
                    unlink($base.'.part');unlink($base.'.json');
                    return ['upload'=>$p['upload'],'next'=>$meta['next'],'complete'=>true];
                }
                if(!preg_match('/\A[1-9][0-9]{0,2}\z/',$p['part']) || (int)$p['part']!==$meta['next'] || $meta['next']>88){throw new \InvalidArgumentException('invalid_bot_upload');}
                $bytes=base64_decode($p['chunk'],true);
                if($bytes===false || $bytes==='' || base64_encode($bytes)!==$p['chunk'] || strlen($bytes)>6000
                        || $meta['size']+strlen($bytes)>self::MAX_BYTES){throw new \InvalidArgumentException('invalid_bot_upload');}
                file_put_contents($base.'.part',$bytes,FILE_APPEND|LOCK_EX);$meta['next']++;$meta['size']+=strlen($bytes);
                file_put_contents($base.'.json',json_encode($meta,JSON_THROW_ON_ERROR),LOCK_EX);
                return ['upload'=>$p['upload'],'next'=>$meta['next'],'complete'=>false];
            });
        });
    }
    public static function received(\stdClass $d,string $token): array {
        self::path($token);$id=(int)$d->userid;$fs=get_file_storage();
        foreach(['jpg','png','webp'] as $ext){
            $file=$fs->get_file(\context_user::instance($id)->id,'local_ustar','connectbotdraft',$d->id,'/',hash('sha256',$token).'.'.$ext);
            if($file){$received=\local_ustar\task_workspace\received_photo::from_bot($file,$d->authority);return $received->upload();}
        }
        throw new \InvalidArgumentException('upload_expired');
    }
    public static function prune(): void {
        global $DB;
        $dir=make_temp_directory('ustar-connect-bot');$n=0;
        foreach(new \DirectoryIterator($dir) as $entry){
            if(++$n>1000){break;}
            if(!$entry->isLink() && $entry->isFile() && preg_match('/\A[0-9a-f]{64}\.(?:json|part)\z/',$entry->getFilename()) && $entry->getMTime()<time()-HOURSECS){unlink($entry->getPathname());}
        }
        $rows=$DB->get_records_select('files','component=:component AND filearea=:area AND timecreated<:cutoff',
            ['component'=>'local_ustar','area'=>'connectbotdraft','cutoff'=>time()-DAYSECS],'id','id',0,500);
        foreach($rows as $row){$file=get_file_storage()->get_file_by_id($row->id);if($file){$file->delete();}}
    }
}
