PRAGMA writable_schema = ON;

UPDATE sqlite_schema
SET sql = replace(
    sql,
    "product_type TEXT NOT NULL DEFAULT 'physical' CHECK(product_type IN ('physical','service','gift_card','digital','other'))",
    "product_type TEXT NOT NULL DEFAULT 'physical' CHECK(product_type IN ('physical','service','gift_card','digital','bundle','other'))"
)
WHERE type = 'table'
  AND name = 'sale_catalog_variant_refs'
  AND sql LIKE "%product_type TEXT NOT NULL DEFAULT 'physical' CHECK(product_type IN ('physical','service','gift_card','digital','other'))%";

UPDATE sqlite_schema
SET sql = replace(
    sql,
    "product_type TEXT NOT NULL DEFAULT 'physical' CHECK(product_type IN ('physical','service','gift_card','digital','other'))",
    "product_type TEXT NOT NULL DEFAULT 'physical' CHECK(product_type IN ('physical','service','gift_card','digital','bundle','other'))"
)
WHERE type = 'table'
  AND name = 'sale_order_lines'
  AND sql LIKE "%product_type TEXT NOT NULL DEFAULT 'physical' CHECK(product_type IN ('physical','service','gift_card','digital','other'))%";

UPDATE sqlite_schema
SET sql = replace(
    sql,
    "product_type TEXT NOT NULL DEFAULT 'physical' CHECK(product_type IN ('physical','service','gift_card','digital','other'))",
    "product_type TEXT NOT NULL DEFAULT 'physical' CHECK(product_type IN ('physical','service','gift_card','digital','bundle','other'))"
)
WHERE type = 'table'
  AND name = 'sale_cart_lines'
  AND sql LIKE "%product_type TEXT NOT NULL DEFAULT 'physical' CHECK(product_type IN ('physical','service','gift_card','digital','other'))%";

PRAGMA writable_schema = OFF;
