<div>
    <flux:button wire:click="openModal" icon="plus">
        Add Customer
    </flux:button>

    <flux:modal wire:model="showModal" variant="flyout" class="max-w-lg">
        <form wire:submit="save">
            <flux:heading size="lg" class="mb-6">Add New Customer</flux:heading>

            <div class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:field>
                        <flux:label>First Name</flux:label>
                        <flux:input wire:model="firstName" placeholder="John" />
                        <flux:error name="firstName" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Last Name</flux:label>
                        <flux:input wire:model="lastName" placeholder="Doe" />
                        <flux:error name="lastName" />
                    </flux:field>
                </div>

                <flux:field>
                    <flux:label>Email</flux:label>
                    <flux:input wire:model="email" type="email" placeholder="john@example.com" />
                    <flux:error name="email" />
                </flux:field>

                <flux:field>
                    <flux:label>Phone</flux:label>
                    <flux:input wire:model="phone" placeholder="(555) 123-4567" />
                    <flux:error name="phone" />
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
                    <flux:label>Notes</flux:label>
                    <flux:textarea wire:model="notes" rows="3" placeholder="Any additional notes..." />
                    <flux:error name="notes" />
                </flux:field>

                @if ($agents->count() > 0)
                    <flux:field>
                        <flux:label>Referring Agent</flux:label>
                        <flux:select wire:model="referredBy">
                            <option value="">No referring agent</option>
                            @foreach ($agents as $agent)
                                <option value="{{ $agent->id }}">{{ $agent->name }}</option>
                            @endforeach
                        </flux:select>
                        <flux:error name="referredBy" />
                    </flux:field>
                @endif
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <flux:button variant="ghost" wire:click="closeModal" type="button">
                    Cancel
                </flux:button>
                <flux:button type="submit" variant="primary">
                    Create Customer
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
