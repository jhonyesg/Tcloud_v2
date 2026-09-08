<?php

namespace App\Http\Controllers\Ia;

use App\Http\Controllers\Controller;
use App\Models\KeywordCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AvisosCategoriesAdminController extends Controller
{
    public function index(Request $request)
    {
        $admin = KeywordCategory::admin()
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'color_hex']);

        $usagePerCategory = DB::table('user_keyword')
            ->select('category_id', DB::raw('COUNT(DISTINCT user_id) AS users'), DB::raw('COUNT(*) AS total_keywords'))
            ->whereIn('category_id', $admin->pluck('id'))
            ->groupBy('category_id')
            ->get()
            ->keyBy('category_id');

        return response()->json([
            'categories' => $admin->map(function (KeywordCategory $c) use ($usagePerCategory) {
                $row = $usagePerCategory[$c->id] ?? null;
                return [
                    'id' => (int) $c->id,
                    'name' => $c->name,
                    'slug' => $c->slug,
                    'color_hex' => $c->color_hex,
                    'users_with_keywords' => (int) ($row->users ?? 0),
                    'keywords_total' => (int) ($row->total_keywords ?? 0),
                ];
            }),
        ]);
    }

    public function showPage()
    {
        return view('ia.avisos-inteligentes.admin-categories');
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:80',
            'color_hex' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
        ]);

        $name = trim((string) $request->input('name'));
        $slug = KeywordCategory::normalizeSlug($name);
        if ($slug === '') {
            return response()->json(['error' => 'Nombre inválido'], 422);
        }

        $exists = KeywordCategory::admin()->where('slug', $slug)->exists();
        if ($exists) {
            return response()->json(['error' => 'Ya existe una categoría admin con ese nombre'], 422);
        }

        $color = $request->input('color_hex');
        if (!KeywordCategory::isValidHex($color)) {
            $color = '#4654a8';
        }

        $category = KeywordCategory::create([
            'owner_scope' => KeywordCategory::SCOPE_ADMIN,
            'owner_id'    => null,
            'name'        => $name,
            'slug'        => $slug,
            'color_hex'   => $color,
        ]);

        return response()->json(['category' => $category], 201);
    }

    public function update(Request $request, int $id)
    {
        $category = KeywordCategory::admin()->find($id);
        if (!$category) {
            return response()->json(['error' => 'Categoría admin no encontrada'], 404);
        }

        $request->validate([
            'name' => 'sometimes|required|string|max:80',
            'color_hex' => 'sometimes|nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
        ]);

        if ($request->has('name')) {
            $name = trim((string) $request->input('name'));
            $slug = KeywordCategory::normalizeSlug($name);
            if ($slug === '') {
                return response()->json(['error' => 'Nombre inválido'], 422);
            }
            $collision = KeywordCategory::admin()
                ->where('slug', $slug)
                ->where('id', '!=', $category->id)
                ->exists();
            if ($collision) {
                return response()->json(['error' => 'Ya existe otra categoría admin con ese nombre'], 422);
            }
            $category->name = $name;
            $category->slug = $slug;
        }
        if ($request->has('color_hex')) {
            $color = $request->input('color_hex');
            if ($color !== null && !KeywordCategory::isValidHex($color)) {
                return response()->json(['error' => 'Color inválido'], 422);
            }
            $category->color_hex = $color;
        }
        $category->save();

        $affected = DB::table('user_keyword')->where('category_id', $category->id)->count();

        return response()->json([
            'category' => $category,
            'keywords_assigned' => $affected,
        ]);
    }

    public function destroy(Request $request, int $id)
    {
        $category = KeywordCategory::admin()->find($id);
        if (!$category) {
            return response()->json(['error' => 'Categoría admin no encontrada'], 404);
        }

        $affected = DB::table('user_keyword')->where('category_id', $category->id)->count();

        if (!$request->boolean('confirm') && $affected > 0) {
            return response()->json([
                'error' => 'confirm_required',
                'affected_keywords' => $affected,
                'message' => "Esta categoría admin tiene {$affected} keyword(s) asignada(s). Reenvía con confirm=true para desasignar y eliminar.",
            ], 409);
        }

        DB::table('user_keyword')
            ->where('category_id', $category->id)
            ->update(['category_id' => null]);

        $category->delete();

        return response()->json([
            'ok' => true,
            'unassigned_keywords' => $affected,
        ]);
    }
}
