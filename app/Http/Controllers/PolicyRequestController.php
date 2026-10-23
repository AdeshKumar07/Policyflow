<?php

namespace App\Http\Controllers;

use App\Models\PolicyRequest;
use App\Services\MockInsurerService;
use App\Services\PolicyStateService;
use App\Services\PremiumCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Exception;

class PolicyRequestController extends Controller
{
    public function __construct(
        protected PremiumCalculator $premiumCalculator,
        protected PolicyStateService $stateService,
        protected MockInsurerService $mockInsurer
    ) {}

    /**
     * GET /api/policy-requests
     * Paginated list with optional status filter.
     */
    public function index(Request $request): JsonResponse
    {
        $query = PolicyRequest::query();

        if ($request->filled('status')) {
            $query->where('status', strtoupper($request->query('status')));
        }

        $perPage = (int) $request->query('per_page', 10);
        $paginated = $query->orderBy('id', 'desc')->paginate($perPage);

        return response()->json($paginated);
    }

    /**
     * POST /api/policy-requests
     * Intake policy request, validate, compute premium, ensure idempotency, and record initial state.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'client_request_id' => 'required|string|max:100',
            'agent_id' => 'required|string|max:100',
            'customer_name' => 'required|string|max:255',
            'date_of_birth' => 'required|date',
            'destination' => 'required|in:DOMESTIC,INTERNATIONAL',
            'trip_start' => 'required|date',
            'trip_end' => 'required|date|after_or_equal:trip_start',
            'plan_code' => 'required|in:BASIC,PLUS,PREMIUM',
            'sum_insured' => 'required|in:100000,300000,500000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $agentId = $request->input('agent_id');
        $clientRequestId = $request->input('client_request_id');

        // Check for existing duplicate logical request (Idempotency)
        $existing = PolicyRequest::where('agent_id', $agentId)
            ->where('client_request_id', $clientRequestId)
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Policy request already exists',
                'data' => $existing,
            ], 200);
        }

        // Calculate premium
        $premium = $this->premiumCalculator->calculate(
            $request->input('date_of_birth'),
            $request->input('trip_start'),
            $request->input('trip_end'),
            $request->input('plan_code'),
            $request->input('destination'),
            (int) $request->input('sum_insured')
        );

        // Atomically create policy request and initial state history
        $policyRequest = DB::transaction(function () use ($request, $premium) {
            $record = PolicyRequest::create([
                'client_request_id' => $request->input('client_request_id'),
                'agent_id' => $request->input('agent_id'),
                'customer_name' => $request->input('customer_name'),
                'date_of_birth' => $request->input('date_of_birth'),
                'destination' => $request->input('destination'),
                'trip_start' => $request->input('trip_start'),
                'trip_end' => $request->input('trip_end'),
                'plan_code' => $request->input('plan_code'),
                'sum_insured' => (int) $request->input('sum_insured'),
                'premium' => $premium,
                'status' => 'RECEIVED',
                'attempt_count' => 0,
            ]);

            $this->stateService->recordInitialState($record, 'Policy request created');

            return $record;
        });

        return response()->json([
            'message' => 'Policy request created successfully',
            'data' => $policyRequest,
        ], 201);
    }

    /**
     * GET /api/policy-requests/{id}
     * Returns full policy details including state audit history.
     */
    public function show(int $id): JsonResponse
    {
        $policyRequest = PolicyRequest::with('stateHistory')->find($id);

        if (!$policyRequest) {
            return response()->json([
                'error' => "Policy request with ID {$id} not found",
            ], 404);
        }

        return response()->json([
            'data' => [
                'id' => $policyRequest->id,
                'client_request_id' => $policyRequest->client_request_id,
                'agent_id' => $policyRequest->agent_id,
                'customer_name' => $policyRequest->customer_name,
                'date_of_birth' => $policyRequest->date_of_birth->format('Y-m-d'),
                'destination' => $policyRequest->destination,
                'trip_start' => $policyRequest->trip_start->format('Y-m-d'),
                'trip_end' => $policyRequest->trip_end->format('Y-m-d'),
                'plan_code' => $policyRequest->plan_code,
                'sum_insured' => $policyRequest->sum_insured,
                'premium' => number_format((float) $policyRequest->premium, 2, '.', ''),
                'status' => $policyRequest->status,
                'provider_reference' => $policyRequest->provider_reference,
                'policy_number' => $policyRequest->policy_number,
                'attempt_count' => $policyRequest->attempt_count,
                'last_response' => $policyRequest->last_response,
                'created_at' => $policyRequest->created_at,
                'updated_at' => $policyRequest->updated_at,
                'state_history' => $policyRequest->stateHistory->map(function ($history) {
                    return [
                        'id' => $history->id,
                        'from_status' => $history->from_status,
                        'to_status' => $history->to_status,
                        'reason' => $history->reason,
                        'created_at' => $history->created_at,
                    ];
                }),
            ],
        ]);
    }

