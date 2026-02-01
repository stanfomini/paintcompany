# Quick Start Guide

## 🚀 Infrastructure Ready

Your Laravel 12 + Livewire 4 project now has a complete agent-driven development system.

## What Was Installed

### ✅ 9 Executor Agents
- **orchestrator** - Coordinates all other agents
- **livewire-builder** - Creates Livewire components
- **database-executor** - Manages database operations
- **test-runner** - Writes and runs Pest tests
- **security-scanner** - Performs security audits
- **research-agent** - Researches best practices
- **package-installer** - Installs packages
- **forge-deployer** - Manages deployments
- **code-reviewer** - Reviews code quality

### ✅ 5 Specialized Skills
- **livewire-4** - Livewire patterns and best practices
- **pest-testing** - Testing strategies
- **database-design** - Schema design patterns
- **security-audit** - Security guidelines
- **forge-deploy** - Deployment procedures

### ✅ 4 Custom Commands
- `/build-feature [name]` - Build complete feature
- `/deploy` - Deploy to production
- `/security-audit` - Run security scan
- `/test-all` - Run complete test suite

## Example Workflows

### Build a New Feature
```
/build-feature user-profile-management
```

This will:
1. Research best practices (parallel)
2. Create database migrations (parallel)
3. Build Livewire components (parallel)
4. Generate test stubs (parallel)
5. Run security scan (sequential)
6. Run all tests (sequential)

### Manual Agent Invocation
```
@livewire-builder "Create a component for managing blog posts with CRUD operations"

@research-agent "Find the best Laravel package for handling file uploads in 2026"

@test-runner "Create comprehensive tests for the authentication system"
```

### Parallel Execution
For independent tasks, the orchestrator will run them simultaneously:
```
User: "Set up a blog with posts, comments, and tags"

→ Spawns 3 agents in parallel:
  @database-executor: "Create posts table"
  @database-executor: "Create comments table"
  @database-executor: "Create tags table"
```

## Project-Specific Guidelines

Once the project vision is defined, update `CLAUDE.md` with:
- Project-specific architecture decisions
- Custom component patterns
- Business logic conventions
- Domain-specific terminology

## Next Steps

1. ✅ Infrastructure complete
2. ⏳ Define project vision (in progress)
3. 🔜 Generate PRD document
4. 🔜 Design database schema
5. 🔜 Build MVP features

## MCP Servers Available

Your `.mcp.json` provides access to:
- **laravel-boost** - Laravel-specific tools (artisan, config, logs, tinker)
- Database queries and schema inspection
- Browser logs and debugging

## Best Practices

1. **Let agents work in parallel** - Orchestrator will maximize efficiency
2. **Use skills automatically** - They trigger based on context
3. **Run security scans** - Before every deployment
4. **Test everything** - Minimum 80% coverage
5. **Follow Laravel conventions** - Artisan commands, Form Requests, Policies

## Getting Help

- See `.claude/README.md` for detailed agent documentation
- Check individual agent files in `.claude/agents/`
- Review skill files in `.claude/skills/`
- Examine command workflows in `.claude/commands/`

---

**Infrastructure Status**: ✅ Complete and Ready

**Waiting for**: Project vision and requirements
