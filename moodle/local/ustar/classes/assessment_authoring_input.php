<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();

/** Validated plain-text authoring contract. No HTML, file paths or SQL accepted. */
final class assessment_authoring_input {
    public const HEADER = ['type','text','option1','option2','option3','option4','correct','points','feedback'];

    public static function csv(string $csv): array {
        if (strlen($csv) > 262144 || !mb_check_encoding($csv, 'UTF-8')) {
            throw new \invalid_parameter_exception('CSV должен быть UTF-8 и не больше 256 КБ.');
        }
        $fp = fopen('php://temp', 'w+');
        fwrite($fp, preg_replace('/^\xEF\xBB\xBF/', '', $csv)); rewind($fp);
        try {
            if (fgetcsv($fp, 0, ';', '"', '') !== self::HEADER) {
                throw new \invalid_parameter_exception('Заголовок CSV не соответствует шаблону. Разделитель — точка с запятой.');
            }
            $rows = [];
            while (($row = fgetcsv($fp, 0, ';', '"', '')) !== false) {
                if ($row === [null]) {continue;}
                if (count($row) !== count(self::HEADER) || count($rows) >= 100) {
                    throw new \invalid_parameter_exception('CSV: неверное число столбцов или больше 100 вопросов.');
                }
                $rows[] = array_combine(self::HEADER, $row);
            }
            return self::questions($rows);
        } finally {fclose($fp);}
    }

    public static function questions(array $rows): array {
        if (count($rows) > 100) {throw new \invalid_parameter_exception('Максимум 100 вопросов.');}
        $out = [];
        foreach ($rows as $i=>$row) {
            if (!is_array($row)) {throw new \invalid_parameter_exception('Неверный вопрос.');}
            $item = [];
            foreach (self::HEADER as $field) {
                $value = $row[$field] ?? '';
                if (!is_scalar($value) || strlen((string)$value) > 24000) {
                    throw new \invalid_parameter_exception('Слишком длинное или некорректное поле вопроса.');
                }
                $item[$field] = trim((string)$value);
            }
            $prefix = 'Вопрос '.($i+1).': ';
            if (!in_array($item['type'], ['choice','essay'], true) || $item['text'] === '') {
                throw new \invalid_parameter_exception($prefix.'укажите тип и текст.');
            }
            if (!is_numeric($item['points']) || !is_finite((float)$item['points'])
                    || (float)$item['points'] < 0.1 || (float)$item['points'] > 100) {
                throw new \invalid_parameter_exception($prefix.'баллы должны быть от 0.1 до 100.');
            }
            if ($item['type'] === 'choice') {
                $answers = array_map(static fn($k)=>$item['option'.$k], range(1,4));
                $nonempty = array_values(array_filter($answers, static fn($v)=>$v!==''));
                if (count($nonempty) < 2 || count(array_unique($nonempty)) !== count($nonempty)
                        || array_slice($answers, 0, count($nonempty)) !== $nonempty
                        || !preg_match('/^[1-4]$/', $item['correct'])
                        || (int)$item['correct'] > count($nonempty)) {
                    throw new \invalid_parameter_exception($prefix.'нужны 2–4 разных варианта подряд и номер правильного ответа.');
                }
            } else {
                foreach (['option1','option2','option3','option4','correct'] as $field) {
                    if ($item[$field] !== '') {throw new \invalid_parameter_exception($prefix.'для открытого ответа варианты и правильный номер не задаются.');}
                }
            }
            $out[] = $item;
        }
        return $out;
    }
}
