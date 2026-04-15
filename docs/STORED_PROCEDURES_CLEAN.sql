-- ============================================================
-- STORED PROCEDURES - POS System
-- Versión: 1.0
-- Copiar y pegar directamente en MySQL Workbench
-- ============================================================

-- ============================================================
-- SALES (Ventas)
-- ============================================================

DELIMITER //

CREATE PROCEDURE sp_create_sale (
    IN p_ticketId VARCHAR(50),
    IN p_customerId VARCHAR(50),
    IN p_customerName VARCHAR(100),
    IN p_paymentMethod VARCHAR(20),
    IN p_subtotal DECIMAL(10, 2),
    IN p_total DECIMAL(10, 2),
    IN p_paidWith DECIMAL(10, 2),
    IN p_change DECIMAL(10, 2),
    IN p_itemsJson JSON,
    IN p_clientRequestId VARCHAR(100),
    IN p_cashier VARCHAR(100),
    OUT OUT_success TINYINT(1),
    OUT OUT_message VARCHAR(255),
    OUT OUT_ticketId VARCHAR(50)
)
proc_create_sale: BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        SET OUT_success = 0;
        SET OUT_message = 'Error al registrar venta';
        SET OUT_ticketId = NULL;
    END;
    
    IF EXISTS(SELECT 1 FROM sales WHERE clientRequestId = p_clientRequestId) THEN
        SET OUT_success = 1;
        SET OUT_message = 'Venta duplicada (ya registrada)';
        SET OUT_ticketId = p_ticketId;
        LEAVE proc_create_sale;
    END IF;
    
    INSERT INTO sales (
        ticketId, customerId, customerName, paymentMethod,
        subtotal, total, paidWith, `change`,
        itemsJson, clientRequestId, cashier, createdAt
    ) VALUES (
        p_ticketId, p_customerId, p_customerName, p_paymentMethod,
        p_subtotal, p_total, p_paidWith, p_change,
        p_itemsJson, p_clientRequestId, p_cashier, NOW()
    );
    
    SET OUT_success = 1;
    SET OUT_message = 'Venta registrada';
    SET OUT_ticketId = p_ticketId;
END proc_create_sale//

DELIMITER ;

-- ============================================================

DELIMITER //

CREATE PROCEDURE sp_get_sales_by_day (
    IN p_date DATE,
    IN p_search VARCHAR(100),
    IN p_page INT,
    IN p_pageSize INT,
    OUT OUT_total INT
)
proc_get_sales: BEGIN
    DECLARE v_offset INT;
    SET v_offset = (p_page - 1) * p_pageSize;
    
    SELECT COUNT(*) INTO OUT_total FROM sales
    WHERE DATE(createdAt) = p_date
    AND (
        p_search = '' OR
        ticketId LIKE CONCAT('%', p_search, '%') OR
        customerName LIKE CONCAT('%', p_search, '%')
    );
    
    SELECT 
        id, ticketId, customerId, customerName,
        paymentMethod, subtotal, total, paidWith,
        `change`, itemsJson, clientRequestId,
        cashier, createdAt
    FROM sales
    WHERE DATE(createdAt) = p_date
    AND (
        p_search = '' OR
        ticketId LIKE CONCAT('%', p_search, '%') OR
        customerName LIKE CONCAT('%', p_search, '%')
    )
    ORDER BY createdAt DESC
    LIMIT v_offset, p_pageSize;
END proc_get_sales//

DELIMITER ;

-- ============================================================

DELIMITER //

CREATE PROCEDURE sp_get_last_sale ()
proc_get_last: BEGIN
    SELECT 
        id, ticketId, customerId, customerName,
        paymentMethod, subtotal, total, paidWith,
        `change`, itemsJson, clientRequestId,
        cashier, createdAt
    FROM sales
    ORDER BY createdAt DESC
    LIMIT 1;
END proc_get_last//

DELIMITER ;

-- ============================================================

DELIMITER //

