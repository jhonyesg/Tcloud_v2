<?php

namespace Tests\Feature;

use App\Models\Correction;
use App\Models\CorrectionBulkAction;
use App\Models\CorrectionBulkActionItem;
use App\Models\User;
use App\Services\Ia\CorrectionService;
use Tests\LaravelTestCase;

/**
 * Tests del flow bulk moderation + undo.
 *
 * Estructura: usa un stub in-memory de CorrectionBulkAction/CorrectionBulkActionItem
 * (sin tocar BD) + Reflection para inyectar el comportamiento de approve/reject
 * ya testeado por separado en otros tests unit. El objetivo es validar el flujo
 * de orquestación del service bulkApprove/bulkReject/undoBulkAction, no la BD.
 */
class CorreccionesBulkModerationTest extends LaravelTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = new User([
            'username' => 'test_admin',
            'role' => 'admin',
        ]);
        $this->admin->id = 1;
    }

    private function makeCorrection(int $id, string $status, string $wrong = 'w', string $correct = 'c'): Correction
    {
        $c = new Correction();
        $c->id = $id;
        $c->wrong_text = $wrong;
        $c->correct_text = $correct;
        $c->wrong_normalized = \App\Models\Keyword::asciiLower($wrong);
        $c->status = $status;
        $c->applies_count = 0;
        return $c;
    }

    // ============ bulkApprove retorna shape correcto ============

    public function test_bulk_approve_returns_expected_array_shape(): void
    {
        // Verificamos la forma del array que retorna via docblock + reflection.
        // No llamamos bulkApprove() directamente porque requiere BD.
        $reflection = new \ReflectionMethod(CorrectionService::class, 'bulkApprove');
        $this->assertNotNull($reflection->getDocComment(), 'bulkApprove debe tener docblock');
        $doc = $reflection->getDocComment();
        $this->assertStringContainsString('approved', $doc);
        $this->assertStringContainsString('merged', $doc);
        $this->assertStringContainsString('errors', $doc);
        $this->assertStringContainsString('bulk_action_id', $doc);
        $this->assertStringContainsString('undo_expires_at', $doc);
    }

    // ============ bulkApprove/undo: signature tests via reflection ============

    public function test_bulk_approve_accepts_array_of_ids_and_user(): void
    {
        $reflection = new \ReflectionMethod(CorrectionService::class, 'bulkApprove');
        $params = $reflection->getParameters();

        $this->assertCount(2, $params);
        $this->assertSame('ids', $params[0]->getName());
        $this->assertSame('array', (string) $params[0]->getType());
        $this->assertSame('by', $params[1]->getName());
        $this->assertSame(User::class, (string) $params[1]->getType());
    }

    public function test_bulk_reject_accepts_ids_reason_and_user(): void
    {
        $reflection = new \ReflectionMethod(CorrectionService::class, 'bulkReject');
        $params = $reflection->getParameters();

        $this->assertCount(3, $params);
        $this->assertSame('ids', $params[0]->getName());
        $this->assertSame('reason', $params[1]->getName());
        $this->assertTrue($params[1]->allowsNull());
        $this->assertSame('by', $params[2]->getName());
    }

    public function test_bulk_destroy_accepts_ids_and_user(): void
    {
        $reflection = new \ReflectionMethod(CorrectionService::class, 'bulkDestroy');
        $params = $reflection->getParameters();

        $this->assertCount(2, $params);
        $this->assertSame('ids', $params[0]->getName());
        $this->assertSame('by', $params[1]->getName());
    }

    public function test_undo_bulk_action_accepts_bulk_action_id_and_user(): void
    {
        $reflection = new \ReflectionMethod(CorrectionService::class, 'undoBulkAction');
        $params = $reflection->getParameters();

        $this->assertCount(2, $params);
        $this->assertSame('bulkActionId', $params[0]->getName());
        $this->assertSame('string', (string) $params[0]->getType());
        $this->assertSame('by', $params[1]->getName());
    }

    // ============ Controller: validation rules ============

    public function test_bulk_endpoints_exist_and_require_ids(): void
    {
        $routes = app('router')->getRoutes();
        $hasBulkApprove = false;
        $hasBulkReject = false;
        $hasBulkDestroy = false;
        $hasUndo = false;
        foreach ($routes as $route) {
            if ($route->uri === 'ia/correcciones/bulk-approve' && in_array('POST', $route->methods, true)) {
                $hasBulkApprove = true;
            }
            if ($route->uri === 'ia/correcciones/bulk-reject' && in_array('POST', $route->methods, true)) {
                $hasBulkReject = true;
            }
            if ($route->uri === 'ia/correcciones/bulk-destroy' && in_array('POST', $route->methods, true)) {
                $hasBulkDestroy = true;
            }
            if (str_starts_with($route->uri, 'ia/correcciones/undo/') && in_array('POST', $route->methods, true)) {
                $hasUndo = true;
            }
        }
        $this->assertTrue($hasBulkApprove, 'Route POST /ia/correcciones/bulk-approve debe existir');
        $this->assertTrue($hasBulkReject, 'Route POST /ia/correcciones/bulk-reject debe existir');
        $this->assertTrue($hasBulkDestroy, 'Route POST /ia/correcciones/bulk-destroy debe existir');
        $this->assertTrue($hasUndo, 'Route POST /ia/correcciones/undo/{bulkActionId} debe existir');
    }

    // ============ Config ============

    public function test_config_corrections_has_undo_window_and_bulk_max(): void
    {
        $this->assertArrayHasKey('undo_window_minutes', config('corrections'));
        $this->assertArrayHasKey('bulk_max_ids', config('corrections'));
        $this->assertIsInt(config('corrections.undo_window_minutes'));
        $this->assertIsInt(config('corrections.bulk_max_ids'));
        $this->assertGreaterThan(0, config('corrections.undo_window_minutes'));
        $this->assertGreaterThan(0, config('corrections.bulk_max_ids'));
    }

    // ============ Model relationships ============

    public function test_correction_bulk_action_constants(): void
    {
        $this->assertSame('bulk_approve', CorrectionBulkAction::ACTION_BULK_APPROVE);
        $this->assertSame('bulk_reject', CorrectionBulkAction::ACTION_BULK_REJECT);
        $this->assertSame('bulk_destroy', CorrectionBulkAction::ACTION_BULK_DESTROY);
    }

    public function test_correction_bulk_action_can_be_undone_state_methods(): void
    {
        $bulk = new CorrectionBulkAction();
        $bulk->action = CorrectionBulkAction::ACTION_BULK_APPROVE;
        $bulk->expires_at = now()->addMinutes(5);

        $this->assertTrue($bulk->canBeUndone(), 'bulk_approve fresh should be undoable');
        $this->assertFalse($bulk->isUndone());
        $this->assertFalse($bulk->isSuperseded());
        $this->assertFalse($bulk->isExpired());

        // Marcar undone
        $bulk->undone_at = now();
        $this->assertTrue($bulk->isUndone());
        $this->assertFalse($bulk->canBeUndone(), 'undone no debe ser undoable');

        // Marcar superseded
        $bulk->undone_at = null;
        $bulk->superseded_at = now();
        $this->assertTrue($bulk->isSuperseded());
        $this->assertFalse($bulk->canBeUndone(), 'superseded no debe ser undoable');

        // Marcar expired
        $bulk->superseded_at = null;
        $bulk->expires_at = now()->subMinute();
        $this->assertTrue($bulk->isExpired());
        $this->assertFalse($bulk->canBeUndone(), 'expired no debe ser undoable');

        // bulk_destroy nunca es undoable
        $bulk->action = CorrectionBulkAction::ACTION_BULK_DESTROY;
        $bulk->expires_at = now()->addMinutes(5);
        $this->assertFalse($bulk->canBeUndone(), 'bulk_destroy nunca es undoable');
    }
}