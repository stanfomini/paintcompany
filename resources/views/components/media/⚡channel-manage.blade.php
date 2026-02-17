<?php

use App\Models\VideoChannel;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app', ['title' => 'Manage Channel'])]
class extends Component {
    public VideoChannel $channel;
};
?>

<div class="py-6 mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
    <flux:heading size="xl">{{ $channel->name }}</flux:heading>
</div>
