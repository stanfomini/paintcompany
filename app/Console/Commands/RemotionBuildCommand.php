<?php

namespace App\Console\Commands;

use App\Models\RemotionBuildRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class RemotionBuildCommand extends Command
{
    protected $signature = 'remotion:build {requestId}';

    protected $description = 'Spawn Claude Code to build a Remotion video composition via AI';

    public function handle(): int
    {
        $request = RemotionBuildRequest::findOrFail($this->argument('requestId'));

        if (! $request->isActive()) {
            $this->error('Request is not in an active state.');

            return self::FAILURE;
        }

        $request->markBuilding();

        $prompt = $this->buildPrompt($request);

        // Create log directory
        $logDir = storage_path('logs/remotion-builds');
        if (! is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $logFile = "{$logDir}/{$request->id}-".now()->format('Y-m-d-His').'.log';
        $request->update(['log_path' => $logFile]);

        $claudeBin = config('ai-build.claude.binary');
        $workingDir = config('ai-build.claude.working_dir');
        $tmuxSession = "remotion-build-{$request->id}";

        // Kill any existing tmux session for this request
        Process::run("tmux kill-session -t {$tmuxSession} 2>/dev/null");

        // Write prompt to temp file (avoids shell escaping issues)
        $promptFile = "/tmp/remotion-build-prompt-{$request->id}.md";
        file_put_contents($promptFile, $prompt);

        // Write a launcher script to avoid shell quoting issues with tmux
        $scriptFile = "/tmp/remotion-build-run-{$request->id}.sh";
        $scriptContent = implode("\n", [
            '#!/bin/bash',
            'unset CLAUDECODE',
            'cd '.escapeshellarg($workingDir),
            escapeshellarg($claudeBin).' --dangerously-skip-permissions --disable-slash-commands --tools "Bash,Read" --model sonnet -p "$(cat '.escapeshellarg($promptFile).')" 2>&1 | tee '.escapeshellarg($logFile),
            'sleep 5',
        ]);
        file_put_contents($scriptFile, $scriptContent);
        chmod($scriptFile, 0755);

        // Spawn in tmux session
        $command = sprintf(
            'tmux new-session -d -s %s %s',
            escapeshellarg($tmuxSession),
            escapeshellarg($scriptFile),
        );

        $this->info("Starting Remotion build for request #{$request->id}...");
        $this->info("tmux session: {$tmuxSession}");
        $this->info("Log file: {$logFile}");
        $this->info("Attach with: tmux attach -t {$tmuxSession}");

        Log::info("Remotion build starting for request #{$request->id}", [
            'request_id' => $request->id,
            'tmux_session' => $tmuxSession,
            'log_file' => $logFile,
        ]);

        Process::run($command);

        return self::SUCCESS;
    }

    protected function buildPrompt(RemotionBuildRequest $request): string
    {
        $callbackUrl = route('api.remotion.build-complete');
        $apiSecret = config('services.remotion.build_secret');
        $workingDir = config('ai-build.claude.working_dir');

        $prompt = "You are building a Remotion video composition.\n\n";
        $prompt .= "USER PROMPT: {$request->prompt}\n\n";

        if ($request->system_context) {
            $prompt .= "ADDITIONAL CONTEXT: {$request->system_context}\n\n";
        }

        $prompt .= "INSTRUCTIONS:\n";
        $prompt .= "1. Read existing compositions in {$workingDir}/remotion/ to understand the project structure.\n";
        $prompt .= "2. Create or modify a composition based on the user's prompt.\n";
        $prompt .= "3. After creating the composition, render it using:\n";
        $prompt .= "   npx remotion render remotion/index.ts <CompositionId> --output=/tmp/remotion-output-{$request->id}.mp4\n";
        $prompt .= "4. After rendering succeeds, upload the result:\n";
        $prompt .= "   curl -X POST {$callbackUrl} \\\n";
        $prompt .= "     -H 'X-Remotion-Build-Secret: {$apiSecret}' \\\n";
        $prompt .= "     -F 'request_id={$request->id}' \\\n";
        $prompt .= "     -F 'file=@/tmp/remotion-output-{$request->id}.mp4'\n";
        $prompt .= "5. If any step fails, report the error:\n";
        $prompt .= "   curl -X POST {$callbackUrl} \\\n";
        $prompt .= "     -H 'X-Remotion-Build-Secret: {$apiSecret}' \\\n";
        $prompt .= "     -H 'Content-Type: application/json' \\\n";
        $prompt .= "     -d '{\"request_id\": {$request->id}, \"error\": \"<describe error>\"}'\n";

        return $prompt;
    }
}
