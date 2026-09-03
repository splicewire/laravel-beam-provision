<?php

namespace Splicewire\Beam\Provision\Tofu;

use Rushing\Popcorn\Runner\Result as PopcornResult;

/**
 * The apply mechanism's own answer, unwrapped from the generic popcorn {@see PopcornResult}:
 * the parsed `change_summary`/`outputs` events from `tofu`'s `-json` log (apply.php's job), plus
 * the underlying Result for callers that want the raw Outcome/telemetry/stderr.
 */
final readonly class TofuApplyResult
{
    /**
     * @param  ?array{add: int, change: int, remove: int, operation: string, import?: int}  $changeSummary
     * @param  ?array<string, mixed>  $outputs
     */
    public function __construct(
        public bool $successful,
        public ?array $changeSummary,
        public ?array $outputs,
        public PopcornResult $popcornResult,
        public bool $sandboxed = false,
    ) {}

    /**
     * True when the isolation this run *asked* for was not actually enforced by the Runner that
     * served it — the requested Grant was a wish, not a policy. Callers that provision on behalf
     * of arbitrary input must treat an advisory result as unfit for that use; a single pre-agreed
     * tenant (the pilot) may proceed on it deliberately.
     */
    public function isAdvisory(): bool
    {
        return ! $this->sandboxed;
    }

    /** Whether this run changed nothing — the idempotent-reapply check ticket 09 requires. */
    public function isNoOp(): bool
    {
        if (! $this->successful || $this->changeSummary === null) {
            return false;
        }

        return ($this->changeSummary['add'] ?? 0) === 0
            && ($this->changeSummary['change'] ?? 0) === 0
            && ($this->changeSummary['remove'] ?? 0) === 0;
    }

    public static function fromPopcornResult(PopcornResult $result): self
    {
        if (! $result->successful()) {
            return new self(successful: false, changeSummary: null, outputs: null, popcornResult: $result, sandboxed: $result->sandboxed);
        }

        $decoded = $result->output();

        return new self(
            successful: true,
            changeSummary: $decoded['changeSummary'] ?? null,
            outputs: $decoded['outputs'] ?? null,
            popcornResult: $result,
            sandboxed: $result->sandboxed,
        );
    }
}
