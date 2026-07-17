CREATE TRIGGER IF NOT EXISTS trg_sale_stock_movements_no_update
BEFORE UPDATE ON sale_stock_movements
BEGIN SELECT RAISE(ABORT, 'sale stock movements are immutable'); END;

CREATE TRIGGER IF NOT EXISTS trg_sale_stock_movements_no_delete
BEFORE DELETE ON sale_stock_movements
BEGIN SELECT RAISE(ABORT, 'sale stock movements are immutable'); END;
