<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Per-session token for every form that changes data. The legacy pages had none, so a page
 * on any other site, opened in a manager's browser, could post a form that cancels an order
 * in this internal system.
 */
final class Csrf
{
    private const KEY = 'csrf_token';

    /** @var array<string, mixed> */
    private array $session;

    /**
     * @param array<string, mixed> $session usually $_SESSION, passed by reference
     */
    public function __construct(array &$session)
    {
        $this->session = &$session;
    }

    public function token(): string
    {
        if (!isset($this->session[self::KEY]) || !is_string($this->session[self::KEY])) {
            $this->session[self::KEY] = bin2hex(random_bytes(32));
        }

        return $this->session[self::KEY];
    }

    public function isValid(mixed $token): bool
    {
        return is_string($token)
            && isset($this->session[self::KEY])
            && is_string($this->session[self::KEY])
            && hash_equals($this->session[self::KEY], $token);
    }
}
