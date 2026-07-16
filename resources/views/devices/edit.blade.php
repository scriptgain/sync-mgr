<x-layouts.app :title="'Edit ' . $device->name">
    <x-page-header :title="'Edit ' . $device->name" icon="server"
        :back="['href' => route('devices.show', $device), 'label' => $device->name]" />

    <x-card>
        <form method="POST" action="{{ route('devices.update', $device) }}" class="space-y-5">
            @csrf
            @method('PUT')
            @include('devices._fields', ['device' => $device])
            <div class="flex items-center justify-end gap-2 pt-1">
                <x-button variant="secondary" href="{{ route('devices.show', $device) }}">Cancel</x-button>
                <x-button type="submit" icon="check">Save Changes</x-button>
            </div>
        </form>
    </x-card>
</x-layouts.app>
