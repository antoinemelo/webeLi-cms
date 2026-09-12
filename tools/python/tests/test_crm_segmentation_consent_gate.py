from __future__ import annotations
import copy,json,unittest
from tools.python.qualification.crm_segmentation_consent_gate import REPORT,validate

class CrmSegmentationConsentGateTest(unittest.TestCase):
    def payload(self):return json.loads(REPORT.read_text(encoding='utf-8'))
    def test_reference(self):self.assertEqual([],validate(self.payload()))
    def test_rejects_implicit_opt_in(self):
        value=copy.deepcopy(self.payload());value['consent']['implicit_opt_in']=True;self.assertTrue(validate(value))
    def test_rejects_sale_transaction_reads(self):
        value=copy.deepcopy(self.payload());value['source']['sale_transaction_table_read']=True;self.assertTrue(validate(value))
    def test_rejects_migration(self):
        value=copy.deepcopy(self.payload());value['fresh_instance']['migration_required']=True;self.assertTrue(validate(value))
if __name__=='__main__':unittest.main()
