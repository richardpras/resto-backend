-- Pre-flight checks before running `outlets` PK migration (MySQL).
-- Run on a STAGING copy of production; review results before migrate.

-- 1) List current string outlet keys
-- SELECT id AS legacy_outlet_key, name FROM outlets ORDER BY id;

-- 2) Distinct numeric outlet_id values used in transactional tables (union)
/*
SELECT DISTINCT outlet_id AS numeric_outlet_id FROM orders WHERE outlet_id IS NOT NULL
UNION
SELECT DISTINCT outlet_id FROM ingredients WHERE outlet_id IS NOT NULL
UNION
SELECT DISTINCT outlet_id FROM menu_items WHERE outlet_id IS NOT NULL
UNION
SELECT DISTINCT outlet_id FROM inventory_stocks WHERE outlet_id IS NOT NULL
UNION
SELECT DISTINCT outlet_id FROM purchase_requests WHERE outlet_id IS NOT NULL
UNION
SELECT DISTINCT outlet_id FROM purchase_orders WHERE outlet_id IS NOT NULL
UNION
SELECT DISTINCT outlet_id FROM goods_receiving_notes WHERE outlet_id IS NOT NULL
UNION
SELECT DISTINCT outlet_id FROM purchase_invoices WHERE outlet_id IS NOT NULL
UNION
SELECT DISTINCT outlet_id FROM stock_movements WHERE outlet_id IS NOT NULL
UNION
SELECT DISTINCT outlet_id FROM print_jobs WHERE outlet_id IS NOT NULL
ORDER BY numeric_outlet_id;
*/

-- 3) Orphan transaction outlet_ids (appear in transactions but not in planned mapping)
-- Replace <mapped_ids> with the set of bigint keys you expect after migration.

/*
SELECT t.numeric_outlet_id FROM (
  -- same UNION as above as subquery
  SELECT DISTINCT outlet_id AS numeric_outlet_id FROM orders WHERE outlet_id IS NOT NULL
) t
LEFT JOIN outlets o ON o._placeholder = 1
WHERE 0 = 1;
*/

-- Manual: every `outlets.id` (string) must map to exactly one positive bigint.
-- Non-numeric legacy keys require config/outlet_bridge.by_key until this migration runs.
