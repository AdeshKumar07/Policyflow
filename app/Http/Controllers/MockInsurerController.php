<?php

namespace App\Http\Controllers;

use App\Models\PolicyRequest;
use App\Services\PolicyStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MockInsurerController extends Controller
{
    public function __construct(
        protected PolicyStateService $stateService
    ) {}

    /**
     * Process asynchronous callback from the mock insurer.
     * Handled idempotently for duplicate deliveries.
     */
    public function handleCallback(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'provider_reference' => 'nullable|string|max:100',
            'client_request_id' => 'nullable|string|max:100',
            'policy_number' => 'nullable|string|max:100',
            'status' => 'required|string',
            'event_id' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $providerRef = $request->input('provider_reference');
        $clientRequestId = $request->input('client_request_id');
        $eventId = $request->input('event_id', 'UNKNOWN');
        $status = strtoupper($request->input('status'));

        // Locate policy request by provider_reference or client_request_id
        $policyRequest = null;
        if ($providerRef) {
            $policyRequest = PolicyRequest::where('provider_reference', $providerRef)->first();
        }
        if (!$policyRequest && $clientRequestId) {
            $policyRequest = PolicyRequest::where('client_request_id', $clientRequestId)->first();
        }

        if (!$policyRequest) {
            return response()->json([
                'error' => 'Policy request not found for the provided reference',
            ], 404);
        }

        // Idempotency check: if already ISSUED, safely acknowledge without modifying
        if ($policyRequest->status === 'ISSUED') {
            return response()->json([
                'status' => 'ok',
                'message' => 'Callback received. Policy is already in terminal ISSUED state.',
                'data' => [
                    'id' => $policyRequest->id,
                    'status' => $policyRequest->status,
                    'policy_number' => $policyRequest->policy_number,
                    'provider_reference' => $policyRequest->provider_reference,
                ],
            ], 200);
        }

        $policyNumber = $request->input('policy_number', $policyRequest->policy_number);
        if (!$policyNumber) {
            $policyNumber = 'POL-' . strtoupper(substr(sha1($policyRequest->client_request_id), 0, 10));
        }

        if (in_array($status, ['ISSUED', 'SUCCESS', 'CONFIRMED'], true)) {
            $policyRequest = $this->stateService->transition(
                $policyRequest,
                'ISSUED',
                "Policy confirmed via insurer callback [Event ID: {$eventId}]",
                [
                    'policy_number' => $policyNumber,
                    'provider_reference' => $providerRef ?? $policyRequest->provider_reference,
                    'last_response' => json_encode($request->all()),
                ]
            );

            return response()->json([
                'status' => 'ok',
                'message' => 'Callback processed successfully. Policy ISSUED.',
                'data' => [
                    'id' => $policyRequest->id,
                    'status' => $policyRequest->status,
                    'policy_number' => $policyRequest->policy_number,
                    'provider_reference' => $policyRequest->provider_reference,
                ],
            ], 200);
        }

        if (in_array($status, ['FAILED', 'REJECTED'], true)) {
            $policyRequest = $this->stateService->transition(
                $policyRequest,
                'FAILED',
                "Policy rejected via insurer callback [Event ID: {$eventId}]",
                [
                    'last_response' => json_encode($request->all()),
                ]
            );

            return response()->json([
                'status' => 'ok',
                'message' => 'Callback processed. Policy marked as FAILED.',
                'data' => [
                    'id' => $policyRequest->id,
                    'status' => $policyRequest->status,
                ],
            ], 200);
        }

        return response()->json([
            'status' => 'ignored',
            'message' => "Unrecognized callback status '{$status}'. No state change made.",
        ], 200);
    }
}
