<?php

use App\Models\VideoChannel;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public ?int $channelId = null;

    public string $name = '';

    public string $description = '';

    public string $visibility = 'public';

    public bool $isActive = true;

    public function mount(?int $channelId = null): void
    {
        if ($channelId) {
            $this->loadChannel($channelId);
        }
    }

    #[On('edit-channel')]
    public function onEditChannel(?int $channelId): void
    {
        if ($channelId) {
            $this->loadChannel($channelId);
        } else {
            $this->resetFields();
        }
    }

    public function save(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'visibility' => ['required', 'in:public,private'],
            'isActive' => ['boolean'],
        ]);

        if ($this->channelId) {
            $channel = VideoChannel::findOrFail($this->channelId);
            $channel->update([
                'name' => $this->name,
                'description' => $this->description ?: null,
                'visibility' => $this->visibility,
                'is_active' => $this->isActive,
            ]);
        } else {
            VideoChannel::create([
                'user_id' => auth()->id(),
                'name' => $this->name,
                'description' => $this->description ?: null,
                'visibility' => $this->visibility,
                'is_active' => $this->isActive,
            ]);
        }

        $this->dispatch('channel-saved');
    }

    private function loadChannel(int $id): void
    {
        $channel = VideoChannel::findOrFail($id);
        $this->channelId = $channel->id;
        $this->name = $channel->name;
        $this->description = $channel->description ?? '';
        $this->visibility = $channel->visibility;
        $this->isActive = $channel->is_active;
    }

    private function resetFields(): void
    {
        $this->channelId = null;
        $this->name = '';
        $this->description = '';
        $this->visibility = 'public';
        $this->isActive = true;
    }
};
?>

<div>
    <form wire:submit="save">
        <flux:heading size="lg" class="mb-4">
            {{ $channelId ? __('Edit Channel') : __('Create Channel') }}
        </flux:heading>

        <div class="space-y-4">
            <flux:field>
                <flux:label>{{ __('Name') }}</flux:label>
                <flux:input wire:model="name" placeholder="{{ __('e.g. Our Best Work') }}" autofocus />
                <flux:error name="name" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Description') }}</flux:label>
                <flux:textarea wire:model="description" rows="3" />
                <flux:error name="description" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Visibility') }}</flux:label>
                <flux:select wire:model="visibility">
                    <flux:select.option value="public">{{ __('Public') }}</flux:select.option>
                    <flux:select.option value="private">{{ __('Private') }}</flux:select.option>
                </flux:select>
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Active') }}</flux:label>
                <flux:switch wire:model="isActive" />
            </flux:field>
        </div>

        <div class="flex justify-end gap-2 mt-6">
            <flux:button type="submit" variant="primary">
                {{ $channelId ? __('Save Changes') : __('Create') }}
            </flux:button>
        </div>
    </form>
</div>
