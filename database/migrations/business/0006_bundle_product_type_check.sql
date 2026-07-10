PRAGMA writable_schema = ON;

UPDATE sqlite_schema
SET sql = replace(
    sql,
    "type TEXT NOT NULL CHECK(type IN ('physical','service','gift_card'))",
    "type TEXT NOT NULL CHECK(type IN ('physical','service','gift_card','bundle'))"
)
WHERE type = 'table'
  AND name = 'business_products'
  AND sql LIKE "%type TEXT NOT NULL CHECK(type IN ('physical','service','gift_card'))%";

UPDATE sqlite_schema
SET sql = replace(
    sql,
    "product_type TEXT NOT NULL DEFAULT 'physical' CHECK(product_type IN ('physical','service','gift_card'))",
    "product_type TEXT NOT NULL DEFAULT 'physical' CHECK(product_type IN ('physical','service','gift_card','bundle'))"
)
WHERE type = 'table'
  AND name = 'business_catalog_products'
  AND sql LIKE "%product_type TEXT NOT NULL DEFAULT 'physical' CHECK(product_type IN ('physical','service','gift_card'))%";

PRAGMA writable_schema = OFF;
