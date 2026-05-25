# MIGRACION_ARABACAZA_2505

**Fecha:** 25/05/2026  
**Sociedad:** Araba Caza  
**Realizada por:** Kong Software Spain

---

## Resumen

Migración de los seguros activos de **Araba Caza** desde la base de datos MySQL antigua (`kyrema_gestion` en Dinaserver) a la base de datos SQL Server de producción (`KYREMA` en `85.215.191.245`), como paso previo al inicio de operaciones de la sociedad en la nueva plataforma.

---

## Conexiones de Base de Datos

| | BD Origen | BD Destino |
|---|---|---|
| **Driver** | MySQL | SQL Server 2019 |
| **Host** | `hl1261.dinaserver.com` | `85.215.191.245` |
| **Base de datos** | `kyrema_gestion` | `KYREMA` |
| **Usuario** | `gest_kyrema` | `Kong` |
| **Conexión Laravel** | `mysql` | `sqlsrv` |

---

## Identificadores de la Sociedad

| Campo | MySQL | SQL Server |
|---|---|---|
| `id_sociedad` | `90` | `10094` |
| `nombre` | `Araba Caza` | `Araba Caza` |
| `codigo_sociedad` | `1235` | `1235` |

---

## Tablas Migradas

| Tabla origen (MySQL) | Tabla destino (SQL Server) | Tipo de seguro |
|---|---|---|
| `seguros_combinados` | `producto_k` | Seguros combinados K |

Las siguientes tablas **no aplican** a Araba Caza (0 registros):

| Tabla | Resultado |
|---|---|
| `seguro_cacerias` | 0 registros — no usa cacería |
| `seguro_rehalas` | 0 registros |
| `seguro_servicios_juridicos` | 0 registros |

---

## Volumen de Datos

| Concepto | Cantidad |
|---|---|
| Total seguros en MySQL (id_sociedad=90) | 1.777 |
| Excluidos (`finalizado=1`) | 1.325 |
| **Activos migrados** (`borrado=0` AND `finalizado=0`) | **383** |
| Seguros ya presentes en SQL Server (runs previas) | 373 |
| Seguros nuevos insertados en esta migración | 10 |
| Errores finales | **0** |

---

## Resultado Final en SQL Server

```sql
-- producto_k WHERE sociedad_id = 10094
Total registros: 383
Subproducto único: KAVIP (ID 10254)
```

---

## Proceso Ejecutado

### Fase 0 — Auditoría previa (solo lectura)

```bash
php artisan migrate:verify-dependencies
```

Resultados relevantes:
- 106 sociedades en MySQL → 123 en SQL Server (offset detectado: +10000)
- Todos los tipos de pago mapeados correctamente
- 14.514 socios mapeados por DNI (97,4% cobertura)
- Tipos de cacería: Cacería / Evento / Rececho ✅

### Fase 1 — Diagnóstico específico de Araba Caza

```bash
php check_arabacaza.php
```

Confirmado:
- `id_sociedad=90` en MySQL → `id=10094` en SQL Server
- Solo tabla `seguros_combinados` con datos (1.777 registros totales)
- `producto_c` (cacería) vacío para esta sociedad

### Fase 2 — Dry-run de la migración

```bash
php artisan migrate:seguros-combinados --sociedad-id=90 --dry-run --rebuild-map
```

Validado:
- `sociedad_id` → `10094` ✅
- `subproducto` → `10254 / KAVIP` ✅
- `comercial_id` → `20083` ✅
- Mapeo de socios por DNI funcional

### Fase 3 — Migración real (primera pasada)

```bash
php artisan migrate:seguros-combinados --sociedad-id=90 --force
```

Resultado:
- ✅ Migrados: 5
- ⏭️ Saltados: 373 (ya existían)
- ❌ Errores: 5 → **error de conversión `nvarchar → int`** en columna `codigo_postal`

**Causa:** Algunos socios tienen `codigo_postal` con valores no numéricos (`'01015.'`, `'ARABA'`). La columna `codigo_postal` en `producto_k` es `int`.

**Fix aplicado** en [`MigrarSegurosCombinadosK.php`](app/Console/Commands/MigrarSegurosCombinadosK.php):

