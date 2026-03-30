<?php

namespace App\Http\Controllers\Prospector;

use App\Http\Controllers\Controller;
use App\Models\Prospector\Company;
use App\Services\Prospector\WebhookIntakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LeadWebhookController extends Controller
{
    public function __construct(
        private WebhookIntakeService $intakeService,
    ) {}

    /**
     * POST /prospector/webhook/{companyUlid}/leads
     *
     * Accepts lead data from external systems.
     * Validates webhook_secret via X-Webhook-Secret header.
     */
    public function store(Request $request, string $companyUlid): JsonResponse
    {
        $company = $this->resolveCompany($companyUlid);

        if (! $company) {
            return response()->json(['error' => 'Company not found.'], 404);
        }

        if (! $this->validateSecret($request, $company)) {
            return response()->json(['error' => 'Invalid webhook secret.'], 401);
        }

        $data = $request->all();

        $errors = $this->intakeService->validatePayload($data);

        if (! empty($errors)) {
            return response()->json([
                'error' => 'Validation failed.',
                'errors' => $errors,
            ], 422);
        }

        $result = $this->intakeService->processLead($company, $data);

        return response()->json([
            'success' => true,
            'contact' => [
                'ulid' => $result['contact']->ulid,
                'first_name' => $result['contact']->first_name,
                'last_name' => $result['contact']->last_name,
                'phone' => $result['contact']->phone,
                'email' => $result['contact']->email,
            ],
            'created' => $result['created'],
            'campaign_enrolled' => $result['campaign_enrolled'],
        ], 201);
    }

    /**
     * POST /prospector/webhook/{companyUlid}/leads/bulk
     *
     * Accepts array of leads for bulk import.
     * Max 100 leads per request.
     */
    public function bulkStore(Request $request, string $companyUlid): JsonResponse
    {
        $company = $this->resolveCompany($companyUlid);

        if (! $company) {
            return response()->json(['error' => 'Company not found.'], 404);
        }

        if (! $this->validateSecret($request, $company)) {
            return response()->json(['error' => 'Invalid webhook secret.'], 401);
        }

        $leads = $request->input('leads');

        if (! is_array($leads)) {
            return response()->json([
                'error' => 'Validation failed.',
                'errors' => ['leads must be an array.'],
            ], 422);
        }

        if (count($leads) > 100) {
            return response()->json([
                'error' => 'Validation failed.',
                'errors' => ['Maximum 100 leads per request.'],
            ], 422);
        }

        if (count($leads) === 0) {
            return response()->json([
                'error' => 'Validation failed.',
                'errors' => ['leads array must not be empty.'],
            ], 422);
        }

        $created = 0;
        $updated = 0;
        $errors = [];

        foreach ($leads as $index => $leadData) {
            if (! is_array($leadData)) {
                $errors[] = ['index' => $index, 'errors' => ['Lead must be an object.']];

                continue;
            }

            $validationErrors = $this->intakeService->validatePayload($leadData);

            if (! empty($validationErrors)) {
                $errors[] = ['index' => $index, 'errors' => $validationErrors];

                continue;
            }

            $result = $this->intakeService->processLead($company, $leadData);

            if ($result['created']) {
                $created++;
            } else {
                $updated++;
            }
        }

        $processed = $created + $updated;

        Log::info('Webhook bulk lead import completed', [
            'company_id' => $company->id,
            'processed' => $processed,
            'created' => $created,
            'updated' => $updated,
            'errors' => count($errors),
        ]);

        return response()->json([
            'success' => true,
            'processed' => $processed,
            'created' => $created,
            'updated' => $updated,
            'errors' => $errors,
        ], 201);
    }

    /**
     * Find company by ULID, must be active.
     */
    private function resolveCompany(string $companyUlid): ?Company
    {
        return Company::where('ulid', $companyUlid)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Validate X-Webhook-Secret header matches company.webhook_secret.
     */
    private function validateSecret(Request $request, Company $company): bool
    {
        $secret = $request->header('X-Webhook-Secret');

        if (empty($secret) || empty($company->webhook_secret)) {
            return false;
        }

        return hash_equals($company->webhook_secret, $secret);
    }
}
