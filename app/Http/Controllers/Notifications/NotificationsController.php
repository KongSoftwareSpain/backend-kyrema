<?php

namespace App\Http\Controllers\Notifications;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Categoria;
use Illuminate\Validation\Rule;
use App\Notifications\ProductChangeRequest;
use Illuminate\Support\Facades\Notification;


class NotificationsController extends Controller
{


    public function notifyProductChange(Request $request)
    {
        // 1) Auth: debe ser un usuario logueado (comercial)
        $sender = $request->user(); // asume Sanctum/Session
        if (!$sender) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // 2) Validación
        $validated = $request->validate([
            'note'         => ['required', 'string', 'min:32'],
            'product'      => ['required', 'array'], // tiene que venir con tipo_producto_id, codigo_producto
            'product.codigo_producto'  => ['nullable', 'string', 'max:64'],
            'categoria_id' => ['required', 'string', Rule::exists('categorias', 'id')],
            'tipo_producto' => ['required', 'array'],
            'tipo_producto.id' => ['required', 'integer', Rule::exists('tipo_producto', 'id')],
            'tipo_producto.nombre' => ['required', 'string', 'max:64'],
        ],
        [
            'note.required' => 'La nota es obligatoria y debe tener al menos 32 caracteres.',
            'product.required' => 'Los datos del producto son obligatorios.',
            'product.tipo_producto_id.required' => 'El tipo de producto es obligatorio y debe existir.',
            'product.codigo_producto.required' => 'El código de producto es obligatorio.',
            'categoria_id.required' => 'La categoría es obligatoria y debe existir.',
        ]);

        // 3) Cargar categoría + responsable
        /** @var \App\Models\Categoria $categoria */
        $categoria = Categoria::query()
            ->with('comercialResponsable') // relación ver más abajo
            ->findOrFail($validated['categoria_id']);

        $responsable = $categoria->comercialResponsable; // notifiable
        if (!$responsable || empty($responsable->email)) {
            return response()->json([
                'message' => 'No responsible commercial with email configured for this category.',
            ], 422);
        }

        Notification::send(
            $responsable,
            new ProductChangeRequest(
                sender: $sender,
                categoria: $categoria,
                product: $validated['product'],
                productName: $validated['tipo_producto']['nombre'] ?? 'Producto',
                note: $validated['note']
            )
        );

        return response()->json([
            'ok' => true,
            'message' => 'Notificación enviada al comercial responsable de la categoría.',
        ], 200);
    }
}