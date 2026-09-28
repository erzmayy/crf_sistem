import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def read(relative):
    return (ROOT / relative).read_text(encoding="utf-8")


class SiapIntegrationStaticTests(unittest.TestCase):
    def test_siap_config_contains_role_mapping(self):
        config = read("config/siap.php")

        self.assertIn("CRF_USER_SOURCE", config)
        self.assertIn("SIAP_DATABASE", config)
        self.assertIn("SIAP_USER_TABLE", config)
        self.assertIn("CRF_ADMIN_USERIDS", config)
        self.assertIn("administrator", config)
        self.assertIn("CRF_OTOMASI_USERIDS", config)
        self.assertIn("N75392", config)
        self.assertIn("N76559", config)
        self.assertIn("CRF_KADEP_OPERASIONAL_USERIDS", config)
        self.assertIn("3736", config)
        self.assertIn("CRF_CMO_DEPTS", config)
        self.assertIn("CMO", config)

    def test_user_provider_can_switch_between_local_and_siap_tables(self):
        provider = read("includes/siap_user_provider.php")

        self.assertIn("function crfUserTable(): string", provider)
        self.assertIn("function findCrfUserById(PDO $pdo, int $id): ?array", provider)
        self.assertIn("function findCrfUserByUserid(PDO $pdo, string $userid): ?array", provider)
        self.assertIn("function verifyCrfUserPassword(string $plain, array $user): bool", provider)
        self.assertIn("function resolveCrfRoleFromUser(array $user): string", provider)
        self.assertIn("password_verify", provider)
        self.assertIn("md5($plain)", provider)

    def test_session_and_login_use_provider_not_hardcoded_users_table(self):
        session = read("includes/session.php")
        login = read("actions/login.php")

        self.assertIn("siap_user_provider.php", session)
        self.assertIn("findCrfUserById($pdo", session)
        self.assertNotIn("SELECT * FROM users", session)

        self.assertIn("findCrfUserByUserid($pdo", login)
        self.assertIn("verifyCrfUserPassword($password, $user)", login)
        self.assertNotIn("FROM users", login)

    def test_auth_uses_resolver_when_configured_for_siap_db(self):
        auth = read("includes/auth.php")

        self.assertIn("resolveCrfRoleFromUser($user)", auth)
        self.assertIn("CRF_ROLE_SOURCE", auth)
        self.assertIn("resolver", auth)

    def test_admin_edit_joins_configured_user_table(self):
        edit = read("admin/edit.php")

        self.assertIn("$userTable = crfUserTable();", edit)
        self.assertIn("JOIN {$userTable} u", edit)
        self.assertNotIn("JOIN users u", edit)


if __name__ == "__main__":
    unittest.main()
