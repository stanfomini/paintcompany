<?php

use App\Mcp\Servers\WorkflowBuilderServer;
use Laravel\Mcp\Facades\Mcp;

/*
|--------------------------------------------------------------------------
| AI & MCP Routes
|--------------------------------------------------------------------------
|
| MCP server endpoints for external AI clients (Claude Desktop, Cursor, etc.)
| and AI-related API routes.
|
*/

// Web-based MCP endpoint (for remote AI clients via HTTP)
Mcp::web('/mcp/workflow-builder', WorkflowBuilderServer::class)
    ->middleware(['auth:sanctum', 'throttle:ai-workflow']);

// Local stdio-based MCP endpoint (for local AI clients)
Mcp::local('workflow-builder', WorkflowBuilderServer::class);
