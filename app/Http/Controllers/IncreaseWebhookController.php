<?php

namespace App\Http\Controllers;

use App\Models\CompanySetting;
use App\Models\Payment;
use App\Services\IncreaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class IncreaseWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $signature = $request->header('Increase-Webhook-Signature', '');

        $increaseService = app(IncreaseService::class);

        if (! $increaseService->verifyWebhookSignature($payload, $signature)) {
            return response()->json(['error' => 'Invalid signature'], 403);
        }

        $event = $request->all();
        $eventType = $event['type'] ?? '';

        match ($eventType) {
            'check_deposit.submitted' => $this->handleCheckDepositSubmitted($event),
            'check_deposit.returned' => $this->handleCheckDepositReturned($event),
            'transaction.created' => $this->handleTransactionCreated($event),
            'ach_transfer.return' => $this->handleAchTransferReturn($event),
            'oauth_connection.deactivated' => $this->handleOAuthDeactivated($event),
            default => null,
        };

        return response()->json(['status' => 'ok']);
    }

    protected function handleCheckDepositSubmitted(array $event): void
    {
        Log::info('Increase check deposit submitted', [
            'deposit_id' => $event['data']['id'] ?? null,
        ]);
    }

    protected function handleCheckDepositReturned(array $event): void
    {
        Log::warning('Increase check deposit returned', [
            'deposit_id' => $event['data']['id'] ?? null,
            'return_reason' => $event['data']['return_reason'] ?? null,
        ]);
    }

    protected function handleTransactionCreated(array $event): void
    {
        $data = $event['data'] ?? [];
        $source = $data['source'] ?? [];

        // Only process check deposit transactions
        if (($source['type'] ?? '') !== 'check_deposit') {
            return;
        }

        $referenceNumber = $source['check_deposit_id'] ?? null;
        if ($referenceNumber) {
            $payment = Payment::where('reference_number', $referenceNumber)
                ->where('method', Payment::METHOD_CHECK)
                ->first();

            if ($payment && ! $payment->split_status) {
                // SplitPaymentJob will be dispatched when the job class is created
                Log::info('Increase transaction created for check deposit', [
                    'payment_id' => $payment->id,
                    'reference_number' => $referenceNumber,
                ]);
            }
        }
    }

    protected function handleAchTransferReturn(array $event): void
    {
        Log::warning('Increase ACH transfer returned', [
            'transfer_id' => $event['data']['id'] ?? null,
            'return_reason' => $event['data']['return_reason'] ?? null,
        ]);
    }

    protected function handleOAuthDeactivated(array $event): void
    {
        $settings = CompanySetting::getInstance();
        $settings->update([
            'increase_access_token' => null,
            'increase_enabled' => false,
        ]);

        Log::warning('Increase OAuth connection deactivated');
    }
}
