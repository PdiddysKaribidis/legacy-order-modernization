-- Demo data. Customers and e-mails are fictional.

INSERT INTO orders (id, customer_name, customer_email, status, created_at) VALUES
    (1, 'ООО «Ромашка»', 'zakupki@romashka.example', 'new', '2026-10-20 10:15:00'),
    (2, 'Иван Петров', 'ivan.petrov@example.com', 'paid', '2026-10-21 12:40:00'),
    (3, 'ИП Сидорова', '', 'shipped', '2026-10-22 09:05:00'),
    (4, 'Мария Козлова', 'm.kozlova@example.com', 'cancelled', '2026-10-23 16:30:00');

INSERT INTO order_items (order_id, product_name, quantity, price) VALUES
    (1, 'Бумага А4, 500 листов', 10, 389.90),
    (1, 'Картридж HP 59A', 2, 7450.00),
    (2, 'Кресло офисное', 1, 12990.00),
    (3, 'Степлер', 3, 455.50),
    (4, 'Монитор 27"', 1, 18500.00);
