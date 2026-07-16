<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesOwners;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Folder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FolderController extends Controller
{
    use ManagesOwners;

    public function index()
    {
        $user = auth()->user();
        $folders = Folder::visibleTo($user)->with('owner:id,name')->withCount('devices')->latest()->paginate(25)->withQueryString();

        $stats = [
            'total' => Folder::visibleTo($user)->count(),
            'syncing' => Folder::visibleTo($user)->where('status', 'syncing')->count(),
            'errors' => Folder::visibleTo($user)->where('status', 'error')->count(),
        ];

        return view('folders.index', compact('folders', 'stats'));
    }

    public function create()
    {
        return view('folders.create', [
            'owners' => $this->assignableOwners(),
            'devices' => Device::visibleTo(auth()->user())->orderBy('name')->get(),
            'selectedDevices' => [],
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['user_id'] = $this->resolveOwner($request);
        unset($data['owner_id'], $data['devices']);

        if (empty($data['folder_id'])) {
            $data['folder_id'] = Folder::generateFolderId();
        }

        $folder = Folder::create($data);
        $this->assignFromRequest($folder, $request);
        $this->syncDevices($request, $folder);
        AuditLog::record('created', "Folder \"{$folder->name}\" created", $folder);

        return redirect()->route('folders.show', $folder)->with('status', "Folder \"{$folder->name}\" created.");
    }

    public function show(Folder $folder)
    {
        $this->guard($folder);
        $folder->load(['owner:id,name', 'devices']);
        $events = $folder->syncEvents()->with('device:id,name')->latest('occurred_at')->latest('id')->limit(20)->get();

        return view('folders.show', compact('folder', 'events'));
    }

    public function edit(Folder $folder)
    {
        $this->guard($folder);

        return view('folders.edit', [
            'folder' => $folder,
            'owners' => $this->assignableOwners(),
            'devices' => Device::visibleTo(auth()->user())->orderBy('name')->get(),
            'selectedDevices' => $folder->devices()->pluck('devices.id')->all(),
        ]);
    }

    public function update(Request $request, Folder $folder)
    {
        $this->guard($folder);
        $data = $this->validated($request, $folder);
        if (auth()->user()->isAdmin()) {
            $data['user_id'] = $data['owner_id'] ?? null;
        }
        unset($data['owner_id'], $data['devices']);

        if (empty($data['folder_id'])) {
            $data['folder_id'] = $folder->folder_id ?: Folder::generateFolderId();
        }

        $folder->update($data);
        $this->syncDevices($request, $folder);
        $this->assignFromRequest($folder, $request);
        AuditLog::record('updated', "Folder \"{$folder->name}\" updated", $folder);

        return redirect()->route('folders.show', $folder)->with('status', 'Folder updated.');
    }

    public function destroy(Folder $folder)
    {
        $this->guard($folder);
        $name = $folder->name;
        $folder->delete();
        AuditLog::record('deleted', "Folder \"{$name}\" deleted");

        return redirect()->route('folders.index')->with('status', "Folder \"{$name}\" deleted.");
    }

    /** Share the folder with the selected devices, limited to devices the user may see. */
    private function syncDevices(Request $request, Folder $folder): void
    {
        $ids = collect($request->input('devices', []))->map(fn ($id) => (int) $id)->filter()->all();
        $allowed = Device::visibleTo(auth()->user())->whereIn('id', $ids)->pluck('id')->all();
        $folder->devices()->sync($allowed);
    }

    private function guard(Folder $folder): void
    {
        abort_unless($folder->isVisibleTo(auth()->user()), 403);
    }

    private function validated(Request $request, ?Folder $folder = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'folder_id' => ['nullable', 'string', 'max:120', Rule::unique('folders', 'folder_id')->ignore($folder?->id)],
            'path' => ['required', 'string', 'max:1024'],
            'type' => ['required', Rule::in(array_keys(Folder::TYPES))],
            'status' => ['required', Rule::in(array_keys(Folder::STATUSES))],
            'rescan_interval' => ['required', 'integer', 'min:0', 'max:31536000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'owner_id' => ['nullable', Rule::exists('users', 'id')],
            'devices' => ['sometimes', 'array'],
            'devices.*' => ['integer'],
        ]);
        $data['versioning'] = $request->boolean('versioning');

        return $data;
    }
}
