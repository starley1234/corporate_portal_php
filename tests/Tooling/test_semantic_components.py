"""Связные компоненты Semantic без обратных вызовов/новых traits."""
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SERVICE = ROOT / 'application/modules/prx115_semantic/classes/Service/Prx115'


class SemanticComponentsTest(unittest.TestCase):
    def test_internal_components_never_call_the_facade_or_embed_or_queue_work(self):
        for name in ('Diagnostics', 'SearchLog', 'Sources'):
            source = (SERVICE / (name + '.php')).read_text(encoding='utf-8')
            with self.subTest(component=name):
                self.assertNotRegex(source, r'Service_Prx115_Semantic\s*::|\bextends\s+Service_|\buse\s+Service_')
                self.assertNotRegex(source, r'::(?:embed_query|queue_index|store_vectors|push|reindex|prune)\s*\(')

    def test_diagnostics_and_sources_do_not_mutate_tables(self):
        for name in ('Diagnostics', 'Sources'):
            source = (SERVICE / (name + '.php')).read_text(encoding='utf-8')
            self.assertNotRegex(source, r'\b(?:INSERT\s+INTO|UPDATE\s+prx_|DELETE\s+FROM|ALTER\s+TABLE|CREATE\s+TABLE)\b')

    def test_public_facade_delegates_all_extracted_methods(self):
        source = (SERVICE / 'Semantic.php').read_text(encoding='utf-8')
        for component, methods in {
            'SearchLog': ['log_search', 'recent_searches', 'search_stats'],
            'Sources': ['sources', 'coverage'],
            'Diagnostics': ['panel', 'health', 'stats'],
        }.items():
            for method in methods:
                body = re.search(r'function ' + method + r'\([^\n]*\)\s*\{([^}]+)\}', source).group(1)
                self.assertIn('Service_Prx115_' + component + '::' + method + '(', body)
                self.assertNotRegex(body, r'\b(?:SELECT|INSERT|UPDATE|DELETE)\b')
