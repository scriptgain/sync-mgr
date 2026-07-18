<x-layouts.app title="New Device">
    <x-page-header title="New Device" icon="server" subtitle="Register a peer device in the sync cluster."
        :back="['href' => route('devices.index'), 'label' => 'Devices']" />

    <x-card>
        <form method="POST" action="{{ route('devices.store') }}" class="space-y-5" autocomplete="off">
            @csrf
            @include('devices._fields', ['device' => null])
            <div class="flex items-center justify-end gap-2 pt-1">
                <x-button variant="secondary" href="{{ route('devices.index') }}">Cancel</x-button>
                <x-button type="submit" icon="plus">Create Device</x-button>
            </div>
        </form>
    </x-card>
</x-layouts.app>
