@component('mail::message')
# Anulación de tu seguro — {{ $tipoProducto->nombre ?? '' }}

Hola @if(isset($socio->nombre)) {{ $socio->nombre }} @else {{ $socio->name ?? '' }} @endif,

El comercial **{{ $senderName }}** ha tramitado la **anulación** de tu seguro.

@component('mail::panel')
**Tipo de producto:** {{ $tipoProducto->nombre ?? '—' }}

**Producto:** {{ $productName }}  
**Código:** {{ $productCode }}
@endcomponent

**Causa de la anulación**  
{{ $causa }}

@if(isset($product) && is_array($product))
@php
    // 1) Define aquí SOLO los campos que pueden mostrarse al cliente
    //    (mantén esta lista corta; mejor pecar de ocultar de más)
    $allowed = ['fecha_de_emisión', 'sociedad', 'nombre', 'apellido_1', 'apellido_2', 'email'];
    // 2) Normaliza y filtra el array de producto
    $productArr = is_array($product) ? $product : (array) $product;

    $extras = collect($productArr)
        ->only($allowed) // <-- allowlist
        ->reject(function ($v) {
            // fuera null, '', arrays/objetos vacíos
            if (is_null($v)) return true;
            if (is_string($v) && trim($v) === '') return true;
            if (is_array($v) && empty($v)) return true;
            return false;
        })
        ->map(function ($v) {
            // render seguro
            return is_scalar($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE);
        });
@endphp

@if($extras->isNotEmpty())
**Detalles adicionales del producto:**

@component('mail::table')
| Campo | Valor |
|:----- |:----- |
@foreach($extras as $k => $v)
| {{ \Illuminate\Support\Str::headline($k) }} | {{ $v }} |
@endforeach
@endcomponent
@endif
@endif

Si necesitas más información o crees que se trata de un error, responde a este correo.

Gracias,  
{{ config('app.name') }}
@endcomponent
