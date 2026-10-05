import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def read(relative):
    return (ROOT / relative).read_text(encoding="utf-8")


FORUM_POST_ACTIONS = [
    "actions/forum_comment.php",
    "actions/forum_comment_manage.php",
    "actions/forum_resolve.php",
    "actions/forum_update_final_sla.php",
]


class ForumStaticTests(unittest.TestCase):
    def test_post_actions_verify_csrf_and_check_role(self):
        for action in FORUM_POST_ACTIONS:
            source = read(action)
            with self.subTest(action=action):
                self.assertIn("verifyCsrf();", source)
                self.assertRegex(source, r"require(ForumAccess|CrfRole)\(")

    def test_final_sla_locked_after_kadep_approval(self):
        self.assertIn("kadep_operasional_approved_at", read("includes/forum.php"))
        self.assertIn("forumSlaLocked($crf)", read("actions/forum_update_final_sla.php"))
        self.assertIn("forumSlaLocked($selectedCrf)", read("forum/index.php"))

    def test_system_and_deleted_comments_not_counted_as_unread(self):
        forum = read("includes/forum.php")
        self.assertIn("is_system = 0 AND {$alias}.deleted_at IS NULL", forum)
        self.assertIn("addForumSystemComment(", read("actions/forum_update_final_sla.php"))

    def test_comment_actions_send_notifications(self):
        self.assertIn("notifyForumComment(", read("actions/forum_comment.php"))
        for action in ["actions/forum_comment.php", "actions/forum_update_final_sla.php", "actions/forum_resolve.php"]:
            with self.subTest(action=action):
                self.assertIn("dispatchPendingNotificationEmails($pdo);", read(action))

    def test_moderation_rules(self):
        source = read("actions/forum_comment_manage.php")
        self.assertIn("isAdmin()", source)
        self.assertIn("Catatan sistem tidak dapat diubah atau dihapus.", source)
        self.assertIn("deleted_at = NOW()", source)

    def test_attachment_download_checks_forum_access_and_deletion(self):
        source = read("actions/download_forum_attachment.php")
        self.assertIn("requireForumAccess();", source)
        self.assertIn("comments.deleted_at IS NULL", source)

    def test_comment_output_is_escaped(self):
        self.assertIn("$html = h($comment);", read("includes/forum.php"))
        self.assertNotIn("nl2br($item['comment'])", read("forum/index.php"))

    def test_empty_forum_urgency_action_removed(self):
        self.assertFalse((ROOT / "actions/forum_urgency.php").exists())


if __name__ == "__main__":
    unittest.main()
