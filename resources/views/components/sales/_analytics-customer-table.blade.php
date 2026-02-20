@props(['knocks'])

@if($knocks->isEmpty())
    <flux:text class="text-zinc-500">{{ __('No records found.') }}</flux:text>
@else
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-zinc-200 dark:border-zinc-700">
                    <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Date') }}</th>
                    <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Rep') }}</th>
                    <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Territory') }}</th>
                    <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Address') }}</th>
                    <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Customer') }}</th>
                    <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Phone') }}</th>
                    <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Email') }}</th>
                    <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Status') }}</th>
                    <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Deal') }}</th>
                    <th class="text-left py-2 font-medium text-zinc-500">{{ __('Notes') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @foreach($knocks as $knock)
                    <tr wire:key="knock-{{ $knock->id }}">
                        <td class="py-2.5 pr-4 whitespace-nowrap">
                            {{ $knock->knocked_at?->format('M j, g:ia') }}
                        </td>
                        <td class="py-2.5 pr-4 whitespace-nowrap">
                            <div class="flex items-center gap-2">
                                <flux:avatar :name="$knock->user?->name ?? 'Unknown'" size="xs" />
                                <span>{{ $knock->user?->name ?? '-' }}</span>
                            </div>
                        </td>
                        <td class="py-2.5 pr-4 whitespace-nowrap">
                            @if($knock->territory)
                                <div class="flex items-center gap-1.5">
                                    <span class="h-2.5 w-2.5 rounded-full" style="background-color: {{ $knock->territory->color }}"></span>
                                    <span>{{ $knock->territory->name }}</span>
                                </div>
                            @else
                                <span class="text-zinc-400">-</span>
                            @endif
                        </td>
                        <td class="py-2.5 pr-4 max-w-[200px] truncate">
                            {{ $knock->address }}
                        </td>
                        <td class="py-2.5 pr-4 whitespace-nowrap font-medium">
                            {{ $knock->customer_name ?: '-' }}
                        </td>
                        <td class="py-2.5 pr-4 whitespace-nowrap">
                            @if($knock->phone)
                                <a href="tel:{{ $knock->phone }}" class="text-blue-600 hover:underline">{{ $knock->phone }}</a>
                            @else
                                <span class="text-zinc-400">-</span>
                            @endif
                        </td>
                        <td class="py-2.5 pr-4 whitespace-nowrap">
                            @if($knock->email)
                                <a href="mailto:{{ $knock->email }}" class="text-blue-600 hover:underline">{{ $knock->email }}</a>
                            @else
                                <span class="text-zinc-400">-</span>
                            @endif
                        </td>
                        <td class="py-2.5 pr-4 whitespace-nowrap">
                            @php
                                $statusColors = [
                                    'not_home' => 'zinc',
                                    'interested' => 'green',
                                    'not_interested' => 'red',
                                    'appointment_set' => 'blue',
                                    'sold' => 'yellow',
                                    'come_back' => 'purple',
                                ];
                            @endphp
                            <flux:badge size="sm" :color="$statusColors[$knock->status] ?? 'zinc'">
                                {{ \App\Models\DoorKnock::STATUSES[$knock->status] ?? $knock->status }}
                            </flux:badge>
                        </td>
                        <td class="py-2.5 pr-4 whitespace-nowrap">
                            @if($knock->deal)
                                <flux:badge size="sm" color="blue">
                                    {{ \App\Models\Deal::STAGES[$knock->deal->stage] ?? $knock->deal->stage }}
                                </flux:badge>
                                @if($knock->deal->estimated_value)
                                    <span class="text-xs text-zinc-500 ml-1">${{ number_format($knock->deal->estimated_value) }}</span>
                                @endif
                            @else
                                <span class="text-zinc-400">-</span>
                            @endif
                        </td>
                        <td class="py-2.5 max-w-[150px] truncate text-zinc-500">
                            {{ $knock->notes ?: '-' }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $knocks->links() }}
    </div>
@endif
