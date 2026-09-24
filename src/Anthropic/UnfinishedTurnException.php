<?php

namespace Ekumanov\ClaudeReply\Anthropic;

use RuntimeException;
use Throwable;

/**
 * A turn that died after at least one call had already been billed.
 *
 * Carries the usage of the calls that did complete, so the ledger records
 * what was spent. Without it a turn whose second or third call failed (a 5xx
 * after the SDK gave up, a transport timeout) was written off as costing
 * nothing — and since the caps count failed rows only when they carry token
 * usage, every such turn was free as far as the quotas could tell.
 */
final class UnfinishedTurnException extends RuntimeException
{
    public function __construct(public readonly ReplyResult $partial, Throwable $previous)
    {
        parent::__construct($previous->getMessage(), 0, $previous);
    }
}
