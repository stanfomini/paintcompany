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
        @elseif (($schema['type'] ?? 'string') === 'html')
            {{-- Inline email body preview + expandable TipTap editor --}}
            <div x-data="{ editing: false }">
                {{-- Preview mode --}}
                <div x-show="!editing">
                    @if (! empty($this->nodeConfig[$key] ?? ''))
                        <div class="max-h-32 overflow-y-auto rounded-lg border border-zinc-200 bg-white p-3 text-sm dark:border-zinc-600 dark:bg-zinc-800">
                            <div class="prose prose-sm dark:prose-invert max-w-none">{!! $this->nodeConfig[$key] !!}</div>
                        </div>
                    @else
                        <div class="rounded-lg border border-dashed border-zinc-300 bg-zinc-50 px-3 py-4 text-center text-sm text-zinc-400 dark:border-zinc-600 dark:bg-zinc-800/50">
                            No content yet — click edit to add email body
                        </div>
                    @endif
                    <button type="button" x-on:click="editing = true"
                        class="mt-2 inline-flex items-center gap-1.5 text-sm font-medium text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300">
                        <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10"/></svg>
                        Edit Email Body
                    </button>
                </div>

                {{-- Edit mode with TipTap --}}
                <template x-if="editing">
                    <div class="space-y-2">
                        <div class="rounded-lg border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                            <div
                                wire:ignore
                                x-data="tiptapEditor({
                                    content: $wire.nodeConfig?.['{{ $key }}'] ?? '',
                                    placeholder: 'Write your email here...',
                                    minimal: false,
                                })"
                                x-effect="if (content !== ($wire.nodeConfig?.['{{ $key }}'] ?? '')) { $wire.set('nodeConfig.{{ $key }}', content) }"
                            >
                                {{-- Toolbar --}}
                                <div class="flex flex-wrap items-center gap-1 rounded-t-lg border-b border-zinc-200 bg-zinc-50 px-3 py-2 dark:border-zinc-700 dark:bg-zinc-800">
                                    <button type="button" @click="toggleBold()" :class="isActive('bold') ? 'bg-zinc-200 dark:bg-zinc-700' : ''"
                                        class="rounded p-1.5 text-zinc-600 hover:bg-zinc-200 dark:text-zinc-400 dark:hover:bg-zinc-700" title="Bold">
                                        <strong class="text-sm">B</strong>
                                    </button>
                                    <button type="button" @click="toggleItalic()" :class="isActive('italic') ? 'bg-zinc-200 dark:bg-zinc-700' : ''"
                                        class="rounded p-1.5 text-zinc-600 hover:bg-zinc-200 dark:text-zinc-400 dark:hover:bg-zinc-700" title="Italic">
                                        <em class="text-sm">I</em>
                                    </button>
                                    <button type="button" @click="toggleUnderline()" :class="isActive('underline') ? 'bg-zinc-200 dark:bg-zinc-700' : ''"
                                        class="rounded p-1.5 text-zinc-600 hover:bg-zinc-200 dark:text-zinc-400 dark:hover:bg-zinc-700" title="Underline">
                                        <span class="text-sm underline">U</span>
                                    </button>
                                    <div class="mx-1 h-5 w-px bg-zinc-300 dark:bg-zinc-600"></div>
                                    <button type="button" @click="setHeading(2)" :class="isActive('heading', {level: 2}) ? 'bg-zinc-200 dark:bg-zinc-700' : ''"
                                        class="rounded p-1.5 text-zinc-600 hover:bg-zinc-200 dark:text-zinc-400 dark:hover:bg-zinc-700" title="Heading">
                                        <span class="text-xs font-bold">H</span>
                                    </button>
                                    <button type="button" @click="toggleBulletList()" :class="isActive('bulletList') ? 'bg-zinc-200 dark:bg-zinc-700' : ''"
                                        class="rounded p-1.5 text-zinc-600 hover:bg-zinc-200 dark:text-zinc-400 dark:hover:bg-zinc-700" title="Bullet List">
                                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>
                                    </button>
                                    <button type="button" @click="toggleOrderedList()" :class="isActive('orderedList') ? 'bg-zinc-200 dark:bg-zinc-700' : ''"
                                        class="rounded p-1.5 text-zinc-600 hover:bg-zinc-200 dark:text-zinc-400 dark:hover:bg-zinc-700" title="Numbered List">
                                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 6h13M8 12h13M8 18h13M3.5 6.5h0M3.5 12.5h0M3.5 18.5h0"/></svg>
                                    </button>
                                    <button type="button" @click="setLink()"
                                        class="rounded p-1.5 text-zinc-600 hover:bg-zinc-200 dark:text-zinc-400 dark:hover:bg-zinc-700" title="Insert Link">
                                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
                                    </button>
                                    <div class="mx-1 h-5 w-px bg-zinc-300 dark:bg-zinc-600"></div>
                                    <button type="button" @click="undo()" class="rounded p-1.5 text-zinc-600 hover:bg-zinc-200 dark:text-zinc-400 dark:hover:bg-zinc-700" title="Undo">
                                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3"/></svg>
                                    </button>
                                    <button type="button" @click="redo()" class="rounded p-1.5 text-zinc-600 hover:bg-zinc-200 dark:text-zinc-400 dark:hover:bg-zinc-700" title="Redo">
                                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 15l6-6m0 0l-6-6m6 6H9a6 6 0 000 12h3"/></svg>
                                    </button>

                                    @if ($availableDynamicFields || $webhookAvailableFields)
                                        <div class="mx-1 h-5 w-px bg-zinc-300 dark:bg-zinc-600"></div>
                                        <div x-data="{ open: false }" class="relative">
                                            <button type="button" @click="open = !open"
                                                class="flex items-center gap-1 rounded px-2 py-1.5 text-xs font-medium text-purple-600 hover:bg-purple-50 dark:text-purple-400 dark:hover:bg-purple-900/30" title="Insert variable">
                                                <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.25 6.75L22.5 12l-5.25 5.25m-10.5 0L1.5 12l5.25-5.25m7.5-3l-4.5 16.5"/></svg>
                                                Variable
                                            </button>
                                            <div x-show="open" @click.outside="open = false" x-cloak
                                                class="absolute left-0 top-full z-50 mt-1 max-h-64 w-60 overflow-y-auto rounded-lg border border-zinc-200 bg-white shadow-lg dark:border-zinc-700 dark:bg-zinc-800">
                                                @if ($availableDynamicFields)
                                                    @php $grouped = collect($availableDynamicFields)->groupBy('prefix'); @endphp
                                                    @foreach ($grouped as $prefix => $fields)
                                                        <div class="px-3 pt-2 pb-1 text-[10px] font-semibold uppercase tracking-wider text-zinc-400">{{ Str::title(str_replace('_', ' ', $prefix)) }}</div>
                                                        @foreach ($fields as $field)
                                                            <button type="button"
                                                                @click="editor.chain().focus().insertContent('{{ chr(123).chr(123) . $field['path'] . chr(125).chr(125) }}').run(); open = false"
                                                                class="block w-full px-3 py-1.5 text-left text-xs text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                                                {{ $field['label'] }}
                                                            </button>
                                                        @endforeach
                                                    @endforeach
                                                @endif
                                                @if ($webhookAvailableFields)
                                                    <div class="px-3 pt-2 pb-1 text-[10px] font-semibold uppercase tracking-wider text-zinc-400">Payload</div>
                                                    @foreach ($webhookAvailableFields as $field)
                                                        <button type="button"
                                                            @click="editor.chain().focus().insertContent('{{ chr(123).chr(123) . 'webhook.' . $field['path'] . chr(125).chr(125) }}').run(); open = false"
                                                            class="block w-full px-3 py-1.5 text-left text-xs text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                                            {{ $field['path'] }}
                                                        </button>
                                                    @endforeach
                                                @endif
                                            </div>
                                        </div>
                                    @endif
                                </div>

                                {{-- Editor area --}}
                                <div x-ref="editor" class="min-h-[200px] rounded-b-lg"></div>
                            </div>
                        </div>

                        <div class="flex justify-end">
                            <button type="button" x-on:click="editing = false"
                                class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700">
                                <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                Done Editing
                            </button>
                        </div>
                    </div>
                </template>
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
