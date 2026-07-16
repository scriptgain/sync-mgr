<x-layouts.app title="New Folder">
    <x-page-header title="New Folder" icon="folder" subtitle="Add a folder to sync across your devices."
        :back="['href' => route('folders.index'), 'label' => 'Folders']" />

    <x-card>
        <form method="POST" action="{{ route('folders.store') }}" class="space-y-5">
            @csrf
            @include('folders._fields', ['folder' => null])
            <div class="flex items-center justify-end gap-2 pt-1">
                <x-button variant="secondary" href="{{ route('folders.index') }}">Cancel</x-button>
                <x-button type="submit" icon="plus">Create Folder</x-button>
            </div>
        </form>
    </x-card>
</x-layouts.app>
