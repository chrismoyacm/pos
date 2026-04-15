# Plan de Migración a Stored Procedures - POS System

**Fecha Inicio:** 2024-01-XX  
**Estado Actual:** ✅ Infrastructure lista - Comenzamos API migrations

---

## ✅ Completado

### 1. Utilities DB Extendidas
- ✅ `utils/db.php` ampliada con 3 nuevas funciones helper:
  - `callStoredProcedure()` - Ejecuta SP y retorna array de filas
  - `callStoredProcedureOne()` - Ejecuta SP y retorna una fila
  - `callStoredProcedureMulti()` - Ejecuta SP con múltiples result sets
  
### 2. Documentación de SPs Completa
- ✅ `docs/STORED_PROCEDURES.md` creado con:
  - 20+ SPs definidos (SQL completo)
  - Estructura de tablas requeridas
  - Convención de nombres
  - Parámetros IN/OUT documentados
  - Checklist de implementación

### 3. Registro de Tracking
- ✅ `/memories/repo/stored_procedures_list.md` - Seguimiento de progreso

---

## ⏳ Próximos Pasos (En Orden)

### Fase 1: Migración de api/sales.php
**SPs requeridos:** 5
- `sp_create_sale`
- `sp_get_sales_by_day`
- `sp_get_last_sale`
- `sp_return_item`
- `sp_get_sale_by_ticket`

**Cambios en el código:**
- Reemplazar `readJsonFile($salesPath)` con llamadas a SPs
- Eliminar lógica de paginación en PHP (ahora en SP)
- Mantener compatibilidad de respuesta JSON

### Fase 2: Migración de api/inventario.php  
**SPs requeridos:** 5
- `sp_get_products_report`
- `sp_get_products`
- `sp_update_product`
- `sp_add_inventory_movement`
- `sp_get_product_by_id`

### Fase 3: APIs Restantes
- **api/customers.php**: 4 SPs
- **api/departments.php**: 3 SPs
- **api/products.php**: 3 SPs
- **api/creditos_*.php**: 3 SPs
- **api/compras.php**: 2 SPs

---

## 📋 Instrucciones para tu Compañero (DBA)

**Archivo a compartir:** `docs/STORED_PROCEDURES.md`

### Lo que necesita hacer:

1. **Crear las tablas base** (SQL en el documento)
2. **Crear todos los SPs** (20+ procedimientos)
3. **Validar permisos** - Usuario de conexión debe tener:
   - `EXECUTE` en todos los SPs
   - `SELECT, INSERT, UPDATE` en todas las tablas
   - `CREATE TEMPORARY TABLES` para SPs complejos

4. **Enviar respuesta cuando sea listo** con:
   - ✅ Confirmación de tablas creadas
   - ✅ Confirmación de SPs creados
   - ✅ Info de conexión (host, usuario, puerto)
   - ✅ Script SQL usado (para documentación)

---

## Ahora: ¿Qué Hacemos?

Tienes dos opciones:

### Opción A: Esperar a tu compañero
- Que cree los SPs del documento primero
- Luego comenzamos a migrar los APIs
- **Tiempo estimado:** Depende del DBA

### Opción B: Comenzar prep en paralelo
- Puedo empezar a reescribir `api/sales.php` para llamar SPs
- Cuando el DBA termine, solo activamos la BD
- **Ventaja:** Ganamos tiempo

---

## ⚠️ Convenciones Importantes

```php
// ANTES (JSON)
$sales = readJsonFile($salesPath);

// DESPUÉS (SP)
$sales = callStoredProcedure('sp_get_sales_by_day', [
    'date' => date('Y-m-d'),
    'search' => $q,
    'page' => $page,
    'pageSize' => $pageSize,
    'total' => NULL // parámetro OUT
]);
```

**Puntos clave:**
- SPs retornan arrays directamente (no objetos)
- Parámetros OUT se pasan como referencias
- Response JSON es igual (no cambia frontend)
- Mantener fallback a JSON si DB no está disponible

---

## 📞 Contacta a tu Compañero

**Dile que:**
1. Lea `docs/STORED_PROCEDURES.md`
2. Cree las **tablas** (sección "Estructura de Tablas Requeridas")
3. Cree los **20+ SPs** (SQL está listo, solo copy-paste)
4. Confirme permisos EXECUTE para el usuario de conexión
5. Responda con detalles cuando esté listo

---

## Estado de Archivos

```
c:\inetpub\wwwroot\pos\
├── utils/
│   └── db.php ✅ (extendida con callStoredProcedure*)
├── docs/
│   └── STORED_PROCEDURES.md ✅ (completo, listo para compartir)
├── api/
│   ├── sales.php ⏳ (próximo a migrar)
│   ├── inventario.php ⏳ (próximo a migrar)
│   └── ... (resto pendiente)
└── config/
    └── database.php ✅ (ya estaba lista)
```

---

## Checklist Final

- [x] Funciones helper en `utils/db.php` creadas
- [x] Documento de SPs completo
- [x] Convenciones establecidas
- [ ] DBA crea tablas y SPs
- [ ] Migrar `api/sales.php` a SPs
- [ ] Migrar `api/inventario.php` a SPs
- [ ] Migrar APIs restantes
- [ ] Testing completo
- [ ] Migración de datos (JSON → BD)

---

**Próximo paso:** ¿Comenzamos con la reescritura de `api/sales.php` mientras tu compañero prepara la BD?
