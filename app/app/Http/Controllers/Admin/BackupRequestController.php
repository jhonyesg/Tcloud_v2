<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\MisArchivos\BackupRequestReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

/**
 * Control administrativo de las solicitudes de backup POR CLIENTE.
 *
 * El cliente ve su propio historial (`GET /files/backup-requests`, filtrado
 * por `user_id`). El administrador no tenía ninguna vista agregada: desde
 * `/admin/backups` solo declaraba el vínculo "storage de origen → carpeta
 * del árbol" y no veía quién pide qué. Este controller es esa vista.
 *
 * Es de SOLO LECTURA: no crea, no revoca y no borra solicitudes. La
 * revocación sigue siendo del cliente, desde Mis Archivos.
 *
 * Rutas (ver `routes/web.php`, grupo `auth` + `admin`):
 *   - `GET /admin/backups/control`           → reporte por cliente.
 *   - `GET /admin/backups/control/clientes`  → clientes con solicitudes.
 *   - `GET /admin/backups/control/exportar`  → el reporte en texto plano.
 */
class BackupRequestController extends Controller
{
    public function __construct(private readonly BackupRequestReportService $reportes)
    {
    }

    /**
     * Reporte de solicitudes por cliente.
     */
    public function control(Request $request): JsonResponse
    {
        if (! $this->esAdmin()) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        return response()->json($this->reportes->porCliente($this->filtros($request)));
    }

    /**
     * Lista de clientes con solicitudes, para el selector del reporte.
     */
    public function clientes(): JsonResponse
    {
        if (! $this->esAdmin()) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        return response()->json(['clientes' => $this->reportes->clientesConSolicitudes()]);
    }

    /**
     * Exportación del reporte en texto plano.
     *
     * Se descarga como adjunto para imprimir o archivar.
     */
    public function exportar(Request $request)
    {
        if (! $this->esAdmin()) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $reporte = $this->reportes->porCliente($this->filtros($request));

        return response($this->reportes->toTxt($reporte), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $this->reportes->nombreArchivo($reporte) . '"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Filtros del reporte, tomados de la petición.
     *
     * @return array{desde:?string,hasta:?string,estado:string,user_id:?int}
     */
    private function filtros(Request $request): array
    {
        return [
            'desde' => $request->query('desde'),
            'hasta' => $request->query('hasta'),
            'estado' => (string) $request->query('estado', BackupRequestReportService::FILTRO_TODAS),
            'user_id' => $request->query('user_id'),
        ];
    }

    /**
     * Guarda de administrador DENTRO del controller.
     *
     * Las rutas ya viven bajo `auth` + `admin`, pero el control se repite
     * aquí: si alguien mueve la ruta a otro grupo, el reporte de los
     * clientes no queda expuesto por accidente.
     */
    private function esAdmin(): bool
    {
        $userId = Session::get('user_id');

        if (! $userId) {
            return false;
        }

        $user = User::find($userId);

        return $user !== null && $user->isAdmin();
    }
}
