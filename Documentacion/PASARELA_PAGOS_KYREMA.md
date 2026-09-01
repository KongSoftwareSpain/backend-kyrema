# Pasarela de pago Redsys en kyrema.org

**Contexto:** canamaseguros.com (Angular + Laravel `backend-kyrema`) es la plataforma principal, pero los pagos deben procesarse en **kyrema.org**, que es el dominio asociado al TPV virtual de Redsys y que ya opera pagos en producción.

---

## 1. Hallazgos de la investigación

### 1.1. kyrema.org ya procesa pagos Redsys en producción

La carpeta `repos/kyrema.org` es la web legacy en **CodeIgniter** y contiene una integración Redsys por **redirección clásica** completamente operativa:

| Pieza | Dónde | Qué hace |
|---|---|---|
| Generación del formulario | `application/controllers/Pagos.php` → `generatePaymentButtonData()` (también en `panel-gestion/Pagos.php`) | Recibe `id_item`, `price`, `ipn_url`, `thankyou_url`, `cancel_url` por POST y devuelve JSON con `dsv` (Ds_SignatureVersion), `dsp` (Ds_MerchantParameters) y `dsig` (Ds_Signature) |
| Librería de firma | `application/libraries/Redsys.php` | Clase oficial Redsys (HMAC_SHA256_V1) |
| Configuración TPV | `application/config/config.php` líneas 22-24 | `tpv_url = https://sis.redsys.es/sis/realizarPago` (**producción**), `tpv_merchant_code = 336852983`, `tpv_password` (clave secreta del comercio) |
| Envío a Redsys | JS de las vistas (p.ej. `assets/panel-gestion/js/application/seguros.js` ~línea 1428) | Rellena un `<form>` oculto con los 3 campos `Ds_*` y lo envía **a página completa** (top-level, sin iframe) a Redsys |
| Notificaciones (IPN) | `Pagos.php` → métodos `ipn_*` (seguros combinados, cacerías, naturaleza, aepes, anexos…) | Redsys llama servidor-a-servidor; se decodifica `Ds_MerchantParameters`, se comprueba `Ds_AuthorisationCode` y se activa el seguro + emails |
| Vuelta del usuario | `Pagos::gracias()` (URLOK) y `Pagos::pago_cancelado()` (URLKO) | Páginas de resultado en kyrema.org |

**Conclusión clave:** el comercio Redsys (código 336852983) está dado de alta para kyrema.org, usa redirección a página completa (el patrón que Redsys recomienda) y toda la lógica de firma/IPN ya existe ahí.

### 1.2. Lo que tiene el backend nuevo (backend-kyrema, Laravel)

- `RedsysInsiteController::start()` — crea el `Pago` + `PaymentGatewayLink` y devuelve el formulario firmado (paquete Creagia) que Angular hoy mete en un **iframe**.
- `RedsysInsiteController::notify()` — notificación servidor-a-servidor que marca el `Pago` como pagado/fallido. **Es la fuente de verdad y se mantiene.**
- `RedsysBridgeController` — páginas OK/KO que hacen `postMessage` al padre del iframe.
- Config en `config/redsys.php` con credenciales por `.env` (`REDSYS_MERCHANT_CODE`, `REDSYS_KEY`, `REDSYS_ENVIRONMENT`).

### 1.3. Por qué el pago no puede quedarse en canamaseguros.com

- Redsys asocia cada comercio a un dominio y mantiene una **lista blanca de dominios autorizados** (visible en el panel de administración del TPV, apartado "Datos de configuración"; solo Redsys puede modificarla). Si el formulario se muestra desde un dominio no autorizado —especialmente en iframes— lo bloquea vía Content-Security-Policy.
- Con **3D Secure**, el reto de autenticación del banco emisor suele romperse dentro de iframes (X-Frame-Options), por lo que la redirección debe ser a página completa.
- Aunque el iframe apunte a kyrema.org, **el dominio que Redsys "ve" como ventana superior seguiría siendo canamaseguros.com**. No basta con enmarcar kyrema.org.

## 2. Flujo para dummies 🐣

Imagina que canamaseguros.com es una tienda y kyrema.org es la única caja registradora autorizada por el banco. No puedes cobrar en la tienda: tienes que acompañar al cliente a la caja y luego devolverlo a la tienda.

