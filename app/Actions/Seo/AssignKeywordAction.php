<?php

namespace App\Actions\Seo;

use App\Models\Keyword;
use App\Models\KeywordPageAssignment;
use App\Models\Service;
use App\Models\ServiceArea;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AssignKeywordAction
{
    /**
     * Assign a keyword to a target page (combo / service-only / area-only) with the given role.
     *
     * Behavior:
     *  - At least one of $service or $serviceArea must be provided.
     *  - If an assignment already exists for the same (keyword, service, area) tuple, the role
     *    is updated in place (idempotent dedup).
     *  - When assigning role=primary and another primary already exists for the same target tuple,
     *    the existing primary is demoted to secondary unless $force is false (in which case an
     *    exception is thrown).
     */
    public function execute(
        Keyword $keyword,
        ?Service $service,
        ?ServiceArea $serviceArea,
        string $role = 'secondary',
        ?string $customSentence = null,
        bool $force = true,
    ): KeywordPageAssignment {
        if ($service === null && $serviceArea === null) {
            throw new InvalidArgumentException('AssignKeywordAction requires at least one of service or serviceArea.');
        }

        if (! in_array($role, KeywordPageAssignment::ROLES, true)) {
            throw new InvalidArgumentException("Invalid role: {$role}");
        }

        return DB::transaction(function () use ($keyword, $service, $serviceArea, $role, $customSentence, $force) {
            // Demote existing primary on the same target tuple if we're claiming primary.
            if ($role === 'primary') {
                $existingPrimary = KeywordPageAssignment::query()
                    ->where('role', 'primary')
                    ->where('service_id', $service?->id)
                    ->where('service_area_id', $serviceArea?->id)
                    ->where('keyword_id', '!=', $keyword->id)
                    ->first();

                if ($existingPrimary !== null) {
                    if (! $force) {
                        throw new InvalidArgumentException(
                            "A primary keyword already exists for this page (assignment #{$existingPrimary->id})."
                        );
                    }
                    $existingPrimary->update(['role' => 'secondary']);
                }
            }

            return KeywordPageAssignment::updateOrCreate(
                [
                    'keyword_id' => $keyword->id,
                    'service_id' => $service?->id,
                    'service_area_id' => $serviceArea?->id,
                ],
                [
                    'role' => $role,
                    'custom_sentence' => $customSentence,
                ],
            );
        });
    }
}
