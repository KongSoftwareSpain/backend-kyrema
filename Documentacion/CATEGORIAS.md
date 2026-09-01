# Categorías — Cómo funcionan

Documentación de la entidad **Categoría** en Kyrema: qué es, cómo se modela en
el backend (Laravel) y cómo se consume desde el frontend (Angular).

> Resumen en una frase: una **categoría** es la "marca / vertical de negocio"
> (p. ej. *Caza*, *Cánama Seguros*) que agrupa **tipos de producto** y sirve de
> **puerta de entrada de los socios** a la aplicación a través de una URL de
> login propia.

---

## 1. Qué es una categoría y para qué sirve

Una categoría cumple tres papeles dentro del sistema:

1. **Agrupador de productos.** Cada `tipo_producto` apunta a una categoría
   mediante `categoria_id`. La navegación de un socio se construye filtrando los
   tipos de producto por su categoría.
2. **Puerta de acceso de socios (clientes).** Cada categoría tiene una URL de
   login pública del tipo `/login/{categoria_id}`. Quien entra por esa URL se
   autentica como **socio** (cliente), no como comercial.
3. **Asignación de comercial responsable.** Una categoría tiene un
   `comercial_responsable_id`. Es el comercial al que se atribuyen las
   contrataciones de un socio que **aún no tiene comercial asignado**.

En la práctica el sistema hoy gira casi todo en torno a una única categoría
(`id = 3`, "Caza"), tal como recoge la auditoría del modelo de datos
([AUDITORIA_MAPEO.md](AUDITORIA_MAPEO.md), §1).

---

## 2. Modelo de datos

### Tabla `categorias`

Esquema **efectivo** (el que usa el código en producción):

| Columna                    | Tipo              | Notas                                               |
|----------------------------|-------------------|-----------------------------------------------------|
| `id`                       | bigint (PK)       | Autoincremental.                                    |
| `nombre`                   | string            | Nombre visible (p. ej. "Caza", "Kyrema").           |
| `logo`                     | string (path)     | Ruta del logo en `storage/app/public/categorias/`.  |
| `comercial_responsable_id` | bigint, nullable  | FK lógica → `comercial.id`. Sin FK física en BD.    |

> ⚠️ **Aviso de desincronización de migraciones.** Las migraciones del repo **no
> reflejan el esquema real**:
> - [`2025_01_09_100111_create_categorias_table.php`](database/migrations/2025_01_09_100111_create_categorias_table.php)
>   crea `id`, `id_comercial`, `id_socio` — columnas que el modelo **no usa**.
> - [`2025_04_29_105356_add_comercial_responsable_id_to_categorias_table.php`](database/migrations/2025_04_29_105356_add_comercial_responsable_id_to_categorias_table.php)
>   añade `comercial_responsable_id` `after('logo')`, dando por hecho que ya
>   existen `nombre` y `logo`, que **ninguna migración del repo crea**.
>
> Es decir, las columnas `nombre` y `logo` se añadieron a la base de datos fuera
> del control de migraciones. Si reconstruyes la BD desde cero con
> `migrate:fresh`, el esquema resultante **no coincidirá** con el que espera el
> modelo `Categoria`. Tenlo en cuenta antes de tocar migraciones.

### Modelo Eloquent

[`app/Models/Categoria.php`](app/Models/Categoria.php)

```php
class Categoria extends Model
{
    protected $table = 'categorias';
    protected $fillable = ['nombre', 'comercial_responsable_id', 'logo'];
    public $timestamps = false;   // la tabla NO tiene created_at / updated_at

    public function comercialResponsable()
    {
        return $this->belongsTo(Comercial::class, 'comercial_responsable_id');
    }
}
```

Puntos clave:
- **Sin timestamps** (`public $timestamps = false`).
- Única relación declarada: `comercialResponsable()` (belongsTo `Comercial`).
- La relación con `tipo_producto` y con `socios` **no** está declarada en el
  modelo; se resuelve con consultas directas por `categoria_id` allí donde hace
  falta.

### Relaciones (vista de conjunto)

```
                 categorias
                  │  │  │
   comercial_responsable_id  │  │
                  ▼  │  │
            comercial │  │           (responsable de contrataciones sin comercial)
                      │  │
        categoria_id  │  │  categoria_id
                      ▼  ▼
              tipo_producto   socios
              (productos de   (clientes que
               la categoría)   entran por /login/{id})
```

- **`categorias.comercial_responsable_id` → `comercial.id`**: comercial por
  defecto de la categoría.
