<?php

namespace App\DTOs;

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;

class LeadResolutionResult
{
    public function __construct(
        public Customer $customer,
        public Deal $deal,
        public ?Estimate $estimate,
        public bool $customerWasExisting,
        public bool $dealWasExisting,
        public bool $estimateWasExisting,
    ) {}
}
