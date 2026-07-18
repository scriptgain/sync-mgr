<x-layouts.app title="New Device Group">
    <x-page-header title="New Device Group" icon="users" subtitle="Group a set of endpoints to fan a one-way sync out to."
        :back="['href' => route('device-groups.index'), 'label' => 'Device Groups']" />

    <x-card>
        <form method="POST" action="{{ route('device-groups.store') }}" class="space-y-5">
            @csrf
            @include('device-groups._fields', ['group' => null])
            <div class="flex items-center justify-end gap-2 pt-1">
                <x-button variant="secondary" href="{{ route('device-groups.index') }}">Cancel</x-button>
                <x-button type="submit" icon="plus">Create Group</x-button>
            </div>
        </form>
    </x-card>
</x-layouts.app>
