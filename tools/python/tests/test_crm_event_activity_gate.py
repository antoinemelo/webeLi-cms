from __future__ import annotations
import copy,json,unittest
from tools.python.qualification.crm_event_activity_gate import REPORT,validate

class CrmEventActivityGateTest(unittest.TestCase):
    def payload(self):return json.loads(REPORT.read_text(encoding='utf-8'))
    def test_reference(self):self.assertEqual([],validate(self.payload()))
    def test_rejects_blocking_crm_failure(self):
        value=copy.deepcopy(self.payload());value['delivery']['crm_failure_non_blocking']=False;self.assertTrue(validate(value))
    def test_rejects_transaction_payload_copy(self):
        value=copy.deepcopy(self.payload());value['privacy']['full_transaction_payload_stored']=True;self.assertTrue(validate(value))
    def test_rejects_migration(self):
        value=copy.deepcopy(self.payload());value['fresh_instance']['migration_required']=True;self.assertTrue(validate(value))
if __name__=='__main__':unittest.main()
