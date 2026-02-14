<?php

namespace App\Console\Commands;

use App\Models\AiBuildRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class EstimateAiBuildCommand extends Command
{
    protected $signature = 'estimate:ai-build {estimateId} {requestId}';

    protected $description = 'Spawn Claude Code to automatically build an estimate from blueprints and photos';

    public function handle(): int
    {
        $estimateId = $this->argument('estimateId');
        $requestId = $this->argument('requestId');

        $buildRequest = AiBuildRequest::find($requestId);
        if (! $buildRequest) {
            $this->error("Build request #{$requestId} not found.");

            return self::FAILURE;
        }

        $buildRequest->markBuilding();

        // Generate the prompt from template
        $prompt = $this->buildPrompt($estimateId, $requestId);

        // Create log directory
        $logDir = storage_path('logs/ai-builds');
        if (! is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $logFile = "{$logDir}/{$estimateId}-".now()->format('Y-m-d-His').'.log';
        $buildRequest->update(['log_path' => $logFile]);

        $claudeBin = config('ai-build.claude.binary');
        $workingDir = config('ai-build.claude.working_dir');
        $tmuxSession = "ai-build-{$estimateId}";

        // Kill any existing tmux session for this estimate
        Process::run("tmux kill-session -t {$tmuxSession} 2>/dev/null");

        // Write prompt to temp file (avoids shell escaping issues)
        $promptFile = "/tmp/ai-build-prompt-{$estimateId}.md";
        file_put_contents($promptFile, $prompt);

        // Spawn Claude Code in a tmux session
        $command = sprintf(
            'tmux new-session -d -s %s "cd %s && %s --dangerously-skip-permissions -p %s 2>&1 | tee %s; sleep 5"',
            escapeshellarg($tmuxSession),
            escapeshellarg($workingDir),
            escapeshellarg($claudeBin),
            escapeshellarg("$(cat {$promptFile})"),
            escapeshellarg($logFile),
        );

        $this->info("Starting AI build for estimate #{$estimateId}...");
        $this->info("tmux session: {$tmuxSession}");
        $this->info("Log file: {$logFile}");
        $this->info("Attach with: tmux attach -t {$tmuxSession}");

        Log::info("AI Build starting for estimate #{$estimateId}", [
            'request_id' => $requestId,
            'tmux_session' => $tmuxSession,
            'log_file' => $logFile,
        ]);

        Process::run($command);

        return self::SUCCESS;
    }

    protected function buildPrompt(int $estimateId, int $requestId): string
    {
        $templatePath = storage_path('app/ai-prompts/estimate-build.md');

        if (! file_exists($templatePath)) {
            throw new \RuntimeException("Prompt template not found at {$templatePath}");
        }

        $template = file_get_contents($templatePath);

        $callbackUrl = config('ai-build.prod_server_url').'/webhooks/ai-build-callback';

        return str_replace([
            '{{ESTIMATE_ID}}',
            '{{REQUEST_ID}}',
            '{{SSH_KEY}}',
            '{{SSH_USER}}',
            '{{SSH_HOST}}',
            '{{PROD_PATH}}',
            '{{CALLBACK_URL}}',
            '{{API_SECRET}}',
        ], [
            $estimateId,
            $requestId,
            config('ai-build.ssh.key'),
            config('ai-build.ssh.user'),
            config('ai-build.ssh.host'),
            config('ai-build.ssh.prod_path'),
            $callbackUrl,
            config('ai-build.api_secret'),
        ], $template);
    }
}
