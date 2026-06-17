from __future__ import annotations
import json, sys, time, traceback
from .registry import select, load

def run_validators(*, categories=(), names=(), include_slow=False, json_output=False, plan_only=False, fail_fast=True, mode="fast", timeout=60) -> int:
    # include_slow reste accepté par compatibilité interne, mais le niveau effectif est porté par mode.
    if include_slow and mode != "slow":
        mode = "slow"
    chosen=select(categories=tuple(categories),names=tuple(names),mode=mode)
    if not chosen:
        payload={"status":"error","returncode":2,"error":"Aucun validateur sélectionné"}; print(json.dumps(payload,ensure_ascii=False) if json_output else payload["error"],file=sys.stdout if json_output else sys.stderr); return 2
    rows=[]; rc=0
    for item in chosen:
        if plan_only:
            rows.append({"name":item.name,"module":item.module,"domain":item.domain,"status":"planned","returncode":0}); continue
        started=time.monotonic()
        try:
            report=load(item).validate(mode)
            row=report.to_dict(); row["duration_ms"]=round((time.monotonic()-started)*1000); row["returncode"]=0 if report.status=="ok" else 1
        except Exception as exc:
            row={"validator":item.name,"domain":item.domain,"mode":mode,"status":"failed","checks":0,"findings":[{"code":"VAL-003","message":str(exc),"severity":"error","details":{"traceback":traceback.format_exc()}}],"duration_ms":round((time.monotonic()-started)*1000),"returncode":1}
        rows.append(row)
        if not json_output:
            print(f"[{row['status'].upper()}] {item.name} ({row.get('checks',0)} contrôles, {row['duration_ms']} ms)")
            for f in row.get("findings",[]): print(f"  {f['severity'].upper()} {f['code']}: {f['message']}"+(f" [{f.get('path')}]" if f.get('path') else ""))
        if row["returncode"]:
            rc=1
            if fail_fast: break
    status="dry-run" if plan_only else ("ok" if rc==0 else "failed")
    if json_output: print(json.dumps({"status":status,"returncode":rc,"mode":mode,"validators":rows},ensure_ascii=False))
    return rc
