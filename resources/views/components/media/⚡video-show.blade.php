<?php

use App\Models\VideoRecording;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app', ['title' => 'Video Detail'])]
class extends Component {
    public VideoRecording $recording;
};
?>

<div class="py-6 mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
    <flux:heading size="xl">{{ $recording->title ?? __('Video Detail') }}</flux:heading>
</div>
