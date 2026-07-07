<?php

namespace App\Http\Controllers;

use App\Models\Correction;
use App\Models\User;
use App\Services\Ia\CorrectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

class CorreccionPropuestaController extends Controller
{
    public function store(Request $request, CorrectionService $service)
    {
        $userId = (int) Session::get('user_id');
        $request->validate([
            'wrong_text' => 'required|string|max:500',
            'correct_text' => 'required|string|max:500',
            'segment_id' => 'nullable|integer|exists:transcription_segments,id',
        ]);

        $user = User::findOrFail($userId);
        $correction = $service->propose(
            $user,
            $request->wrong_text,
            $request->correct_text,
            $request->segment_id ? (int) $request->segment_id : null
        );

        return response()->json($correction, 201);
    }

    public function mine()
    {
        $userId = (int) Session::get('user_id');
        $corrections = Correction::where('proposed_by', $userId)
            ->with('sourceSegment')
            ->latest()
            ->paginate(25);

        return view('mis-avisos.corrections-mine', ['corrections' => $corrections]);
    }
}