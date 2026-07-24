<?php

namespace App\Http\Controllers;

use App\Models\Keyword;
use App\Models\KeywordMatch;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;

class MisAvisosController extends Controller
{
    public function index()
    {
        $userId = (int) Session::get('user_id');
        $user = User::with(['userKeywords', 'alertsInteligente'])->findOrFail($userId);

        $used = $user->userKeywords->count();
        $quota = $user->alertsInteligente?->keywords_quota ?? 0;
        $moduleEnabled = (bool) $user->alertsInteligente?->enabled && $quota > 0;

        $matches = $user->keywordMatches()
            ->with(['transcription.file', 'keyword'])
            ->orderByDesc('matched_at')
            ->paginate(25);

        return view('mis-avisos.index', [
            'user' => $user,
            'used' => $used,
            'quota' => $quota,
            'moduleEnabled' => $moduleEnabled,
            'matches' => $matches,
        ]);
    }

    public function storeKeyword(Request $request)
    {
        $userId = (int) Session::get('user_id');
        $request->validate(['text' => 'required|string|max:200']);

        $config = \App\Models\UserAlertsInteligente::where('user_id', $userId)->firstOrFail();
        $used = DB::table('user_keyword')->where('user_id', $userId)->count();

        if ($used >= $config->keywords_quota) {
            return response()->json([
                'error' => "Cupo alcanzado ({$used}/{$config->keywords_quota})",
            ], 422);
        }

        $normalized = Keyword::normalize($request->text);
        $keyword = Keyword::firstOrCreate(
            ['normalized' => $normalized],
            ['text' => trim($request->text)]
        );

        DB::table('user_keyword')->insertOrIgnore([
            'user_id' => $userId,
            'keyword_id' => $keyword->id,
            'created_at' => now(),
        ]);

        return response()->json([
            'keyword' => $keyword,
            'used' => $used + 1,
            'quota' => $config->keywords_quota,
        ], 201);
    }

    public function destroyKeyword(int $keywordId)
    {
        $userId = (int) Session::get('user_id');
        DB::table('user_keyword')
            ->where('user_id', $userId)
            ->where('keyword_id', $keywordId)
            ->delete();

        return response()->json(['message' => 'Eliminada']);
    }
}