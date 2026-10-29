<?php
/** @var App\Http\View $this */
/** @var App\Order\Order $order */
/** @var string $message */
/** @var array<string, string> $errors */
/** @var array<string, mixed> $old */
/** @var string $csrfToken */

use App\Order\OrderStatus;

$oldValue = fn (string $name, string $default = '') => is_string($old[$name] ?? null) ? $old[$name] : $default;
$status = $order->status();
?>
<h1>Заказ #<?= $order->id ?></h1>

<?php if ($message !== ''): ?>
    <p class="message"><?= $this->e($message) ?></p>
<?php endif ?>
<?php foreach ($errors as $error): ?>
    <p class="error"><?= $this->e($error) ?></p>
<?php endforeach ?>

<p>Клиент: <?= $this->e($order->customerName) ?> (<?= $this->e($order->customerEmail) ?>)</p>
<p>Статус: <span class="status"><?= $status->label() ?></span></p>
<p>Создан: <?= $order->createdAt->format('d.m.Y H:i') ?></p>

<table>
    <tr>
        <th>Товар</th>
        <th>Кол-во</th>
        <th>Цена</th>
        <th>Сумма</th>
    </tr>
    <?php foreach ($order->items() as $item): ?>
        <tr>
            <td><?= $this->e($item->productName) ?></td>
            <td><?= $item->quantity ?></td>
            <td><?= $this->money($item->price) ?></td>
            <td><?= $this->money($item->lineTotal()) ?></td>
        </tr>
    <?php endforeach ?>
</table>

<p>Сумма: <span class="subtotal"><?= $this->money($order->subtotal()) ?></span> руб.</p>
<?php if ($order->discount() > 0): ?>
    <p>Скидка: <span class="discount"><?= $this->money($order->discount()) ?></span> руб.</p>
<?php endif ?>
<p class="total">Итого: <?= $this->money($order->total()) ?> руб.</p>

<?php if ($status === OrderStatus::New): ?>
    <h2>Добавить позицию</h2>
    <form method="post" action="order.php?id=<?= $order->id ?>">
        <input type="hidden" name="csrf_token" value="<?= $this->e($csrfToken) ?>">
        <input type="hidden" name="action" value="add_item">
        <p><label>Товар<br><input type="text" name="product_name" value="<?= $this->e($oldValue('product_name')) ?>"></label></p>
        <p><label>Количество<br><input type="text" name="quantity" value="<?= $this->e($oldValue('quantity', '1')) ?>"></label></p>
        <p><label>Цена, руб.<br><input type="text" name="price" value="<?= $this->e($oldValue('price')) ?>"></label></p>
        <button type="submit">Добавить</button>
    </form>
<?php endif ?>

<?php $nextStatuses = array_filter(OrderStatus::cases(), fn (OrderStatus $next) => $status->canTransitionTo($next)) ?>
<?php if ($nextStatuses !== []): ?>
    <h2>Статус</h2>
    <form method="post" action="order.php?id=<?= $order->id ?>">
        <input type="hidden" name="csrf_token" value="<?= $this->e($csrfToken) ?>">
        <input type="hidden" name="action" value="change_status">
        <select name="status">
            <?php foreach ($nextStatuses as $next): ?>
                <option value="<?= $next->value ?>"><?= $next->label() ?></option>
            <?php endforeach ?>
        </select>
        <button type="submit">Сохранить</button>
    </form>
<?php endif ?>

<?php if ($status->canTransitionTo(OrderStatus::Cancelled)): ?>
    <form method="post" action="order.php?id=<?= $order->id ?>">
        <input type="hidden" name="csrf_token" value="<?= $this->e($csrfToken) ?>">
        <input type="hidden" name="action" value="cancel">
        <button type="submit" onclick="return confirm('Отменить заказ?')">Отменить заказ</button>
    </form>
<?php endif ?>
