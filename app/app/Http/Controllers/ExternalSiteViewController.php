<?php

namespace App\Http\Controllers;

use App\Models\ExternalSite;
use Illuminate\Support\Facades\Session;

class ExternalSiteViewController extends Controller
{
    public function show(ExternalSite $externalSite)
    {
        $userId = Session::get('user_id');

        if (!$externalSite->enabled) {
            abort(403, 'Este site está deshabilitado.');
        }

        $assigned = $externalSite->users()->where('users.id', $userId)->exists();
        if (!$assigned) {
            abort(403, 'No tienes acceso a este site.');
        }

        return view('sites.show', ['site' => $externalSite]);
    }
}
