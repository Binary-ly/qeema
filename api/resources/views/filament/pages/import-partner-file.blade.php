{{-- SPDX-License-Identifier: Apache-2.0 --}}
<x-filament-panels::page>
    {{ $this->form }}

    @if ($headers)
        <x-filament::section :heading="__('Map columns')">
            <p class="fi-section-header-description">
                Qeema guessed these from the file's own headers. Confirm them before importing —
                a misread column puts a price against the wrong item, which is much harder to
                notice later than to correct now.
            </p>

            <div class="grid gap-4 sm:grid-cols-2 mt-4">
                @foreach (array_merge(\App\Support\Ingestion\ColumnMapping::REQUIRED, \App\Support\Ingestion\ColumnMapping::OPTIONAL) as $field)
                    <label class="flex flex-col gap-1 text-sm">
                        <span class="font-medium">
                            {{ str($field)->headline() }}
                            @if (in_array($field, \App\Support\Ingestion\ColumnMapping::REQUIRED, true))
                                <span class="text-danger-600">*</span>
                            @endif
                        </span>
                        <select class="fi-input rounded-lg border-gray-300 dark:bg-gray-900"
                                wire:model="data.mapping.{{ $field }}">
                            <option value="">— not mapped —</option>
                            @foreach ($headers as $header)
                                <option value="{{ $header }}">{{ $header }}</option>
                            @endforeach
                        </select>
                    </label>
                @endforeach
            </div>
        </x-filament::section>

        @if ($sample)
            <x-filament::section :heading="__('First rows')" collapsible collapsed>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr>
                                @foreach ($headers as $header)
                                    <th class="px-2 py-1 text-start font-medium">{{ $header }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($sample as $row)
                                <tr>
                                    @foreach ($headers as $header)
                                        <td class="px-2 py-1">{{ $row[$header] ?? '' }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif

        <div class="flex justify-end">
            <x-filament::button wire:click="import" wire:loading.attr="disabled">
                Import
            </x-filament::button>
        </div>
    @endif
</x-filament-panels::page>
