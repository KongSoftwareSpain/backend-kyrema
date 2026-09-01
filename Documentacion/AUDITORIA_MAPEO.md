# Auditoría de mapeo: Sociedades · Comerciales · Socios · Productos

> Fecha: 2026-06-15 · BD: `KYREMA` (sqlsrv, 85.215.191.245)
> Scripts de auditoría (solo lectura): `audit_mapping.php`, `audit_detail.php`, `audit_20093.php`, `audit_duplicados.php`. Vuelco crudo en `audit_result.json`.
> Scripts de corrección (con backup + transacción): `apply_fixes.php`, `apply_fusion.php`.

---

## 0. Registro de cambios aplicados (2026-06-15)

Ejecutado con backup previo en tablas `_bak_*` y dentro de transacción. **Ninguna cuenta fue borrada.**

| Acción | Resultado | Backup |
|---|---|---|
| Limpieza `socios_comerciales` con socio inexistente | **602 filas borradas** | `_bak_sc_socio_huerfano` |
| Limpieza `socios_productos` con socio inexistente | **137 filas borradas** | `_bak_sp_socio_huerfano` |
| Reasignación comercial borrado `20093` → `20135` (Oskar, Araba Caza) | **486 socios** reasignados | `_bak_sc_reasignacion_20093` |
| Fusión `20026` → `23` (sociedad 10027, Joaquin) | 26 solapes dedup + **1.126 socios movidos**; 20026 a responsable=0 | `_bak_sc_fusion`, `_bak_comercial_fusion` |
| Fusión `20027` → `22` (sociedad 10028, Manuel) | 38 solapes dedup + **2.615 socios movidos**; email 22 → `info@tecorportas.com`; 20027 a responsable=0 | `_bak_sc_fusion`, `_bak_comercial_fusion` |
| Reasignación productos ARAC (Araba Caza) `20083` → `20135` (Oskar) | **388 productos** (comercial + creador) movidos; **5** con `sociedad_id` 10084→10094 corregidos | `_bak_prodk_arac_20083` |

**Estado de anomalías tras los cambios:**

| Métrica | Antes | Ahora |
|---|---:|---:|
| `socios_comerciales` → socio inexistente | 602 | **0** ✅ |
| `socios_comerciales` → comercial inexistente | 486 | **0** ✅ |
| `socios_productos` → socio inexistente | 137 | **0** ✅ |
| socios con >1 comercial | 64 | **0** ✅ |
| productos K con comercial de otra sociedad | 383 | **0** ✅ |
| sociedades con >1 responsable | 4 | **2** (solo restan sociedad 1 y 10094, ambas con cuentas de prueba que se conservan) |

**Cómo revertir:** los `_bak_*` contienen las filas exactas previas. Reasignación 20093 y fusiones se revierten con un `UPDATE` desde el backup correspondiente; las limpiezas, reinsertando desde el backup.

### Pendiente (requiere decisión / no ejecutado)
- §5.4 — 24 socios sin comercial y 10 productos K sin `socio_id` (asignación manual).
- **Maraña 20083 / Rasher**: los 384 socios de `socios_comerciales` pegados al comercial 20083 tienen sus pólizas en la sociedad **10073 (Rasher)**, no en Araba Caza. Cuenta-basura de importación con datos de varias sociedades mezclados. Investigación aparte.
- §5.3 — decisión de arquitectura sobre `socios_productos` (mantener vs deprecar).
- §7.9 — añadir FK/UNIQUE/NOT NULL (cambio de esquema).

---

## 1. Modelo de datos (cómo se relaciona todo)

```
                       categorias (1 fila: id=3 "Caza")
                            │ comercial_responsable_id
                            ▼
  sociedad ──1:N──> comercial ──1:N(responsable)──┐
     │ id_sociedad      │ id                        │
     │                  │                           │
     │ sociedad_padre_id│ (auto-relación jerárquica)│
     │ (árbol)          │                           │
     ▼                  ▼                           ▼
  tipo_producto_sociedad         socios_comerciales (pivote socio↔comercial)
   (pivote sociedad↔tipo)          │ id_socio / id_comercial
     │ id_sociedad                 ▼
     │ id_tipo_producto         socios ──1:N──> [tabla de cada producto]
     ▼                            │ id            (PRODUCTO_K, PRODUCTO_C, …)
  tipo_producto                   │                 columnas clave:
   id, padre_id,                  │                   · socio_id        → socios.id
   tipo_producto_asociado,        └───────────────────· comercial_id     → comercial.id
   categoria_id,                                      · comercial_creador_id → comercial.id
   letras_identificacion (= nombre físico de tabla)   · sociedad_id      → sociedad.id
                                                       · subproducto      → tipo_producto.id

  socios_productos (pivote socio↔producto, LEGACY — ver §5.3)
   id_socio / id_producto / letras_identificacion
```

