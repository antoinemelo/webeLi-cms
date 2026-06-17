from __future__ import annotations

import ast
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
TEST_ROOT = ROOT / "tools/python/tests"


class NestedTestVisitor(ast.NodeVisitor):
    def __init__(self) -> None:
        self.function_depth = 0
        self.nested_tests: list[tuple[str, int]] = []

    def _visit_function(self, node: ast.FunctionDef | ast.AsyncFunctionDef) -> None:
        if node.name.startswith("test_") and self.function_depth > 0:
            self.nested_tests.append((node.name, node.lineno))
        self.function_depth += 1
        self.generic_visit(node)
        self.function_depth -= 1

    def visit_FunctionDef(self, node: ast.FunctionDef) -> None:
        self._visit_function(node)

    def visit_AsyncFunctionDef(self, node: ast.AsyncFunctionDef) -> None:
        self._visit_function(node)


class TestSuiteStructureTest(unittest.TestCase):
    def test_no_test_function_is_nested(self) -> None:
        errors: list[str] = []
        for path in sorted(TEST_ROOT.rglob("test*.py")):
            source = path.read_text(encoding="utf-8")
            tree = ast.parse(source, filename=str(path))
            visitor = NestedTestVisitor()
            visitor.visit(tree)
            relative = path.relative_to(ROOT).as_posix()
            for name, line in visitor.nested_tests:
                errors.append(f"{relative}:{line}: {name}")

        self.assertEqual(
            [],
            errors,
            "Fonctions test_* imbriquées et non découvrables :\n"
            + "\n".join(errors),
        )


if __name__ == "__main__":
    unittest.main()
