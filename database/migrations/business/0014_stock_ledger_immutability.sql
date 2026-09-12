CREATE TRIGGER IF NOT EXISTS trg_business_stock_movements_no_update
BEFORE UPDATE ON business_stock_movements
BEGIN SELECT RAISE(ABORT, 'business stock movements are immutable'); END;

CREATE TRIGGER IF NOT EXISTS trg_business_stock_movements_no_delete
BEFORE DELETE ON business_stock_movements
BEGIN SELECT RAISE(ABORT, 'business stock movements are immutable'); END;