CREATE PROCEDURE sp_return_item (
    IN p_ticketId VARCHAR(50),
    IN p_itemId VARCHAR(50),
    IN p_qty INT,
    IN p_reason VARCHAR(255),
    OUT OUT_success TINYINT(1),
    OUT OUT_message VARCHAR(255),
    OUT OUT_availableAfter INT
)
proc_return: BEGIN
    DECLARE v_soldQty INT;
    DECLARE v_alreadyReturned INT;
    DECLARE v_available INT;
    DECLARE v_saleId INT;
    
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        SET OUT_success = 0;
        SET OUT_message = 'Error al procesar devolución';
    END;
    
    SELECT id INTO v_saleId FROM sales WHERE ticketId = p_ticketId LIMIT 1;
    IF v_saleId IS NULL THEN
        SET OUT_success = 0;
        SET OUT_message = 'Ticket no encontrado';
        LEAVE proc_return;
    END IF;
    
    INSERT INTO returns (
        saleId, itemId, qty, reason, createdAt
    ) VALUES (
        v_saleId, p_itemId, p_qty, p_reason, NOW()
    );
    
    SET OUT_success = 1;
    SET OUT_message = 'Devolución registrada';
    SET OUT_availableAfter = COALESCE(v_available - p_qty, 0);
END proc_return//

DELIMITER ;

-- ============================================================

DELIMITER //

CREATE PROCEDURE sp_get_sale_by_ticket (
    IN p_ticketId VARCHAR(50)
)
proc_get_ticket: BEGIN
    SELECT 
        id, ticketId, customerId, customerName,
        paymentMethod, subtotal, total, paidWith,
        `change`, itemsJson, clientRequestId,
        cashier, createdAt
    FROM sales
    WHERE ticketId = p_ticketId
    LIMIT 1;
END proc_get_ticket//

DELIMITER ;

-- ============================================================
-- INVENTORY (Inventario)
-- ============================================================

DELIMITER //

CREATE PROCEDURE sp_get_products_report (
    IN p_department VARCHAR(100),
    IN p_page INT,
    IN p_pageSize INT,
    OUT OUT_totalCost DECIMAL(15, 2),
    OUT OUT_totalStock INT,
    OUT OUT_total INT
)
proc_report: BEGIN
    DECLARE v_offset INT;
    SET v_offset = (p_page - 1) * p_pageSize;
    
    SELECT 
        COUNT(*),
        COALESCE(SUM(cost * stock), 0),
        COALESCE(SUM(stock), 0)
    INTO OUT_total, OUT_totalCost, OUT_totalStock
    FROM products
    WHERE inventoryEnabled = TRUE
    AND unitType != 'package'
    AND (
        p_department = '' OR
        COALESCE(department, 'Sin Departamento') = p_department
    );
    
    SELECT 
        id, name, sku, department, `cost`, sellingPrice,
        stock, unitType, inventoryEnabled, createdAt
    FROM products
    WHERE inventoryEnabled = TRUE
    AND unitType != 'package'
    AND (
        p_department = '' OR
        COALESCE(department, 'Sin Departamento') = p_department
    )
    ORDER BY name ASC
    LIMIT v_offset, p_pageSize;
END proc_report//

DELIMITER ;

-- ============================================================

DELIMITER //

CREATE PROCEDURE sp_get_products ()
proc_products: BEGIN
    SELECT 
        id, name, sku, department, `cost`,
        sellingPrice, stock, unitType, inventoryEnabled
    FROM products
    WHERE inventoryEnabled = TRUE
    ORDER BY department, name ASC;
END proc_products//

DELIMITER ;

-- ============================================================

DELIMITER //

