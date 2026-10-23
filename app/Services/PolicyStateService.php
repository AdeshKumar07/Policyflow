<?php

namespace App\Services;

use App\Models\PolicyRequest;
use App\Models\PolicyStateHistory;
use Illuminate\Support\Facades\DB;
use DomainException;

class PolicyStateService
{
    // Allowed transitions: from_status => [allowed to_statuses]
    protected array $allowedTransitions = [
        null => ['RECEIVED'],
        'RECEIVED' => ['VALIDATED', 'FAILED'],
        'VALIDATED' => ['SUBMITTED', 'FAILED'],
        'SUBMITTED' => ['ISSUED', 'PENDING_CONFIRMATION', 'NEEDS_RECONCILIATION', 'FAILED'],
        'PENDING_CONFIRMATION' => ['ISSUED', 'FAILED', 'NEEDS_RECONCILIATION'],
        'NEEDS_RECONCILIATION' => ['ISSUED', 'FAILED', 'SUBMITTED'],
        'FAILED' => ['VALIDATED', 'SUBMITTED'],
        'ISSUED' => [], // Terminal state - no further transitions permitted
    ];

    /**
     * Transition a policy request to a new status atomically with audit history.
     *
     * @param PolicyRequest $policyRequest
     * @param string $toStatus
     * @param string $reason
     * @param array $additionalAttributes Attributes to update on the PolicyRequest model
     * @return PolicyRequest
     * @throws DomainException
     */
    public function transition(
        PolicyRequest $policyRequest,
        string $toStatus,
        string $reason,
        array $additionalAttributes = []
    ): PolicyRequest {
        return DB::transaction(function () use ($policyRequest, $toStatus, $reason, $additionalAttributes) {
            // Reload with lock to avoid race conditions
            $lockedRequest = PolicyRequest::where('id', $policyRequest->id)->lockForUpdate()->firstOrFail();
            $fromStatus = $lockedRequest->status;

            // If already in target status, treat as idempotent no-op
            if ($fromStatus === $toStatus) {
                if (!empty($additionalAttributes)) {
                    $lockedRequest->update($additionalAttributes);
                }
                return $lockedRequest;
            }

            // Disallow any transition out of terminal ISSUED state
            if ($fromStatus === 'ISSUED') {
                throw new DomainException("Cannot transition request {$lockedRequest->id} from terminal state 'ISSUED' to '{$toStatus}'.");
            }

            // Verify if transition is permitted
            $allowed = $this->allowedTransitions[$fromStatus] ?? [];
            if (!in_array($toStatus, $allowed, true)) {
                throw new DomainException("Invalid state transition from '{$fromStatus}' to '{$toStatus}'.");
            }

            // Update policy request status and additional attributes
            $attributesToUpdate = array_merge($additionalAttributes, [
                'status' => $toStatus,
            ]);
            $lockedRequest->update($attributesToUpdate);

            // Record state history
            PolicyStateHistory::create([
                'policy_request_id' => $lockedRequest->id,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'reason' => $reason,
            ]);

            return $lockedRequest->fresh(['stateHistory']);
        });
    }

    /**
     * Record the initial state creation (NULL -> RECEIVED)
     */
    public function recordInitialState(PolicyRequest $policyRequest, string $reason = 'Policy request created'): void
    {
        // Avoid duplicate initial records
        $exists = PolicyStateHistory::where('policy_request_id', $policyRequest->id)
            ->whereNull('from_status')
            ->where('to_status', 'RECEIVED')
            ->exists();

        if (!$exists) {
            PolicyStateHistory::create([
                'policy_request_id' => $policyRequest->id,
                'from_status' => null,
                'to_status' => 'RECEIVED',
                'reason' => $reason,
            ]);
        }
    }
}
