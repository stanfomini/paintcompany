# Claude Code Infrastructure

This directory contains the complete agent, skill, and command infrastructure for this Laravel 12 + Livewire 4 project.

## Directory Structure

```
.claude/
├── agents/              # Executor agents for task delegation
│   ├── orchestrator.md         # Master coordinator
│   ├── livewire-builder.md     # Builds Livewire components
│   ├── database-executor.md    # Manages database operations
│   ├── test-runner.md          # Writes and runs tests
│   ├── security-scanner.md     # Security audits
│   ├── research-agent.md       # Web research
│   ├── package-installer.md    # Package installation
│   ├── forge-deployer.md       # Deployment management
│   └── code-reviewer.md        # Code quality reviews
│
├── skills/              # Specialized knowledge bases
│   ├── livewire-4/             # Livewire 4 patterns
│   ├── pest-testing/           # Testing patterns
│   ├── database-design/        # Schema design
│   ├── security-audit/         # Security best practices
│   └── forge-deploy/           # Deployment procedures
│
├── commands/            # Custom slash commands
│   ├── build-feature.md        # /build-feature [name]
│   ├── deploy.md               # /deploy
│   ├── security-audit.md       # /security-audit
│   └── test-all.md             # /test-all
│
└── README.md            # This file
```

## Available Agents

### @orchestrator
Master coordinator that delegates tasks to specialized agents. Automatically invoked for complex multi-step workflows.

### @livewire-builder
Builds Livewire 4 components using artisan commands. Handles validation, loading states, and wire:model patterns.

### @database-executor
Creates migrations, runs migrations, seeds data, creates factories. Uses Laravel Boost MCP for database inspection.

### @test-runner
Writes Pest tests, runs test suite, analyzes failures, fixes broken tests. Ensures coverage targets are met.

### @security-scanner
Scans for vulnerabilities (SQL injection, XSS, mass assignment, etc.). Auto-fixes common issues and generates reports.

### @research-agent
Searches the web for current best practices, evaluates packages, compares alternatives. Documents findings in docs/research/.

### @package-installer
Installs composer/npm packages, publishes configs, runs migrations, verifies installation.

### @forge-deployer
Manages Laravel Forge deployments to Digital Ocean. Handles SSL, queues, environment configuration.

### @code-reviewer
Reviews code quality, performance, and adherence to Laravel/Livewire best practices.

## Available Skills

Skills are automatically triggered based on context:

- **livewire-4**: Activated when working with Livewire components
- **pest-testing**: Activated when writing or running tests
- **database-design**: Activated when designing schemas or migrations
- **security-audit**: Activated during security scans
- **forge-deploy**: Activated during deployment tasks

## Custom Commands

Use these commands for common workflows:

```bash
/build-feature [feature-name]    # Build complete feature with tests
/deploy                          # Deploy to production with checks
/security-audit                  # Run comprehensive security scan
/test-all                        # Run complete test suite
```

## How It Works

1. **Complex tasks** → Orchestrator analyzes and delegates to specialized agents
2. **Agents run in parallel** when tasks are independent
3. **Skills provide context** automatically based on task type
4. **Commands trigger workflows** with pre-defined execution plans

## Example Usage

```
User: "Create a blog post management system"

→ Orchestrator spawns in parallel:
  - @research-agent: Research Laravel blog packages
  - @database-executor: Create posts table migration
  - @livewire-builder: Create post CRUD components
  - @test-runner: Create test stubs

→ Then sequentially:
  - @security-scanner: Scan new code
  - @test-runner: Run and verify tests
```

## Agent Invocation

Agents can be invoked:
- **Automatically**: By the orchestrator for complex tasks
- **Manually**: Using @agent-name in prompts
- **Via commands**: Using custom slash commands

## Best Practices

1. Let the orchestrator handle complex multi-step tasks
2. Reference specific agents for focused work
3. Use skills to ensure best practices are followed
4. Leverage parallel execution for independent tasks
5. Always run security scans before deployment