### Reglas del modelo
- **`tipo_producto`** es jerárquico en dos ejes:
  - `padre_id` → subproductos (K1, K3, KVIP… cuelgan de PRODUCTO_K=202). Comparten **la tabla física del padre** + columna `subproducto`.
  - `tipo_producto_asociado` → anexos (Anexo Portugal=235 asociado a 202). Tienen **su propia tabla** `ANEXOS_*`.
  - Solo los tipos con `padre_id IS NULL AND tipo_producto_asociado IS NULL` tienen tabla física propia de producto.
- **Sociedad** es un árbol vía `sociedad_padre_id`. `SOCIEDAD_ADMIN_ID = 1` (Kyrema/Cánama) lo ve todo.
- **Comercial** pertenece a una sociedad (`id_sociedad`) y puede ser `responsable` (1) o no (0).
- El vínculo **socio → comercial** vive en `socios_comerciales` (el modelo `Socio` lo trata como `hasOne`, pero en datos hay N — ver §4.4).
- El vínculo **socio → producto** real es la columna **`socio_id` dentro de cada tabla de producto**, NO la pivote `socios_productos` (que está abandonada, §5.3).

---

## 2. Inventario

| Tabla | Filas |
|---|---|
| sociedad | 120 |
| comercial | 136 |
| socios | 14 968 |
| socios_comerciales | 15 610 |
| socios_productos | 386 |
| tipo_producto | 41 |
| tipo_producto_sociedad | 144 |
| categorias | 1 |

---

## 3. Resumen de anomalías (semáforo)

| Anomalía | Nº | Gravedad |
|---|---:|:--:|
| `socios_comerciales` → comercial inexistente (**todo es el comercial 20093**) | **486** | 🔴 Alta |
| `socios_comerciales` → socio inexistente (ids 8–12627, reimportación) | **602** | 🟠 Media |
| `socios_productos` → socio inexistente | 137 | 🟠 Media |
| `socios_productos` con `letras_identificacion` de tablas que ya no existen | 20 tipos | 🟠 Media |
| `socios_productos` → producto inexistente (producto_k) | 6 | 🟢 Baja |
| Socios sin ningún comercial asignado | 24 | 🟠 Media |
| Socios con **varios** comerciales (modelo asume 1) | 64 | 🟠 Media |
| Sociedades sin ningún comercial | 10 | 🟢 Baja (test) |
| Sociedades sin comercial responsable | 14 | 🟠 Media |
| Sociedades con **>1** responsable | 4 | 🟠 Media |
| PRODUCTO_K: productos sin `socio_id` | 10 | 🟠 Media |
| PRODUCTO_K: `socio_id` huérfano (socio borrado) | 11 | 🟠 Media |
| PRODUCTO_K: `comercial_id` no pertenece a la `sociedad_id` del producto | 383 | 🟡 Revisar |
| PRODUCTO_C: `socio_id` huérfano | 20 | 🟠 Media |
| **tipo_producto_sociedad / categorías / jerarquías: TODO íntegro** | 0 | ✅ |

> `tipo_producto_sociedad`, las categorías y las jerarquías padre/asociado **no tienen ni un solo huérfano ni duplicado**. El problema NO está en la configuración de productos-sociedades, sino en el mapeo **socio↔comercial** y **socio↔producto**.

---

## 4. Sociedad · Comercial · Socio

### 4.1 Sociedad → Comercial ✅ íntegro
- 0 comerciales con `id_sociedad` NULL.
- 0 comerciales apuntando a sociedad inexistente.
- 0 sociedades con padre inexistente (árbol consistente).

### 4.2 Sociedades sin comercial / sin responsable 🟢🟠
- **10 sin ningún comercial** y **14 sin responsable**. Casi todas son de prueba: `Prueba`, `Hija/Nieta/Bisnieta Prueba`, `TEST`, `Kyrema 2`, `Sociedad con tipos de pago`, `pedro`, `VALENCIA`, `SociedadDIECISETE`, `sdadcomercialB`.
- Riesgo real: una sociedad **operativa** sin responsable rompe la navegación (`NavController` y `getNavegacionSocio` usan el responsable / comercial de la categoría como *fallback*). Conviene confirmar que ninguna de estas 14 tiene productos vivos antes de borrarlas.

