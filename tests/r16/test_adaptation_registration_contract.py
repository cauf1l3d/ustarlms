from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[2]


class AdaptationRegistrationContract(unittest.TestCase):
    def test_adaptation_accepts_approved_registration_and_direct_manager(self):
        service = (
            ROOT / 'moodle/local/ustar/classes/adaptation_service.php'
        ).read_text(encoding='utf-8')

        self.assertIn("staffing_requests::TYPE_HIRE", service)
        self.assertIn("staffing_requests::TYPE_REGISTRATION", service)
        self.assertIn("organization_model::primary_assignment($userid)", service)
        self.assertIn("organization_model::manager_user_for_place", service)
        self.assertIn("$canonicalmanager !== $managerid", service)
        self.assertIn("'source_request_type' => (string)$request->requesttype", service)

    def test_registration_cards_are_handed_to_manager_only_after_approval(self):
        source = (
            ROOT / 'moodle/local/ustar/classes/staffing_requests.php'
        ).read_text(encoding='utf-8')

        self.assertIn("adaptation_service::assignment_offer($record, $viewerid)", source)
        self.assertIn("$status !== self::STATUS_APPROVED", source)
        self.assertIn("$ownsadaptation", source)
        self.assertIn("registration_adaptation_ready", source)
        self.assertIn("registration-adaptation-ready:", source)

    def test_staffing_template_keeps_manual_adaptation_control(self):
        source = (
            ROOT / 'moodle/local/ustar/templates/staffing.mustache'
        ).read_text(encoding='utf-8')

        self.assertIn("{{#canassignadaptation}}", source)
        self.assertIn('name="action" value="assignadaptation"', source)
        self.assertIn("Назначить адаптационный чек", source)
        self.assertIn("{{#isregistration}}Заявлено{{/isregistration}}", source)


if __name__ == '__main__':
    unittest.main()