- **`tipo_producto.categoria_id` → `categorias.id`**: qué productos pertenecen a
  la categoría.
- **`socios.categoria_id` → `categorias.id`**: a qué categoría pertenece cada
  socio/cliente (ver [`app/Models/Socio.php`](app/Models/Socio.php), `$fillable`).

---

## 3. Backend (Laravel)

### Controlador

[`app/Http/Controllers/CategoriaController.php`](app/Http/Controllers/CategoriaController.php)
expone un CRUD básico:

| Método      | Acción    | Descripción                                                        |
|-------------|-----------|--------------------------------------------------------------------|
| `index()`   | listar    | `Categoria::all()` → JSON con todas las categorías.                |
| `store()`   | crear     | Valida `nombre` (obligatorio), `comercial_responsable_id`, `logo`. |
| `show($id)` | ver una   | Valida que el id no sea null/'null'/vacío; 404 si no existe.        |
| `update()`  | editar    | Mismas validaciones; reemplaza el logo y borra el anterior.        |
| `destroy()` | eliminar  | Borra la categoría; devuelve mensaje de éxito.                     |

Detalles de la gestión del **logo**:
- Se guarda con `store('categorias', 'public')` → disco `public`
  (`storage/app/public/categorias/...`).
- En `update`, antes de subir el nuevo logo se borra el anterior con
  `Storage::disk('public')->delete(...)` si existía.
- Validación del archivo: `nullable|image|max:2048` (máx. 2 MB).

### Rutas

[`routes/api.php`](routes/api.php)

| Verbo  | Ruta                  | Controlador@método        | Auth                |
|--------|-----------------------|---------------------------|---------------------|
| GET    | `categorias/{id}`     | `CategoriaController@show` | **Pública** (l. 50) |
| GET    | `categorias`          | `CategoriaController@index`| Protegida           |
| POST   | `categorias`          | `CategoriaController@store`| Protegida           |
| POST   | `categorias/{id}`     | `CategoriaController@update`| Protegida          |

> Notas:
> - `show` está **fuera** del grupo autenticado (línea ~50) para que el login de
>   socios pueda leer la categoría (nombre/logo) antes de iniciar sesión.
> - El **update usa POST** (`categorias/{id}`), no PUT/PATCH, porque se envía un
>   `FormData` con el fichero de logo (multipart).
> - **No hay ruta `DELETE` registrada** para categorías en `api.php`, aunque el
>   controlador implementa `destroy()` y el frontend tiene un servicio
>   `deleteCategory()`. El borrado, hoy, no está expuesto por API.

### Usos de la categoría en otros controladores

- **Navegación de socios** —
  [`NavController@getNavegacionSocio`](app/Http/Controllers/NavController.php):
  ruta `GET /nav-socio/{categoria}/socio/{socio_id}`. Construye el menú del socio
  (Mis Datos / Contratar / Mis Productos) con los `tipo_producto` activos de esa
  categoría (`whereNull('padre_id')` y `whereNull('tipo_producto_asociado')`,
  es decir, solo productos base, no subproductos ni anexos). Si el socio no tiene
  comercial en `socios_comerciales`, **cae al `comercial_responsable_id` de la
  categoría** para atribuir las contrataciones.
- **Alta y consulta de socios** —
  [`SocioController`](app/Http/Controllers/SocioController.php):
  - `GET socio/{dni}/categoria/{categoria_id}` (`getAsegurado`)
  - `POST socio/categoria/{categoria_id}` (`store`): da de alta al socio con
    `categoria_id` y le envía la notificación de contraseña inicial usando el
    `nombre` de la categoría como marca del correo.
- **Alta de tipos de producto** —
  [`ProductoController`](app/Http/Controllers/ProductoController.php): al crear un
  `tipo_producto` se guarda su `categoria_id`.

---

## 4. Frontend (Angular)

### Servicio

[`src/app/services/categories/categories.service.ts`](../../Kyrema/frontend/src/app/services/categories/categories.service.ts)

```ts
getCategories()                       // GET  /categorias
getCategory(id)                       // GET  /categorias/{id}
createCategory(formData)              // POST /categorias          (multipart)
updateCategory(id, formData)          // POST /categorias/{id}     (multipart)
deleteCategory(id)                    // DELETE /categorias/{id}   (sin ruta en API)
```

Además mantiene un `BehaviorSubject` (`logo$`) con el logo "activo" de la
aplicación, con un logo por defecto (`assets/Logo_CANAMA__003.png`).

### Rutas Angular

[`src/app/app.routes.ts`](../../Kyrema/frontend/src/app/app.routes.ts)

