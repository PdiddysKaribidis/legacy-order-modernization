<?php
/** @var App\Http\View $this */
/** @var list<App\Order\Order> $orders */
/** @var ?App\Order\OrderStatus $filter */
/** @var array<string, string> $errors */
/** @var array<string, mixed> $old */
/** @var string $csrfToken */

use App\Order\OrderStatus;

?>
<h1>Заказы</h1>

<form method="get" action="orders.php">
    <select name="status">
        <option value="">Все статусы</option>
        <?php foreach (OrderStatus::cases() as $status): ?>
            <option value="<?= $status->value ?>"<?= $status === $filter ? ' selected' : '' ?>><?= $status->label() ?></option>
        <?php endforeach ?>
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
    <?php foreach ($orders as $order): ?>
        <tr>
            <td><a href="order.php?id=<?= $order->id ?>">#<?= $order->id ?></a></td>
            <td><?= $this->e($order->customerName) ?></td>
            <td><?= $order->status()->label() ?></td>
            <td class="total"><?= $this->money($order->total()) ?> руб.</td>
            <td><?= $order->createdAt->format('d.m.Y H:i') ?></td>
        </tr>
    <?php endforeach ?>
</table>

<h2>Новый заказ</h2>
<?php foreach ($errors as $error): ?>
    <p class="error"><?= $this->e($error) ?></p>
<?php endforeach ?>
<form method="post" action="orders.php">
    <input type="hidden" name="csrf_token" value="<?= $this->e($csrfToken) ?>">
    <p><label>Имя клиента<br><input type="text" name="customer_name" value="<?= $this->e(is_string($old['customer_name'] ?? null) ? $old['customer_name'] : '') ?>"></label></p>
    <p><label>E-mail<br><input type="text" name="customer_email" value="<?= $this->e(is_string($old['customer_email'] ?? null) ? $old['customer_email'] : '') ?>"></label></p>
    <button type="submit">Создать</button>
</form>
