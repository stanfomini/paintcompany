<?php

use App\Models\TrainingPath;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app', ['title' => 'Manage Training Path'])]
class extends Component {
    public TrainingPath $trainingPath;
};
?>

<div class="py-6 mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
    <flux:heading size="xl">{{ $trainingPath->name }}</flux:heading>
</div>
