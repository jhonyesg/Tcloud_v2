<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Symfony\Component\HttpFoundation\Response;

class Authenticate
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!Session::has('user_id')) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'No authenticated'], 401);
            }
            return redirect('/login');
        }

        $userId = Session::get('user_id');
        $request->merge(['auth_user_id' => $userId]);
        $request->setUserResolver(function () use ($userId) {
            return \App\Models\User::find($userId);
        });

        return $next($request);
    }
}
