<?php
require 'db.php';
require 'functions.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = trim($_POST['customer_name']);
    $email = trim($_POST['customer_email']);

    if ($name == '') {
        $error = 'Укажите имя клиента';
    } else {
        $stmt = $pdo->prepare('INSERT INTO orders (customer_name, customer_email, status, created_at) VALUES (?, ?, ?, ?)');
        $stmt->execute(array($name, $email, 'new', date('Y-m-d H:i:s')));

        redirect('order.php?id=' . $pdo->lastInsertId() . '&msg=created');
    }
}

$status = isset($_GET['status']) ? $_GET['status'] : '';

if ($status != '') {
    $stmt = $pdo->prepare('SELECT * FROM orders WHERE status = ? ORDER BY id DESC');
    $stmt->execute(array($status));
} else {
    $stmt = $pdo->query('SELECT * FROM orders ORDER BY id DESC');
}
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

$statuses = array(
    'new' => 'Новый',
    'paid' => 'Оплачен',
    'shipped' => 'Отправлен',
    'cancelled' => 'Отменён',
);

include 'header.php';
?>
<h1>Заказы</h1>

<form method="get" action="orders.php">
    <select name="status">
        <option value="">Все статусы</option>
        <?php foreach ($statuses as $key => $label) { ?>
            <option value="<?php echo $key; ?>"<?php if ($key == $status) echo ' selected'; ?>><?php echo $label; ?></option>
        <?php } ?>
    </select>
    <button type="submit">Показать</button>
</form>

<table>
    <tr>
        <th>Номер</th>
        <th>Клиент</th>
        <th>Статус</th>
        <th>Сумма</th>
        <th>Создан</th>
    </tr>
    <?php
    foreach ($orders as $order) {
        $items = get_order_items($order['id']);
        $total = 0;
        foreach ($items as $item) {
            $total = $total + $item['price'] * $item['quantity'];
        }
        // скидка 5% на крупные заказы
        if ($total > 10000) {
            $total = $total * 0.95;
        }
        ?>
        <tr>
            <td><a href="order.php?id=<?php echo $order['id']; ?>">#<?php echo $order['id']; ?></a></td>
            <td><?php echo h($order['customer_name']); ?></td>
            <td><?php echo $statuses[$order['status']]; ?></td>
            <td class="total"><?php echo number_format($total, 2, ',', ' '); ?> руб.</td>
            <td><?php echo date('d.m.Y H:i', strtotime($order['created_at'])); ?></td>
        </tr>
    <?php } ?>
</table>

<h2>Новый заказ</h2>
<?php if ($error != '') { ?>
    <p class="error"><?php echo $error; ?></p>
<?php } ?>
<form method="post" action="orders.php">
    <p><label>Имя клиента<br><input type="text" name="customer_name"></label></p>
    <p><label>E-mail<br><input type="text" name="customer_email"></label></p>
    <button type="submit">Создать</button>
</form>
<?php include 'footer.php'; ?>
