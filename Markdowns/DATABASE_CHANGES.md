# Cambios en la Base de Datos - Salto de Línea

Se han añadido nuevas columnas para soportar la funcionalidad de **Salto de Línea** en la generación de certificados PDF.

## Columnas Añadidas

| Tabla | Columna | Tipo | Descripción |
| :--- | :--- | :--- | :--- |
| `campos` | `salto_linea_x` | `string` (nullable) | Coordenada X límite para el salto de línea del campo. |
| `campos` | `salto_linea_y` | `string` (nullable) | Coordenada Y límite para el salto de línea del campo. |
| `tipo_producto_polizas` | `salto_linea_x` | `string` (nullable) | Coordenada X límite para el salto de línea del número de póliza. |
| `tipo_producto_polizas` | `salto_linea_y` | `string` (nullable) | Coordenada Y límite para el salto de línea del número de póliza. |
| `campos_logos` | `salto_linea_x` | `string` (nullable) | Coordenada X límite (uso futuro o consistencia). |
| `campos_logos` | `salto_linea_y` | `string` (nullable) | Coordenada Y límite (uso futuro o consistencia). |

## Propósito

Estas coordenadas definen un "límite" visual en la plantilla del PDF. Si el texto a imprimir (por ejemplo, una dirección larga) supera este límite, el sistema de generación de PDF debe realizar un salto de línea y continuar la impresión en la siguiente línea, respetando el margen establecido por estas coordenadas.

En el frontend, estas coordenadas se representan con un **punto amarillo** en el selector de posición.
