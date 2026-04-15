# Quick Reference - Usando Stored Procedures en PHP

**Localización de helpers:** `utils/db.php` (requiere `db()` activo)

---

## 📌 3 Funciones Helper

### 1. `callStoredProcedure()` - Múltiples filas

Ejecuta un SP y retorna **todos los registros**.

```php
use function callStoredProcedure;

// Sintaxis básica
$rows = callStoredProcedure('sp_get_sales_by_day', [
    'date' => '2024-01-15',
    'search' => '',
    'page' => 1,
    'pageSize' => 20
]);

// Resultado: array de arrays
// [
//   ['id' => 1, 'ticketId' => 'TK-001', ...],
//   ['id' => 2, 'ticketId' => 'TK-002', ...],
// ]
```

---

### 2. `callStoredProcedureOne()` - Una fila

Ejecuta un SP y retorna **solo el primer registro** o `null`.

```php
// Obtener un cliente específico
$customer = callStoredProcedureOne('sp_get_customer_by_id', [
    'id' => 'c-12345'
]);

// Resultado: array o null
// ['id' => 'c-12345', 'name' => 'Juan', 'phone' => '555-1234']
```

---

### 3. `callStoredProcedureMulti()` - Múltiples result sets

Para SPs que retornan **varias listas de datos**.

```php
// SP que retorna: items + paginación + resumen
$results = callStoredProcedureMulti('sp_report_complex', [
    'year' => 2024
]);

// Resultado: array de arrays
// [
//   [items 1er query],
//   [items 2do query],
//   [items 3er query]
// ]

$items = $results[0] ?? [];
$pagination = $results[1] ?? [];
$summary = $results[2] ?? [];
```

---

## 🔧 Patrones Comunes

### Patrón 1: Obtener y Renderizar

```php
$products = callStoredProcedure('sp_get_products', []);

json_response([
    'success' => true,
    'products' => $products
]);
```

### Patrón 2: Con Paginación

```php
$page = max(1, (int)($_GET['page'] ?? 1));
$pageSize = max(1, min(100, (int)($_GET['pageSize'] ?? 25)));

$products = callStoredProcedure('sp_get_products_report', [
    'department' => $_GET['department'] ?? '',
    'page' => $page,
    'pageSize' => $pageSize
]);

json_response([
    'items' => $products,
    'pagination' => [
        'page' => $page,
        'pageSize' => $pageSize,
        'total' => COUNT, // Obtener del SP via OUT param
        'totalPages' => max(1, ceil(COUNT / $pageSize))
    ]
]);
```

### Patrón 3: Validar Existencia

```php
$product = callStoredProcedureOne('sp_get_product_by_id', [
    'id' => $productId
]);

if (!$product) {
    error_response('Producto no encontrado', 404);
}

// Usar $product con seguridad
$stock = (int)($product['stock'] ?? 0);
```

### Patrón 4: Try-Catch para Errores

```php
try {
    $result = callStoredProcedure('sp_create_sale', [
        'ticketId' => $ticketId,
        'customerId' => $customerId,
        'total' => $total,
        'clientRequestId' => $requestId
    ]);
    
    json_response(['success' => true, 'ticketId' => $ticketId]);
} catch (PDOException $e) {
    error_response('Error al registrar venta: ' . $e->getMessage(), 500);
}
```

---

## 📊 Mapeo: JSON → Stored Procedures

### Antes (JSON)
```php
require_once __DIR__ . '/../utils/json_store.php';

$sales = readJsonFile($salesPath);

foreach ($sales as $sale) {
    if (date('Y-m-d', strtotime($sale['createdAt'])) === $date) {
        // procesar sale
    }
}

// Retornar
json_response($filtered_sales);
```

### Después (SP)
```php
require_once __DIR__ . '/../utils/db.php';

$sales = callStoredProcedure('sp_get_sales_by_day', [
    'date' => $date,
    'search' => $q,
    'page' => 1,
    'pageSize' => 100
]);

// Retornar directamente
json_response($sales);
```

---

## ⚠️ Error Handling

### Conexión no disponible
```php
try {
    $data = callStoredProcedure('sp_get_sales_by_day', [...]);
} catch (RuntimeException $e) {
    if (str_contains($e->getMessage(), 'deshabilitada')) {
        // Fallback a JSON si lo prefieres
        return readJsonFile($salesPath);
    }
    throw $e;
}
```

### SP no existe
```php
// El error viene como PDOException
try {
    callStoredProcedure('sp_inexistente', []);
} catch (PDOException $e) {
    // "Procedure 'database.sp_inexistente' does not exist"
    error_response('SP no encontrado', 500);
}
```

---

## 🎯 Checklist Antes de Usar

Cuando conviertas un API:

- [ ] ¿SP existe en la BD?
- [ ] ¿Función helper importada? (`require 'utils/db.php'`)
- [ ] ¿Parámetros correctamente mapeados?
- [ ] ¿Response format igual al original?
- [ ] ¿Try-catch si puede fallar?
- [ ] ¿Testing en localhost?

---

## 💡 Tips

1. **Nombrar claro:** `$products`, `$sales`, no `$result`
2. **Validar retoro:** `if (!$result)`, `if (count($results) > 0)`
3. **Reutilizar SPs:** Si dos endpoints necesitan lo mismo, usa el mismo SP
4. **Documentar:** Comentar qué SP hace qué en el código
5. **Test primero:** Prueba el SP en SQL Manager antes en PHP

---

## Ejemplo Completo: Convertir api/sales.php

### Estado Actual (JSON)
```php
<?php
require_once __DIR__ . '/../utils/json_store.php';

if ($_GET['action'] === 'day') {
    $sales = readJsonFile(storagePath('sales.json'));
    
    $daySales = array_filter($sales, ...);
    $total = count($daySales);
    
    json_response(['items' => $daySales, 'total' => $total]);
}
```

### Estado Nuevo (SP)
```php
<?php
require_once __DIR__ . '/../utils/db.php';

if ($_GET['action'] === 'day') {
    $date = $_GET['date'] ?? date('Y-m-d');
    $page = (int)($_GET['page'] ?? 1);
    $pageSize = (int)($_GET['pageSize'] ?? 20);
    
    $sales = callStoredProcedure('sp_get_sales_by_day', [
        'date' => $date,
        'search' => $_GET['q'] ?? '',
        'page' => $page,
        'pageSize' => $pageSize
    ]);
    
    json_response(['items' => $sales]);
}
```

---

## Referencias

- **Archivo con SPs:** `docs/STORED_PROCEDURES.md`
- **Archivo con Plan:** `docs/MIGRACION_PLAN.md`
- **Código Helper:** `utils/db.php` (líneas ~60-100)

**¿Preguntas?** Consulta este documento o revisa `docs/STORED_PROCEDURES.md`