CREATE PROCEDURE sp_update_product (
    IN p_id VARCHAR(50),
    IN p_name VARCHAR(255),
    IN p_sku VARCHAR(50),
    IN p_department VARCHAR(100),
    IN p_cost DECIMAL(10, 2),
    IN p_sellingPrice DECIMAL(10, 2),
    IN p_stock INT,
    IN p_unitType VARCHAR(20),
    OUT OUT_success TINYINT(1),
    OUT OUT_message VARCHAR(255)
)
proc_update: BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        SET OUT_success = 0;
        SET OUT_message = 'Error al actualizar producto';
    END;
    
    UPDATE products SET
        `name` = p_name,
        sku = p_sku,
        department = p_department,
        cost = p_cost,
        sellingPrice = p_sellingPrice,
        stock = p_stock,
        unitType = p_unitType,
        updatedAt = NOW()
    WHERE id = p_id;
    
    SET OUT_success = 1;
    SET OUT_message = 'Producto actualizado';
END proc_update//

DELIMITER ;

-- ============================================================

DELIMITER //

CREATE PROCEDURE sp_add_inventory_movement (
    IN p_type VARCHAR(50),
    IN p_productId VARCHAR(50),
    IN p_productName VARCHAR(255),
    IN p_delta INT,
    IN p_before INT,
    IN p_after INT,
    IN p_note VARCHAR(500),
    IN p_source VARCHAR(50),
    OUT OUT_success TINYINT(1),
    OUT OUT_message VARCHAR(255)
)
proc_movement: BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        SET OUT_success = 0;
        SET OUT_message = 'Error al registrar movimiento';
    END;
    
    INSERT INTO inventoryMovements (
        `type`, productId, productName, delta,
        beforeStock, afterStock, note, source, createdAt
    ) VALUES (
        p_type, p_productId, p_productName, p_delta,
        p_before, p_after, p_note, p_source, NOW()
    );
    
    SET OUT_success = 1;
    SET OUT_message = 'Movimiento registrado';
END proc_movement//

DELIMITER ;

-- ============================================================

DELIMITER //

CREATE PROCEDURE sp_get_product_by_id (
    IN p_id VARCHAR(50)
)
proc_get_product: BEGIN
    SELECT 
        id, name, sku, department, `cost`,
        sellingPrice, stock, unitType, inventoryEnabled,
        createdAt, updatedAt
    FROM products
    WHERE id = p_id
    LIMIT 1;
END proc_get_product//

DELIMITER ;

-- ============================================================
-- CUSTOMERS (Clientes)
-- ============================================================

DELIMITER //

CREATE PROCEDURE sp_get_customers (
    IN p_search VARCHAR(100),
    IN p_page INT,
    IN p_pageSize INT,
    OUT OUT_total INT
)
proc_get_customers: BEGIN
    DECLARE v_offset INT;
    SET v_offset = (p_page - 1) * p_pageSize;
    
    SELECT COUNT(*) INTO OUT_total FROM customers
    WHERE p_search = '' OR
          name LIKE CONCAT('%', p_search, '%') OR
          phone LIKE CONCAT('%', p_search, '%') OR
          email LIKE CONCAT('%', p_search, '%');
    
    SELECT 
        id, name, phone, email, address,
        city, creditLimit, totalDebt, createdAt
    FROM customers
    WHERE p_search = '' OR
          name LIKE CONCAT('%', p_search, '%') OR
          phone LIKE CONCAT('%', p_search, '%') OR
          email LIKE CONCAT('%', p_search, '%')
    ORDER BY name ASC
    LIMIT v_offset, p_pageSize;
END proc_get_customers//

DELIMITER ;

-- ============================================================

DELIMITER //

CREATE PROCEDURE sp_create_customer (
    IN p_name VARCHAR(255),
    IN p_phone VARCHAR(20),
    IN p_email VARCHAR(100),
    IN p_address VARCHAR(255),
    IN p_city VARCHAR(100),
    IN p_creditLimit DECIMAL(10, 2),
    OUT OUT_id VARCHAR(50),
    OUT OUT_success TINYINT(1),
    OUT OUT_message VARCHAR(255)
)
proc_create_customer: BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        SET OUT_success = 0;
        SET OUT_message = 'Error al crear cliente';
        SET OUT_id = NULL;
    END;
    
    SET OUT_id = CONCAT('c-', UNIX_TIMESTAMP(), '-', LPAD(FLOOR(RAND() * 10000), 4, '0'));
    
    INSERT INTO customers (
        id, `name`, phone, email, address,
        city, creditLimit, totalDebt, createdAt
    ) VALUES (
        OUT_id, p_name, p_phone, p_email, p_address,
        p_city, p_creditLimit, 0, NOW()
    );
    
    SET OUT_success = 1;
    SET OUT_message = 'Cliente creado';
