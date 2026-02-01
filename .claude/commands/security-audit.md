---
description: Comprehensive security audit with automatic fixes
---

# Security Audit

## Parallel Scans

```
Task: @security-scanner
"Scan for SQL injection vulnerabilities - check all raw queries"

Task: @security-scanner
"Scan for XSS vulnerabilities - check all Blade/Livewire templates"

Task: @security-scanner
"Scan for authentication/authorization issues"

Task: @security-scanner
"Run composer audit for dependency vulnerabilities"
```

## Generate Report

After all scans complete, generate `docs/security/audit-[date].md`:

```markdown
# Security Audit Report
*Date: [Today]*

## Summary
| Severity | Count | Auto-Fixed |
|----------|-------|------------|
| Critical | X | X |
| High | X | X |
| Medium | X | X |
| Low | X | X |

## Findings
[Detailed findings with file:line references]

## Recommendations
[Priority-ordered fixes for remaining issues]
```
