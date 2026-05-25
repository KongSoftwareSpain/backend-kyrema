# VERIFICACION_ANEXOS_ARABACAZA_2505

**Fecha:** 25/05/2026  
**Sociedad:** Araba Caza  
**ID MySQL:** `90`  
**ID SQL Server:** `10094`  
**Realizada por:** Kong Software Spain

---

## Resumen de la Auditoría de Anexos

Auditoría y comparación de consistencia de los anexos (acompañantes y perros) de los seguros combinados de la sociedad **Araba Caza** entre la base de datos de origen (MySQL) y la base de datos de destino (SQL Server).

---

## Resultados Generales

| Mapeo de Tablas | MySQL (Activos) | SQL Server (Activos) | Estado |
|---|---|---|---|
| `seguro_acompaniantes` $\rightarrow$ `anexos_ka` | 0 | 0 | Coincide ✅ |
| `seguro_perros` (tipo 2) $\rightarrow$ `anexos_ap` | 0 | 0 | Coincide ✅ |

Todos los anexos de las **383 pólizas activas** migradas coinciden perfectamente en ambas bases de datos.

---

## Detalle del Análisis Histórico en MySQL

Al realizar una búsqueda histórica sin filtrar por el estado de la póliza principal (`borrado = 0 AND finalizado = 0`), se detectaron registros en MySQL que **no fueron migrados por diseño** ya que sus pólizas correspondientes estaban finalizadas o borradas:

### 1. Acompañantes (`seguro_acompaniantes`)
* **Total histórico en MySQL para id_sociedad=90:** 2 registros (1 activo, 1 borrado).
* **Registro activo no migrado:**
  * **ID Acompañante:** `344`
  * **Nombre:** `PEPE`
  * **DNI:** `23295059S`
  * **Póliza vinculada:** `ARAC19760-2023` (ID Seguro: `19760`)
  * **Causa de exclusión:** La póliza vinculada tiene los flags `borrado = 1` y `finalizado = 1` en MySQL, por lo que no cumple las condiciones de migración activa y no existe en SQL Server.

### 2. Perros (`seguro_perros`)
* **Total histórico en MySQL para id_sociedad=90:** 445 registros.
  * **Tipo 2 (Combinados):** 5 registros (1 activo, 4 borrados).
  * **Tipo 1 (Otros/Históricos):** 440 registros (398 activos, 42 borrados).
* **Registro de Tipo 2 activo no migrado:**
  * **ID Perro:** `9248`
  * **Nombre:** `PERRO`
  * **Microchip:** `MICRO`
  * **Póliza vinculada:** `ARAC19760-2023` (ID Seguro: `19760`)
  * **Causa de exclusión:** Vinculado a la misma póliza borrada y finalizada (`ARAC19760-2023`).
* **Registros de Tipo 1 activos no migrados:**
  * **Cantidad:** 398 perros activos.
  * **Causa de exclusión:** Todos ellos están asociados a pólizas de años anteriores ya finalizadas/inactivas (ej. pólizas de 2021 como *ARAC9848-2021* o *ARAC9833-2021*). Ninguno de estos perros pertenece a los 383 seguros combinados actualmente vigentes.

---

## Conclusiones
La integridad y consistencia de los datos entre MySQL y SQL Server es del **100%**. La ausencia de registros en `anexos_ka` y `anexos_ap` para Araba Caza en el backend de producción es correcta, ya que ninguna de las pólizas activas en origen contaba con anexos vigentes.

---

## Consultas de Verificación Manual (MySQL)

Puedes ejecutar las siguientes consultas en el cliente de base de datos MySQL de origen para validar la consistencia de los datos de forma independiente:

### 1. Pólizas Combinadas Activas de Araba Caza (id_sociedad = 90)
Debería retornar exactamente **383 registros** (los que fueron migrados a SQL Server):
```sql
SELECT id_seguro, poliza_seguro, fecha_inicio, borrado, finalizado, cancelado
FROM seguros_combinados
WHERE id_sociedad = 90
  AND borrado = 0
  AND finalizado = 0;
```

### 2. Acompañantes de Pólizas Activas (Deduplicación de ka)
Debería retornar **0 registros** (confirmando que no hay acompañantes contratados para las pólizas activas):
```sql
SELECT a.*
FROM seguro_acompaniantes a
INNER JOIN seguros_combinados s ON a.id_seguro_combinado = s.id_seguro
WHERE s.id_sociedad = 90
  AND s.borrado = 0
  AND s.finalizado = 0
  AND a.borrado = 0;
```

### 3. Perros de Pólizas Activas (Deduplicación de ap)
Debería retornar **0 registros** (confirmando que no hay perros contratados para las pólizas activas):
```sql
SELECT p.*
FROM seguro_perros p
INNER JOIN seguros_combinados s ON p.id_seguro = s.id_seguro
WHERE s.id_sociedad = 90
  AND s.borrado = 0
  AND s.finalizado = 0
  AND p.borrado = 0;
```

### 4. Acompañantes Históricos Activos (Excluidos por Póliza Madre Inactiva)
Retorna el acompañante activo en origen, pero muestra que está vinculado a una póliza dada de baja en origen (`seguro_borrado = 1` y `seguro_finalizado = 1`):
```sql
SELECT a.id_seguro_acompaniante, a.nombre, a.dni, s.poliza_seguro, s.borrado AS seguro_borrado, s.finalizado AS seguro_finalizado
FROM seguro_acompaniantes a
INNER JOIN seguros_combinados s ON a.id_seguro_combinado = s.id_seguro
WHERE s.id_sociedad = 90
  AND a.borrado = 0;
```

### 5. Perros Históricos Activos (Excluidos por Póliza Madre Inactiva)
Retorna los perros activos en origen, confirmando que todos corresponden a pólizas dadas de baja en origen (`seguro_borrado = 1` o `seguro_finalizado = 1`):
```sql
SELECT p.id_seguro_perros, p.nombre, p.microchip, p.id_tipo_seguro_perros, s.poliza_seguro, s.borrado AS seguro_borrado, s.finalizado AS seguro_finalizado
FROM seguro_perros p
INNER JOIN seguros_combinados s ON p.id_seguro = s.id_seguro
WHERE s.id_sociedad = 90
  AND p.borrado = 0;
```