END proc_create_customer//

DELIMITER ;

-- ============================================================

DELIMITER //

CREATE PROCEDURE sp_update_customer (
    IN p_id VARCHAR(50),
    IN p_name VARCHAR(255),
    IN p_phone VARCHAR(20),
    IN p_email VARCHAR(100),
    IN p_address VARCHAR(255),
    IN p_city VARCHAR(100),
    IN p_creditLimit DECIMAL(10, 2),
    OUT OUT_success TINYINT(1),
    OUT OUT_message VARCHAR(255)
)
proc_update_customer: BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        SET OUT_success = 0;
        SET OUT_message = 'Error al actualizar cliente';
    END;
    
    UPDATE customers SET
        `name` = p_name,
        phone = p_phone,
        email = p_email,
        address = p_address,
        city = p_city,
        creditLimit = p_creditLimit,
        updatedAt = NOW()
    WHERE id = p_id;
    
    SET OUT_success = 1;
    SET OUT_message = 'Cliente actualizado';
END proc_update_customer//

DELIMITER ;

-- ============================================================

DELIMITER //

CREATE PROCEDURE sp_get_customer_by_id (
    IN p_id VARCHAR(50)
)
proc_get_customer_id: BEGIN
    SELECT 
        id, name, phone, email, address,
        city, creditLimit, totalDebt, createdAt, updatedAt
    FROM customers
    WHERE id = p_id
    LIMIT 1;
END proc_get_customer_id//

DELIMITER ;

-- ============================================================
-- DEPARTMENTS (Departamentos)
-- ============================================================

DELIMITER //

CREATE PROCEDURE sp_get_departments ()
proc_get_depts: BEGIN
    SELECT 
        id, `name`, description, createdAt
    FROM departments
    ORDER BY `name` ASC;
END proc_get_depts//

DELIMITER ;

-- ============================================================

DELIMITER //

CREATE PROCEDURE sp_create_department (
    IN p_name VARCHAR(100),
    IN p_description VARCHAR(255),
    OUT OUT_id INT,
    OUT OUT_success TINYINT(1),
    OUT OUT_message VARCHAR(255)
)
proc_create_dept: BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        SET OUT_success = 0;
        SET OUT_message = 'Error al crear departamento';
        SET OUT_id = NULL;
    END;
    
    INSERT INTO departments (`name`, description, createdAt)
    VALUES (p_name, p_description, NOW());
    
    SET OUT_id = LAST_INSERT_ID();
    SET OUT_success = 1;
    SET OUT_message = 'Departamento creado';
END proc_create_dept//

DELIMITER ;

-- ============================================================

DELIMITER //

CREATE PROCEDURE sp_update_department (
    IN p_id INT,
    IN p_name VARCHAR(100),
    IN p_description VARCHAR(255),
    OUT OUT_success TINYINT(1),
    OUT OUT_message VARCHAR(255)
)
proc_update_dept: BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        SET OUT_success = 0;
        SET OUT_message = 'Error al actualizar departamento';
    END;
    
    UPDATE departments SET
        `name` = p_name,
        description = p_description,
        updatedAt = NOW()
    WHERE id = p_id;
    
    SET OUT_success = 1;
    SET OUT_message = 'Departamento actualizado';
END proc_update_dept//

DELIMITER ;

-- ============================================================
-- PURCHASES (Compras)
-- ============================================================

DELIMITER //

CREATE PROCEDURE sp_get_purchases_by_day (
    IN p_date DATE
)
proc_get_purchases: BEGIN
    SELECT 
        id, purchaseNumber, supplierId, supplierName,
        total, paymentMethod, notes, createdAt
    FROM purchases
    WHERE DATE(createdAt) = p_date
    ORDER BY createdAt DESC;
