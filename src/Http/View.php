<?php

declare(strict_types=1);

namespace App\Http;

use App\Order\Money;

/**
 * Renders plain PHP templates from templates/. Inside a template $this is the View,
 * so every output goes through $this->e() or $this->money().
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
