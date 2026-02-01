<div>
    <flux:button wire:click="openModal" icon="plus">
        Add Listing
    </flux:button>

    <flux:modal wire:model="showModal" variant="flyout" class="max-w-lg">
        <form wire:submit="save">
            <flux:heading size="lg" class="mb-6">Add Agent Listing</flux:heading>

            <div class="space-y-4">
                <flux:field>
                    <flux:label>Agent</flux:label>
                    <flux:select wire:model="agentId">
                        <option value="">Select Agent...</option>
                        @foreach ($agents as $agent)
                            <option value="{{ $agent->id }}">{{ $agent->name }}</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="agentId" />
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
                        <flux:input wire:model="state" maxlength="2" placeholder="CA" />
                        <flux:error name="state" />
                    </flux:field>

                    <flux:field>
                        <flux:label>ZIP</flux:label>
                        <flux:input wire:model="zip" placeholder="90001" />
                        <flux:error name="zip" />
                    </flux:field>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:field>
                        <flux:label>Listing Price</flux:label>
                        <flux:input wire:model="listingPrice" type="number" step="0.01" placeholder="350000" />
                        <flux:error name="listingPrice" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Status</flux:label>
                        <flux:select wire:model="status">
                            @foreach ($statuses as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </flux:select>
                        <flux:error name="status" />
                    </flux:field>
                </div>

                <flux:field>
                    <flux:label>Open House Date/Time</flux:label>
                    <flux:input wire:model="openHouseDate" type="datetime-local" />
                    <flux:error name="openHouseDate" />
                </flux:field>

                <flux:field>
                    <flux:label>Description</flux:label>
                    <flux:textarea wire:model="description" rows="3" placeholder="Property description for proposals..." />
                    <flux:error name="description" />
                </flux:field>

                <flux:field>
                    <flux:label>Internal Notes</flux:label>
                    <flux:textarea wire:model="notes" rows="2" placeholder="Internal notes..." />
                    <flux:error name="notes" />
                </flux:field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <flux:button variant="ghost" wire:click="closeModal" type="button">
                    Cancel
                </flux:button>
                <flux:button type="submit" variant="primary">
                    Create Listing
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
