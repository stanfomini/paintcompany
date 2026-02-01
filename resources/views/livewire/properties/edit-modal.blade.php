<div>
    <flux:button wire:click="openModal({{ $propertyId ?? 'null' }})" icon="pencil" variant="ghost">
        Edit
    </flux:button>

    <flux:modal wire:model="showModal" variant="flyout" class="max-w-lg">
        <form wire:submit="save">
            <flux:heading size="lg" class="mb-6">Edit Property</flux:heading>

            <div class="space-y-4">
                <flux:field>
                    <flux:label>Customer</flux:label>
                    <flux:select wire:model="customerId">
                        <option value="">Select a customer...</option>
                        @foreach ($this->customers as $customer)
                            <option value="{{ $customer->id }}">{{ $customer->first_name }} {{ $customer->last_name }}</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="customerId" />
                </flux:field>

                <flux:field>
                    <flux:label>Property Name (optional)</flux:label>
                    <flux:input wire:model="name" placeholder="e.g., Main Office Building" />
                    <flux:error name="name" />
                </flux:field>

                <flux:field>
                    <flux:label>Address</flux:label>
                    <flux:input wire:model="address" placeholder="123 Main St" />
                    <flux:error name="address" />
                </flux:field>

                <div class="grid gap-4 sm:grid-cols-3">
                    <flux:field>
                        <flux:label>City</flux:label>
                        <flux:input wire:model="city" placeholder="Los Angeles" />
                        <flux:error name="city" />
                    </flux:field>

                    <flux:field>
                        <flux:label>State</flux:label>
                        <flux:input wire:model="state" placeholder="CA" />
                        <flux:error name="state" />
                    </flux:field>

                    <flux:field>
                        <flux:label>ZIP</flux:label>
                        <flux:input wire:model="zip" placeholder="90001" />
                        <flux:error name="zip" />
                    </flux:field>
                </div>

                <flux:field>
                    <flux:label>Property Type</flux:label>
                    <flux:select wire:model="type">
                        <option value="residential">Residential</option>
                        <option value="commercial">Commercial</option>
                        <option value="industrial">Industrial</option>
                    </flux:select>
                    <flux:error name="type" />
                </flux:field>

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:field>
                        <flux:label>Square Footage</flux:label>
                        <flux:input wire:model="squareFootage" type="number" placeholder="2500" />
                        <flux:error name="squareFootage" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Year Built</flux:label>
                        <flux:input wire:model="yearBuilt" type="number" placeholder="1990" />
                        <flux:error name="yearBuilt" />
                    </flux:field>
                </div>

                <flux:field>
                    <flux:label>Notes</flux:label>
                    <flux:textarea wire:model="notes" rows="3" placeholder="Any additional notes..." />
                    <flux:error name="notes" />
                </flux:field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <flux:button variant="ghost" wire:click="closeModal" type="button">
                    Cancel
                </flux:button>
                <flux:button type="submit" variant="primary">
                    Save Changes
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
