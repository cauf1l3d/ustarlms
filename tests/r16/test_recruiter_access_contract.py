from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]


class RecruiterAccessContract(unittest.TestCase):
    def test_recruiter_has_reduced_hr_shell(self):
        layout = (
            ROOT / 'moodle/theme/ustar/layout/ustar.php'
        ).read_text(encoding='utf-8')
        self.assertIn("hr_access::is_recruiter", layout)
        self.assertIn("if (!$isrecruiter)", layout)
        self.assertIn("'Панель HR'", layout)
        self.assertIn("'Материалы'", layout)
        self.assertIn("'Контроль'", layout)
        self.assertIn("'Проверка аттестаций'", layout)

    def test_position_architecture_requires_non_recruiter_hrd_boundary(self):
        for rel in [
            'moodle/local/ustar/positions.php',
            'moodle/local/ustar/organization_settings.php',
            'moodle/local/ustar/route_studio.php',
            'moodle/local/ustar/grade_ladders.php',
            'moodle/local/ustar/grade_rules.php',
        ]:
            source = (ROOT / rel).read_text(encoding='utf-8')
            self.assertIn("hr_access::require_structure_manager", source, rel)

    def test_recruiter_manual_grading_does_not_expose_hrd_escalations(self):
        grading = (
            ROOT / 'moodle/local/ustar/hr_quiz_grading.php'
        ).read_text(encoding='utf-8')
        lifecycle = (
            ROOT / 'moodle/local/ustar/classes/assessment_lifecycle.php'
        ).read_text(encoding='utf-8')
        self.assertIn("hr_access::can_view_hrd_escalations", grading)
        self.assertIn("hr_access::can_view_hrd_escalations($actorid)", lifecycle)
        self.assertIn("hr_access::can_view_hrd_escalations($viewerid)", lifecycle)


if __name__ == '__main__':
    unittest.main()
