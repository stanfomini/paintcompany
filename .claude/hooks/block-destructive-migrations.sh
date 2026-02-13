#!/usr/bin/env bash
#
# Hook: Block ALL destructive migration commands
# Applies to: PreToolUse(Bash) - all agents including subagents
#
# This script reads TOOL_INPUT (JSON) from the Bash tool call,
# extracts the command, and blocks any form of migrate:fresh or migrate:refresh.
#

COMMAND="$TOOL_INPUT"

# Normalize: lowercase everything for case-insensitive matching
LOWER_COMMAND=$(echo "$COMMAND" | tr '[:upper:]' '[:lower:]')

# Check for migrate:fresh or migrate:refresh in ANY form
# This catches: direct calls, chained with &&, piped, backticked, quoted, etc.
if echo "$LOWER_COMMAND" | grep -qE 'migrate[: _-]*(fresh|refresh)'; then
    echo "BLOCKED: migrate:fresh and migrate:refresh are PERMANENTLY FORBIDDEN." >&2
    echo "These commands DESTROY ALL DATA in the production database." >&2
    echo "Use 'php artisan migrate' (without fresh/refresh) to run pending migrations only." >&2
    exit 2
fi

# Also block db:wipe which has the same effect
if echo "$LOWER_COMMAND" | grep -qE 'db[: _-]*wipe'; then
    echo "BLOCKED: db:wipe is PERMANENTLY FORBIDDEN." >&2
    echo "This command DESTROYS ALL DATA in the production database." >&2
    exit 2
fi

# Also block schema:dump --prune which drops tables
if echo "$LOWER_COMMAND" | grep -qE 'schema[: _-]*dump.*--prune'; then
    echo "BLOCKED: schema:dump --prune is FORBIDDEN." >&2
    exit 2
fi

exit 0
