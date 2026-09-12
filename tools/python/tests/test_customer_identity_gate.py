from __future__ import annotations
import copy,json,unittest
from tools.python.qualification.customer_identity_gate import REPORT,validate

class CustomerIdentityGateTest(unittest.TestCase):
    def payload(self):return json.loads(REPORT.read_text(encoding='utf-8'))
    def test_reference(self):self.assertEqual([],validate(self.payload()))
    def test_rejects_name_only(self):
        value=copy.deepcopy(self.payload());value['resolution_strategies']['name_only_forbidden']=False;self.assertTrue(validate(value))
    def test_rejects_automatic_merge(self):
        value=copy.deepcopy(self.payload());value['safety']['automatic_risky_merge']=True;self.assertTrue(validate(value))
    def test_rejects_migration(self):
        value=copy.deepcopy(self.payload());value['fresh_instance']['migration_required']=True;self.assertTrue(validate(value))
if __name__=='__main__':unittest.main()
