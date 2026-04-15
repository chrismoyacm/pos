# Correcciones Realizadas al Archivo STORED_PROCEDURES.md

**Fecha:** 2024-01-XX  
**Estado:** ✅ Corregido y listo para MySQL

---

## 🐛 Errores Encontrados y Corregidos

### 1. **LEAVE sin Label (ERROR CRÍTICO)**

**Problema:**
```sql
-- ❌ INCORRECTO
LEAVE;  -- Sin donde ir, causa error de sintaxis
```

**Solución:**
```sql
-- ✅ CORRECTO
proc_create_sale: BEGIN
    -- ... código ...
    LEAVE proc_create_sale;  -- Sale del procedimiento con nombre
END proc_create_sale;
```

**Por qué:** En MySQL, `LEAVE` debe ir con un label válido. El label debe coincidir con el label del `BEGIN` del procedimiento.

---

### 2. **Tipo BOOLEAN no soportado en parámetros OUT**

**Problema:**
```sql
-- ❌ INCORRECTO
OUT OUT_success BOOLEAN,
SET OUT_success = TRUE;
SET OUT_success = FALSE;
```

**Solución:**
```sql
-- ✅ CORRECTO
OUT OUT_success TINYINT(1),
SET OUT_success = 1;        -- 1 = verdadero
SET OUT_success = 0;        -- 0 = falso
```

**Por qué:** MySQL no tiene tipo `BOOLEAN` nativo para parámetros de procedimientos. `TINYINT(1)` es el estándar.

---

### 3. **DELIMITER no especificado**

**Problema:**
```sql
-- ❌ INCORRECTO
CREATE PROCEDURE sp_create_sale (...)
BEGIN
    ...
END;  -- MySQL interpreta el ; como fin del comando
```

**Solución:**
```sql
-- ✅ CORRECTO
DELIMITER //

CREATE PROCEDURE sp_create_sale (...)
BEGIN
    ...
END//

DELIMITER ;
```

**Por qué:** MySQL Workbench necesita saber dónde termina el SP. Sin `DELIMITER`, el `;` dentro del SP lo confunde.

---

### 4. **LIMIT incorrecto**

**Problema:**
```sql
-- ❌ INCORRECTO
LIMIT v_offset, v_offset + p_pageSize;
-- Esto debería retornar un número, no una suma
```

**Solución:**
```sql
-- ✅ CORRECTO
LIMIT v_offset, p_pageSize;
-- LIMIT offset, count (no LIMIT offset, offset+count)
```

---

## 📋 Resumen de Cambios

| Cambio | Antes | Después |
|--------|-------|---------|
| **LEAVE** | `LEAVE;` | `LEAVE proc_name;` |
| **DELIMITER** | No incluido | `DELIMITER //` al inicio, `DELIMITER ;` al final |
| **BOOLEAN** | `BOOLEAN` / `TRUE`/`FALSE` | `TINYINT(1)` / `1`/`0` |
| **LIMIT** | `LIMIT offset, offset+size` | `LIMIT offset, size` |
| **Labels** | `BEGIN ... END;` | `proc_name: BEGIN ... END proc_name//` |

---

## 📂 Archivos Disponibles

### 1. **STORED_PROCEDURES.md** (Con documentación)
- Explicación de cada SP
- Parámetros documentados
- Estructura de tablas
- **Mejor para:** Documentación y referencia

### 2. **STORED_PROCEDURES_CLEAN.sql** ⭐ (Copiar-Pegar)
- Solo SQL puro
- Sin markdown/backticks
- Listo para MySQL Workbench
- **Mejor para:** Ejecutar directamente en BD

---

## ✅ Cómo Usar

### Opción A: Copiar desde archivo SQL limpio
1. Abre `docs/STORED_PROCEDURES_CLEAN.sql`
2. Selecciona todo (Ctrl+A)
3. Copia (Ctrl+C)
4. Pega en MySQL Workbench
5. Ejecuta (Ctrl+Shift+Enter)

### Opción B: Copiar SP uno por uno
1. Abre `docs/STORED_PROCEDURES.md` en el editor
2. Busca el SP que necesitas
3. Copia el bloque SQL (entre ````sql` y `````)
4. Pega en MySQL Workbench
5. Ejecuta

---

## 🧪 Testing de Sintaxis

Puedes verificar que un SP está bien creado:

```sql
-- Ver si el SP se creó
SHOW PROCEDURE STATUS WHERE Name = 'sp_create_sale';

-- Ver código del SP
SHOW CREATE PROCEDURE sp_create_sale;

-- Llamar el SP para probar
CALL sp_create_sale(
    'TK-001',
    'c-001',
    'Juan',
    'cash',
    100.00,
    110.00,
    110.00,
    0.00,
    JSON_OBJECT('item', 'test'),
    'req-123',
    'cajero1',
    @success,
    @msg,
    @ticket
);

-- Ver resultados
SELECT @success, @msg, @ticket;
```

---

## 📞 Si Sigues Teniendo Errores

**Error típico:** `Syntax error near 'LEAVE'`
- ✅ Verifica que hayas copiado el DELIMITER correcto
- ✅ Asegúrate que el label coincida: `proc_name: BEGIN ... END proc_name`

**Error típico:** `Unknown table 'sales'`
- ✅ Primero crea las tablas (ver sección "Estructura de Tablas" en STORED_PROCEDURES.md)
- ✅ Verifica que estés en la BD correcta: `USE pos_data;`

**Error típico:** `OUT parameter of ...`
- ✅ Verifica que sea `OUT` (no `IN OUT`)
- ✅ Verifica el tipo: `TINYINT(1)` para booleans, no `BOOLEAN`

---

## ✨ Próximos Pasos

1. ✅ Copia todos los SPs desde `STORED_PROCEDURES_CLEAN.sql`
2. ✅ Ejecuta en MySQL Workbench
3. ✅ Verifica que la creación fue exitosa
4. ✅ Prueba cada SP con datos de ejemplo
5. ✅ Confirma que el usuario BD tiene permisos EXECUTE

---

**Documento actualizado:** 2024-01-XX  
**Estado:** Listo para producción
