from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[2]


class GradesPageContract(unittest.TestCase):
    def test_page_scaffold_precedes_all_grade_view_output(self):
        source = (ROOT / 'moodle/local/ustar/grades.php').read_text(encoding='utf-8')

        page_context = source.index('$PAGE->set_context($context);')
        page_url = source.index('$PAGE->set_url(', page_context)
        page_layout = source.index("$PAGE->set_pagelayout('ustar');", page_url)
        header = source.index('echo $OUTPUT->header();', page_layout)

        mine = source.index("if ($view === 'mine') {", header)
        assignments = source.index("if ($view === 'assignments') {", header)
        team = source.index("if ($view === 'team') {", header)

        self.assertLess(page_context, page_url)
        self.assertLess(page_url, page_layout)
        self.assertLess(page_layout, header)
        self.assertLess(header, mine)
        self.assertLess(header, assignments)
        self.assertLess(header, team)

        pre_header = source[:header]
        self.assertNotIn("echo html_writer::", pre_header)
        self.assertNotIn("echo $OUTPUT->notification", pre_header)
        self.assertNotIn("echo $OUTPUT->footer", pre_header)

    def test_linked_assignment_filters_are_data_only_before_header(self):
        source = (ROOT / 'moodle/local/ustar/grades.php').read_text(encoding='utf-8')
        header = source.index('echo $OUTPUT->header();')

        self.assertIn("$departmentfilter = $view === 'assignments'", source[:header])
        self.assertIn("$positionfilter = $view === 'assignments'", source[:header])
        self.assertIn("$employeeid = $view === 'assignments'", source[:header])
        self.assertIn(
            "grade_assignment_directory::employees_for_position(",
            source[:header],
        )

        directory = (
            ROOT / 'moodle/local/ustar/classes/grade_assignment_directory.php'
        ).read_text(encoding='utf-8')
        self.assertIn("EXISTS (", directory)
        self.assertIn("{local_ustar_assignments}", directory)
        self.assertIn("{local_ustar_staff_places}", directory)
        self.assertIn("NOT EXISTS (", directory)
        self.assertIn("posfield.shortname = :positionfield", directory)
        self.assertIn("(e.id IS NULL OR e.status = :employmentactive)", directory)
        for field in (
            "firstnamephonetic",
            "lastnamephonetic",
            "middlename",
            "alternatename",
        ):
            self.assertIn(field, directory)

        assignment_picker = source.index(
            "echo html_writer::start_div('u-stage6-card u-grades__assignment-picker')"
        )
        self.assertGreater(assignment_picker, header)


    def test_bulk_assignment_is_previewed_and_server_scoped(self):
        source = (ROOT / 'moodle/local/ustar/grades.php').read_text(encoding='utf-8')
        directory = (
            ROOT / 'moodle/local/ustar/classes/grade_assignment_directory.php'
        ).read_text(encoding='utf-8')

        self.assertIn("if ($action === 'bulkassigninitial')", source)
        self.assertIn("initial_assignment_preview(", source)
        self.assertIn("'Будет назначено'", source)
        self.assertIn("'action' => 'bulkassigninitial'", source)
        self.assertIn("bulk_assign_initial(", directory)
        self.assertIn("Existing grades are never overwritten", directory)
        self.assertIn("if ($preview['truncated'])", directory)
        self.assertIn("start_delegated_transaction()", directory)

    def test_assignment_picker_uses_supported_noscript_markup(self):
        source = (ROOT / 'moodle/local/ustar/grades.php').read_text(encoding='utf-8')

        self.assertNotIn('html_writer::noscript(', source)
        self.assertIn("html_writer::tag(\n        'noscript'", source)

if __name__ == '__main__':
    unittest.main()
