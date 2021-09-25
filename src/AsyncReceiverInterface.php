<?php

declare(strict_types=1);

namespace Kurzyx\AsyncMessengerBundle;

use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;

/**
 * Receiver that receives envelopes asynchronously.
 */
interface AsyncReceiverInterface extends ReceiverInterface
{
    /**
     * Callback that should be invoked when there's one or more envelope's ready.
     *
     * @param callable<void>|null $callback
     */
    public function setOnEnvelopePendingCallback(?callable $callback): void;
}
