PRAGMA writable_schema = ON;

UPDATE sqlite_schema
SET sql = replace(
    sql,
    "adjustment_type TEXT NOT NULL CHECK(adjustment_type IN ('discount','manual_discount','promotion','rounding'))",
    "adjustment_type TEXT NOT NULL CHECK(adjustment_type IN ('discount','manual_discount','promotion','rounding','surcharge'))"
)
WHERE type = 'table'
  AND name IN ('sale_order_adjustments', 'sale_cart_adjustments')
  AND sql LIKE "%adjustment_type TEXT NOT NULL CHECK(adjustment_type IN ('discount','manual_discount','promotion','rounding'))%";

PRAGMA writable_schema = OFF;
