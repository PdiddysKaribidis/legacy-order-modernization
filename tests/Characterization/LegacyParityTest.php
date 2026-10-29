<?php

declare(strict_types=1);

namespace Tests\Characterization;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\HttpClient;
use Tests\Support\PhpServer;
use Tests\Support\TestDatabase;

/**
 * Runs the same user actions against legacy/ and public/ over HTTP, each with its own
 * empty SQLite database, and compares what the user sees. Identical results prove the
 * refactoring kept the behavior; the tests at the bottom pin down the differences
 * that were introduced on purpose (docs/MODERNIZATION.md, "Изменения поведения").
 */
final class LegacyParityTest extends TestCase
{
    private static string $dir;

    /** @var array<string, PhpServer> */
    private static array $servers = [];

    /** @var array<string, HttpClient> */
    private array $clients = [];

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . '/legacy-parity-' . bin2hex(random_bytes(4));
        mkdir(self::$dir);
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$servers as $server) {
            $server->stop();
        }
        self::$servers = [];
        array_map('unlink', glob(self::$dir . '/*') ?: []);
        @rmdir(self::$dir);
    }

    protected function setUp(): void
    {
        // A fresh database per test, so order IDs are the same in both applications.
        foreach (['legacy' => 'legacy', 'modern' => 'public'] as $app => $documentRoot) {
            if (isset(self::$servers[$app])) {
                self::$servers[$app]->stop();
            }

            $database = self::$dir . "/{$app}-" . bin2hex(random_bytes(4)) . '.sqlite';
            TestDatabase::createSqliteFile($database);

            self::$servers[$app] = new PhpServer(__DIR__ . "/../../{$documentRoot}", ['DB_DSN' => "sqlite:{$database}"]);
            $this->clients[$app] = new HttpClient(self::$servers[$app]->baseUrl);
        }
    }

    /**
     * @return array<string, array{list<array{string, string, string}>}>
     */
    public static function orders(): array
    {
        return [
            'small order' => [[['Ручка', '3', '45,50'], ['Тетрадь', '10', '89']]],
            'discount from exactly 10 000' => [[['Ноутбук', '1', '10000']]],
            'discount with kopecks' => [[['Монитор', '1', '10000,10'], ['Кабель', '3', '333,33']]],
            'just below threshold' => [[['Стол', '1', '9999,99']]],
            'large quantities' => [[['Бумага А4', '1000', '389,90']]],
        ];
    }

    /**
     * @param list<array{string, string, string}> $items
     */
    #[DataProvider('orders')]
    public function test_order_page_is_the_same(array $items): void
    {
        $pages = [];
        foreach (['legacy', 'modern'] as $app) {
            $create = $this->submit($app, 'orders.php', 'orders.php', ['customer_name' => 'Иван Петров', 'customer_email' => 'ivan@example.com']);
            $this->assertSame('order.php?id=1&msg=created', $create['location'], $app);

            foreach ($items as [$product, $quantity, $price]) {
                $add = $this->submit($app, 'order.php?id=1', 'order.php?id=1', [
                    'action' => 'add_item',
                    'product_name' => $product,
                    'quantity' => $quantity,
                    'price' => $price,
                ]);
                $this->assertSame('order.php?id=1&msg=item_added', $add['location'], $app);
            }

            $pages[$app] = $this->summary($this->clients[$app]->get('order.php?id=1&msg=item_added')['body']);
        }

        $this->assertNotEmpty($pages['legacy']['total']);
        $this->assertSame($pages['legacy'], $pages['modern']);
    }

    public function test_lifecycle_and_cancellation_are_the_same(): void
    {
        foreach (['legacy', 'modern'] as $app) {
            $this->submit($app, 'orders.php', 'orders.php', ['customer_name' => 'ИП Сидорова', 'customer_email' => '']);
            $this->submit($app, 'order.php?id=1', 'order.php?id=1', ['action' => 'add_item', 'product_name' => 'Степлер', 'quantity' => '2', 'price' => '455,5']);
            $paid = $this->submit($app, 'order.php?id=1', 'order.php?id=1', ['action' => 'change_status', 'status' => 'paid']);
            $shipped = $this->submit($app, 'order.php?id=1', 'order.php?id=1', ['action' => 'change_status', 'status' => 'shipped']);

            $this->submit($app, 'orders.php', 'orders.php', ['customer_name' => 'Мария Козлова', 'customer_email' => '']);
            $cancelled = $this->submit($app, 'order.php?id=2', 'order.php?id=2', ['action' => 'cancel']);

            $this->assertSame('order.php?id=1&msg=status_changed', $paid['location'], $app);
            $this->assertSame('order.php?id=1&msg=status_changed', $shipped['location'], $app);
            $this->assertSame('order.php?id=2&msg=cancelled', $cancelled['location'], $app);
            $this->assertSame('Отправлен', $this->summary($this->clients[$app]->get('order.php?id=1')['body'])['status'], $app);
            $this->assertSame('Отменён', $this->summary($this->clients[$app]->get('order.php?id=2')['body'])['status'], $app);

            // A shipped order page has no forms, so the token is taken from the list page (one per session).
            $cancelShipped = $this->submit($app, 'orders.php', 'order.php?id=1', ['action' => 'cancel']);
            $this->assertStringContainsString('Отправленный заказ нельзя отменить', $cancelShipped['body'], $app);
        }
    }

    public function test_missing_order_is_404_in_both(): void
    {
        foreach (['legacy', 'modern'] as $app) {
            $response = $this->clients[$app]->get('order.php?id=999');
            $this->assertSame(404, $response['status'], $app);
            $this->assertStringContainsString('Заказ не найден', $response['body'], $app);
        }
    }

    public function test_status_filter_on_list_page_is_the_same(): void
    {
        foreach (['legacy', 'modern'] as $app) {
            foreach (['Первый', 'Второй', 'Третий'] as $name) {
                $this->submit($app, 'orders.php', 'orders.php', ['customer_name' => $name, 'customer_email' => '']);
            }
            $this->submit($app, 'order.php?id=2', 'order.php?id=2', ['action' => 'cancel']);
        }

        foreach (['', 'new', 'cancelled', 'paid'] as $status) {
            $this->assertSame(
                $this->listedCustomers($this->clients['legacy']->get("orders.php?status={$status}")['body']),
                $this->listedCustomers($this->clients['modern']->get("orders.php?status={$status}")['body']),
                "Filter '{$status}'",
            );
        }
    }

    // Intentional differences below. Each one is a bug of the legacy version.

    public function test_difference_list_page_discount_matches_order_page(): void
    {
        foreach (['legacy', 'modern'] as $app) {
            $this->submit($app, 'orders.php', 'orders.php', ['customer_name' => 'Клиент', 'customer_email' => '']);
            $this->submit($app, 'order.php?id=1', 'order.php?id=1', ['action' => 'add_item', 'product_name' => 'Ноутбук', 'quantity' => '1', 'price' => '10000']);
            $this->submit($app, 'orders.php', 'orders.php', ['customer_name' => 'Клиент 2', 'customer_email' => '']);
            $this->submit($app, 'order.php?id=2', 'order.php?id=2', ['action' => 'add_item', 'product_name' => 'Монитор', 'quantity' => '1', 'price' => '10000,10']);
        }

        // Legacy: the list used "> 10 000" and rounded the total instead of the discount,
        // so it disagreed with the order page of the same order.
        $this->assertSame(['9 500,10 руб.', '10 000,00 руб.'], $this->listedTotals($this->clients['legacy']->get('orders.php')['body']));
        $this->assertSame('9 500,00', $this->summary($this->clients['legacy']->get('order.php?id=1')['body'])['total']);
        $this->assertSame('9 500,09', $this->summary($this->clients['legacy']->get('order.php?id=2')['body'])['total']);

        $this->assertSame(['9 500,09 руб.', '9 500,00 руб.'], $this->listedTotals($this->clients['modern']->get('orders.php')['body']));
    }

    public function test_difference_any_status_could_be_set_in_legacy(): void
    {
        foreach (['legacy', 'modern'] as $app) {
            $this->submit($app, 'orders.php', 'orders.php', ['customer_name' => 'Клиент', 'customer_email' => '']);
        }

        $legacy = $this->submit('legacy', 'order.php?id=1', 'order.php?id=1', ['action' => 'change_status', 'status' => 'shipped']);
        $modern = $this->submit('modern', 'order.php?id=1', 'order.php?id=1', ['action' => 'change_status', 'status' => 'shipped']);

        // Legacy shipped an empty unpaid order without a word.
        $this->assertSame('order.php?id=1&msg=status_changed', $legacy['location']);
        $this->assertSame(409, $modern['status']);
        $this->assertSame('Новый', $this->summary($this->clients['modern']->get('order.php?id=1')['body'])['status']);
    }

    public function test_difference_items_could_be_added_to_paid_order_in_legacy(): void
    {
        foreach (['legacy', 'modern'] as $app) {
            $this->submit($app, 'orders.php', 'orders.php', ['customer_name' => 'Клиент', 'customer_email' => '']);
            $this->submit($app, 'order.php?id=1', 'order.php?id=1', ['action' => 'add_item', 'product_name' => 'Ручка', 'quantity' => '1', 'price' => '50']);
            $this->submit($app, 'order.php?id=1', 'order.php?id=1', ['action' => 'change_status', 'status' => 'paid']);
        }

        // The form is hidden for paid orders, but the legacy handler did not check the status.
        $item = ['action' => 'add_item', 'product_name' => 'Карандаш', 'quantity' => '1', 'price' => '20'];
        $legacy = $this->clients['legacy']->post('order.php?id=1', $item);
        $modern = $this->submit('modern', 'order.php?id=1', 'order.php?id=1', $item);

        $this->assertSame('order.php?id=1&msg=item_added', $legacy['location']);
        $this->assertSame(409, $modern['status']);
    }

    public function test_difference_invalid_price_was_saved_as_zero_in_legacy(): void
    {
        foreach (['legacy', 'modern'] as $app) {
            $this->submit($app, 'orders.php', 'orders.php', ['customer_name' => 'Клиент', 'customer_email' => '']);
        }

        $item = ['action' => 'add_item', 'product_name' => 'Ручка', 'quantity' => '2', 'price' => 'двадцать'];
        $legacy = $this->submit('legacy', 'order.php?id=1', 'order.php?id=1', $item);
        $modern = $this->submit('modern', 'order.php?id=1', 'order.php?id=1', $item);

        $this->assertSame('order.php?id=1&msg=item_added', $legacy['location']);
        $this->assertSame('0,00', $this->summary($this->clients['legacy']->get('order.php?id=1')['body'])['total']);
        $this->assertSame(422, $modern['status']);
    }

    /**
     * Submits a form the way a browser does: opens the page first (the modern
     * version puts a CSRF token into it; the legacy pages have none), then posts.
     *
     * @param array<string, string> $data
     * @return array{status: int, location: ?string, body: string}
     */
    private function submit(string $app, string $formPage, string $action, array $data): array
    {
        $client = $this->clients[$app];
        $page = $client->get($formPage)['body'];

        if (preg_match('/name="csrf_token" value="([^"]+)"/', $page, $m)) {
            $data['csrf_token'] = $m[1];
        }

        return $client->post($action, $data);
    }

    /**
     * What a user reads on the order page, without markup and whitespace differences.
     *
     * @return array{status: string, rows: list<string>, subtotal: string, discount: string, total: string}
     */
    private function summary(string $html): array
    {
        preg_match('/<span class="status">([^<]*)</', $html, $status);
        preg_match('/<span class="subtotal">([^<]*)</', $html, $subtotal);
        preg_match('/<span class="discount">([^<]*)</', $html, $discount);
        preg_match('/<p class="total">Итого: ([^<]*) руб\./', $html, $total);
        preg_match_all('#<tr>\s*<td>(.*?)</td>\s*<td>(.*?)</td>\s*<td>(.*?)</td>\s*<td>(.*?)</td>\s*</tr>#s', $html, $rows, PREG_SET_ORDER);

        return [
            'status' => $status[1] ?? '',
            'rows' => array_map(fn (array $row) => implode(' | ', array_slice($row, 1)), $rows),
            'subtotal' => $subtotal[1] ?? '',
            'discount' => $discount[1] ?? '',
            'total' => $total[1] ?? '',
        ];
    }

    /**
     * @return list<string>
     */
    private function listedTotals(string $html): array
    {
        preg_match_all('#<td class="total">([^<]*)</td>#', $html, $matches);

        return $matches[1];
    }

    /**
     * @return list<string>
     */
    private function listedCustomers(string $html): array
    {
        preg_match_all('#</a></td>\s*<td>([^<]*)</td>\s*<td>([^<]*)</td>#', $html, $matches, PREG_SET_ORDER);

        return array_map(fn (array $m) => $m[1] . ' — ' . $m[2], $matches);
    }
}
