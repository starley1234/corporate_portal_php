"""Рендерер письма не подписывает токены, не отправляет и не читает БД."""
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SERVICE = ROOT / 'application/modules/prx597_notifications/classes/Service/Prx597'


class NotificationEmailContentBoundaryTest(unittest.TestCase):
    def test_renderer_has_no_facade_database_transport_or_token_dependencies(self):
        source = (SERVICE / 'EmailContent.php').read_text(encoding='utf-8')
        self.assertNotRegex(source, r'Service_Prx597_(?:Notify|Engine|Preferences)\s*::')
        self.assertNotRegex(source, r'\b(?:Database|DB|ORM|Auth)::|Symfony\\|\b(?:unsub_url|send_email|ensure_tables)\s*\(')
        self.assertNotIn('extends Service_', source)

    def test_facade_prepares_links_and_delegates_rendering(self):
        source = (SERVICE / 'Notify.php').read_text(encoding='utf-8')
        self.assertIn("['_email_href'] = self::_safe_href(", source)
        self.assertIn('Service_Prx597_EmailContent::digest($rows, $extra)', source)
        self.assertIn('Service_Prx597_EmailContent::shell($title, $inner_html, $footer)', source)
