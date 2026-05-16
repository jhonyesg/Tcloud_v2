<?php

namespace App\Http\Controllers;

use App\Models\ExternalSite;
use App\Models\User;
use Illuminate\Http\Request;

class ExternalSiteController extends Controller
{
    public function index(Request $request)
    {
        if ($request->wantsJson() || $request->ajax()) {
            return response()->json(ExternalSite::orderBy('name')->get());
        }
        return view('admin.external-sites');
    }

    public function store(Request $request)
    {
        \Log::info('ExternalSite store input', $request->all());

        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'name'    => 'required|string|max:120',
            'url'     => ['required', 'string', 'max:500'],
            'icon'    => 'required|string|max:60',
            'color'   => 'required|in:blue,green,red,purple,amber,cyan,rose,slate',
            'enabled' => 'boolean',
        ]);

        if ($validator->fails()) {
            \Log::warning('ExternalSite store validation failed', $validator->errors()->toArray());
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        // Forzar HTTPS
        if (!str_starts_with($data['url'], 'https://')) {
            return response()->json(['errors' => ['url' => ['La URL debe comenzar con https://']]], 422);
        }

        $data['enabled'] = $data['enabled'] ?? true;
        $site = ExternalSite::create($data);
        return response()->json($site, 201);
    }

    public function update(Request $request, ExternalSite $externalSite)
    {
        $data = $request->validate([
            'name'    => 'sometimes|required|string|max:120',
            'url'     => ['sometimes', 'required', 'url', 'starts_with:https://'],
            'icon'    => 'sometimes|required|string|max:60',
            'color'   => 'sometimes|required|in:blue,green,red,purple,amber,cyan,rose,slate',
            'enabled' => 'boolean',
        ]);

        $externalSite->update($data);
        return response()->json($externalSite);
    }

    public function destroy(ExternalSite $externalSite)
    {
        $externalSite->delete();
        return response()->json(['message' => 'Site eliminado']);
    }

    public function users(ExternalSite $externalSite)
    {
        $assigned = $externalSite->users()->get(['users.id', 'users.email', 'users.username']);
        return response()->json(['users' => $assigned]);
    }

    public function assignUser(Request $request, ExternalSite $externalSite)
    {
        $request->validate(['user_id' => 'required|exists:users,id']);

        $externalSite->users()->syncWithoutDetaching([
            $request->user_id => ['sort_order' => 0],
        ]);

        return response()->json(['message' => 'Usuario asignado']);
    }

    public function removeUser(ExternalSite $externalSite, User $user)
    {
        $externalSite->users()->detach($user->id);
        return response()->json(['message' => 'Asignación eliminada']);
    }
}