```php
// Método añadido para sanitizar el código postal
private function limpiarCodigoPostalInt($valor): ?int
{
    if ($valor === null || $valor === '') return null;
    $soloDigitos = preg_replace('/[^0-9]/', '', (string) $valor);
    if ($soloDigitos === '' || strlen($soloDigitos) < 4 || strlen($soloDigitos) > 5) return null;
    return (int) $soloDigitos;
}
```

### Fase 4 — Migración real (segunda pasada, tras fix)

```bash
php artisan migrate:seguros-combinados --sociedad-id=90 --force
```

Resultado:
- ✅ Migrados: 5 (los que fallaron antes)
- ⏭️ Saltados: 378
- ❌ Errores: **0**

### Fase 5 — Corrección de sociedad_id en runs previas

Se detectó que los 373 registros previos habían sido insertados con `sociedad_id=10084` (Sdad. caza y pesca Picos de Europa) en lugar de `10094` (Araba Caza), por un error de mapeo en una migración anterior no filtrada.

```bash
php fix_sociedad_arabacaza.php
```

```sql
UPDATE producto_k
SET sociedad_id = 10094, updated_at = GETDATE()
WHERE codigo_producto IN (<383 pólizas de ArabaCaza>)
  AND sociedad_id != 10094
-- 373 registros actualizados
```

---

## Modificaciones al Código

### [`MigrarSegurosCombinadosK.php`](app/Console/Commands/MigrarSegurosCombinadosK.php)

| Cambio | Descripción |
|---|---|
| Nueva opción `--sociedad-id=` | Permite filtrar la migración por `id_sociedad` en MySQL. Evita afectar a otras sociedades aún no listas para migrar. |
| Nuevo método `limpiarCodigoPostalInt()` | Sanitiza `codigo_postal` extrayendo solo dígitos (4-5 chars) antes de insertar en columna `int` de SQL Server. |

---

## Mapeo de Tipos de Pago

| MySQL (`tipo_pago`) | SQL Server (`tipo_pago_id`) | Nombre |
|---|---|---|
| `NULL` | 6 | No completado |
| `1` | 6 | No completado |
| `2` | 5 | Transferencia |
| `3` | 8 | Efectivo |
| `5` | 9 | Tarjeta |

---

## Anomalías de Datos (no son errores)

| Anomalía | Registros afectados | Causa |
|---|---|---|
| Precio cero | 10 | Tabla `seguros_detalles` no existe en MySQL — precios históricos no disponibles |
| Sin sexo | 5 | Socios sin campo sexo en la BD de origen |
| Sin email | 5 | Socios sin email en la BD de origen |

---

## Logs Generados

| Archivo | Contenido |
|---|---|
| `storage/logs/migracion_seguros.log` | Log detallado por registro (debug, info, errores) |
| `storage/logs/migracion_anomalias_combinados.log` | Reporte de anomalías de datos |

---

## Scripts de Diagnóstico Creados

| Script | Uso |
|---|---|
| `check_arabacaza.php` | Diagnóstico previo: conteo por tabla, IDs, comerciales |
| `check_errores_arabacaza.php` | Análisis de los 5 registros con error de conversión |
| `fix_sociedad_arabacaza.php` | Corrección de `sociedad_id` en runs previas |
| `verify_arabacaza.php` | Verificación final en SQL Server |

---

## Verificación Post-Migración

```sql
-- SQL Server KYREMA
SELECT COUNT(*) FROM producto_k WHERE sociedad_id = 10094
-- Resultado: 383 ✅

SELECT subproducto_codigo, COUNT(*) as total
FROM producto_k WHERE sociedad_id = 10094
GROUP BY subproducto_codigo
-- KAVIP: 383
```

---

## Notas

- La migración es **idempotente**: re-ejecutar no duplica registros (deduplicación por `poliza_seguro` → `codigo_producto`).
- Los seguros con `finalizado=1` (1.325) **no se migran** por diseño: son pólizas expiradas/cerradas sin relevancia operativa.
- Los seguros con `borrado=1` tampoco se migran.
- `pago_id` se deja `NULL` en todos los registros: el campo `pagado` de MySQL es un flag (0/1), no una FK, y no hay registros de pago reales que vincular.
