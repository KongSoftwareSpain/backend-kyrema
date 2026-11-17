<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\Comercial;
use App\Models\ConfiguracionEnvioCorreos;
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

    public function anularProducto(string $letrasIdentificacion, Request $request)
    {
        // Validar campos ANTES anidados dentro de desc_anulacion + sendEmail suelto
        $validated = $request->validate([
            'desc_anulacion.id'               => ['required', 'integer'],
            'desc_anulacion.sociedad_id'      => ['required', 'integer'],
            'desc_anulacion.sociedad_nombre'  => ['required', 'string'],
            'desc_anulacion.comercial_id'     => ['required', 'integer', Rule::exists('comercial', 'id')],
            'desc_anulacion.comercial_nombre' => ['required', 'string'],
            'desc_anulacion.causa'            => ['required', 'string', 'min:16'],
            'desc_anulacion.codigo_producto'  => ['nullable', 'string', 'max:255'],
            'desc_anulacion.socio_id'         => ['nullable', 'integer', Rule::exists('socios', 'id')],
            'sendEmail'                       => ['sometimes', 'boolean'],
        ]);

        // Payload ya validado
        $p = $validated['desc_anulacion'];
        $sendEmail = (bool)($validated['sendEmail'] ?? false);

        $tabla = strtolower($letrasIdentificacion);
        $id    = (int) $p['id'];

        $anulacionId = DB::transaction(function () use ($tabla, $id, $p, $letrasIdentificacion) {
            $row = DB::table($tabla)->where('id', $id)->first();
            if (!$row) abort(404, 'Producto no encontrado.');

            DB::table($tabla)->where('id', $id)->update(['anulado' => true]);

            return DB::table('anulaciones')->insertGetId([
                'fecha'                => Carbon::now()->format('Y-m-d\TH:i:s'),
                'sociedad_id'          => $p['sociedad_id'],
                'comercial_id'         => $p['comercial_id'],
                'sociedad_nombre'      => $p['sociedad_nombre'],
                'comercial_nombre'     => $p['comercial_nombre'],
                'causa'                => $p['causa'],
                'letrasIdentificacion' => $letrasIdentificacion,
                'producto_id'          => $id,
                'codigo_producto'      => $p['codigo_producto'] ?? ($row->codigo ?? $row->codigo_producto ?? null),
            ]);
        });

        // Notificación (si procede)
        // email_sent solo es true si el correo se ha puesto realmente en camino;
        // email_motivo explica por qué no, para que el front pueda avisar al usuario.
        $emailSent = false;
        $emailMotivo = null;
        if ($sendEmail) {
            $sender       = Comercial::find($p['comercial_id']);
            $tipoProducto = TipoProducto::where('letras_identificacion', $letrasIdentificacion)->first()
                ?? new TipoProducto(['nombre' => $letrasIdentificacion]);
            $product      = (array)(DB::table($tabla)->where('id', $id)->first() ?? []);
            $socio        = !empty($p['socio_id']) ? Socio::find($p['socio_id']) : null;
            // ?: (no ??) para que un email vacío ('') también cuente como ausente
            $socioEmail   = $socio?->email ?: ($product['email'] ?? null);

            $notificacion = fn () => new ProductCancellationNotice(
                sender: $sender,
                product: $product,
                tipoProducto: $tipoProducto,
                causa: $p['causa']
            );

            if (!$socioEmail) {
                $emailMotivo = 'sin_email';
            } else {
                // La notificación se encola igualmente (el canal 'database' guarda el
                // registro), pero con el interruptor maestro apagado el correo lo
                // cancela el listener: no se debe afirmar que se ha enviado.
                if ($socio && $socio->email) {
                    $socio->notify($notificacion());
                } else {
                    Notification::route('mail', $socioEmail)->notify($notificacion());
                }

                if (ConfiguracionEnvioCorreos::actual()->activo) {
                    $emailSent = true;
                } else {
                    $emailMotivo = 'interruptor_desactivado';
                }
            }
        }

        return response()->json([
            'ok'           => true,
            'message'      => 'Producto anulado con éxito',
            'anulacion_id' => $anulacionId,
            'email_sent'   => $emailSent,
            'email_motivo' => $emailMotivo,
        ]);
    }
}
