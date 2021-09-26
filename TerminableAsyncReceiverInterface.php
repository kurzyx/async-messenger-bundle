<?php

declare(strict_types=1);

namespace Kurzyx\AsyncMessengerBundle;

interface TerminableAsyncReceiverInterface extends AsyncReceiverInterface
{
    /**
     * Terminate the async receiver. New envelopes must not be received anymore.
     *
     * Any pending envelopes must be rejected.
     */
    public function terminateAsync(): void;
}
