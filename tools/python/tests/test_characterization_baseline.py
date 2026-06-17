#!/usr/bin/env python3
from __future__ import annotations
import json, sqlite3, subprocess, sys, tempfile, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[3]
CORE=ROOT/'storage/database/core.sqlite'
class CharacterizationBaseline(unittest.TestCase):
 @classmethod
 def setUpClass(cls):
  cls.con=sqlite3.connect(f'file:{CORE}?mode=ro',uri=True); cls.con.row_factory=sqlite3.Row
 @classmethod
 def tearDownClass(cls): cls.con.close()
 def tables(self): return {r[0] for r in self.con.execute("select name from sqlite_master where type='table'")}
 def test_five_sqlite_databases_exist(self): self.assertEqual({'core.sqlite','iam.sqlite','forms.sqlite','cookies.sqlite','ai.sqlite'},{p.name for p in (ROOT/'storage/database').glob('*.sqlite')})
 def test_blueprint_representations_are_converged(self):
  forbidden='schema_'+'blueprints'; self.assertNotIn(forbidden,self.tables())
  self.assertTrue({'blueprints','blueprint_versions','blueprint_sections','blueprint_fields','schema_field_types','content_types','fields'}.issubset(self.tables()))
 def test_active_blueprint_versions_resolve(self):
  bad=self.con.execute('SELECT COUNT(*) FROM blueprints b LEFT JOIN blueprint_versions v ON v.id=b.active_version_id WHERE b.active_version_id IS NOT NULL AND v.id IS NULL').fetchone()[0]; self.assertEqual(0,bad)
 def test_blueprint_version_json_is_valid(self):
  for r in self.con.execute('SELECT id,schema_json FROM blueprint_versions ORDER BY id'):
   if r['schema_json']: self.assertIsInstance(json.loads(r['schema_json']),(dict,list),f"version {r['id']}")
 def test_publications_reference_existing_entities(self):
  if 'content_entry_publications' not in self.tables(): self.skipTest('table absent')
  cols={r[1] for r in self.con.execute('pragma table_info(content_entry_publications)')}
  self.assertIn('entry_id',cols)
 def test_no_published_snapshot_for_draft_only_entry(self):
  if not {'public_content_snapshots','content_entries'}.issubset(self.tables()): self.skipTest('tables absent')
  cols={r[1] for r in self.con.execute('pragma table_info(public_content_snapshots)')}
  if 'entry_id' not in cols: self.skipTest('snapshot has no entry_id')
  q="SELECT COUNT(*) FROM public_content_snapshots s JOIN content_entries e ON e.id=s.entry_id WHERE e.status='draft'"
  self.assertEqual(0,self.con.execute(q).fetchone()[0])

 def test_canonical_blueprint_lifecycle_on_isolated_copy(self):
  with tempfile.TemporaryDirectory() as td:
   db=Path(td)/'core.sqlite'; db.write_bytes(CORE.read_bytes())
   con=sqlite3.connect(db); con.row_factory=sqlite3.Row
   try:
    con.execute('PRAGMA foreign_keys=ON')
    con.execute("INSERT INTO blueprints(blueprint_key,resource_type,label,is_active) VALUES('acceptance_probe','content_type','Acceptance probe',1)")
    bid=con.execute("SELECT id FROM blueprints WHERE blueprint_key='acceptance_probe'").fetchone()[0]
    schema_v1=json.dumps({'fields':[{'field_key':'title','field_type':'text'},{'field_key':'body','field_type':'markdown'}]},separators=(',',':'))
    con.execute("INSERT INTO blueprint_versions(blueprint_id,version,status,schema_json,ui_schema_json,validation_json,seo_policy_json,routing_policy_json,workflow_policy_json,translation_policy_json,permissions_policy_json,is_active) VALUES(?,1,'active',?,'{}','{}','{}','{}','{}','{}','{}',1)",(bid,schema_v1))
    v1=con.execute('SELECT id FROM blueprint_versions WHERE blueprint_id=? AND version=1',(bid,)).fetchone()[0]
    con.execute('UPDATE blueprints SET active_version_id=? WHERE id=?',(v1,bid))
    con.execute("INSERT INTO blueprint_sections(blueprint_id,section_key,label,layout,sort_order) VALUES(?,'content','Contenu','tab',10)",(bid,))
    sid=con.execute("SELECT id FROM blueprint_sections WHERE blueprint_id=? AND section_key='content'",(bid,)).fetchone()[0]
    con.execute("INSERT INTO blueprint_fields(blueprint_id,section_id,field_handle,field_type,label,field_purpose,width,is_required,is_localized,is_system,is_deletable,sort_order,options_json,validation_json,conditions_json,config_json) VALUES(?,?, 'body','markdown','Body','content',100,0,1,0,1,10,'[]','{}','[]','{}')",(bid,sid))
    schema_v2=json.dumps({'fields':[{'field_key':'title','field_type':'text'},{'field_key':'body','field_type':'markdown'},{'field_key':'summary','field_type':'text'}]},separators=(',',':'))
    con.execute("UPDATE blueprint_versions SET is_active=0,status='archived' WHERE id=?",(v1,))
    con.execute("INSERT INTO blueprint_versions(blueprint_id,version,status,schema_json,ui_schema_json,validation_json,seo_policy_json,routing_policy_json,workflow_policy_json,translation_policy_json,permissions_policy_json,is_active) VALUES(?,2,'active',?,'{}','{}','{}','{}','{}','{}','{}',1)",(bid,schema_v2))
    v2=con.execute('SELECT id FROM blueprint_versions WHERE blueprint_id=? AND version=2',(bid,)).fetchone()[0]
    con.execute('UPDATE blueprints SET active_version_id=? WHERE id=?',(v2,bid))
    con.execute("INSERT INTO blueprint_publications(blueprint_id,blueprint_version_id,publication_status) VALUES(?,?,'published')",(bid,v2))
    con.commit()
    row=con.execute('SELECT b.active_version_id,bv.version FROM blueprints b JOIN blueprint_versions bv ON bv.id=b.active_version_id WHERE b.id=?',(bid,)).fetchone()
    self.assertEqual((v2,2),(row['active_version_id'],row['version']))
    published=con.execute('SELECT blueprint_version_id FROM blueprint_publications WHERE blueprint_id=?',(bid,)).fetchone()[0]
    self.assertEqual(v2,published)
    self.assertEqual(2,con.execute('SELECT COUNT(*) FROM blueprint_versions WHERE blueprint_id=?',(bid,)).fetchone()[0])
   finally:
    con.close()

 def test_studio_and_content_editor_use_versioned_blueprint_contract(self):
  repo=(ROOT/'backend/src/Infrastructure/Persistence/Sql/SqlBlueprintRepository.php').read_text(encoding='utf-8')
  controller=(ROOT/'backend/src/Application/Api/Admin/BlueprintApiController.php').read_text(encoding='utf-8')
  editor=(ROOT/'backend/src/Application/Api/Admin/ContentTypeApiController.php').read_text(encoding='utf-8')
  revisions=(ROOT/'backend/src/Infrastructure/Persistence/Sql/SqlContentRevisionRepository.php').read_text(encoding='utf-8')
  self.assertIn('saveDesign',repo)
  self.assertIn('createVersion',repo)
  self.assertIn('blueprint_versions',repo)
  self.assertIn('editorSchema',controller)
  self.assertIn('getEditorSchema',editor)
  self.assertIn('blueprint_version_id',revisions)
 def test_baseline_capture_is_deterministic(self):
  with tempfile.TemporaryDirectory() as td:
   a=Path(td)/'a'; b=Path(td)/'b'; script=ROOT/'tools/python/baseline/capture_baseline.py'
   subprocess.run([sys.executable,str(script),'--output',str(a)],cwd=ROOT,check=True,stdout=subprocess.DEVNULL)
   subprocess.run([sys.executable,str(script),'--output',str(b)],cwd=ROOT,check=True,stdout=subprocess.DEVNULL)
   self.assertEqual((a/'baseline.json').read_bytes(),(b/'baseline.json').read_bytes())
if __name__=='__main__': unittest.main(verbosity=2)
