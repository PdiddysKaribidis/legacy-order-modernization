-- Brings a database created from legacy/schema.sql to database/schema.sql (MySQL / MariaDB).
-- Run the checks first: adding the foreign key fails while orphaned items exist.

-- 1. Items that point to a missing order. Must return no rows before step 3.
SELECT i.id, i.order_id
FROM order_items i
LEFT JOIN orders o ON o.id = i.order_id
WHERE o.id IS NULL;

-- 2. Data the new version shows but cannot change. Not blocking; review and fix by hand.
--    Unknown statuses came from the legacy status form, which accepted any value.
SELECT id, status FROM orders WHERE status NOT IN ('new', 'paid', 'shipped', 'cancelled');
--    Negative prices came from the legacy item form, which saved "-10" as is.
SELECT id, order_id, price FROM order_items WHERE price < 0;

-- 3. Schema changes. Columns are not changed, so the legacy pages keep working.
ALTER TABLE orders ADD INDEX idx_orders_status (status);
ALTER TABLE order_items ADD CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders (id);
