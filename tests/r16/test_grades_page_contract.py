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
        self.assertIn("JOIN {local_ustar_staff_places}", source[:header])
        self.assertIn("sp.positionid = :positionid", source[:header])

        assignment_picker = source.index(
            "echo html_writer::start_div('u-stage6-card u-grades__assignment-picker')"
        )
        self.assertGreater(assignment_picker, header)


if __name__ == '__main__':
    unittest.main()