### 4.3 Sociedades con >1 responsable 🟠
| Sociedad | Responsables | Diagnóstico |
|---|---|---|
| 1 (Admin) | Admin + 4 "Página Web" + "Prueba" + "Oskar" | Los `Pagina Web*` son pseudo-comerciales de contratación web → **probablemente OK**. Revisar `Prueba`(20129) y `Oskar`(20134). |
| 10027 | `Joaquin Canalejo` (23) **y** `jcanalejo` (20026) | 🔴 **Cuenta duplicada** (persona real + usuario suelto). |
| 10028 | `Manuel` (22) **y** `info` (20027) | 🔴 **Cuenta duplicada**. |
| 10094 | `Oskar Berdión` + `María Vergara` + `Prueba admin` (20138) | 2 responsables reales + 1 cuenta de **prueba** a eliminar. |

> El modelo no impide varios responsables, pero `NavController` y los *fallbacks* asumen 1. Con duplicados, qué comercial "gana" depende del orden de la query → comportamiento no determinista.

### 4.4 Socio → Comercial 🔴🟠

**(a) 486 filas apuntan a un comercial que NO existe — y es siempre el mismo: `id_comercial = 20093`.**
Esas 486 filas **sí** tienen socios válidos. Es decir: **486 socios reales quedaron colgados de un comercial borrado**. En la práctica esos socios no tienen comercial efectivo (el `JOIN` con `comercial` los deja fuera de informes, comisiones y navegación).
→ **Acción**: decidir el comercial sustituto y reasignar, o recrear el comercial 20093. *Una sola corrección arregla las 486.*

**(b) 602 filas apuntan a socios inexistentes (id 8–12627).**
La tabla `socios` empieza hoy en **id=12628** (max 28420). Todo lo anterior desapareció → hubo una **reimportación/truncado de `socios`** que no limpió `socios_comerciales`. Son filas zombi sin efecto funcional pero ensucian conteos.
→ **Acción**: borrar las filas de `socios_comerciales` cuyo `id_socio` no exista (limpieza segura).

**(c) 24 socios sin ningún comercial.** Incluyen varios `CLUB DE CAZADORES` (personas jurídicas) y altas sueltas. Sin comercial no entran en comisiones ni en la jerarquía de sociedad.

**(d) 64 socios con MÁS de un comercial.** El modelo `Socio::socioComercial()` es `hasOne` → al leer, Eloquent coge **uno arbitrario**. Esto provoca que el mismo socio aparezca bajo distintas sociedades según la consulta. Hay que definir regla de unicidad (último gana / comercial principal) y deduplicar.

> Nota: 0 duplicados exactos (mismo socio + mismo comercial), así que el multi-comercial son asignaciones a comerciales **distintos**, no inserciones repetidas.

---

## 5. Socio · Producto

### 5.1 Integridad de `socios_productos`
- 137 filas → socio inexistente (mismo origen que §4.4b: reimportación de socios).
- 6 filas → producto inexistente en `PRODUCTO_K`.
- 0 filas con `letras_identificacion` vacía.

### 5.2 `letras_identificacion` fantasma
20 valores distintos apuntan a tablas **que ya no existen**: `producto_pb1` (46 filas), `producto_copi` (22), `producto_psub`, `producto_scp`, `producto_pcrs`, `producto_cace`, `producto_test`, `producto_ppl2`, `producto_pro2`, `producto_prod`, `producto_p1/p2`, `producto_sc`, `producto_scc`, `producto_sel`, `producto_caes`, `producto_bloq`, `producto_kvip+`, `producto_pl`, `producto_pc`. Son restos de tablas de pruebas borradas.

### 5.3 ⚠️ Hallazgo principal: `socios_productos` está ABANDONADA
- Solo **386 filas** en total, frente a **5 532 productos solo en PRODUCTO_K**.
- De esos 5 532, únicamente **142** tienen fila en la pivote → **97,4 % de los productos K no están en `socios_productos`**.
- El **vínculo real socio↔producto es la columna `socio_id` de cada tabla de producto**, que sí está bien poblada.

→ **Conclusión**: `socios_productos` no es fuente de verdad. O se mantiene **consistentemente** (rellenarla al crear cada producto) o se **deprecia** formalmente. Hoy está a medias y cualquier código que dependa de ella da resultados incompletos.

### 5.4 Integridad por tabla de producto (columna `socio_id` / `comercial_id` / `sociedad_id`)

| Tabla | Total | sin socio | socio huérfano | sin comercial | com. huérfano | sin creador | sociedad huérfana | comercial≠sociedad |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| PRODUCTO_K | 5532 | 10 | 11 | 0 | 1 | 41 | 0 | **383** |
| PRODUCTO_C | 50 | 0 | 20 | 0 | 0 | 12 | 0 | 1 |
| PRODUCTO_REHAL | 19 | 0 | 3 | 0 | 0 | 5 | 0 | 0 |
| PRODUCTO_TDOC | 41 | 0 | 0 | 0 | 0 | 13 | 0 | 0 |
| PRODUCTO_SJK | 4 | 1 | 0 | 0 | 0 | 0 | 0 | 0 |
| PRODUCTO_SMK | 2 | 0 | 0 | 0 | 0 | 0 | 0 | 0 |
| PRODUCTO_PR | 0 | – | – | – | – | – | – | – |

