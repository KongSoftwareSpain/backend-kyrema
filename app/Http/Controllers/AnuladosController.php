<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\Comercial;
use App\Models\TipoProducto;
use App\Models\Socio;
use App\Notifications\ProductCancellationNotice;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;

class AnuladosController extends Controller
{
    public function getAnulados($letrasIdentificacion, Request $request)
    {
        // Obtener las sociedades desde la consulta
        $sociedades = $request->query('sociedades');

        if ($sociedades) {
            $sociedades = explode(',', $sociedades);
        } else {
            $sociedades = [];
        }

        // Convertir letras de identificación a nombre de tabla (en este caso, anulaciones)
        $nombreTabla = 'anulaciones';

        // Construir la consulta para obtener los datos anulados
        $query = DB::table($nombreTabla)
            ->where('letrasIdentificacion', $letrasIdentificacion)
            ->select('id', 'fecha', 'sociedad_id', 'comercial_id', 'sociedad_nombre', 'comercial_nombre', 'causa', 'letrasIdentificacion', 'producto_id', 'codigo_producto', 'created_at', 'updated_at');

        // Filtrar por sociedades si están presentes
        if (!empty($sociedades)) {
            $query->whereIn('sociedad_id', $sociedades);
        }

        // Obtener los resultados
        $anulados = $query->get();

        return response()->json($anulados);
    }

    public function anularProducto($letrasIdentificacion, Request $request)
    {
        // 1) Validación
        $validated = $request->validate([
            'id'               => ['required', 'integer'],
            'sociedad_id'      => ['required', 'integer'],
            'sociedad_nombre'  => ['required', 'string'],
            'comercial_id'     => ['required', 'integer', Rule::exists('comercial', 'id')],
            'comercial_nombre' => ['required', 'string'],
            'causa'            => ['required', 'string', 'min:16'],
            'codigo_producto'  => ['nullable', 'string', 'max:255'],
            // opcional si ya la traes desde el front:
            'socio_id'         => ['nullable', 'integer', Rule::exists('socios', 'id')],
            'sendEmail'        => ['sometimes', 'boolean'],
        ]);

        $tabla = strtolower($letrasIdentificacion);
        $id    = (int) $validated['id'];

        // 2) Transacción: anular + registrar anulación
        $anulacionId = DB::transaction(function () use ($tabla, $id, $validated, $letrasIdentificacion) {

            // 2.1) Verificar que el producto existe (y de paso obtener datos para el mail)
            $productoRow = DB::table($tabla)->where('id', $id)->first();
            if (!$productoRow) {
                abort(404, 'Producto no encontrado.');
            }

            // 2.2) Marcar como anulado
            DB::table($tabla)->where('id', $id)->update(['anulado' => true]);

            // 2.3) Insertar en tabla de anulaciones
            return DB::table('anulaciones')->insertGetId([
                'fecha'               => Carbon::now()->format('Y-m-d\TH:i:s'),
                'sociedad_id'         => $validated['sociedad_id'],
                'comercial_id'        => $validated['comercial_id'],
                'sociedad_nombre'     => $validated['sociedad_nombre'],
                'comercial_nombre'    => $validated['comercial_nombre'],
                'causa'               => $validated['causa'],
                'letrasIdentificacion' => $letrasIdentificacion,
                'producto_id'         => $id,
                'codigo_producto'     => $validated['codigo_producto'] ?? ($productoRow->codigo ?? $productoRow->codigo_producto ?? null),
            ]);
        });

        // 3) Resolver modelos y datos para la notificación
        $sender = Comercial::find($validated['comercial_id']); // Comercial que anula (requerido por tu Notification)
        if (!$sender) {
            // fallback defensivo (no debería ocurrir por la validación)
            return response()->json(['message' => 'Comercial no encontrado.'], 422);
        }

        // TipoProducto por letras_identificacion (ajusta si tu columna se llama distinto)
        $tipoProducto = TipoProducto::where('letras_identificacion', $letrasIdentificacion)->first();
        if (!$tipoProducto) {
            // Creamos un "contenedor" mínimo para no romper tipos
            $tipoProducto = new TipoProducto(['nombre' => $letrasIdentificacion]);
        }

        // Producto crudo para payload de notificación
        $productPayload = (array) (DB::table($tabla)->where('id', $id)->first() ?? []);

        // 4) Localizar destinatario (Socio) o su email
        $socio = null;
        if (!empty($validated['socio_id'])) {
            $socio = Socio::find($validated['socio_id']);
        }


        if ($validated['sendEmail'] ?? false) {
            // Si sin socio, intenta con email suelto en la fila o request
            $socioEmail = null;
            if (!$socio) {
                $socioEmail = $productPayload['email'] ?? null;
            }

            // 5) Enviar notificación
            $emailSent = false;
            if ($socio) {
                // Canal mail + database (Socio debe usar Notifiable)
                $socio->notify(
                    new ProductCancellationNotice(
                        sender: $sender,
                        product: $productPayload,
                        tipoProducto: $tipoProducto,
                        causa: $validated['causa']
                    )
                );
                $emailSent = true;
            } elseif ($socioEmail) {
                // Solo mail (con AnonymousNotifiable; no guarda en database)
                Notification::route('mail', $socioEmail)->notify(
                    new ProductCancellationNotice(
                        sender: $sender,
                        product: $productPayload,
                        tipoProducto: $tipoProducto,
                        causa: $validated['causa']
                    )
                );
                $emailSent = true;
            }
        }

        return response()->json([
            'ok'               => true,
            'message'          => 'Producto anulado con éxito',
            'anulacion_id'     => $anulacionId,
            'email_sent'       => $emailSent ?? false,
            'notified_via'     => $socio ? 'model+db' : ($socioEmail ? 'email-only' : 'none'),
        ], 200);
    }
}
