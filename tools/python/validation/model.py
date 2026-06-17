from __future__ import annotations
from dataclasses import asdict, dataclass, field
from typing import Any

@dataclass(frozen=True)
class Finding:
    code: str
    message: str
    severity: str = "error"
    path: str | None = None
    details: dict[str, Any] = field(default_factory=dict)

@dataclass
class ValidationReport:
    validator: str
    domain: str
    mode: str
    findings: list[Finding] = field(default_factory=list)
    checks: int = 0

    @property
    def status(self) -> str:
        return "failed" if any(f.severity == "error" for f in self.findings) else "ok"

    def add(self, code: str, message: str, *, severity: str = "error", path: str | None = None, **details: Any) -> None:
        self.findings.append(Finding(code, message, severity, path, details))

    def checked(self, count: int = 1) -> None:
        self.checks += count

    def to_dict(self) -> dict[str, Any]:
        return {"validator": self.validator, "domain": self.domain, "mode": self.mode, "status": self.status, "checks": self.checks, "findings": [asdict(f) for f in self.findings]}
