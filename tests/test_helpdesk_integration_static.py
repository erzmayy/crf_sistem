import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def read(relative):
    return (ROOT / relative).read_text(encoding="utf-8")


POST_ACTIONS = [
    "actions/helpdesk_submit.php",
    "actions/helpdesk_follow_up.php",
    "actions/category_master.php",
    "actions/crf_assign.php",
]


class HelpdeskIntegrationStaticTests(unittest.TestCase):
    def test_new_post_actions_verify_csrf_and_require_login(self):
        for action in POST_ACTIONS:
            source = read(action)
            with self.subTest(action=action):
                self.assertIn("verifyCsrf();", source)
                self.assertRegex(source, r"require(Login|Admin|CrfRole)\(")

    def test_admin_only_master_data(self):
        for page in [
            "actions/category_master.php",
            "actions/user_search.php",
            "admin/master_data.php",
            "admin/crf_kategori.php",
            "admin/handling_kategori.php",
            "admin/helpdesk_kategori.php",
        ]:
            with self.subTest(page=page):
                self.assertIn("requireAdmin();", read(page))

    def test_handler_actions_check_category_scope_on_backend(self):
        self.assertIn("canHandleCrf($pdo, $crf)", read("actions/automation_action.php"))
        self.assertIn("isAssignedCrfHandler($crf)", read("actions/automation_action.php"))
        self.assertIn("canHandleCrf($pdo, $crf)", read("actions/crf_assign.php"))
        self.assertIn("canHandleCrf($pdo, $crf)", read("otomasi/detail.php"))
        self.assertIn("crfHandlerScopeSql($pdo, 'cr')", read("otomasi/index.php"))
        self.assertIn("return canHandleCrf($pdo, $row);", read("includes/auth.php"))

    def test_crf_status_changes_sync_helpdesk_ticket(self):
        for action in [
            "actions/submit_crf.php",
            "actions/cmo_action.php",
            "actions/automation_action.php",
            "actions/pak_joko_approve.php",
            "actions/update_crf.php",
        ]:
            with self.subTest(action=action):
                self.assertIn("syncHelpdeskTicketFromCrf($pdo", read(action))

    def test_categories_are_dynamic_not_enum_in_form(self):
        form = read("user/form_crf.php")
        self.assertIn('name="crf_category_id"', form)
        self.assertIn("crfCategories($pdo)", form)
        self.assertNotIn('<option value="Aplikasi"', form)

    def test_category_delete_is_soft_delete(self):
        source = read("actions/category_master.php")
        self.assertIn("deleted_at = NOW()", source)
        self.assertNotIn("DELETE FROM {$table}", source)


if __name__ == "__main__":
    unittest.main()
