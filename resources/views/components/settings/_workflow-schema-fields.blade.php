{{-- Workflow Node Schema Fields Partial --}}
{{-- Renders config fields with dynamic field mapping dropdowns for all triggers --}}
@foreach ($nodeClass::getConfigSchema() as $key => $schema)
    <flux:field>
        <flux:label>{{ $schema['label'] ?? Str::title(str_replace('_', ' ', $key)) }}</flux:label>
        @if (! empty($schema['description']) && ! $availableDynamicFields && ! $webhookAvailableFields)
            <flux:description>{{ $schema['description'] }}</flux:description>
        @endif

        @if (isset($schema['options']) && is_array($schema['options']) && ! empty($schema['multiple']))
            {{-- Multi-select: checkboxes that store as comma-separated string --}}
            <div x-data="{
                selected: (() => {
                    const val = $wire.nodeConfig?.['{{ $key }}'] ?? '';
                    return val ? val.split(',').map(s => s.trim()).filter(Boolean) : [];
                })(),
                toggle(id) {
                    const idx = this.selected.indexOf(String(id));
                    if (idx >= 0) { this.selected.splice(idx, 1); }
                    else { this.selected.push(String(id)); }
                    $wire.set('nodeConfig.{{ $key }}', this.selected.join(','));
                },
                isSelected(id) { return this.selected.includes(String(id)); }
            }" class="max-h-48 overflow-y-auto rounded-lg border border-zinc-200 dark:border-zinc-600">
                @foreach ($schema['options'] as $optVal => $optLabel)
                    <label class="flex cursor-pointer items-center gap-2 px-3 py-2 hover:bg-zinc-50 dark:hover:bg-zinc-700/50"
                        x-on:click.prevent="toggle('{{ $optVal }}')">
                        <input type="checkbox" x-bind:checked="isSelected('{{ $optVal }}')" class="rounded border-zinc-300 text-blue-600 dark:border-zinc-600 dark:bg-zinc-800" />
                        <span class="text-sm text-zinc-700 dark:text-zinc-300">{{ $optLabel }}</span>
                    </label>
                @endforeach
            </div>
        @elseif (isset($schema['options']) && is_array($schema['options']))
            <flux:select wire:model="nodeConfig.{{ $key }}">
                <flux:select.option value="">-- Select --</flux:select.option>
                @foreach ($schema['options'] as $optVal => $optLabel)
                    <flux:select.option value="{{ $optVal }}">{{ $optLabel }}</flux:select.option>
                @endforeach
            </flux:select>
        @elseif (($schema['type'] ?? 'string') === 'boolean')
            <flux:switch wire:model="nodeConfig.{{ $key }}" />
        @elseif (($availableDynamicFields || $webhookAvailableFields) && in_array(($schema['type'] ?? 'string'), ['string', 'text']))
            {{-- Universal field mapping dropdown for string/text fields --}}
            <div x-data="{
                mode: (() => {
                    const val = $wire.nodeConfig?.['{{ $key }}'] ?? '';
                    if (!val) return 'skip';
                    if (/\{\{\w+\.\w/.test(val)) return 'mapped';
                    return 'custom';
                })(),
                setField(v) {
                    if (v === '__custom__') { this.mode = 'custom'; $wire.set('nodeConfig.{{ $key }}', ''); }
                    else if (v === '') { this.mode = 'skip'; $wire.set('nodeConfig.{{ $key }}', ''); }
                    else { this.mode = 'mapped'; $wire.set('nodeConfig.{{ $key }}', '{' + '{' + v + '}' + '}'); }
                },
                dropVal() {
                    const val = $wire.nodeConfig?.['{{ $key }}'] ?? '';
                    if (!val) return '';
                    const m = val.match(/\{\{(\w+\.\w[\w.]*)\}\}/);
                    return m ? m[1] : '__custom__';
                }
            }">
                <select x-show="mode !== 'custom'" x-on:change="setField($event.target.value)" x-bind:value="dropVal()"
                    class="block w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200">
                    <option value="">-- Skip --</option>
                    @if ($availableDynamicFields)
                        @php
                            $grouped = collect($availableDynamicFields)->groupBy('prefix');
                        @endphp
                        @foreach ($grouped as $prefix => $fields)
                            <optgroup label="{{ Str::title(str_replace('_', ' ', $prefix)) }}">
                                @foreach ($fields as $field)
                                    <option value="{{ $field['path'] }}">{{ $field['label'] }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    @endif
                    @if ($webhookAvailableFields)
                        <optgroup label="Payload Fields">
                            @foreach ($webhookAvailableFields as $field)
                                <option value="webhook.{{ $field['path'] }}">{{ $field['path'] }}{{ $field['sample'] !== null && $field['sample'] !== '' ? ' (' . Str::limit((string) $field['sample'], 25) . ')' : '' }}</option>
                            @endforeach
                        </optgroup>
                    @endif
                    <optgroup label="Other">
                        <option value="__custom__">Custom value...</option>
                    </optgroup>
                </select>
                <div x-show="mode === 'custom'" class="flex items-center gap-2">
                    @php $placeholderHint = 'e.g. ' . chr(123) . chr(123) . 'contact.email' . chr(125) . chr(125); @endphp
                    <flux:input wire:model="nodeConfig.{{ $key }}" :placeholder="$placeholderHint" class="flex-1" />
                    <flux:button variant="ghost" size="sm" x-on:click="mode = 'skip'; setField('')" icon="arrow-uturn-left" title="Back to dropdown" />
                </div>
            </div>
        @elseif (($schema['type'] ?? 'string') === 'integer' || ($schema['type'] ?? 'string') === 'number')
            <flux:input wire:model="nodeConfig.{{ $key }}" type="number" />
        @elseif (($schema['type'] ?? 'string') === 'text')
            <flux:textarea wire:model="nodeConfig.{{ $key }}" rows="3" />
        @else
            <flux:input wire:model="nodeConfig.{{ $key }}" />
        @endif
    </flux:field>
@endforeach
