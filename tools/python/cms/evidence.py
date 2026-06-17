from __future__ import annotations

import hashlib
import json
import os
import platform
import shlex
import sys
import time
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Iterable


def _utc_now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace('+00:00', 'Z')


def _sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open('rb') as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b''):
            digest.update(chunk)
    return digest.hexdigest()


def artifact_records(paths: Iterable[Path], root: Path) -> list[dict[str, object]]:
    records: list[dict[str, object]] = []
    for candidate in sorted({path.resolve() for path in paths if path.exists()}):
        if candidate.is_dir():
            items = sorted(item for item in candidate.rglob('*') if item.is_file())
        else:
            items = [candidate]
        for item in items:
            try:
                relative = item.relative_to(root.resolve()).as_posix()
            except ValueError:
                relative = str(item)
            records.append({'path': relative, 'size_bytes': item.stat().st_size, 'sha256': _sha256(item)})
    return records


@dataclass
class CommandEvidence:
    directory: Path
    root: Path
    argv: list[str]
    version: str
    started_at: str
    started_monotonic: float

    @classmethod
    def start(cls, directory: Path, root: Path, argv: list[str], version: str) -> 'CommandEvidence':
        directory.mkdir(parents=True, exist_ok=True)
        return cls(directory, root, argv, version, _utc_now(), time.monotonic())

    def finish(self, returncode: int, *, summary: str, artifacts: Iterable[Path] = ()) -> Path:
        duration = round(time.monotonic() - self.started_monotonic, 3)
        payload = {
            'schema_version': 1,
            'command': shlex.join(self.argv),
            'argv': self.argv,
            'date_utc': self.started_at,
            'finished_at_utc': _utc_now(),
            'cms_version': self.version,
            'environment': {
                'platform': platform.platform(),
                'python': platform.python_version(),
                'executable': sys.executable,
                'cwd': str(self.root),
                'ci': os.environ.get('CI', ''),
            },
            'duration_seconds': duration,
            'exit_code': int(returncode),
            'status': 'ok' if returncode == 0 else ('timeout' if returncode == 124 else 'failed'),
            'summary': summary,
            'artifacts': artifact_records(artifacts, self.root),
        }
        stamp = self.started_at.replace(':', '').replace('-', '')
        target = self.directory / f'{stamp}-{os.getpid()}.command-evidence.json'
        temporary = target.with_suffix(target.suffix + '.tmp')
        temporary.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
        temporary.replace(target)
        checksum = target.with_suffix(target.suffix + '.sha256')
        checksum.write_text(f'{_sha256(target)}  {target.name}\n', encoding='utf-8')
        return target
