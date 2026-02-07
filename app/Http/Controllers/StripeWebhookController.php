<?php

namespace App\Http\Controllers;

use App\Services\StripePaymentService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class StripeWebhookController extends Controller
{
    public function handle(Request $request, StripePaymentService $service): Response
    {
        try {
            $service->handleWebhook($request);

            return response('OK', 200);
        } catch (\RuntimeException $e) {
            return response($e->getMessage(), 400);
        }
    }
}
