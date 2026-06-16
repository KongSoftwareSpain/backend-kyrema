# Informe de revisión de datos — Red de sociedades, comerciales y socios

**Para:** Dirección de Cánama Seguros
**Fecha:** 15 de junio de 2026
**Asunto:** Revisión y saneamiento del mapeo entre sociedades, comerciales (vendedores), socios (clientes) y pólizas.

---

## 1. En una frase

Hemos revisado a fondo cómo están enlazados en el sistema las **sociedades**, los **comerciales**, los **socios** y las **pólizas**. Encontramos varios enredos heredados de una antigua importación de datos que estaban **distorsionando los informes y las comisiones por comercial**. Los hemos corregido de forma segura (con copia de seguridad de todo y sin borrar ninguna cuenta). Quedan algunos puntos que **necesitan tu confirmación** porque dependen de conocimiento del negocio que solo tú tienes.

---

## 2. Por qué esto importa para el negocio

En el sistema, cada póliza y cada cliente está "colgado" de un comercial y de una sociedad. De esos enlaces dependen tres cosas que se ven todos los días:

- **Las comisiones**: quién cobra por cada póliza.
- **Los informes por comercial y por sociedad**: cuánto ha vendido cada uno.
- **Qué ve cada usuario** al entrar (su cartera de clientes y pólizas).

Si esos enlaces están mal, las comisiones y los informes salen mal **aunque la venta sea correcta**. Eso es justo lo que estaba pasando en varios sitios.

---

## 3. Qué encontramos (en cristiano)

Casi todos los problemas vienen de la **misma raíz**: en su día se hizo una **importación masiva de socios** desde el sistema antiguo, y esa importación dejó:

- **Cuentas "fantasma" de comercial** (creadas automáticamente, con fecha de alta "01/01/1900" y datos de relleno) que se quedaron como titulares de miles de clientes y pólizas que en realidad son de otra persona.
- **Clientes y notas "huérfanas"** que apuntaban a registros que ya no existen (porque se reimportaron los socios y cambiaron de número interno).

Los casos concretos:

| Caso | Qué pasaba | Impacto |
|---|---|---|
| **Comercial borrado en Araba Caza** | 486 clientes seguían colgados de una cuenta de vendedor que ya no existía. No aparecían en ningún informe ni comisión. | Cartera "invisible" |
| **Cuentas duplicadas** en dos sociedades (Joaquin Canalejo y Tecor Portas) | Cada vendedor tenía **dos cuentas**: una con la que vende cada día y otra "de importación" donde se habían volcado miles de clientes. La cartera estaba partida en dos. | Comisiones e informes partidos |
| **Pólizas de Araba Caza atribuidas a otra sociedad** | 388 pólizas de Araba Caza (con código `ARAC...`) figuraban a nombre de una cuenta de importación etiquetada como "Picos de Europa". | Comisiones de Araba Caza mal atribuidas |
| **Basura de la importación** | 739 enlaces que apuntaban a clientes que ya no existen. | Informes "sucios" |

---

## 4. Qué hemos corregido (ya hecho)

Todo lo siguiente se ha aplicado **con copia de seguridad previa** y de forma **reversible**. **No se ha borrado ninguna cuenta de comercial.**

1. ✅ **Araba Caza – comercial borrado**: los **486 clientes** que estaban colgados de la cuenta desaparecida se han reasignado a **Oskar Berdión**, responsable actual de Araba Caza.

2. ✅ **Araba Caza – pólizas mal atribuidas**: las **388 pólizas `ARAC`** que figuraban bajo la cuenta de importación se han pasado a **Oskar Berdión** y se ha corregido la sociedad de 5 de ellas que estaban mal etiquetadas. *(Esto traslada esas comisiones a Araba Caza, que es lo correcto.)*

3. ✅ **Joaquin Canalejo (sociedad 10027)**: se han unido sus dos cuentas en la principal (`jcanalejo@canamaseguros.es`). Ahora ve sus **1.152 clientes** juntos. La cuenta sobrante se ha dejado inactiva como vendedor (no se ha borrado).

4. ✅ **Tecor Portas (sociedad 10028)**: igual, unificadas en la cuenta principal con **2.653 clientes** juntos, y de paso le hemos **corregido el correo electrónico** (tenía uno inválido).

5. ✅ **Limpieza general**: eliminados **739 enlaces basura** que apuntaban a clientes inexistentes.

### Resultado medible

| Indicador | Antes | Ahora |
|---|---:|---:|
| Clientes colgados de un vendedor inexistente | 486 | **0** |
| Enlaces a clientes que ya no existen | 739 | **0** |
| Clientes con vendedor duplicado | 64 | **0** |
| Pólizas atribuidas a la sociedad equivocada | 383 | **0** |
| Sociedades con dos "jefes" a la vez | 4 | 2* |

\* Las 2 que quedan contienen cuentas de prueba que pediste **no borrar**; se pueden ajustar cuando digas.

---

## 5. Lo que necesita tu decisión

Estos puntos **no los hemos tocado** porque dependen de información que solo tú o los responsables podéis confirmar:

### 5.1 El enredo "Rasher" 🔴 (el más importante)
Hay una cuenta de importación que tiene pegados **384 clientes cuyas pólizas están en una sociedad llamada "Rasher"**. No sabemos si **Rasher** es:
- una sociedad real vuestra,
- el nombre del proveedor de software antiguo que se coló como si fuera una sociedad, o
- un "cajón" de migración.

**Necesitamos que nos digas qué es Rasher** para saber a quién deben pertenecer realmente esos 384 clientes. *(Hasta entonces, lo dejamos intacto.)*

### 5.2 Cuentas de prueba
Hay varias cuentas de "Prueba" / "admin" mezcladas con cuentas reales en un par de sociedades. Pediste no borrarlas. **¿Las dejamos como están o las marcamos como inactivas** para que no ensucien los informes?

### 5.3 Clientes y pólizas sueltos
- **24 clientes** no tienen ningún vendedor asignado (varios son *Clubs de Cazadores*).
- **10 pólizas** no tienen cliente asignado.

Son pocos y hay que asignarlos **a mano**. ¿Nos pasáis a quién corresponde cada uno, o preferís que os demos la lista para que la reviséis?

### 5.4 Validación de lo ya hecho
Las reasignaciones del punto 4 **mueven comisiones** (de cuentas fantasma a los vendedores reales). Todo apunta a que es lo correcto, pero **conviene que Oskar (Araba Caza), Joaquin y Tecor Portas confirmen** que ahora ven su cartera completa y correcta.

---

## 6. Recomendación para que no vuelva a pasar

El origen de casi todo fue una importación antigua sin "candados" que impidieran enlaces incorrectos. Recomendamos añadir esas protecciones técnicas en la base de datos (reglas que impidan, por ejemplo, que un cliente quede colgado de un vendedor inexistente). Es un cambio técnico que prepararíamos y aplicaríamos de forma controlada, con tu visto bueno.

---

## 7. Garantías

- **Nada se ha borrado de forma irreversible.** Cada cambio tiene su copia de seguridad y se puede deshacer.
- **Ninguna cuenta de comercial se ha eliminado**; las sobrantes solo se han marcado como inactivas.
- Toda la operación se hizo en bloques verificados: si algo no cuadraba, se revertía automáticamente.

---

*Detalle técnico completo (consultas, números exactos y procedimiento de reversión) disponible en el documento interno `AUDITORIA_MAPEO.md`.*
