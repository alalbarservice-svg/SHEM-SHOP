# Information Map

| Resource | Type | Exact location | What is there | Access method | Permission level | Priority |
|---|---|---|---|---|---|---|
| Handoff report | path | handoff/SHEM-SHOP/2026-10-28_1248_admin-redesign-360-and-servers/handoff_report.md | 12-section handoff | Read | read | must-read |
| Servers decision | path | .../SERVERS_DECISION.md | what was decided, why, goals, T1-T8, costs, open access problem | Read | read | must-read |
| Handoff state | path | .../handoff_state.json | machine-readable state | Read | read | must-read |
| Open questions | path | /home/claude/shem-shop/HANDOFF_OPEN_QUESTIONS.md | shared Q file (new agent appends, user relays) | Edit/append | write (this file only) | must-read |
| Project state | path | docs/project-state.md | running status, top pinned hosting block | Read, append at end of task | write docs | must-read |
| Decisions log | path | docs/decisions.md | dated owner decisions | Read, append | write docs | must-read |
| Hosting handoff | path | docs/hosting/HANDOFF-server-selection-and-prepurchase-tests.md | pinned server task | Read | read | must-read |
| Privacy/hosting reqs | path | docs/hosting/privacy-and-hosting-requirements.md | evidence, go-live checklist | Read | read | as-needed |
| Same-origin pay proxy | path | docs/hosting/same-origin-pay-proxy.md | proxy design | Read | read | as-needed |
| Agent rules | path | docs/AGENT-RULES.md | repo agent rules | Read | read | must-read |
| Pre-purchase tests | path | WP-TESTS/hosting/prepurchase/ (e2e.py, idscan.py, cb.php, mkorder.php, README.md) | test helpers for T5/T6 | run in staging | staging only | as-needed |
| Reports | path | docs/reports/ (2026-10-28-shem-dok-360.md, 2026-10-28-f1-verification-and-plan.md) | prior verifications | Read | read | as-needed |
| 360 logs | path | /home/claude/logs/full360/ (summary.txt, unit_*.log, e2e_*.log) | latest full test run | Read | read | as-needed |
| Project doc A | Projects tool | claude/hosting-handoff-server-selection.md | same as repo hosting handoff | project_read | read, write full file only | as-needed |
| Project doc B | Projects tool | claude/pre-golive-critical-actions.md | living pre-go-live list (15.10) | project_read | read | as-needed |
| Memory | memory tools | /projects/01a13eb6-3d66-724f-8f94-dbb0109d3043/areas/shem-shimi.md and shem-shimi-hosting.md | owner stated decisions | memory_read | write only under /projects/01a13eb6-.../ | as-needed |
| Repo | repo | /home/claude/shem-shop, origin https://github.com/alalbarservice-svg/SHEM-SHOP.git, branch staging/shimi-linked-48 @ 863f434 | all code | git | push only on owner word "דחוף" | must-read |
| Staging SHEM | URL | http://localhost:8081 (container shem-staging-shem-1) | SHEM staging | browser/wp-cli via /home/claude/wps.sh shem | staging admin (credentials in chmod 600 files, never in chat) | as-needed |
| Staging SHIMI | URL | http://localhost:8082 (shem-staging-shimi-1) | SHIMI staging | /home/claude/wps.sh shimi | same | as-needed |
| Staging DB | container | shem-staging-db-1 | MariaDB 11 | docker | staging only | as-needed |
| Docker start | script | bash /home/claude/dstart.sh | starts daemon, prints UP | Bash | local | must-read |
| Backup script | script | /home/claude/backup-staging.sh <label> -> /home/claude/backups-staging/ | staging DB backups | Bash | local | must-read before any change |
| Theme build | script | /home/claude/shem-shop/WP-THEMES/build-theme.sh SHEM or SHIMI | builds themes | Bash | local | as-needed |
| Plugin deploy | script | /home/claude/deploy-scc.sh | deploys shem-shimi-commerce to staging | Bash | staging | as-needed |
| Secret locations | files | /home/claude/.stg-pw-localadmin, /home/claude/.stg-admin-pass (chmod 600) | staging-only admin credentials; values never shown | cat only inside scripts | staging only | as-needed |
| Live SHEM | URL | https://shem-menswear.genial-comet-8090.chatgpt.site/ (admin /admin.html) | current live store | read-only | read-only, login required | as-needed |
| Live SHIMI | URL | https://shimi-basics.genial-comet-8090.chatgpt.site/ (admin /admin.html) | current live store | read-only | read-only | as-needed |
| Backup Supabase | DB | project zpzhyckdqmkbllebhglv | only authorized Supabase project | Supabase MCP | read/backup only (not reachable from the account in earlier sessions) | as-needed |
| Protected/out of scope | DB | alealabar-production (protected), top-model-production (out of scope) | never touch | none | forbidden | n/a |
| Accounts | account | your.shem88@gmail.com (stores, admin, Supabase), alalbar.service@gmail.com (GitHub) | check which before any action | n/a | do not mix | must-read |
| Cloudinary | connector | owner account | product images (links from owner) | Cloudinary MCP | read; upload with care | as-needed |
| PayPal | account | tied to your.shem88@gmail.com | sandbox keys not yet delivered by owner | owner vault | owner only | as-needed |
| Outgoing agent | agent | this session (claude-sonnet-5-5) | context | via user relay | n/a | n/a |