END proc_get_purchases//

DELIMITER ;

-- ============================================================

DELIMITER //

CREATE PROCEDURE sp_create_purchase (
    IN p_purchaseNumber VARCHAR(50),
    IN p_supplierId VARCHAR(50),
    IN p_supplierName VARCHAR(255),
    IN p_itemsJson JSON,
    IN p_total DECIMAL(10, 2),
    IN p_paymentMethod VARCHAR(20),
    IN p_notes VARCHAR(500),
    OUT OUT_success TINYINT(1),
    OUT OUT_message VARCHAR(255)
)
proc_create_purchase: BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        SET OUT_success = 0;
        SET OUT_message = 'Error al registrar compra';
    END;
    
    INSERT INTO purchases (
        purchaseNumber, supplierId, supplierName,
        itemsJson, total, paymentMethod, notes, createdAt
    ) VALUES (
        p_purchaseNumber, p_supplierId, p_supplierName,
        p_itemsJson, p_total, p_paymentMethod, p_notes, NOW()
    );
    
    SET OUT_success = 1;
    SET OUT_message = 'Compra registrada';
END proc_create_purchase//

DELIMITER ;

-- ============================================================
-- CREDITS (Créditos)
-- ============================================================

DELIMITER //

CREATE PROCEDURE sp_get_customer_credits (
    IN p_customerId VARCHAR(50)
)
proc_get_credits: BEGIN
    SELECT 
        id, customerId, saleId, saleTicketId,
        originalAmount, remainingBalance, dueDate,
        status, createdAt, updatedAt
    FROM credits
    WHERE customerId = p_customerId
    AND status != 'closed'
    ORDER BY createdAt DESC;
END proc_get_credits//

DELIMITER ;

-- ============================================================

DELIMITER //

CREATE PROCEDURE sp_register_credit (
    IN p_customerId VARCHAR(50),
    IN p_saleId INT,
    IN p_saleTicketId VARCHAR(50),
    IN p_amount DECIMAL(10, 2),
    IN p_dueDate DATE,
    OUT OUT_success TINYINT(1),
    OUT OUT_message VARCHAR(255)
)
proc_register_credit: BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        SET OUT_success = 0;
        SET OUT_message = 'Error al registrar crédito';
    END;
    
    INSERT INTO credits (
        customerId, saleId, saleTicketId,
        originalAmount, remainingBalance, dueDate,
        status, createdAt
    ) VALUES (
        p_customerId, p_saleId, p_saleTicketId,
        p_amount, p_amount, p_dueDate,
        'active', NOW()
    );
    
    SET OUT_success = 1;
    SET OUT_message = 'Crédito registrado';
END proc_register_credit//

DELIMITER ;

-- ============================================================

DELIMITER //

CREATE PROCEDURE sp_get_credits_report (
    IN p_status VARCHAR(20),
    IN p_customerId VARCHAR(50),
    IN p_page INT,
    IN p_pageSize INT,
    OUT OUT_total INT,
    OUT OUT_totalAmount DECIMAL(15, 2)
)
proc_credits_report: BEGIN
    DECLARE v_offset INT;
    SET v_offset = (p_page - 1) * p_pageSize;
    
    SELECT 
        COUNT(*),
        COALESCE(SUM(remainingBalance), 0)
    INTO OUT_total, OUT_totalAmount
    FROM credits
    WHERE (p_status = '' OR status = p_status)
    AND (p_customerId = '' OR customerId = p_customerId);
    
    SELECT 
        id, customerId, saleTicketId, originalAmount,
        remainingBalance, dueDate, status, createdAt
    FROM credits
    WHERE (p_status = '' OR status = p_status)
    AND (p_customerId = '' OR customerId = p_customerId)
    ORDER BY createdAt DESC
    LIMIT v_offset, p_pageSize;
END proc_credits_report//

DELIMITER ;

-- ============================================================
-- END OF STORED PROCEDURES
-- ============================================================
