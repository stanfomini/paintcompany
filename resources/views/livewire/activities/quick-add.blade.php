<div>
    @if (session('quick-add-success'))
        <flux:callout variant="success" class="mb-4">
            {{ session('quick-add-success') }}
        </flux:callout>
    @endif

    <form wire:submit="save">
        <div class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>Title</flux:label>
                    <flux:input wire:model="title" placeholder="Activity title..." />
                    <flux:error name="title" />
                </flux:field>
                <flux:field>
                    <flux:label>Type</flux:label>
                    <flux:select wire:model="type">
                        @foreach ($types as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="type" />
                </flux:field>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>Due Date (optional)</flux:label>
                    <flux:input wire:model="dueAt" type="date" />
                    <flux:error name="dueAt" />
                </flux:field>
            </div>
            <flux:field>
                <flux:label>Description (optional)</flux:label>
                <flux:textarea wire:model="description" rows="2" placeholder="Add details..." />
                <flux:error name="description" />
            </flux:field>
        </div>
        <div class="mt-4 flex justify-end">
            <flux:button type="submit" variant="primary" size="sm">
                Add Activity
            </flux:button>
        </div>
    </form>
</div>
