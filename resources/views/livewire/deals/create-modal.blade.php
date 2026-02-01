<div>
    <flux:button wire:click="openModal" icon="plus">
        Add Deal
    </flux:button>

    <flux:modal wire:model="showModal" variant="flyout" class="max-w-lg">
        <form wire:submit="save">
            <flux:heading size="lg" class="mb-6">Add New Deal</flux:heading>

            <div class="space-y-4">
                <flux:field>
                    <flux:label>Customer</flux:label>
                    <flux:select wire:model.live="customerId" placeholder="Select a customer...">
                        <flux:select.option value="">Select a customer...</flux:select.option>
                        @foreach ($customers as $customer)
                            <flux:select.option value="{{ $customer->id }}">
                                {{ $customer->full_name }} ({{ $customer->email }})
                            </flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="customerId" />
                </flux:field>

                @if ($customerId && $properties->isNotEmpty())
                    <flux:field>
                        <flux:label>Property (Optional)</flux:label>
                        <flux:select wire:model="propertyId" placeholder="Select a property...">
                            <flux:select.option value="">No specific property</flux:select.option>
                            @foreach ($properties as $property)
                                <flux:select.option value="{{ $property->id }}">
                                    {{ $property->display_name }}
                                </flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="propertyId" />
                    </flux:field>
                @endif

                <flux:field>
                    <flux:label>Title</flux:label>
                    <flux:input wire:model="title" placeholder="e.g., Exterior Painting - Main House" />
                    <flux:error name="title" />
                </flux:field>

                <flux:field>
                    <flux:label>Description (Optional)</flux:label>
                    <flux:textarea wire:model="description" rows="3" placeholder="Details about this deal..." />
                    <flux:error name="description" />
                </flux:field>

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:field>
                        <flux:label>Stage</flux:label>
                        <flux:select wire:model="stage">
                            @foreach ($stages as $value => $label)
                                <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="stage" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Estimated Value</flux:label>
                        <flux:input wire:model="estimatedValue" type="number" step="0.01" min="0" placeholder="0.00" icon="currency-dollar" />
                        <flux:error name="estimatedValue" />
                    </flux:field>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:field>
                        <flux:label>Expected Start Date</flux:label>
                        <flux:input wire:model="expectedStartDate" type="date" />
                        <flux:error name="expectedStartDate" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Expected End Date</flux:label>
                        <flux:input wire:model="expectedEndDate" type="date" />
                        <flux:error name="expectedEndDate" />
                    </flux:field>
                </div>

                <flux:field>
                    <flux:label>Assigned To (Optional)</flux:label>
                    <flux:select wire:model="assignedTo" placeholder="Select team member...">
                        <flux:select.option value="">Unassigned</flux:select.option>
                        @foreach ($internalUsers as $user)
                            <flux:select.option value="{{ $user->id }}">{{ $user->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="assignedTo" />
                </flux:field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <flux:button variant="ghost" wire:click="closeModal" type="button">
                    Cancel
                </flux:button>
                <flux:button type="submit" variant="primary">
                    Create Deal
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
