<?php

declare(strict_types=1);

namespace App\Http;

use App\Order\Money;

/**
 * Renders plain PHP templates from templates/. Inside a template $this is the View.
 * Any string that comes from users or the database is printed through $this->e();
 * integers, enum labels and formatted dates are printed as is.
 */
final class View
{
    public function __construct(private readonly string $templateDir)
    {
    }

    /**
     * @param array<string, mixed> $params
     */
    public function render(string $template, array $params = []): string
    {
        $content = $this->renderFile($template, $params);

        return $this->renderFile('layout', ['content' => $content]);
    }

    public function e(string|int|null $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    public function money(int $kopecks): string
    {
        return Money::format($kopecks);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function renderFile(string $template, array $params): string
    {
        extract($params, EXTR_SKIP);

        ob_start();
        try {
            require $this->templateDir . '/' . $template . '.php';
        } finally {
            $output = (string) ob_get_clean();
        }

        return $output;
    }
}