| Ruta                              | Componente                      | Para qué                         |
|-----------------------------------|---------------------------------|----------------------------------|
| `login/:categoria`                | `LayoutLoginComponent`          | Login de **socios** por categoría|
| `categorias`                      | `CategoriesManagerComponent`    | Listado / gestión                |
| `configurador-categorias`         | `CategoriesConfiguratorComponent`| Crear categoría                 |
| `configurador-categorias/:categoria` | `CategoriesConfiguratorComponent` | Editar categoría             |

### Componentes

- **`CategoriesManagerComponent`**
  ([manager](../../Kyrema/frontend/src/app/pages/categories-manager/categories-manager.component.ts)):
  tabla de gestión (`ManagementTableComponent`) con columnas `nombre` y
  `actions`. Botón de eliminar abre `DeleteCategoriesDialogComponent`. El enlace
  de "configurar" apunta a `/configurador-categorias`.
- **`CategoriesConfiguratorComponent`**
  ([configurator](../../Kyrema/frontend/src/app/pages/categories-configurator/categories-configurator.component.ts)):
  formulario de alta/edición con campos:
  - `nombre` (obligatorio),
  - `comercial_responsable_id` (`ng-select`, cargado desde
    `userService.getComercialesResponsables()` → `GET comerciales/responsables`,
    que devuelve los comerciales con `responsable = 1`),
  - `logo` (input file con previsualización).
  - Envía todo como `FormData`. En modo edición precarga la categoría y
    construye la previsualización con `STORAGE_URL + category.logo`.
  - Botón **"Copiar URL login clientes"** → copia
    `AppConfig.URL + '/login/' + categoria_id` al portapapeles. **Esta es la URL
    que se entrega a los socios** para que accedan a su categoría.

### Flujo de login por categoría

[`login.component.ts`](../../Kyrema/frontend/src/app/pages/login/login.component.ts)

1. La ruta `login/:categoria` aporta el `categoria` (id).
2. Si **hay categoría** → se autentica como **socio**
   (`authService.loginSocio(usuario, password, categoria)`) y luego pide la
   navegación de socio (`getNavegationSocio(categoriaId, user.id)`).
3. Si **no hay categoría** (`/login` a secas) → se autentica como **comercial**
   (`loginUser`) y pide la navegación normal de comercial.
4. Tras login de socio, redirige al primer enlace disponible (normalmente
   "Mis Datos").

```
URL /login/3
   └─► login como SOCIO de la categoría 3
         └─► getNavegacionSocio(3, socio_id)
               └─► menú con los tipo_producto de la categoría 3
                     └─► comercial = comercial del socio
                          o, si no tiene → comercial_responsable_id de la categoría
```

---

## 5. Ciclo de vida completo (de extremo a extremo)

1. Un administrador crea la categoría en `/configurador-categorias`: nombre,
   comercial responsable y logo → `POST /categorias`.
2. Se asocian `tipo_producto` a la categoría (`categoria_id`) desde el
   configurador de productos.
3. El administrador copia la **URL de login de clientes**
   (`/login/{categoria_id}`) y la entrega/publica para los socios.
4. Un socio entra por esa URL, inicia sesión como socio y ve el menú generado
   por `getNavegacionSocio`, con los productos contratables de su categoría.
5. Si el socio no tiene comercial asignado, sus contrataciones se atribuyen al
   `comercial_responsable_id` de la categoría.

---

## 6. Avisos y deuda técnica

- **Migraciones desincronizadas** con el esquema real (`nombre` y `logo` no se
  crean en ninguna migración; la migración inicial crea columnas no usadas). Ver
  §2. No ejecutes `migrate:fresh` esperando reproducir producción.
- **Borrado de categorías no expuesto**: existe `destroy()` y
  `deleteCategory()`, pero falta la ruta `DELETE categorias/{id}` en
  [`routes/api.php`](routes/api.php).
- **Sin FK físicas** entre `categorias`, `comercial`, `tipo_producto` y `socios`
  (la integridad se valida en código). La auditoría confirma 0 categorías con
  `comercial_responsable_id` huérfano hoy ([AUDITORIA_MAPEO.md](AUDITORIA_MAPEO.md), §6.7).
- **Una sola categoría real** en uso (`id = 3`, "Caza"); el código está preparado
  para varias, pero no se ha ejercitado a escala.
- En el frontend hay referencias a una categoría hardcodeada (`'/login/3'`) en
  `login.component.ts` para fijar título/subtítulo; revisar si se añaden más
  categorías de socios.
