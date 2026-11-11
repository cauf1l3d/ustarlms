<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * Deterministic quick-check generator for ONE catalog product.
 *
 * Same product + same catalog snapshot -> same questions. The pipeline runs
 * every few minutes; a check must not reshuffle its answers between runs,
 * or the stored input hash would churn and every run would rewrite content.
 *
 * Sources: the product's own attributesjson and its subgroup title.
 * No external calls, no image guessing.
 *
 * Output shape is what library_assessment reads:
 *   [ ['text' => ..., 'options' => [..], 'correct' => int], ... ]
 */
final class library_quick_generator {

    public const QUESTIONS = 2;
    public const OPTIONS = 4;

    /**
     * @param \stdClass $product        row, itemtype=product
     * @param \stdClass $subgroup       parent row, itemtype=subgroup
     * @param array<int,string> $subgrouppool  titles of OTHER active subgroups
     * @param array<int,string> $valuepool     values of the same attribute, other products
     * @return array<int,array{text:string,options:array<int,string>,correct:int}>
     */
    public static function generate(\stdClass $product, \stdClass $subgroup,
            array $subgrouppool, array $valuepool): array {

        $title = self::clean((string)$product->title, 120);
        if ($title === '') {
            return [];
        }
        $seed = self::seed((int)$product->id, (string)$product->title, (string)$product->attributesjson);

        $questions = [];

        $category = self::category_question($title, $subgroup, $subgrouppool, $seed);
        if ($category) {
            $questions[] = $category;
        }

        $attribute = self::attribute_question($product, $title, $valuepool, $seed);
        if ($attribute) {
            $questions[] = $attribute;
        }

        return array_slice($questions, 0, self::QUESTIONS);
    }

    /**
     * sha1 over the exact inputs of generate(). Stored with the assessment;
     * a different hash means the source changed and the check must be refreshed.
     */
    public static function input_hash(\stdClass $product, \stdClass $subgroup,
            array $subgrouppool, array $valuepool): string {
        sort($subgrouppool, SORT_STRING);
        sort($valuepool, SORT_STRING);
        return sha1(json_encode([
            'v' => 1,
            'product' => [(int)$product->id, (string)$product->title,
                (string)$product->attributesjson],
            'subgroup' => [(int)$subgroup->id, (string)$subgroup->title],
            'subgrouppool' => $subgrouppool,
            'valuepool' => $valuepool,
        ], JSON_UNESCAPED_UNICODE));
    }

    private static function category_question(string $title, \stdClass $subgroup,
            array $pool, int $seed): ?array {
        $correct = self::clean((string)$subgroup->title, 100);
        if ($correct === '') {
            return null;
        }
        $options = self::pick_options($correct, $pool, $seed);
        if ($options === null) {
            return null;
        }
        return [
            'text' => 'К какой категории относится «' . $title . '»?',
            'options' => $options['options'],
            'correct' => $options['correct'],
        ];
    }

    private static function attribute_question(\stdClass $product, string $title,
            array $valuepool, int $seed): ?array {
        $attribute = self::usable_attribute($product);
        if (!$attribute) { return null; }
        $options = self::pick_options($attribute['value'], $valuepool, $seed);
        if (!$options) { return null; }
        return ['text'=>'Что указано в поле «'.$attribute['name'].'» для товара «'.$title.'»?',
            'options'=>$options['options'],'correct'=>$options['correct']];
    }

    /** Shared by the question and its pool: never mix values from different attributes. */
    public static function usable_attribute(\stdClass $product): ?array {
        $attrs=json_decode((string)$product->attributesjson,true);
        if (!is_array($attrs)) { return null; }
        ksort($attrs,SORT_STRING);
        foreach ($attrs as $name=>$value) {
            if (str_starts_with((string)$name,'_') || !is_scalar($value) || is_bool($value)) { continue; }
            $label=self::clean((string)$name,60);$value=self::clean((string)$value,120);
            if ($label!=='' && $value!=='') { return ['key'=>(string)$name,'name'=>$label,'value'=>$value]; }
        }
        return null;
    }

    /**
     * Exactly OPTIONS answers: the correct one plus OPTIONS-1 distractors.
     * Distractors rotate by seed, the correct answer sits at a seed-derived
     * position. Returns null when there are not enough distinct distractors.
     *
     * @return array{options:array<int,string>,correct:int}|null
     */
    private static function pick_options(string $correct, array $pool, int $seed): ?array {
        $unique = [];
        foreach ($pool as $value) {
            $value = self::clean((string)$value, 120);
            if ($value !== '' && $value !== $correct) {
                $unique[$value] = true;
            }
        }
        $values = array_keys($unique);
        sort($values, SORT_STRING);

        $n = count($values);
        if ($n < self::OPTIONS - 1) {
            return null;
        }

        $offset = $seed % $n;
        $distractors = [];
        for ($i = 0; count($distractors) < self::OPTIONS - 1; $i++) {
            $distractors[] = $values[($offset + $i) % $n];
        }

        $position = ($seed >> 3) % self::OPTIONS;
        array_splice($distractors, $position, 0, [$correct]);

        return ['options' => $distractors, 'correct' => $position];
    }

    private static function seed(int $productid, string $title, string $attrs): int {
        return (int)hexdec(substr(sha1($productid . '|' . $title . '|' . $attrs), 0, 8));
    }

    private static function clean(string $value, int $limit): string {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = trim((string)preg_replace('/\s+/u', ' ', $value));
        return \core_text::substr($value, 0, $limit);
    }
}
