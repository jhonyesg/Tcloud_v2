<?php

namespace App\Http\Controllers\Ia;

use App\Http\Controllers\Controller;
use App\Models\Correction;
use App\Services\Ia\CorrectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

class CorreccionesController extends Controller
{
    public function index()
    {
        $approved = Correction::approved()
            ->with('proposedBy', 'approvedBy')
            ->orderByDesc('applies_count')
            ->get();

        $pendingCount = Correction::pending()->count();

        return view('ia.correcciones.index', [
            'approved' => $approved,
            'pendingCount' => $pendingCount,
        ]);
    }

    public function pending()
    {
        $pending = Correction::pending()
            ->with('proposedBy', 'sourceSegment')
            ->latest()
            ->get();

        return response()->json($pending);
    }

    public function approve(int $id, CorrectionService $service)
    {
        $correction = Correction::findOrFail($id);
        $admin = $this->adminUser();
        $updated = $service->approve($correction, $admin);

        return response()->json($updated->load('proposedBy', 'approvedBy'));
    }

    public function reject(Request $request, int $id, CorrectionService $service)
    {
        $request->validate(['rejected_reason' => 'nullable|string|max:1000']);

        $correction = Correction::findOrFail($id);
        $admin = $this->adminUser();
        $updated = $service->reject($correction, $admin, $request->input('rejected_reason'));

        return response()->json($updated->load('proposedBy'));
    }

    public function store(Request $request, CorrectionService $service)
    {
        $request->validate([
            'wrong' => 'required|string|max:500',
            'correct' => 'required|string|max:500',
        ]);

        $admin = $this->adminUser();
        $correction = $service->upsertApproved($request->wrong, $request->correct, $admin);

        return response()->json($correction->load('proposedBy', 'approvedBy'), 201);
    }

    public function destroy(int $id)
    {
        Correction::findOrFail($id)->delete();
        return response()->json(['message' => 'Corrección eliminada']);
    }

    public function applyRetroactive(Request $request, CorrectionService $service)
    {
        $dryRun = (bool) $request->input('dry_run', false);
        $chunk = (int) $request->input('chunk', 500);
        $start = microtime(true);

        $updated = $service->applyRetroactively(null, $chunk, $dryRun);
        $elapsed = round(microtime(true) - $start, 2);

        return response()->json([
            'updated' => $updated,
            'elapsed_seconds' => $elapsed,
            'dry_run' => $dryRun,
        ]);
    }

    public function previewRetroactive(CorrectionService $service)
    {
        return response()->json(['would_update' => $service->previewRetroactive()]);
    }

    private function adminUser()
    {
        $id = (int) Session::get('user_id');
        return \App\Models\User::findOrFail($id);
    }
}