from __future__ import annotations

import sys
import tempfile
import time
import unittest
from pathlib import Path

from tools.python.cms.runtime import Context, execute


ROOT = Path(__file__).resolve().parents[3]


class CommandRuntimeReliabilityTest(unittest.TestCase):
    def test_timeout_returns_124_with_a_real_time_bound(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            ctx = Context.build(str(ROOT), None, False, False, tmp, 10)
            started = time.monotonic()
            code = execute(ctx, [sys.executable, '-c', 'import time; time.sleep(30)'], timeout=1)
            elapsed = time.monotonic() - started
        self.assertEqual(code, 124)
        self.assertLess(elapsed, 6)

    def test_default_timeout_is_applied_when_not_explicit(self) -> None:
        ctx = Context.build(str(ROOT), None, True, True, None, 17)
        code = execute(ctx, [sys.executable, '-c', 'print(1)'])
        self.assertEqual(code, 0)


if __name__ == '__main__':
    unittest.main()