    /**
     * POST /api/policy-requests/{id}/submit
     * Submits policy to mock insurer, handling retries, timeouts, and idempotent state transitions.
     */
    public function submit(Request $request, int $id): JsonResponse
    {
        $policyRequest = PolicyRequest::find($id);

        if (!$policyRequest) {
            return response()->json([
                'error' => "Policy request with ID {$id} not found",
            ], 404);
        }

        // Idempotency: If already ISSUED, return existing details without resubmitting
        if ($policyRequest->status === 'ISSUED') {
            return response()->json([
                'message' => 'Policy is already ISSUED',
                'data' => [
                    'id' => $policyRequest->id,
                    'status' => $policyRequest->status,
                    'policy_number' => $policyRequest->policy_number,
                    'provider_reference' => $policyRequest->provider_reference,
                    'attempt_count' => $policyRequest->attempt_count,
                ],
            ], 200);
        }

        // Increment submission attempt count
        $policyRequest->increment('attempt_count');

        // Optional scenario for testing failure/timeout modes
        $scenario = $request->input('scenario', 'SUCCESS');

        try {
            // Step 1: Transition to VALIDATED if still in RECEIVED
            if ($policyRequest->status === 'RECEIVED') {
                $policyRequest = $this->stateService->transition(
                    $policyRequest,
                    'VALIDATED',
                    'Validation successful'
                );
            }

            // Step 2: Transition to SUBMITTED
            $policyRequest = $this->stateService->transition(
                $policyRequest,
                'SUBMITTED',
                'Submitted to insurer'
            );

            // Step 3: Call Mock Insurer
            $result = $this->mockInsurer->submit($policyRequest, $scenario);

            // Step 4: Handle response based on outcome
            if ($result['status'] === 'ISSUED') {
                $policyRequest = $this->stateService->transition(
                    $policyRequest,
                    'ISSUED',
                    'Insurer confirmed policy',
                    [
                        'provider_reference' => $result['provider_reference'],
                        'policy_number' => $result['policy_number'],
                        'last_response' => json_encode($result),
                    ]
                );

                return response()->json([
                    'message' => 'Policy issued successfully',
                    'data' => [
                        'id' => $policyRequest->id,
                        'status' => $policyRequest->status,
                        'policy_number' => $policyRequest->policy_number,
                        'provider_reference' => $policyRequest->provider_reference,
                        'attempt_count' => $policyRequest->attempt_count,
                    ],
                ], 200);
            }

            if ($result['status'] === 'PENDING') {
                $policyRequest = $this->stateService->transition(
                    $policyRequest,
                    'PENDING_CONFIRMATION',
                    'Insurer acknowledged; awaiting confirmation callback',
                    [
                        'provider_reference' => $result['provider_reference'],
                        'last_response' => json_encode($result),
                    ]
                );

                return response()->json([
                    'message' => 'Policy submitted, pending asynchronous confirmation',
                    'data' => [
                        'id' => $policyRequest->id,
                        'status' => $policyRequest->status,
                        'provider_reference' => $policyRequest->provider_reference,
                        'attempt_count' => $policyRequest->attempt_count,
                    ],
                ], 202);
            }

            if ($result['status'] === 'TIMEOUT') {
                $policyRequest = $this->stateService->transition(
                    $policyRequest,
                    'NEEDS_RECONCILIATION',
                    'Insurer connection timed out after processing; pending reconciliation',
                    [
                        'provider_reference' => $result['provider_reference'],
                        'last_response' => json_encode($result),
                    ]
                );

                return response()->json([
                    'message' => 'Submission timed out. Marked for reconciliation.',
                    'data' => [
                        'id' => $policyRequest->id,
                        'status' => $policyRequest->status,
                        'provider_reference' => $policyRequest->provider_reference,
                        'attempt_count' => $policyRequest->attempt_count,
                    ],
                ], 504);
            }

            // Other failures
            $policyRequest = $this->stateService->transition(
                $policyRequest,
                'FAILED',
                'Insurer submission failed: ' . ($result['message'] ?? 'Unknown failure'),
                [
                    'last_response' => json_encode($result),
                ]
            );

            return response()->json([
                'message' => 'Submission failed',
                'error' => $result['message'] ?? 'Provider error',
                'data' => [
                    'id' => $policyRequest->id,
                    'status' => $policyRequest->status,
                    'attempt_count' => $policyRequest->attempt_count,
                ],
            ], 502);

        } catch (Exception $e) {
            return response()->json([
                'error' => 'State transition error: ' . $e->getMessage(),
                'status' => $policyRequest->status,
            ], 500);
        }
    }

    /**
     * POST /api/policy-requests/{id}/reconcile
     * Reconciles timed-out or unresolved policy requests by querying the provider.
     */
    public function reconcile(int $id): JsonResponse
    {
        $policyRequest = PolicyRequest::find($id);

        if (!$policyRequest) {
            return response()->json([
                'error' => "Policy request with ID {$id} not found",
            ], 404);
        }

        // If already ISSUED, no action needed
        if ($policyRequest->status === 'ISSUED') {
            return response()->json([
                'message' => 'Policy is already ISSUED',
                'data' => [
                    'id' => $policyRequest->id,
                    'status' => $policyRequest->status,
                    'policy_number' => $policyRequest->policy_number,
                    'provider_reference' => $policyRequest->provider_reference,
                ],
            ], 200);
        }

        // Query mock insurer registry
        $providerRecord = $this->mockInsurer->queryStatus(
            $policyRequest->client_request_id,
            $policyRequest->provider_reference
        );

        if ($providerRecord && $providerRecord['status'] === 'ISSUED') {
            $policyRequest = $this->stateService->transition(
                $policyRequest,
                'ISSUED',
                'Policy reconciled with provider records',
                [
                    'policy_number' => $providerRecord['policy_number'],
                    'provider_reference' => $providerRecord['provider_reference'],
                    'last_response' => json_encode([
                        'reconciled' => true,
                        'provider_record' => $providerRecord,
                    ]),
                ]
            );

            return response()->json([
                'message' => 'Policy reconciled successfully and ISSUED',
                'data' => [
                    'id' => $policyRequest->id,
                    'status' => $policyRequest->status,
                    'policy_number' => $policyRequest->policy_number,
                    'provider_reference' => $policyRequest->provider_reference,
                ],
            ], 200);
        }

        return response()->json([
            'message' => 'Provider does not have an issued policy for this request',
            'current_status' => $policyRequest->status,
        ], 200);
    }
}