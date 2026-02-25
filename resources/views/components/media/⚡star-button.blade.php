<?php

use App\Models\MediaStar;
use Livewire\Component;

new class extends Component {
    public string $starrableType;
    public int $starrableId;
    public bool $isStarred = false;

    public function mount(string $starrableType, int $starrableId): void
    {
        $this->starrableType = $starrableType;
        $this->starrableId = $starrableId;
        $this->isStarred = MediaStar::where('user_id', auth()->id())
            ->where('starrable_type', $starrableType)
            ->where('starrable_id', $starrableId)
            ->exists();
    }

    public function toggle(): void
    {
        $model = $this->starrableType::findOrFail($this->starrableId);
        $this->isStarred = $model->toggleStar(auth()->user());
        $this->dispatch('star-toggled');
    }
};
?>

<div class="inline-flex">
    <button
        wire:click="toggle"
        wire:loading.attr="disabled"
        type="button"
        class="p-1 rounded transition-colors {{ $isStarred ? 'text-yellow-500 hover:text-yellow-600' : 'text-zinc-400 hover:text-yellow-500 dark:text-zinc-500 dark:hover:text-yellow-400' }}"
        title="{{ $isStarred ? __('Remove from favorites') : __('Add to favorites') }}"
    >
        @if($isStarred)
            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" /></svg>
        @else
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.563.563 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.386a.562.562 0 00-.182-.557l-4.204-3.602a.563.563 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z" /></svg>
        @endif
    </button>
</div>