Lecturas:
- **`sociedad_id` perfecto** en todas las tablas (0 huérfanos, 0 nulos). La asociación producto→sociedad es sólida.
- **`socio_id` huérfano** (PRODUCTO_K=11, PRODUCTO_C=20, REHAL=3): productos de socios borrados en la reimportación. Suelen ser registros antiguos de test (`socio_id` 8 y 10, que ya no existen).
- **Productos sin `socio_id`** (PRODUCTO_K=10): incluye altas recientes como `062026K100000052/053` (sociedad 1). Revisar el flujo de alta: un producto no debería guardarse sin socio.
- **`comercial_creador_id` nulo** (PRODUCTO_K=41, C=12, TDOC=13): productos sin trazar quién los creó. No rompe nada pero impide auditoría de "referidos".
- **383 productos K con `comercial_id` de otra sociedad distinta a `sociedad_id`**: puede ser legítimo (comercial admin creando para otra sociedad, o un comercial reubicado de sociedad después de vender) **o** un error de asignación. Es el punto que más conviene revisar manualmente — empezar agrupando por `comercial_id` para ver si hay un comercial concreto concentrando el desajuste.

---

## 6. Tipo de producto · Sociedad · Categoría — ✅ TODO ÍNTEGRO

- `tipo_producto_sociedad`: 0 huérfanos de sociedad, 0 huérfanos de tipo, 0 duplicados.
- 0 tipos padre activos sin sociedad asignada.
- 0 `tipo_producto` con categoría inexistente (todo cuelga de la categoría 3).
- 0 subproductos con `padre_id` roto · 0 anexos con `tipo_producto_asociado` roto.
- 0 categorías con `comercial_responsable_id` inexistente.

Esta capa de configuración está sana; no requiere acción.

---

## 7. Plan de acción priorizado

| # | Acción | Impacto | Riesgo |
|---|---|---|---|
| 1 | **Reasignar/recrear el comercial 20093** → arregla 486 socios colgados de golpe. | 🔴 Alto | Bajo |
| 2 | Deduplicar comerciales **responsables duplicados** en sociedades 10027 (`jcanalejo`) y 10028 (`info`); eliminar cuentas de prueba (`Prueba admin` 20138, `Prueba` 20129). | 🟠 | Bajo |
| 3 | Limpiar `socios_comerciales` con `id_socio` inexistente (602 filas zombi de la reimportación). | 🟠 | Bajo (solo borra huérfanos) |
| 4 | Limpiar `socios_productos` con `id_socio` inexistente (137) y `letras_identificacion` fantasma (tablas borradas). | 🟠 | Bajo |
| 5 | Resolver los **64 socios con varios comerciales**: definir regla (comercial principal / último) y aplicar UNIQUE en `socios_comerciales(id_socio)` si el negocio es 1:1. | 🟠 | Medio (cambia comportamiento) |
| 6 | Revisar los **383 productos K** con `comercial_id` de otra sociedad — agrupar por comercial para separar legítimos de errores. | 🟡 | Medio |
| 7 | Asignar comercial a los **24 socios** sin comercial; asignar socio a los **10 productos K** sin `socio_id`. | 🟠 | Bajo |
| 8 | **Decisión de arquitectura**: mantener `socios_productos` consistente (rellenarla en cada alta) **o** deprecarla y usar solo `socio_id`. Hoy cubre el 2,6 % de los productos. | 🟠 | — |
| 9 | Salvaguardas en código: `NOT NULL` + FK donde el negocio lo permita; validar `socio_id` y `comercial_creador_id` obligatorios en el alta de producto. | 🟢 | Bajo |

### Causa raíz transversal
La mayoría de huérfanos de socios (602 + 137 + `socio_id` huérfanos) provienen de **una reimportación/truncado de la tab`socios`** (los ids hoy arrancan en 12628) que **no propagó la limpieza** a `socios_comerciales` ni `socios_productos`, y de la **falta de claves foráneas** que habrían impedido esos huérfanos. Recomendado: añadir FKs (o al menos triggers/validación) tras la limpieza para que no se repita.

---

## 8. Cómo reproducir

```bash
php audit_mapping.php   # auditoría completa → consola + audit_result.json
php audit_detail.php    # detalle de las anomalías grandes
```
Ambos son **solo lectura** (únicamente `SELECT`). No modifican datos.
