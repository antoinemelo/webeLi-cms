"""Temporary compatibility package for historical validator module names."""
from __future__ import annotations
import importlib.abc, importlib.util, json, sys
from pathlib import Path
_MAP=json.loads((Path(__file__).resolve().parents[1]/"validation/legacy_aliases.json").read_text(encoding="utf-8"))
class _LegacyLoader(importlib.abc.Loader):
    def __init__(self,name,target,validator_id): self.name=name; self.target=target; self.validator_id=validator_id
    def create_module(self,spec): return None
    def get_code(self, fullname):
        source = f"from tools.python.validation.runner import run_validators\nimport sys\nprint(\"AVERTISSEMENT: {self.name} est déprécié; utilisez tools/cms.py validate --validator {self.validator_id}.\", file=sys.stderr)\nraise SystemExit(run_validators(names=(\"{self.validator_id}\",), mode=\"fast\"))\n"
        return compile(source, f"<legacy-validator:{self.name}>", "exec")
    def exec_module(self,module):
        module.__dict__["__legacy_alias__"]=True
        def main():
            from tools.python.validation.runner import run_validators
            print(f"AVERTISSEMENT: {self.name} est déprécié; utilisez tools/cms.py validate --validator {self.validator_id}.",file=sys.stderr)
            return run_validators(names=(self.validator_id,),mode="fast")
        module.main=main
        if module.__name__ == "__main__": raise SystemExit(main())
class _LegacyFinder(importlib.abc.MetaPathFinder):
    def find_spec(self,fullname,path=None,target=None):
        prefix=__name__+"."
        if not fullname.startswith(prefix): return None
        name=fullname[len(prefix):]
        item=_MAP.get(name)
        if not item: return None
        return importlib.util.spec_from_loader(fullname,_LegacyLoader(name,item["target"],item["validator_id"]))
if not any(type(x).__name__=="_LegacyFinder" for x in sys.meta_path): sys.meta_path.insert(0,_LegacyFinder())
