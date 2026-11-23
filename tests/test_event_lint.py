"""События: публикация только через Event::publish() — правило из .tools/event_lint.py."""

from __future__ import annotations

import importlib.util
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SPEC = importlib.util.spec_from_file_location("prx_event_lint", ROOT / ".tools/event_lint.py")
assert SPEC and SPEC.loader
event_lint = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(event_lint)

FACADE = "application/classes/Event.php"
CANONICAL = "Event::publish('prxNNN.entity.action', [...], $initiator_id)"


class EventLintTest(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = tempfile.TemporaryDirectory(prefix="prx-event-lint-")
        self.root = Path(self.tmp.name)
        (self.root / "application" / "classes").mkdir(parents=True)
        (self.root / "application" / "classes" / "Event.php").write_text(
            "<?php class Event { public static function bus() { return Service_Prx208_EventBus::instance(); } }\n",
            encoding="utf-8",
        )

    def tearDown(self) -> None:
        self.tmp.cleanup()

    def snippet(self, name: str, code: str) -> None:
        (self.root / "application" / name).write_text(code, encoding="utf-8")

    def test_canonical_publish_passes(self) -> None:
        self.snippet("Module.php", "<?php\nEvent::publish('prx312.claim.approved', ['claim_id' => $id], $actor->id());\nEvent::listen('prx312.claim.approved', $cb);\nEvent::has('prx312.claim.approved');\n")
        self.assertEqual([], event_lint.errors(self.root))

    def test_fire_queue_and_prx_event_are_rejected_with_canonical_hint(self) -> None:
        self.snippet("Module.php", "<?php\n// line 2\nEvent::fire('prx312.claim.approved', $model);\nEvent::queue('prx312.claim.approved', $model);\nPrx_Event::publish('prx312.claim.approved', ['id' => 1]);\n")
        found = event_lint.errors(self.root)
        self.assertEqual(3, len(found))
        for item in found:
            self.assertTrue(item.startswith("application/Module.php:"), item)
            self.assertIn(CANONICAL, item)
        self.assertIn("Event::fire удалён", found[0])
        self.assertIn("Event::queue удалён", found[1])
        self.assertIn("Prx_Event удалён", found[2])
        self.assertTrue(found[0].startswith("application/Module.php:3:"), found[0])

    def test_bus_publish_outside_facade_is_rejected_in_both_spellings(self) -> None:
        self.snippet("Queue.php", "<?php\nService_Prx208_EventBus::instance()->publish('prx120.job.done', $payload);\n$this->_event_bus->publish($name, $payload);\n$event_bus_service->publish($name, $payload);\n$bus->publish($name, $payload);\n")
        found = event_lint.errors(self.root)
        self.assertEqual(3, len(found), found)
        self.assertTrue(all("публикация мимо фасада" in item for item in found), found)

    def test_facade_may_call_the_bus(self) -> None:
        self.assertEqual([], event_lint.errors(self.root))
        facade = self.root / FACADE
        facade.write_text(facade.read_text(encoding="utf-8").replace("Service_Prx208_EventBus::instance();", "Service_Prx208_EventBus::instance()->publish($name, $payload);"), encoding="utf-8")
        self.assertEqual([], event_lint.errors(self.root))

    def test_repository_publishes_only_through_the_facade(self) -> None:
        self.assertEqual([], event_lint.errors(ROOT))


if __name__ == "__main__":
    unittest.main()
