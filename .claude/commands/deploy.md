---
description: Deploy to Laravel Forge on Digital Ocean with pre-flight checks
---

# Deploy to Production

## Pre-Flight Checks (Run in Parallel)

```
Task: @test-runner
"Run full test suite with coverage check (min 80%)"

Task: @security-scanner
"Run security audit on codebase"

Task: @database-executor
"Verify all migrations are ready and reversible"
```

## If All Checks Pass

```
Task: @forge-deployer
"Deploy to production:
1. Push to main branch
2. Trigger Forge deployment
3. Verify deployment succeeded
4. Run smoke tests on production URL
5. Monitor for errors"
```

## Rollback Protocol
If deployment fails:
1. Revert git commit
2. Trigger Forge rollback
3. Notify team
