<?php

declare(strict_types=1);

namespace Tests\Characterization;

use App\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\HttpClient;
use Tests\Support\PhpServer;
use Tests\Support\TestDatabase;

/**
 * Runs the same user actions against legacy/ and public/ over HTTP, each with its own
 * SQLite database, and compares what the user sees. Identical results show the refactoring
 * kept the behavior; the test_difference_* tests pin down every intentional change listed
 * in docs/MODERNIZATION.md, "Изменения поведения".
 */
final class LegacyParityTest extends TestCase
{
    private const APPS = ['legacy' => 'legacy', 'modern' => 'public'];

    private static string $dir;

    /** @var array<string, PhpServer> */
    private static array $servers = [];

    /** @var array<string, HttpClient> */
    private array $clients = [];

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . '/legacy-parity-' . bin2hex(random_bytes(4));
        mkdir(self::$dir);

        // One server per application for the whole class; each test gets a fresh database file.
        foreach (self::APPS as $app => $documentRoot) {
            self::$servers[$app] = new PhpServer(
                __DIR__ . "/../../{$documentRoot}",
                ['DB_DSN' => 'sqlite:' . self::database($app)],
                self::$dir . "/{$app}.log",
            );
        }
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
        foreach (array_keys(self::APPS) as $app) {
            @unlink(self::database($app));
            TestDatabase::createSqliteFile(self::database($app));
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
            'large quantity' => [[['Бумага А4', '5000', '389,90']]],
            'free gift line' => [[['Кресло', '1', '12990'], ['Подарок', '1', '0']]],
        ];
    }

    /**
     * @param list<array{string, string, string}> $items
     */
    #[DataProvider('orders')]
    public function test_order_page_is_the_same(array $items): void
    {
        $pages = [];
        foreach (array_keys(self::APPS) as $app) {
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

            $page = $this->clients[$app]->get('order.php?id=1&msg=item_added');
            $this->assertSame(200, $page['status'], $app);
            $pages[$app] = $this->summary($page['body']);
        }

        $this->assertNotSame('', $pages['legacy']['total']);
        $this->assertSame($pages['legacy'], $pages['modern']);
    }

    public function test_lifecycle_and_cancellation_are_the_same(): void
    {
        foreach (array_keys(self::APPS) as $app) {
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

            // A shipped order page has no forms, so the token comes from the list page (one per session).
            $cancelShipped = $this->submit($app, 'orders.php', 'order.php?id=1', ['action' => 'cancel', 'expected_status' => 'shipped']);
            $this->assertStringContainsString('Отправленный заказ нельзя отменить', $cancelShipped['body'], $app);
        }
    }

    public function test_missing_order_is_404_in_both(): void
    {
        foreach (array_keys(self::APPS) as $app) {
            $response = $this->clients[$app]->get('order.php?id=999');
            $this->assertSame(404, $response['status'], $app);
            $this->assertStringContainsString('Заказ не найден', $response['body'], $app);
        }
    }

    public function test_list_page_and_status_filter_are_the_same(): void
    {
        foreach (array_keys(self::APPS) as $app) {
            foreach (['Первый', 'Второй', 'Третий'] as $name) {
                $this->submit($app, 'orders.php', 'orders.php', ['customer_name' => $name, 'customer_email' => '']);
            }
            $this->submit($app, 'order.php?id=1', 'order.php?id=1', ['action' => 'add_item', 'product_name' => 'Ручка', 'quantity' => '3', 'price' => '45,50']);
            $this->submit($app, 'order.php?id=2', 'order.php?id=2', ['action' => 'cancel']);
        }

        foreach (['', 'new', 'cancelled', 'paid', 'no-such-status'] as $status) {
            $legacy = $this->clients['legacy']->get("orders.php?status={$status}");
            $modern = $this->clients['modern']->get("orders.php?status={$status}");

            $this->assertSame(200, $legacy['status']);
            $this->assertSame(200, $modern['status']);
            $this->assertSame($this->listRows($legacy['body']), $this->listRows($modern['body']), "Filter '{$status}'");
        }
    }

    /**
     * The legacy status form saved any value. Both versions share one database during the
     * migration, so the new version must show such orders the way the legacy page did.
     */
    public function test_order_with_unknown_status_is_shown_the_same(): void
    {
        foreach (array_keys(self::APPS) as $app) {
            $this->submit($app, 'orders.php', 'orders.php', ['customer_name' => 'Клиент', 'customer_email' => '']);
            Database::connect('sqlite:' . self::database($app))->exec("UPDATE orders SET status = 'archived'");
        }

        $legacy = $this->clients['legacy']->get('order.php?id=1');
        $modern = $this->clients['modern']->get('order.php?id=1');

        $this->assertSame(200, $modern['status']);
        $this->assertSame($this->summary($legacy['body']), $this->summary($modern['body']));
        $this->assertSame('archived', $this->summary($modern['body'])['status']);
    }

    // Intentional differences below. Each one is a bug of the legacy version or a deliberate
    // change listed in docs/MODERNIZATION.md.

    public function test_difference_list_page_discount_matches_order_page(): void
    {
        foreach (array_keys(self::APPS) as $app) {
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
        $this->createOrderInBoth();

        $legacy = $this->submit('legacy', 'order.php?id=1', 'order.php?id=1', ['action' => 'change_status', 'status' => 'shipped']);
        $modern = $this->submit('modern', 'order.php?id=1', 'order.php?id=1', ['action' => 'change_status', 'status' => 'shipped']);

        $this->assertSame('order.php?id=1&msg=status_changed', $legacy['location']);
        $this->assertSame(409, $modern['status']);
        $this->assertSame('Новый', $this->summary($this->clients['modern']->get('order.php?id=1')['body'])['status']);
    }

    public function test_difference_empty_order_could_be_paid_in_legacy(): void
    {
        $this->createOrderInBoth();

        $legacy = $this->submit('legacy', 'order.php?id=1', 'order.php?id=1', ['action' => 'change_status', 'status' => 'paid']);
        $modern = $this->submit('modern', 'order.php?id=1', 'order.php?id=1', ['action' => 'change_status', 'status' => 'paid']);

        $this->assertSame('order.php?id=1&msg=status_changed', $legacy['location']);
        $this->assertSame(409, $modern['status']);
        $this->assertStringContainsString('Нельзя оплатить пустой заказ.', $modern['body']);
    }

    public function test_difference_items_could_be_added_to_paid_order_in_legacy(): void
    {
        foreach (array_keys(self::APPS) as $app) {
            $this->submit($app, 'orders.php', 'orders.php', ['customer_name' => 'Клиент', 'customer_email' => '']);
            $this->submit($app, 'order.php?id=1', 'order.php?id=1', ['action' => 'add_item', 'product_name' => 'Ручка', 'quantity' => '1', 'price' => '50']);
            $this->submit($app, 'order.php?id=1', 'order.php?id=1', ['action' => 'change_status', 'status' => 'paid']);
        }

        // The form is hidden for paid orders, but the legacy handler did not check the status.
        $item = ['action' => 'add_item', 'product_name' => 'Карандаш', 'quantity' => '1', 'price' => '20'];
        $legacy = $this->clients['legacy']->post('order.php?id=1', $item);
        $modern = $this->submit('modern', 'orders.php', 'order.php?id=1', $item);

        $this->assertSame('order.php?id=1&msg=item_added', $legacy['location']);
        $this->assertSame(409, $modern['status']);
    }

    /**
     * @return array<string, array{string, string, string}> quantity, price, what legacy saved as the line total
     */
    public static function inputsLegacySilentlyConverted(): array
    {
        return [
            'price in words' => ['2', 'двадцать', '0,00'],
            'fractional quantity' => ['2.5', '100', '200,00'],
            'negative price' => ['1', '-10', '-10,00'],
        ];
    }

    #[DataProvider('inputsLegacySilentlyConverted')]
    public function test_difference_invalid_item_input_was_saved_in_legacy(string $quantity, string $price, string $legacyTotal): void
    {
        $this->createOrderInBoth();

        $item = ['action' => 'add_item', 'product_name' => 'Ручка', 'quantity' => $quantity, 'price' => $price];
        $legacy = $this->submit('legacy', 'order.php?id=1', 'order.php?id=1', $item);
        $modern = $this->submit('modern', 'order.php?id=1', 'order.php?id=1', $item);

        $this->assertSame('order.php?id=1&msg=item_added', $legacy['location']);
        $this->assertSame($legacyTotal, $this->summary($this->clients['legacy']->get('order.php?id=1')['body'])['total']);
        $this->assertSame(422, $modern['status']);
    }

    public function test_difference_price_with_thousands_separator(): void
    {
        $this->createOrderInBoth();

        $item = ['action' => 'add_item', 'product_name' => 'Кресло', 'quantity' => '1', 'price' => '1 500'];
        $this->submit('legacy', 'order.php?id=1', 'order.php?id=1', $item);
        $this->submit('modern', 'order.php?id=1', 'order.php?id=1', $item);

        // (float) "1 500" is 1.0 in PHP.
        $this->assertSame('1,00', $this->summary($this->clients['legacy']->get('order.php?id=1')['body'])['total']);
        $this->assertSame('1 500,00', $this->summary($this->clients['modern']->get('order.php?id=1')['body'])['total']);
    }

    public function test_difference_malformed_id_and_action(): void
    {
        $this->createOrderInBoth();

        // (int) "1abc" is 1 in PHP, so the legacy page showed order 1.
        $this->assertSame(200, $this->clients['legacy']->get('order.php?id=1abc')['status']);
        $this->assertSame(404, $this->clients['modern']->get('order.php?id=1abc')['status']);

        $legacy = $this->submit('legacy', 'order.php?id=1', 'order.php?id=1', ['action' => 'archive']);
        $modern = $this->submit('modern', 'order.php?id=1', 'order.php?id=1', ['action' => 'archive']);
        $this->assertSame(200, $legacy['status']);
        $this->assertSame(422, $modern['status']);
    }

    private static function database(string $app): string
    {
        return self::$dir . "/{$app}.sqlite";
    }

    private function createOrderInBoth(): void
    {
        foreach (array_keys(self::APPS) as $app) {
            $this->submit($app, 'orders.php', 'orders.php', ['customer_name' => 'Клиент', 'customer_email' => '']);
        }
    }

    /**
     * Submits a form the way a browser does: opens the page first, then posts. The modern
     * pages put a CSRF token and the expected order status into their forms; the legacy
     * pages have neither and ignore these fields.
     *
     * @param array<string, string> $data
     * @return array{status: int, location: ?string, body: string}
     */
    private function submit(string $app, string $formPage, string $action, array $data): array
    {
        $client = $this->clients[$app];
        $page = $client->get($formPage)['body'];

        foreach (['csrf_token', 'expected_status'] as $field) {
            if (!isset($data[$field]) && preg_match('/name="' . $field . '" value="([^"]*)"/', $page, $m)) {
                $data[$field] = $m[1];
            }
        }

        return $client->post($action, $data);
    }

    /**
     * What a user reads on the order page, without markup and whitespace differences.
     *
     * @return array<string, string|list<string>>
     */
    private function summary(string $html): array
    {
        $first = fn (string $pattern) => preg_match($pattern, $html, $m) ? $m[1] : '';
        preg_match_all('#<tr>\s*<td>(.*?)</td>\s*<td>(.*?)</td>\s*<td>(.*?)</td>\s*<td>(.*?)</td>\s*</tr>#s', $html, $rows, PREG_SET_ORDER);

        return [
            'customer' => $first('#<p>Клиент: (.*?)</p>#'),
            // Only the date: the two orders are created a moment apart and may differ in minutes.
            'created' => $first('#<p>Создан: (\d\d\.\d\d\.\d{4})#'),
            'status' => $first('/<span class="status">([^<]*)</'),
            'rows' => array_map(fn (array $row) => implode(' | ', array_slice($row, 1)), $rows),
            'subtotal' => $first('/<span class="subtotal">([^<]*)</'),
            'discount' => $first('/<span class="discount">([^<]*)</'),
            'total' => $first('/<p class="total">Итого: ([^<]*) руб\./'),
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
     * Link, customer, status, total and creation date of every row in the order list.
     *
     * @return list<string>
     */
    private function listRows(string $html): array
    {
        preg_match_all(
            '#<td><a href="([^"]*)">[^<]*</a></td>\s*<td>([^<]*)</td>\s*<td>([^<]*)</td>\s*<td class="total">([^<]*)</td>\s*<td>(\d\d\.\d\d\.\d{4})#',
            $html,
            $matches,
            PREG_SET_ORDER,
        );

        return array_map(fn (array $m) => implode(' | ', array_slice($m, 1)), $matches);
    }
}
