<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessInboundEmailJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailInboundWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        // Respond 200 immediately, process asynchronously
        ProcessInboundEmailJob::dispatch($request->all());

        return response()->json(['status' => 'queued']);
    }
}
