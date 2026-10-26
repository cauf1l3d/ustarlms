<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();
#[\PHPUnit\Framework\Attributes\CoversClass(course_studio_project::class)]
final class course_studio_project_test extends \advanced_testcase {
    public static function project(): \stdClass {
        return json_decode('{"data":{"code":"SALES-01","title":"Продажи","version":"1.0","pages":[{"id":"one","title":"Начало","kind":"lesson","html":"<p>Материал</p>"}]},"assets":{},"fonts":[],"theme":{"font":"Arial","headingFont":"Arial","fontSize":17,"headingSize":34,"lineHeight":1.65,"ink":"#2b2b2b","accent":"#ebc500","paper":"#ffffff","background":"#ededed"},"catalogue":{},"revision":0}');
    }
    public function test_valid_generic_project_and_stable_fingerprint(): void {
        $p = self::project();
        course_studio_project::validate($p);
        $this->assertSame(hash('sha256', course_studio_project::encode($p)), course_studio_project::fingerprint($p));
    }
    public function test_encoded_script_is_rejected(): void {
        $p = self::project(); $p->data->pages[0]->html = '<a href="jav&#97;script:alert(1)">Open</a>';
        $this->expectException(\invalid_parameter_exception::class);
        course_studio_project::validate($p);
    }
    public function test_nonlocal_image_is_rejected(): void {
        $p = self::project(); $p->data->pages[0]->html = '<img src="https://example.org/leak">';
        $this->expectException(\invalid_parameter_exception::class);
        course_studio_project::validate($p);
    }
    public function test_style_escape_is_rejected(): void {
        $p = self::project(); $p->data->pages[0]->html = '<p style="background:u\\72l(https://example.org)">x</p>';
        $this->expectException(\invalid_parameter_exception::class);
        course_studio_project::validate($p);
    }
    public function test_fake_image_bytes_are_rejected(): void {
        $this->expectException(\invalid_parameter_exception::class);
        course_studio_project::asset_type('<script>bad</script>', 'image/png');
    }
}
