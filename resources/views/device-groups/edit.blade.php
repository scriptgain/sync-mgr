<x-layouts.app :title="'Edit ' . $group->name">
    <x-page-header :title="'Edit ' . $group->name" icon="users"
        :back="['href' => route('device-groups.show', $group), 'label' => $group->name]" />

    <x-card>
        <form method="POST" action="{{ route('device-groups.update', $group) }}" class="space-y-5">
            @csrf
            @method('PUT')
            @include('device-groups._fields', ['group' => $group])
            <div class="flex items-center justify-end gap-2 pt-1">
                <x-button variant="secondary" href="{{ route('device-groups.show', $group) }}">Cancel</x-button>
                <x-button type="submit" icon="check">Save Changes</x-button>
            </div>
        </form>
    </x-card>
</x-layouts.app>
