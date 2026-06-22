# Pruebas de Importacion de Productos

Estos archivos CSV estan pensados para probar `Productos -> Importar` uno por uno.
Los encabezados fueron actualizados al formato legacy esperado por la tabla `PRODUCTOS`, por ejemplo:
- `CODIGO`
- `DESCRIPCION`
- `PCOSTO`
- `PVENTA`
- `DINVENTARIO`
- `DEPT`
- `PROVID`

Casos que deben pasar:
- `01-ok-minimo.csv`
- `02-ok-completo.csv`
- `03-ok-kit.csv`

Casos que deben fallar:
- `11-error-sin-barcode.csv`
- `12-error-sin-nombre.csv`
- `13-error-precio-invalido.csv`
- `14-error-costo-invalido.csv`
- `15-error-stock-invalido.csv`
- `16-error-unitType-invalido.csv`
- `17-error-codigo-duplicado.csv`
- `18-error-header-faltante.csv`
- `19-error-header-desconocido.csv`
- `20-error-header-duplicado.csv`

Nota:
- Los archivos con error estan hechos para disparar validaciones del importador.
- El archivo `17-error-codigo-duplicado.csv` tiene dos filas a proposito, porque esa validacion solo aplica cuando el mismo codigo se repite dentro del mismo archivo.
