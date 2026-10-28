<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();
/** Builds only the trusted local player and validated source; never executes an uploaded package. */
final class course_studio_package {
    private static function contrast_text(string $hex): string {
        $rgb = array_map(static function(string $channel): float {
            $value = hexdec($channel) / 255;
            return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, str_split(substr($hex, 1), 2));
        return 0.2126 * $rgb[0] + 0.7152 * $rgb[1] + 0.0722 * $rgb[2] > 0.1791287847 ? '#000000' : '#ffffff';
    }
    public static function build(\stdClass $p,string $path,int $actorid): void {
        course_studio_project::validate($p);
        $dir=__DIR__.'/../course_studio/';$esc=fn($x)=>htmlspecialchars((string)$x,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $nav='';foreach ($p->data->pages as $i=>$page) {
            $nav.='<button type="button" data-page="'.$i.'"><span class="navnum">'.sprintf('%02d',$i+1)
                .'</span><span>'.$esc($page->title).'</span><span class="mark" aria-hidden="true"></span></button>';
        }
        $data=clone $p->data;$data->revisionId=course_studio_project::fingerprint($p);
        $html=strtr(file_get_contents($dir.'player.html'),['{{TITLE}}'=>$esc($p->data->title),
            '{{CODE}}'=>$esc($p->data->code),'{{VERSION}}'=>$esc($p->data->version ?? '1.0'),'{{NAV}}'=>$nav]);
        $t=$p->theme;
        $navbg=$t->navBackground ?? '#2b2b2b';$navtext=$t->navText ?? '#ffffff';
        $navactive=$t->navActive ?? '#474747';$navaccent=$t->navAccent ?? '#ebc500';
        $navforeground=self::contrast_text($navactive);$accentforeground=self::contrast_text($t->accent);
        $css=file_get_contents($dir.'player.css')."\n:root{--ink:$t->ink;--yellow:$t->accent;--paper:$t->paper;--bg:$t->background;"
            ."--accent-ink:$accentforeground;--nav-bg:$navbg;--nav-text:$navtext;--nav-active:$navactive;--nav-accent:$navaccent;--nav-active-ink:$navforeground}"
            .'body{font-family:"'.$t->font.'",Arial,sans-serif;font-size:'.$t->fontSize.'px;line-height:'.$t->lineHeight.'}'
            .'.card h2{font-family:"'.$t->headingFont.'",Arial,sans-serif;font-size:'.$t->headingSize.'px}'
            .'.callout{padding:14px 18px;border-left:4px solid var(--yellow);background:#fff7cf;border-radius:0 8px 8px 0;margin:18px 0}'
            .'.callout-note{border-color:#557753;background:#f0f5ed}table{border-collapse:collapse;width:100%;margin:16px 0}'
            .'th,td{border:1px solid #cbd1c5;padding:10px}th{background:#f1f4eb}'
            .'.photo{margin:18px 0}.photo .picture{width:100%}.photo-caption,figcaption{display:block;font-size:14px;line-height:1.45;text-align:center;margin-top:8px}.picture img[style]{height:auto}';
        foreach ($p->fonts as $font) { $css.='@font-face{font-family:"'.$font->family.'";src:url("'.$font->path.'");font-display:swap}'; }
        $metadata=(object)['format'=>'ustar-rc-embedded-project','schemaVersion'=>1,'course'=>clone $p,'assets'=>new \stdClass()];
        unset($metadata->course->assets);
        $files=['index.html'=>$html,'style.css'=>$css,'course.js'=>file_get_contents($dir.'player.js'),
            'data.js'=>'window.COURSE='.str_replace('<','\\u003c',course_studio_project::encode($data)).';'];
        foreach ((array)$p->assets as $name=>$asset) {
            $file=course_studio::asset($asset->hash,$actorid);
            if (!$file || (int)$file->get_filesize()!==$asset->size) { throw new \invalid_parameter_exception('Утрачен ресурс курса.'); }
            $files[$name]=$file->get_content();$metadata->assets->$name=(object)['type'=>$asset->type];
        }
        $files['editor.json']=course_studio_project::encode($metadata);
        $manifest='<?xml version="1.0" encoding="UTF-8"?><manifest identifier="USTAR-'.$esc($p->data->code).'" version="1.2"'
            .' xmlns="http://www.imsproject.org/xsd/imscp_rootv1p1p2" xmlns:adlcp="http://www.adlnet.org/xsd/adlcp_rootv1p2">'
            .'<metadata><schema>ADL SCORM</schema><schemaversion>1.2</schemaversion></metadata>'
            .'<organizations default="ORG"><organization identifier="ORG"><title>'.$esc($p->data->title).'</title>'
            .'<item identifier="ITEM" identifierref="SCO"><title>'.$esc($p->data->title).'</title></item></organization></organizations>'
            .'<resources><resource identifier="SCO" type="webcontent" adlcp:scormtype="sco" href="index.html">';
        foreach ($files as $name=>$bytes) { $manifest.='<file href="'.$esc($name).'"/>'; }
        $files['imsmanifest.xml']=$manifest.'</resource></resources></manifest>';
        $zip=new \ZipArchive();
        if ($zip->open($path,\ZipArchive::CREATE|\ZipArchive::OVERWRITE)!==true) { throw new \invalid_parameter_exception('Не удалось собрать SCORM.'); }
        try { foreach ($files as $name=>$bytes) {
            if (!$zip->addFromString($name,$bytes)) { throw new \invalid_parameter_exception('Не удалось записать SCORM.'); }
            $zip->setMtimeName($name,315532800);
        } } finally { $zip->close(); }
        if (filesize($path)>100*1024*1024) { throw new \invalid_parameter_exception('SCORM ZIP превышает 100 МБ.'); }
    }
}
