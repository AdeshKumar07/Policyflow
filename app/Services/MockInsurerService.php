<?php

namespace App\Services;

use App\Models\PolicyRequest;
use Illuminate\Support\Facades\Cache;

class MockInsurerService
{
    /**
     * Submit policy to mock insurer
     *
     * @param PolicyRequest $policyRequest
     * @param string $scenario SUCCESS | TEMPORARY_FAILURE | TIMEOUT_AFTER_ISSUE | DELAYED_CALLBACK
     * @return array
     */
    public function submit(PolicyRequest $policyRequest, string $scenario = 'SUCCESS'): array
    {
        $clientRequestId = $policyRequest->client_request_id;
        $cacheKey = "mock_insurer_policy_{$clientRequestId}";

        // Consistent, deterministic identifiers for the same client_request_id
        $providerReference = 'INS-' . strtoupper(substr(md5($clientRequestId), 0, 8));
        $policyNumber = 'POL-' . strtoupper(substr(sha1($clientRequestId), 0, 10));

        // Check if insurer already issued this policy internally
        $existing = Cache::get($cacheKey);
        if ($existing && $existing['status'] === 'ISSUED') {
            return [
                'success' => true,
                'status' => 'ISSUED',
                'provider_reference' => $existing['provider_reference'],
                'policy_number' => $existing['policy_number'],
                'message' => 'Policy already issued by insurer',
            ];
        }

        switch (strtoupper($scenario)) {
            case 'TEMPORARY_FAILURE':
                return [
                    'success' => false,
                    'status' => 'FAILED',
                    'error_code' => 'PROVIDER_UNAVAILABLE',
                    'message' => 'Provider gateway temporarily unavailable. Please retry.',
                ];

            case 'TIMEOUT_AFTER_ISSUE':
                // The insurer processed and issued the policy, but the response timed out
                $record = [
                    'status' => 'ISSUED',
                    'provider_reference' => $providerReference,
                    'policy_number' => $policyNumber,
                    'client_request_id' => $clientRequestId,
                    'issued_at' => now()->toISOString(),
                ];
                Cache::put($cacheKey, $record, now()->addDays(7));
                Cache::put("mock_insurer_by_ref_{$providerReference}", $record, now()->addDays(7));

                return [
                    'success' => false,
                    'status' => 'TIMEOUT',
                    'provider_reference' => $providerReference,
                    'error_code' => 'GATEWAY_TIMEOUT',
                    'message' => 'Insurer connection timed out after policy processing.',
                ];

            case 'DELAYED_CALLBACK':
                // Acknowledged by insurer, pending asynchronous confirmation
                $record = [
                    'status' => 'PENDING',
                    'provider_reference' => $providerReference,
                    'policy_number' => $policyNumber,
                    'client_request_id' => $clientRequestId,
                ];
                Cache::put($cacheKey, $record, now()->addDays(7));
                Cache::put("mock_insurer_by_ref_{$providerReference}", $record, now()->addDays(7));

                return [
                    'success' => true,
                    'status' => 'PENDING',
                    'provider_reference' => $providerReference,
                    'message' => 'Submission acknowledged. Final status will be sent via callback.',
                ];

            case 'SUCCESS':
            default:
                $record = [
                    'status' => 'ISSUED',
                    'provider_reference' => $providerReference,
                    'policy_number' => $policyNumber,
                    'client_request_id' => $clientRequestId,
                    'issued_at' => now()->toISOString(),
                ];
                Cache::put($cacheKey, $record, now()->addDays(7));
                Cache::put("mock_insurer_by_ref_{$providerReference}", $record, now()->addDays(7));

                return [
                    'success' => true,
                    'status' => 'ISSUED',
                    'provider_reference' => $providerReference,
                    'policy_number' => $policyNumber,
                    'message' => 'Policy issued successfully',
                ];
        }
    }

    /**
     * Query mock insurer for policy status (reconciliation)
     *
     * @param string $clientRequestId
     * @param string|null $providerReference
     * @return array|null
     */
    public function queryStatus(string $clientRequestId, ?string $providerReference = null): ?array
    {
        $cacheKey = "mock_insurer_policy_{$clientRequestId}";
        $record = Cache::get($cacheKey);

        if (!$record && $providerReference) {
            $record = Cache::get("mock_insurer_by_ref_{$providerReference}");
        }

        return $record;
    }
}
