SET @schema_name = DATABASE();

SET @add_fecha_pago = (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = @schema_name
              AND TABLE_NAME = 'CLIENTESV2_CREDITO'
              AND COLUMN_NAME = 'FECHA_PAGO'
        ),
        'SELECT 1',
        'ALTER TABLE CLIENTESV2_CREDITO ADD COLUMN FECHA_PAGO VARCHAR(10) NULL AFTER ELIMINADO_EN'
    )
);
PREPARE stmt FROM @add_fecha_pago;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @add_dia_pago = (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = @schema_name
              AND TABLE_NAME = 'CLIENTESV2_CREDITO'
              AND COLUMN_NAME = 'DIA_PAGO'
        ),
        'SELECT 1',
        'ALTER TABLE CLIENTESV2_CREDITO ADD COLUMN DIA_PAGO TINYINT NULL AFTER FECHA_PAGO'
    )
);
PREPARE stmt FROM @add_dia_pago;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
