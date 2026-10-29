<?php

declare(strict_types=1);

namespace App\Order;

use DomainException;

/**
 * The action is valid in general but not for the order in its current state,
 * e.g. adding an item to a paid order or shipping a cancelled one.
 */
final class OrderStateException extends DomainException
{
}
