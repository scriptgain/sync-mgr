<x-layouts.app :title="'Edit ' . $folder->name">
    <x-page-header :title="'Edit ' . $folder->name" icon="folder"
        :back="['href' => route('folders.show', $folder), 'label' => $folder->name]" />

    <x-card>
        <form method="POST" action="{{ route('folders.update', $folder) }}" class="space-y-5">
            @csrf
            @method('PUT')
            @include('folders._fields', ['folder' => $folder])
            <div class="flex items-center justify-end gap-2 pt-1">
                <x-button variant="secondary" href="{{ route('folders.show', $folder) }}">Cancel</x-button>
                <x-button type="submit" icon="check">Save Changes</x-button>
            </div>
        </form>
    </x-card>
</x-layouts.app>
