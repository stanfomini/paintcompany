<?php

namespace App\Livewire\Customer\Chat;

use App\Models\ChatThread;
use App\Models\Customer;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.customer', ['title' => 'Messages'])]
class ThreadList extends Component
{
    public function render(): View
    {
        $user = auth('customer')->user();

        // Get the customer record for this user
        $customer = Customer::query()
            ->where('user_id', $user->id)
            ->first();

        $threads = collect();

        if ($customer) {
            // Get deal-type threads where this customer user is a participant
            $threads = ChatThread::query()
                ->ofType(ChatThread::TYPE_DEAL)
                ->forUser($user)
                ->active()
                ->with(['latestMessage.user', 'deal'])
                ->latestActivity()
                ->get()
                ->map(function (ChatThread $thread) use ($user) {
                    $thread->unread_count = $thread->getUnreadCountFor($user);
                    $thread->display_name = $thread->getDisplayNameFor($user);

                    return $thread;
                });
        }

        return view('livewire.customer.chat.thread-list', [
            'threads' => $threads,
        ]);
    }
}
