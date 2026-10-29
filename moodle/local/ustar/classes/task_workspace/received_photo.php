<?php
namespace local_ustar\task_workspace;
defined('MOODLE_INTERNAL') || die();

/** Trusted File API draft, not a caller supplied pathname or JSON upload descriptor. */
final class received_photo {
    private function __construct(private readonly \stored_file $file) {}
    public static function from_bot(\stored_file $file,string $authority): self {
        global $USER;
        $source=json_decode((string)$file->get_source(),true);
        if($file->get_component()!=='local_ustar' || $file->get_filearea()!=='connectbotdraft'
                || (int)$file->get_contextid()!==\context_user::instance((int)$USER->id)->id
                || (int)$file->get_userid()!==(int)$USER->id || $file->get_filesize()>524288
                || !is_array($source) || ($source['expires']??0)<=time()
                || !hash_equals($source['authority']??'',$authority)){
            throw new \invalid_parameter_exception('Фотография недоступна. Пришлите её заново.');
        }
        return new self($file);
    }
    public function file(): \stored_file { return $this->file; }
    public function upload(): array {
        $tmp=make_request_directory().'/'.$this->file->get_filename();
        $this->file->copy_content_to($tmp);
        return ['filename'=>$this->file->get_filename(),'tmp'=>$tmp,'size'=>(int)$this->file->get_filesize(),'received'=>$this];
    }
}