1. **El cliente pulsa "Pagar" en canamaseguros.com.** Todavía no ha pasado nada con el banco.
2. **canamaseguros le dice a su servidor (Laravel): "prepárame un cobro de 50 €".** Laravel apunta el pago en su base de datos como *pendiente* y genera un **ticket de un solo uso** (un token, una cadena aleatoria que caduca en unos minutos).
3. **Laravel responde: "mándalo a `https://kyrema.org/pago/abc123`"** (abc123 es el ticket).
4. **El navegador del cliente viaja a esa dirección de kyrema.org.** Sale de canamaseguros.com por completo — no hay iframe ni ventanitas, cambia la URL de la barra del navegador.
5. **La página de kyrema.org canjea el ticket:** le pregunta a Laravel "¿qué pago es abc123?", recibe el formulario ya firmado, y lo envía automáticamente a Redsys. El cliente ni lo ve: en medio segundo está en la pantalla de Redsys metiendo su tarjeta.
6. **El cliente paga en Redsys.** Como la página se abrió desde kyrema.org (el dominio que el banco tiene autorizado), todo funciona: la pantalla de la tarjeta, el SMS/confirmación del banco (3D Secure), etc.
7. **Redsys avisa dos veces del resultado, por dos caminos distintos:**
   - **Al servidor de Laravel directamente** (el IPN o "notificación"): "el pago 12345 está OK". Laravel marca el pago como *pagado*. Este aviso es el que vale — llega aunque el cliente cierre el navegador.
   - **Al navegador del cliente:** lo devuelve a una página de kyrema.org (la URLOK o URLKO).
8. **Esa página de kyrema.org no muestra nada:** simplemente reenvía al cliente de vuelta a canamaseguros.com, a una página tipo `/pago/resultado?order=12345&status=ok`.
9. **canamaseguros.com muestra "¡Pago completado!"** — pero antes le pregunta a Laravel "¿de verdad está pagado el 12345?", porque el `status=ok` de la URL lo podría escribir cualquiera a mano. La verdad siempre está en la base de datos, que actualizó el paso 7.

En resumen: **canamaseguros vende, kyrema.org cobra, Laravel lleva las cuentas.** El cliente hace un viaje de ida y vuelta entre dominios y ni se entera.

## 3. Arquitectura recomendada

**Hosted payment page en kyrema.org + lógica de negocio en Laravel.** El usuario sale de canamaseguros.com hacia una página mínima en kyrema.org que auto-envía el formulario a Redsys, y vuelve a canamaseguros.com al terminar.

### Flujo

```
canamaseguros.com (Angular)                kyrema.org                    Redsys
        │                                       │                          │
1. click "Pagar" ──► POST /api/.../start        │                          │
   (Laravel crea Pago + token único,            │                          │
   devuelve https://kyrema.org/pago/{token})    │                          │
        │                                       │                          │
2. window.location.href = url ────────────────► │                          │
        │                          3. página puente: obtiene los campos    │
        │                             Ds_* (vía API Laravel por token)     │
        │                             y auto-envía el <form> ────────────► │
        │                                       │            4. usuario paga (3DS ok,
        │                                       │               dominio autorizado)
        │                                       │ ◄──── 5. URLOK/URLKO en kyrema.org
        │                                       │                          │
        │ ◄──── 6. redirect a return_url        │       5b. IPN (notify) ─► Laravel
        │       ?order=XXX&status=ok            │           actualiza Pago (fuente de verdad)
        │                                       │
7. página de resultado consulta a la API el estado real del Pago
```

### Quién firma: dos opciones

**Opción A (recomendada): firma Laravel.** Se copian las credenciales del TPV real (merchant code + clave, hoy en `config.php` de kyrema.org) al `.env` de Laravel. `start()` ya genera los tres campos `Ds_*` con el paquete Creagia. La página de kyrema.org es "tonta": recibe el token, pide a la API de Laravel los campos firmados y hace el auto-submit. Todo el estado (Pago, notify) queda en Laravel.

- Ventaja: un solo sitio con lógica de pago; kyrema.org solo aporta el dominio.
- La página puente puede ser un controlador CodeIgniter nuevo o incluso un PHP suelto de ~20 líneas.

