<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateTemplate;
use App\Models\ProjectPreview;
use App\Models\ProjectPreviewPhoto;
use App\Models\PropertyPhoto;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProjectPreviewService
{
    /**
     * Create a ProjectPreview for a phone number.
     * Looks up existing Customer by phone if available.
     */
    public function createForPhone(
        string $phone,
        ?int $customerId = null,
        ?int $leadSourceId = null
    ): ProjectPreview {
        $phone = $this->normalizePhone($phone);

        // Try to find existing customer by ID or phone
        if (! $customerId) {
            $customer = Customer::where('phone', $phone)->first();
            $customerId = $customer?->id;
        }

        return ProjectPreview::create([
            'phone' => $phone,
            'customer_id' => $customerId,
            'lead_source_id' => $leadSourceId,
            'status' => 'active',
        ]);
    }

    /**
     * Create a ProjectPreview from an existing Customer.
     */
    public function createForCustomer(Customer $customer, ?int $leadSourceId = null): ProjectPreview
    {
        return ProjectPreview::create([
            'customer_id' => $customer->id,
            'phone' => $customer->phone,
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'email' => $customer->email,
            'address' => $customer->full_address,
            'lead_source_id' => $leadSourceId ?? $customer->lead_source_id,
            'status' => 'active',
        ]);
    }

    /**
     * Calculate ballpark price range for preview's rooms_data.
     * Maps room names to EstimateTemplate slugs, calculates S and L price range.
     *
     * @return array{total_min: float, total_max: float, rooms: array}
     */
    public function calculateBallpark(ProjectPreview $preview): array
    {
        $roomsData = $preview->rooms_data ?? [];
        $scopeType = $preview->scope_type ?? 'interior';

        $totalMin = 0.0;
        $totalMax = 0.0;
        $rooms = [];

        foreach ($roomsData as $room) {
            $roomName = $room['name'] ?? '';
            if (empty($roomName)) {
                continue;
            }

            // Find matching template by name (case-insensitive)
            $template = EstimateTemplate::where('name', 'LIKE', $roomName)
                ->where('is_active', true)
                ->where(function ($q) use ($scopeType) {
                    $q->where('scope_type', $scopeType)
                        ->orWhere('scope_type', EstimateTemplate::SCOPE_TYPE_BOTH);
                })
                ->first();

            if (! $template) {
                continue;
            }

            $metadata = $template->metadata ?? [];
            $sizes = $metadata['sizes'] ?? [];

            if (empty($sizes)) {
                continue;
            }

            // Get small and large size sqft values
            $smallSqft = $sizes['small']['sqft'] ?? null;
            $largeSqft = $sizes['large']['sqft'] ?? null;

            if ($smallSqft === null || $largeSqft === null) {
                continue;
            }

            // Calculate price for each size using the template's full PBP formula
            $minPrice = $template->calculatePriceWithMargins((float) $smallSqft);
            $maxPrice = $template->calculatePriceWithMargins((float) $largeSqft);

            $rooms[] = [
                'name' => $roomName,
                'min' => round($minPrice, 2),
                'max' => round($maxPrice, 2),
            ];

            $totalMin += $minPrice;
            $totalMax += $maxPrice;
        }

        return [
            'total_min' => round($totalMin, 2),
            'total_max' => round($totalMax, 2),
            'rooms' => $rooms,
        ];
    }

    /**
     * Convert a ProjectPreview into an Estimate.
     * 1. Creates/reuses Customer and Deal
     * 2. Creates Estimate with status 'draft'
     * 3. Maps rooms_data entries to EstimateAreas using templates
     * 4. Copies photos to estimate
     * 5. Marks preview as 'converted'
     */
    public function convertToEstimate(ProjectPreview $preview): Estimate
    {
        return DB::transaction(function () use ($preview) {
            // 1. Ensure we have a customer
            $customer = $this->resolveCustomer($preview);

            // 2. Create or get a Deal for this customer
            $deal = $preview->deal_id
                ? Deal::find($preview->deal_id)
                : $customer->deals()->create([
                    'title' => 'Project from Preview',
                    'stage' => 'lead',
                    'lead_source_id' => $preview->lead_source_id,
                    'assigned_to' => $preview->estimator_id,
                ]);

            // 3. Create Estimate
            $customerName = $customer->full_name ?? 'Customer';
            $estimate = $deal->estimates()->create([
                'status' => 'draft',
                'title' => "Estimate for {$customerName}",
                'project_type' => $preview->scope_type ?? 'interior',
                'estimator_mode' => Estimate::MODE_TEMPLATE,
                'created_by' => $preview->estimator_id ?? $this->getFirstInternalUserId(),
            ]);

            // 4. Map rooms_data to EstimateAreas
            $this->createAreasFromRoomsData($estimate, $preview);

            // 5. Copy photos to estimate
            $this->copyPhotosToEstimate($estimate, $preview);

            // 6. Mark preview as converted
            $preview->update([
                'status' => 'converted',
                'estimate_id' => $estimate->id,
                'deal_id' => $deal->id,
                'customer_id' => $customer->id,
            ]);

            return $estimate;
        });
    }

    /**
     * Store a photo for the preview.
     */
    public function storePhoto(
        ProjectPreview $preview,
        string $path,
        string $disk = 'public',
        string $mimeType = 'image/jpeg',
        int $fileSize = 0
    ): ProjectPreviewPhoto {
        $nextSort = ($preview->photos()->max('sort_order') ?? 0) + 1;

        return $preview->photos()->create([
            'original_path' => $path,
            'disk' => $disk,
            'mime_type' => $mimeType,
            'file_size' => $fileSize,
            'sort_order' => $nextSort,
        ]);
    }

    /**
     * Find or create preview by phone number.
     */
    public function findOrCreateByPhone(string $phone): ProjectPreview
    {
        $phone = $this->normalizePhone($phone);

        return ProjectPreview::active()
            ->forPhone($phone)
            ->latest()
            ->first() ?? $this->createForPhone($phone);
    }

    /**
     * Resolve or create a Customer from the preview data.
     */
    protected function resolveCustomer(ProjectPreview $preview): Customer
    {
        // Use existing customer if linked
        if ($preview->customer_id) {
            $customer = Customer::find($preview->customer_id);
            if ($customer) {
                return $customer;
            }
        }

        // Try to find by phone
        $phone = $this->normalizePhone($preview->phone ?? '');
        if ($phone) {
            $customer = Customer::where('phone', $phone)->first();
            if ($customer) {
                return $customer;
            }
        }

        // Try to find by email
        if ($preview->email) {
            $customer = Customer::where('email', $preview->email)->first();
            if ($customer) {
                return $customer;
            }
        }

        // Create new customer
        return Customer::create([
            'first_name' => $preview->first_name ?? 'Unknown',
            'last_name' => $preview->last_name ?? '',
            'email' => $preview->email,
            'phone' => $phone,
            'address' => $preview->address,
            'lead_source_id' => $preview->lead_source_id,
            'status' => 'active',
            'created_by' => $preview->estimator_id ?? $this->getFirstInternalUserId(),
        ]);
    }

    /**
     * Create EstimateAreas from the preview's rooms_data using templates.
     */
    protected function createAreasFromRoomsData(Estimate $estimate, ProjectPreview $preview): void
    {
        $roomsData = $preview->rooms_data ?? [];
        $scopeType = $preview->scope_type ?? 'interior';

        foreach ($roomsData as $index => $room) {
            $roomName = $room['name'] ?? '';
            if (empty($roomName)) {
                continue;
            }

            // Find matching template
            $template = EstimateTemplate::where('name', 'LIKE', $roomName)
                ->where('is_active', true)
                ->where(function ($q) use ($scopeType) {
                    $q->where('scope_type', $scopeType)
                        ->orWhere('scope_type', EstimateTemplate::SCOPE_TYPE_BOTH);
                })
                ->first();

            $metadata = $template?->metadata ?? [];
            $sizes = $metadata['sizes'] ?? [];

            // Determine sqft from size selection or template default
            $selectedSize = $room['size'] ?? ($metadata['default_size'] ?? 'medium');
            $sqft = $sizes[$selectedSize]['sqft'] ?? ($template?->default_quantity ?? 200);

            EstimateArea::create([
                'estimate_id' => $estimate->id,
                'name' => $roomName,
                'scope_type' => $scopeType,
                'measurement_type' => EstimateArea::MEASUREMENT_SQFT,
                'direct_sqft' => (float) $sqft,
                'substrate_id' => $template?->substrate_id,
                'sort_order' => $index + 1,
            ]);
        }
    }

    /**
     * Copy preview photos to the estimate as PropertyPhotos.
     * Requires a property to exist on the deal for the photo FK constraint.
     */
    protected function copyPhotosToEstimate(Estimate $estimate, ProjectPreview $preview): void
    {
        if ($preview->photos->isEmpty()) {
            return;
        }

        // Resolve property_id from the deal or customer
        $deal = $estimate->deal;
        $propertyId = $deal?->property_id;

        if (! $propertyId) {
            // Try to get from customer's first property
            $customer = $deal?->customer;
            $propertyId = $customer?->properties()->first()?->id;
        }

        if (! $propertyId) {
            Log::warning("Cannot copy preview photos: no property found for estimate {$estimate->id}");

            return;
        }

        $uploadedBy = $preview->estimator_id ?? $this->getFirstInternalUserId();

        foreach ($preview->photos as $photo) {
            try {
                PropertyPhoto::create([
                    'property_id' => $propertyId,
                    'uploaded_by' => $uploadedBy,
                    'path' => $photo->original_path,
                    'filename' => basename($photo->original_path),
                    'original_path' => $photo->original_path,
                    'disk' => $photo->disk,
                    'mime_type' => $photo->mime_type,
                    'file_size' => $photo->file_size,
                    'caption' => $photo->room_label,
                    'category' => 'reference',
                    'show_on_proposal' => true,
                    'estimate_id' => $estimate->id,
                    'sort_order' => $photo->sort_order,
                ]);
            } catch (\Throwable $e) {
                Log::warning("Failed to copy preview photo {$photo->id} to estimate: {$e->getMessage()}");
            }
        }
    }

    /**
     * Normalize a phone number to digits only.
     */
    protected function normalizePhone(string $phone): string
    {
        return preg_replace('/\D/', '', $phone);
    }

    /**
     * Get the first internal user ID as a fallback for created_by.
     */
    protected function getFirstInternalUserId(): ?int
    {
        return User::where('type', 'internal')->value('id');
    }
}
