<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Folder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FolderController extends Controller
{
    public function index(Request $request)
    {
        return Folder::visibleTo($request->user())
            ->withCount('devices')
            ->latest()
            ->paginate(50);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['user_id'] = $this->resolveOwner($request);

        if (empty($data['folder_id'])) {
            $data['folder_id'] = Folder::generateFolderId();
        }

        return response()->json(Folder::create($data), 201);
    }

    public function show(Folder $folder)
    {
        abort_unless($folder->isVisibleTo(auth()->user()), 403);

        return $folder->load('devices');
    }

    public function update(Request $request, Folder $folder)
    {
        abort_unless($folder->isVisibleTo($request->user()), 403);

        $data = $this->validated($request, $folder, updating: true);

        if ($request->user()->isAdmin() && $request->filled('user_id')) {
            $data['user_id'] = $request->validate([
                'user_id' => ['integer', 'exists:users,id'],
            ])['user_id'];
        } else {
            unset($data['user_id']);
        }

        $folder->update($data);

        return $folder;
    }

    public function destroy(Folder $folder)
    {
        abort_unless($folder->isVisibleTo(auth()->user()), 403);

        $folder->delete();

        return response()->noContent();
    }

    /** Admins may assign an explicit owner; everyone else owns what they create. */
    private function resolveOwner(Request $request): int
    {
        if ($request->user()->isAdmin() && $request->filled('user_id')) {
            return (int) $request->validate([
                'user_id' => ['integer', 'exists:users,id'],
            ])['user_id'];
        }

        return $request->user()->id;
    }

    private function validated(Request $request, ?Folder $folder = null, bool $updating = false): array
    {
        $req = $updating ? 'sometimes' : 'required';

        $data = $request->validate([
            'name' => [$req, 'string', 'max:120'],
            'folder_id' => ['sometimes', 'nullable', 'string', 'max:120', Rule::unique('folders', 'folder_id')->ignore($folder?->id)],
            'path' => [$req, 'string', 'max:1024'],
            'type' => [$req, Rule::in(array_keys(Folder::TYPES))],
            'status' => ['sometimes', Rule::in(array_keys(Folder::STATUSES))],
            'rescan_interval' => ['sometimes', 'integer', 'min:0', 'max:31536000'],
            'versioning' => ['sometimes', 'boolean'],
            'file_count' => ['sometimes', 'integer', 'min:0'],
            'size_bytes' => ['sometimes', 'integer', 'min:0'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        if ($request->has('versioning')) {
            $data['versioning'] = $request->boolean('versioning');
        }

        return $data;
    }
}
