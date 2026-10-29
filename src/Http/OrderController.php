<?php

declare(strict_types=1);

namespace App\Http;

use App\Order\OrderNotFoundException;
use App\Order\OrderRepository;
use App\Order\OrderService;
use App\Order\OrderStateException;
use App\Order\OrderStatus;
use App\Order\ValidationException;

/**
 * Handles the same URLs and form fields as legacy/orders.php and legacy/order.php,
 * so bookmarks and the page layout stay the same for users.
 */
final class OrderController
{
    /** Same ?msg= codes as the legacy pages. */
    private const MESSAGES = [
        'created' => 'Заказ создан',
        'item_added' => 'Позиция добавлена',
        'status_changed' => 'Статус изменён',
        'cancelled' => 'Заказ отменён',
    ];

    public function __construct(
        private readonly OrderRepository $orders,
        private readonly OrderService $service,
        private readonly View $view,
        private readonly Csrf $csrf,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     */
    public function listOrders(array $query): Response
    {
        return $this->orderList($query);
    }

    /**
     * @param array<string, mixed> $post
     */
    public function createOrder(array $post): Response
    {
        if (!$this->csrf->isValid($post['csrf_token'] ?? null)) {
            return $this->csrfFailure();
        }

        try {
            $id = $this->service->createOrder(self::field($post, 'customer_name'), self::field($post, 'customer_email'));
        } catch (ValidationException $e) {
            return $this->orderList([], $e->errors, $post, 422);
        }

        return Response::redirect("order.php?id={$id}&msg=created");
    }

    /**
     * @param array<string, mixed> $query
     */
    public function showOrder(int $id, array $query): Response
    {
        $message = self::MESSAGES[self::field($query, 'msg')] ?? '';

        return $this->orderPage($id, message: $message);
    }

    /**
     * @param array<string, mixed> $post
     */
    public function handleOrderAction(int $id, array $post): Response
    {
        if (!$this->csrf->isValid($post['csrf_token'] ?? null)) {
            return $this->csrfFailure();
        }

        try {
            $messageCode = match (self::field($post, 'action')) {
                'add_item' => $this->addItem($id, $post),
                'change_status' => $this->changeStatus($id, $post),
                'cancel' => $this->cancel($id, $post),
                default => throw new ValidationException(['action' => 'Неизвестное действие.']),
            };
        } catch (ValidationException $e) {
            return $this->orderPage($id, errors: $e->errors, old: $post, status: 422);
        } catch (OrderStateException $e) {
            return $this->orderPage($id, errors: ['state' => $e->getMessage()], status: 409);
        } catch (OrderNotFoundException) {
            return $this->notFound();
        }

        return Response::redirect("order.php?id={$id}&msg={$messageCode}");
    }

    /**
     * @param array<string, mixed> $post
     */
    private function addItem(int $id, array $post): string
    {
        $this->service->addItem(
            $id,
            self::field($post, 'product_name'),
            self::field($post, 'quantity'),
            self::field($post, 'price'),
        );

        return 'item_added';
    }

    /**
     * @param array<string, mixed> $post
     */
    private function changeStatus(int $id, array $post): string
    {
        $status = OrderStatus::tryFrom(self::field($post, 'status'))
            ?? throw new ValidationException(['status' => 'Неизвестный статус.']);

        $this->service->changeStatus($id, $status, self::field($post, 'expected_status'));

        return 'status_changed';
    }

    /**
     * @param array<string, mixed> $post
     */
    private function cancel(int $id, array $post): string
    {
        $this->service->cancelOrder($id, self::field($post, 'expected_status'));

        return 'cancelled';
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    private function orderList(array $query, array $errors = [], array $old = [], int $status = 200): Response
    {
        $filter = self::field($query, 'status');

        return new Response($status, $this->view->render('orders', [
            'orders' => $this->orders->findAll($filter === '' ? null : $filter),
            'filter' => $filter,
            'errors' => $errors,
            'old' => $old,
            'csrfToken' => $this->csrf->token(),
        ]));
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    private function orderPage(int $id, string $message = '', array $errors = [], array $old = [], int $status = 200): Response
    {
        try {
            $order = $this->orders->get($id);
        } catch (OrderNotFoundException) {
            return $this->notFound();
        }

        return new Response($status, $this->view->render('order', [
            'order' => $order,
            'message' => $message,
            'errors' => $errors,
            'old' => $old,
            'csrfToken' => $this->csrf->token(),
        ]));
    }

    private function notFound(): Response
    {
        return new Response(404, $this->view->render('error', ['message' => 'Заказ не найден']));
    }

    private function csrfFailure(): Response
    {
        return new Response(403, $this->view->render('error', [
            'message' => 'Форма устарела. Обновите страницу и попробуйте ещё раз.',
        ]));
    }

    /**
     * Request arrays may contain arrays (?status[]=x); such values are treated as empty.
     *
     * @param array<string, mixed> $input
     */
    private static function field(array $input, string $name): string
    {
        $value = $input[$name] ?? '';

        return is_string($value) ? $value : '';
    }
}
