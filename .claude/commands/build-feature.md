---
description: Build a complete feature with components, tests, and migrations using parallel agent execution
---

# Build Feature: $ARGUMENTS

## Execution Plan

Use the Task tool to spawn agents in parallel:

### Wave 1 (Parallel Research & Database)
```
Task: @research-agent
"Research best practices for $ARGUMENTS in Laravel 12 + Livewire 4. Check for existing packages. Document in docs/research/"

Task: @database-executor
"Design and create migrations for $ARGUMENTS feature"
```

### Wave 2 (Parallel Component Building)
```
Task: @livewire-builder
"Create Livewire 4 components for $ARGUMENTS"

Task: @test-runner
"Create Pest test stubs for $ARGUMENTS feature"
```

### Wave 3 (Sequential Validation)
```
Task: @security-scanner
"Scan all new code for security vulnerabilities"

Task: @test-runner
"Run all tests and fix any failures"
```

### Final: Update CLAUDE.md with any new patterns discovered
