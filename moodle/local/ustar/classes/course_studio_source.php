<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();

/** Convert readable material source, resolving media only through the caller's authorized File API. */
final class course_studio_source {
    private const TAGS = ['p','div','span','section','article','button','img','br','li','figure','figcaption',
        'h1','h2','h3','h4','h5','h6','details','summary','strong','b','i','em','u','s','strike','del','sub','sup',
        'mark','small','ul','ol','a','blockquote','pre','code','table','thead','tbody','tfoot','tr','th','td','hr','font'];
    private const STYLES = ['color','background','background-color','font-size','font-family','font-weight','font-style',
        'line-height','text-align','text-decoration','text-indent','white-space','vertical-align','width','max-width',
        'height','max-height','margin','margin-left','margin-right','margin-top','margin-bottom','padding','padding-left',
        'padding-right','padding-top','padding-bottom','border','border-color','border-width','border-style','border-radius'];

    /** $resolve(string $source) returns ['path'=>string,'asset'=>verified manifest object]. */
    public static function from_pages(string $title, string $code, array $pages, callable $resolve): \stdClass {
        if (!$pages || count($pages) > 500) {
            throw new \invalid_parameter_exception('Материал должен содержать от 1 до 500 экранов.');
        }
        $project = (object)[
            'data'=>(object)['code'=>$code,'title'=>mb_substr(trim(strip_tags($title)),0,255),'version'=>'1.0','pages'=>[]],
            'assets'=>new \stdClass(),'fonts'=>[],
            'theme'=>(object)['font'=>'Arial','headingFont'=>'Arial','fontSize'=>17,'headingSize'=>34,
                'lineHeight'=>1.65,'ink'=>'#2b2b2b','accent'=>'#ebc500','paper'=>'#ffffff','background'=>'#ededed'],
            'catalogue'=>new \stdClass(),'revision'=>0,
        ];
        $total = 0;
        $media = static function(string $src) use ($project,$resolve): string {
            $item = $resolve($src);
            if (!is_array($item) || !course_studio_project::path($item['path'] ?? '')
                    || !is_object($item['asset'] ?? null)) {
                throw new \invalid_parameter_exception('Не удалось перенести изображение в студию.');
            }
            $path = $item['path'];
            if (isset($project->assets->$path) && $project->assets->$path != $item['asset']) {
                throw new \invalid_parameter_exception('Пути ресурсов материала не совпали.');
            }
            $project->assets->$path = $item['asset'];
            return $path;
        };
        foreach ($pages as $index=>$raw) {
            $raw = (array)$raw;
            $html = (string)($raw['html'] ?? $raw['body'] ?? '');
            $total += strlen($html);
            if (strlen($html) > 2000000 || $total > 5*1024*1024) {
                throw new \invalid_parameter_exception('Исходный текст превышает размер проекта студии.');
            }
            $html = self::html($html,$media);
            if (!empty($raw['image'])) {
                $path = $media((string)$raw['image']);
                $html .= '<p><img src="'.htmlspecialchars($path,ENT_QUOTES,'UTF-8').'" alt="'
                    .htmlspecialchars((string)($raw['title'] ?? ''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'"></p>';
            }
            if (trim(html_entity_decode(strip_tags($html),ENT_QUOTES|ENT_HTML5,'UTF-8')) === '' && !str_contains($html,'<img')) {
                throw new \invalid_parameter_exception('В материале нет редактируемого текста или изображений.');
            }
            $project->data->pages[] = (object)[
                'id'=>$code.'-'.($index+1),'title'=>mb_substr(trim(strip_tags((string)($raw['title'] ?? ''))),0,500) ?: 'Экран '.($index+1),
                'kind'=>'lesson','chapter'=>(string)($raw['chapter'] ?? ''),'html'=>$html,
                'source_text'=>(string)($raw['source_text'] ?? ''),'sources'=>[],
            ];
        }
        course_studio_project::validate($project);
        return $project;
    }

    private static function html(string $html, callable $media): string {
        $dom = new \DOMDocument();$out = new \DOMDocument('1.0','UTF-8');
        $previous = libxml_use_internal_errors(true);
        try { $dom->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>', LIBXML_NONET); }
        finally { libxml_clear_errors();libxml_use_internal_errors($previous); }
        if ($dom->getElementsByTagName('*')->length > 20000) {
            throw new \invalid_parameter_exception('Слишком сложная разметка материала.');
        }
        $copy = static function(\DOMNode $node, \DOMNode $parent, int $depth=0) use (&$copy,$out,$media): void {
            if ($depth > 40) { throw new \invalid_parameter_exception('Слишком глубокая разметка материала.'); }
            if ($node instanceof \DOMText) { $parent->appendChild($out->createTextNode($node->textContent));return; }
            if (!($node instanceof \DOMElement)) { return; }
            $tag = strtolower($node->tagName);
            if (in_array($tag,['script','style','iframe','object','embed','form','input','link','meta','base','svg'],true)) { return; }
            if (!in_array($tag,self::TAGS,true)) {
                foreach ($node->childNodes as $child) { $copy($child,$parent,$depth+1); }return;
            }
            $el = $out->createElement($tag);$parent->appendChild($el);
            if ($tag === 'img') {
                $el->setAttribute('src',$media($node->getAttribute('src')));
                $el->setAttribute('alt',$node->getAttribute('alt'));
                $el->setAttribute('loading','lazy');
            }
            if ($tag === 'a') {
                $href = trim($node->getAttribute('href'));
                if (preg_match('~^(https?://|mailto:|tel:|#)[^\x00-\x20]*$~iD',$href)) {
                    $el->setAttribute('href',$href);$el->setAttribute('rel','noopener noreferrer');
                }
            }
            foreach (['colspan','rowspan','width','height','start'] as $name) {
                $n = $node->getAttribute($name);
                if (preg_match('/^\d{1,4}$/D',$n) && (int)$n > 0) { $el->setAttribute($name,$n); }
            }
            if ($node->hasAttribute('class')) {
                $class = preg_replace('/[^a-zA-Z0-9_ -]/','',$node->getAttribute('class'));
                if ($class !== '') { $el->setAttribute('class',substr($class,0,200)); }
            }
            $rules = [];
            foreach (explode(';',$node->getAttribute('style')) as $rule) {
                $pair = explode(':',$rule,2);if (count($pair) !== 2) { continue; }
                [$name,$value] = array_map('trim',$pair);$name = strtolower($name);
                if (in_array($name,self::STYLES,true) && $value !== ''
                        && !preg_match('/[\\\\<>@{}]|url|expression|behavior|binding|javascript/i',$value)) {
                    $rules[] = $name.':'.$value;
                }
            }
            if ($rules) { $el->setAttribute('style',implode(';',$rules)); }
            foreach ($node->childNodes as $child) { $copy($child,$el,$depth+1); }
        };
        $root = $out->createElement('div');$out->appendChild($root);
        foreach ($dom->getElementsByTagName('body')->item(0)->childNodes as $node) { $copy($node,$root); }
        $result='';foreach ($root->childNodes as $node) { $result.=$out->saveHTML($node); }return $result;
    }
}