**Opción B: firma kyrema.org.** Laravel manda importe/orden/URLs a `generatePaymentButtonData` (que ya existe) y la clave no sale de kyrema.org. A cambio hay que coordinar dos sistemas para el mismo pago (¿quién recibe el IPN?, ¿cómo se entera Laravel?). Más acoplamiento, solo tiene sentido si no se quiere replicar la clave.

### Parámetros Redsys en el nuevo flujo

- `DS_MERCHANT_MERCHANTURL` (IPN) → endpoint `notify` de Laravel (`/api/payments/redsys/notify`). Es servidor-a-servidor, no pasa por el navegador.
- `DS_MERCHANT_URLOK` / `DS_MERCHANT_URLKO` → páginas en kyrema.org que redirigen a la `return_url` de canamaseguros.com con `?order=XXX&status=ok|ko`.
- `DS_MERCHANT_ORDER` → el que ya genera `start()` (guardado en `PaymentGatewayLink.gateway_order_ref`).

**Nunca fiarse del `status` de la URL de vuelta**: la confirmación real es el IPN. La página de resultado en canamaseguros.com debe consultar el estado del `Pago` a la API.

### Alternativa de UX: popup con `window.open`

Abrir `https://kyrema.org/pago/{token}` en ventana nueva y comunicar el resultado con `postMessage` cross-domain (el bridge actual ya hace casi esto; habría que fijar `targetOrigin` al origen de canamaseguros.com en vez de `"*"`). Los bloqueadores de popups y el móvil lo hacen menos fiable. **Recomendación: redirección completa**, como hace la propia web de kyrema.org hoy.

## 4. Cambios necesarios

### backend-kyrema (Laravel)

1. `start()`: aceptar `return_url`, generar **token de un solo uso con caducidad** (columna nueva en `payment_gateway_links` o tabla aparte) y devolver `https://kyrema.org/pago/{token}` en lugar del payload del iframe.
2. Endpoint interno `GET /api/payments/redsys/form/{token}`: devuelve los campos `Ds_*` si el token es válido y no está usado/caducado.
3. Bridge OK/KO: redirigir a la `return_url` guardada (con `order` y `status`) en vez de `postMessage`.
4. `.env` de producción: `REDSYS_ENVIRONMENT=production`, `REDSYS_MERCHANT_CODE` y `REDSYS_KEY` del TPV real (opción A).

### kyrema.org (CodeIgniter)

1. Página puente `GET /pago/{token}`: llama a la API de Laravel, pinta el `<form action="https://sis.redsys.es/sis/realizarPago">` con los 3 campos y lo auto-envía (mismo patrón que ya usa su JS).
2. Páginas de aterrizaje URLOK/URLKO (o reutilizar la puente con un parámetro) que redirigen a la `return_url`.

### frontend canamaseguros (Angular)

1. Sustituir el iframe por `window.location.href` a la URL devuelta por `start`.
2. Página `/pago/resultado` que consulta el estado real del pago a la API.

### Gestión / banco

- Confirmar que kyrema.org está en los **dominios autorizados** del comercio (ya debería, al operar en producción).
- Si el backend Laravel vive en otro dominio para el IPN, no hace falta autorizarlo: el IPN no se sirve al navegador. Solo el dominio donde se **muestra** el pago importa.

## 5. Nota de seguridad

Las credenciales del TPV de producción (código de comercio y **clave secreta HMAC**) están hardcodeadas y commiteadas en `application/config/config.php` del repo kyrema.org. Cualquiera con acceso al repo puede firmar operaciones. Conviene moverlas a variables de entorno / fichero no versionado y valorar pedir regeneración de la clave a Redsys.

## 6. Fuentes

- [Redsys — Documentación redirección](https://pagosonline.redsys.es/desarrolladores-inicio/documentacion-tipos-de-integracion/desarrolladores-redireccion/)
- [Redsys — Tipos de integración](https://pagosonline.redsys.es/desarrolladores-inicio/)
- [Redsys — inSite](https://pagosonline.redsys.es/desarrolladores-inicio/documentacion-tipos-de-integracion/desarrolladores-insite/)
- [Enrique J. Ros — Redsys y lista de dominios autorizados](https://www.enriquejros.com/redsys-integrado-woocommerce/)
- Código fuente: `repos/kyrema.org/application/controllers/Pagos.php`, `application/config/config.php`, `assets/panel-gestion/js/application/seguros.js`; `backend-kyrema/app/Http/Controllers/Payments/*`, `config/redsys.php`
