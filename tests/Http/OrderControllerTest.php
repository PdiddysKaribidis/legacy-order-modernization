<?php

declare(strict_types=1);

namespace Tests\Http;

use App\Http\Csrf;
use App\Http\OrderController;
use App\Http\View;
use App\Order\OrderRepository;
use App\Order\OrderService;
use App\Order\OrderStatus;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDatabase;

final class OrderControllerTest extends TestCase
{
    private OrderRepository $orders;
    private OrderController $controller;
    private string $token;

    /** @var array<string, mixed> */
    private array $session = [];

    protected function setUp(): void
    {
        $this->orders = new OrderRepository(TestDatabase::connect());
        $csrf = new Csrf($this->session);
        $this->token = $csrf->token();
        $this->controller = new OrderController(
            $this->orders,
            new OrderService($this->orders),
            new View(__DIR__ . '/../../templates'),
            $csrf,
        );
    }

    public function test_create_order_redirects_to_its_page(): void
    {
        $response = $this->controller->createOrder([
            'csrf_token' => $this->token,
            'customer_name' => 'Иван Петров',
            'customer_email' => 'ivan@example.com',
        ]);

        $this->assertSame(303, $response->status);
        $this->assertMatchesRegularExpression('/^order\.php\?id=\d+&msg=created$/', $response->headers['Location']);
    }

    public function test_form_without_valid_token_is_rejected(): void
    {
        $withoutToken = $this->controller->createOrder(['customer_name' => 'Иван']);
        $withWrongToken = $this->controller->createOrder(['customer_name' => 'Иван', 'csrf_token' => 'forged']);

        $this->assertSame(403, $withoutToken->status);
        $this->assertSame(403, $withWrongToken->status);
        $this->assertSame([], $this->orders->findAll());
    }

    public function test_invalid_form_is_shown_again_with_errors_and_entered_values(): void
    {
        $response = $this->controller->createOrder([
            'csrf_token' => $this->token,
            'customer_name' => '',
            'customer_email' => 'wrong@',
        ]);

        $this->assertSame(422, $response->status);
        $this->assertStringContainsString('Укажите имя клиента.', $response->body);
        $this->assertStringContainsString('value="wrong@"', $response->body);
    }

    public function test_customer_data_is_escaped(): void
    {
        $id = (new OrderService($this->orders))->createOrder('<script>alert(1)</script>', '');

        $response = $this->controller->showOrder($id, []);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $response->body);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $response->body);
    }

    public function test_unknown_order_returns_404(): void
    {
        $this->assertSame(404, $this->controller->showOrder(999, [])->status);
        $this->assertSame(404, $this->controller->handleOrderAction(999, [
            'csrf_token' => $this->token,
            'action' => 'cancel',
        ])->status);
    }

    public function test_forbidden_transition_returns_409_and_keeps_status(): void
    {
        $id = (new OrderService($this->orders))->createOrder('Клиент', '');

        $response = $this->controller->handleOrderAction($id, [
            'csrf_token' => $this->token,
            'action' => 'change_status',
            'status' => 'shipped',
            'expected_status' => 'new',
        ]);

        $this->assertSame(409, $response->status);
        $this->assertStringContainsString('Нельзя перевести заказ из статуса «Новый» в «Отправлен».', $response->body);
        $this->assertSame(OrderStatus::New, $this->orders->get($id)->status());
    }

    public function test_unknown_status_or_action_is_a_validation_error(): void
    {
        $id = (new OrderService($this->orders))->createOrder('Клиент', '');

        foreach ([['action' => 'change_status', 'status' => 'archived'], ['action' => 'delete'], []] as $post) {
            $response = $this->controller->handleOrderAction($id, ['csrf_token' => $this->token] + $post);
            $this->assertSame(422, $response->status);
        }
    }

    public function test_stale_cancel_form_does_not_cancel_paid_order(): void
    {
        $service = new OrderService($this->orders);
        $id = $service->createOrder('Клиент', '');
        $service->addItem($id, 'Ручка', '1', '50');

        // The page was rendered while the order was new...
        $form = $this->controller->showOrder($id, [])->body;
        $this->assertStringContainsString('name="expected_status" value="new"', $form);

        // ...then a colleague marked it as paid, and only after that the cancel was submitted.
        $service->changeStatus($id, OrderStatus::Paid, 'new');
        $response = $this->controller->handleOrderAction($id, [
            'csrf_token' => $this->token,
            'action' => 'cancel',
            'expected_status' => 'new',
        ]);

        $this->assertSame(409, $response->status);
        $this->assertStringContainsString('Заказ изменился', $response->body);
        $this->assertSame(OrderStatus::Paid, $this->orders->get($id)->status());
    }

    public function test_order_with_unknown_status_is_shown_without_actions(): void
    {
        $pdo = TestDatabase::connect();
        $orders = new OrderRepository($pdo);
        $controller = new OrderController($orders, new OrderService($orders), new View(__DIR__ . '/../../templates'), new Csrf($this->session));
        $id = $orders->create('Клиент', '', new \DateTimeImmutable());
        $pdo->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute(['archived', $id]);

        $page = $controller->showOrder($id, []);
        $list = $controller->listOrders([]);

        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('<span class="status">archived</span>', $page->body);
        $this->assertStringNotContainsString('name="action"', $page->body);
        $this->assertSame(200, $list->status);
        $this->assertStringContainsString('<td>archived</td>', $list->body);
    }

    public function test_unknown_status_filter_shows_nothing_as_in_legacy(): void
    {
        (new OrderService($this->orders))->createOrder('ООО Ромашка', '');

        $body = $this->controller->listOrders(['status' => 'foo'])->body;

        $this->assertStringNotContainsString('ООО Ромашка', $body);
    }

    public function test_array_in_query_does_not_break_page(): void
    {
        $response = $this->controller->listOrders(['status' => ['paid']]);

        $this->assertSame(200, $response->status);
    }

    public function test_status_form_offers_only_allowed_transitions(): void
    {
        $id = (new OrderService($this->orders))->createOrder('Клиент', '');

        $body = $this->controller->showOrder($id, [])->body;

        $this->assertStringContainsString('<option value="paid">', $body);
        $this->assertStringContainsString('<option value="cancelled">', $body);
        $this->assertStringNotContainsString('<option value="shipped">', $body);
    }
}
