"""Preferences — отдельная обязанность, не трейт и не обратный вызов фасада."""
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SERVICE = ROOT / 'application/modules/prx597_notifications/classes/Service/Prx597'


class NotificationPreferencesBoundaryTest(unittest.TestCase):
    def test_preferences_does_not_call_facade_or_prepare_schema(self):
        source = (SERVICE / 'Preferences.php').read_text(encoding='utf-8')
        self.assertNotRegex(source, r'Service_Prx597_Notify\s*::|\bensure_tables\s*\(')
        self.assertNotRegex(source, r'\b(?:ALTER|CREATE|DROP)\s+TABLE\b')
        self.assertIn('final class Service_Prx597_Preferences', source)

    def test_public_preferences_facade_delegates_without_own_sql(self):
        source = (SERVICE / 'Notify.php').read_text(encoding='utf-8')
        self.assertIn('const PREF_FIELDS = Service_Prx597_Preferences::FIELDS;', source)
        for method, delegate in [('prefs_default', 'defaults'), ('prefs', 'get'),
                                 ('user_pref_fields', 'form_fields'), ('save_prefs', 'save')]:
            body = re.search(r'function ' + method + r'\([^)]*\)\s*\{([^}]+)\}', source).group(1)
            self.assertIn('Service_Prx597_Preferences::' + delegate + '(', body)
            self.assertNotRegex(body, r'\b(?:SELECT|INSERT|UPDATE|DELETE)\b')
