from __future__ import annotations
import sys
from tools.python.cms.runtime import execute


def configure(parser):
    parser.add_argument('--profile', choices=('quick','complete','release'), default='complete')
    parser.add_argument('--json-report')
    parser.add_argument('--markdown-report')
    parser.add_argument('--no-reports', action='store_true')
    parser.add_argument('--continue-on-failure', action='store_true')
    parser.add_argument('--list', action='store_true')


def run(ctx, args):
    command=[sys.executable,'-m','tools.python.qualification.run_all','--profile',args.profile]
    if args.json_report: command += ['--json-report',args.json_report]
    if args.markdown_report: command += ['--markdown-report',args.markdown_report]
    if args.no_reports: command.append('--no-reports')
    if args.continue_on_failure: command.append('--continue-on-failure')
    if args.list: command.append('--list')
    return execute(ctx,command)
