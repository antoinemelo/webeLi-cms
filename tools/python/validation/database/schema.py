from __future__ import annotations
from tools.python.validation.checks import *
from tools.python.validation.model import ValidationReport

VALIDATOR_ID='DB_SCHEMA'
DOMAIN='database'
MODES=("fast","full")

def validate(mode: str="fast") -> ValidationReport:
    r=ValidationReport(VALIDATOR_ID,DOMAIN,mode)
    sql_compiles(r,"database/schema/core.sql"); sql_compiles(r,"database/iam.sql"); require_tokens(r,"database/schema/core.sql",["PRAGMA foreign_keys = ON","CREATE TABLE sites","CREATE TABLE content_entries","CREATE TABLE webhook_endpoints","CREATE TABLE webhook_deliveries"],"DB-001"); return r
