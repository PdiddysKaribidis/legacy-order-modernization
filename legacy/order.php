<?php
require 'db.php';
require 'functions.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$order = get_order($id);

if (!$order) {
    header('HTTP/1.0 404 Not Found');
    echo 'Заказ не найден';
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'];

    if ($action == 'add_item') {
        $product = trim($_POST['product_name']);
        $qty = (int)$_POST['quantity'];
        $price = (float)str_replace(',', '.', $_POST['price']);

        if ($product == '' || $qty <= 0) {
            $error = 'Укажите товар и количество';
        } else {
            $stmt = $pdo->prepare('INSERT INTO order_items (order_id, product_name, quantity, price) VALUES (?, ?, ?, ?)');
            $stmt->execute(array($id, $product, $qty, $price));

            redirect('order.php?id=' . $id . '&msg=item_added');
        }
    } elseif ($action == 'change_status') {
        $stmt = $pdo->prepare('UPDATE orders SET status = ? WHERE id = ?');
        $stmt->execute(array($_POST['status'], $id));

        redirect('order.php?id=' . $id . '&msg=status_changed');
    } elseif ($action == 'cancel') {
        if ($order['status'] == 'shipped') {
            $error = 'Отправленный заказ нельзя отменить';
        } else {
            $stmt = $pdo->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ?");
            $stmt->execute(array($id));

            redirect('order.php?id=' . $id . '&msg=cancelled');
        }
    }
}

$items = get_order_items($id);

$sum = 0;
foreach ($items as $item) {
    $sum = $sum + $item['price'] * $item['quantity'];
}

// скидка 5% при сумме от 10 000 руб.
$discount = 0;
if ($sum >= 10000) {
    $discount = round($sum * 0.05, 2);
}
$total = $sum - $discount;

switch ($order['status']) {
    case 'new':
        $status_name = 'Новый';
        break;
    case 'paid':
        $status_name = 'Оплачен';
        break;
    case 'shipped':
        $status_name = 'Отправлен';
        break;
    case 'cancelled':
        $status_name = 'Отменён';
        break;
    default:
        $status_name = $order['status'];
}

$messages = array(
    'created' => 'Заказ создан',
    'item_added' => 'Позиция добавлена',
    'status_changed' => 'Статус изменён',
    'cancelled' => 'Заказ отменён',
);
$msg = isset($_GET['msg']) && isset($messages[$_GET['msg']]) ? $messages[$_GET['msg']] : '';

include 'header.php';
?>
<h1>Заказ #<?php echo $order['id']; ?></h1>

<?php if ($msg != '') { ?>
    <p class="message"><?php echo $msg; ?></p>
<?php } ?>
<?php if ($error != '') { ?>
    <p class="error"><?php echo $error; ?></p>
<?php } ?>

<p>Клиент: <?php echo h($order['customer_name']); ?> (<?php echo h($order['customer_email']); ?>)</p>
<p>Статус: <span class="status"><?php echo h($status_name); ?></span></p>
<p>Создан: <?php echo date('d.m.Y H:i', strtotime($order['created_at'])); ?></p>

<table>
    <tr>
        <th>Товар</th>
        <th>Кол-во</th>
        <th>Цена</th>
        <th>Сумма</th>
    </tr>
    <?php foreach ($items as $item) { ?>
        <tr>
            <td><?php echo h($item['product_name']); ?></td>
            <td><?php echo $item['quantity']; ?></td>
            <td><?php echo number_format($item['price'], 2, ',', ' '); ?></td>
            <td><?php echo number_format($item['price'] * $item['quantity'], 2, ',', ' '); ?></td>
        </tr>
    <?php } ?>
</table>

<p>Сумма: <span class="subtotal"><?php echo number_format($sum, 2, ',', ' '); ?></span> руб.</p>
<?php if ($discount > 0) { ?>
    <p>Скидка: <span class="discount"><?php echo number_format($discount, 2, ',', ' '); ?></span> руб.</p>
<?php } ?>
<p class="total">Итого: <?php echo number_format($total, 2, ',', ' '); ?> руб.</p>

<?php if ($order['status'] == 'new') { ?>
    <h2>Добавить позицию</h2>
    <form method="post" action="order.php?id=<?php echo $order['id']; ?>">
        <input type="hidden" name="action" value="add_item">
        <p><label>Товар<br><input type="text" name="product_name"></label></p>
        <p><label>Количество<br><input type="text" name="quantity" value="1"></label></p>
        <p><label>Цена, руб.<br><input type="text" name="price"></label></p>
        <button type="submit">Добавить</button>
    </form>
<?php } ?>

<h2>Статус</h2>
<form method="post" action="order.php?id=<?php echo $order['id']; ?>">
    <input type="hidden" name="action" value="change_status">
    <select name="status">
        <option value="new">Новый</option>
        <option value="paid">Оплачен</option>
        <option value="shipped">Отправлен</option>
        <option value="cancelled">Отменён</option>
    </select>
    <button type="submit">Сохранить</button>
</form>

<?php if ($order['status'] != 'cancelled' && $order['status'] != 'shipped') { ?>
    <form method="post" action="order.php?id=<?php echo $order['id']; ?>">
        <input type="hidden" name="action" value="cancel">
        <button type="submit" onclick="return confirm('Отменить заказ?')">Отменить заказ</button>
    </form>
<?php } ?>
<?php include 'footer.php'; ?>
