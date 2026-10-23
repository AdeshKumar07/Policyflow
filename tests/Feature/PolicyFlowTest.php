<?php

namespace Tests\Feature;

use App\Models\PolicyRequest;
use App\Models\PolicyStateHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PolicyFlowTest extends TestCase
{
    use RefreshDatabase;

    protected array $validPayload = [
        'client_request_id' => 'REQ-TEST-001',
        'agent_id' => 'AGENT-007',
        'customer_name' => 'Aditi Rao',
        'date_of_birth' => '1998-04-10',
        'destination' => 'INTERNATIONAL',
        'trip_start' => '2026-11-10',
        'trip_end' => '2026-11-14',
        'plan_code' => 'PLUS',
        'sum_insured' => 300000,
    ];

    /**
     * 1. Valid request calculates expected premium and returns RECEIVED.
     */
    public function test_valid_policy_request_calculates_premium_and_returns_received(): void
    {
        $response = $this->postJson('/api/policy-requests', $this->validPayload);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'RECEIVED')
            ->assertJsonPath('data.premium', '225.00')
            ->assertJsonPath('data.client_request_id', 'REQ-TEST-001');

        $this->assertDatabaseHas('policy_requests', [
            'client_request_id' => 'REQ-TEST-001',
            'agent_id' => 'AGENT-007',
            'status' => 'RECEIVED',
            'premium' => 225.00,
        ]);

        // State history check
        $this->assertDatabaseHas('policy_state_history', [
            'from_status' => null,
            'to_status' => 'RECEIVED',
            'reason' => 'Policy request created',
        ]);
    }

    /**
     * 2. Invalid trip dates return 422.
     */
    public function test_invalid_trip_dates_return_validation_error(): void
    {
        $payload = array_merge($this->validPayload, [
            'trip_start' => '2026-11-15',
            'trip_end' => '2026-11-10', // End before start
        ]);

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['trip_end']);
    }

    /**
     * 3. Unsupported plan returns 422.
     */
    public function test_unsupported_plan_code_returns_validation_error(): void
    {
        $payload = array_merge($this->validPayload, [
            'plan_code' => 'SUPER_DELUXE_UNKNOWN',
        ]);

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['plan_code']);
    }

    /**
     * 4. Duplicate same agent + client_request_id does not create another logical request.
     */
    public function test_duplicate_agent_and_client_request_id_returns_existing_record_idempotently(): void
    {
        // First submission creates
        $first = $this->postJson('/api/policy-requests', $this->validPayload);
        $first->assertStatus(201);
        $createdId = $first->json('data.id');

        // Second submission with exact same agent + client_request_id
        $second = $this->postJson('/api/policy-requests', $this->validPayload);
        $second->assertStatus(200)
            ->assertJsonPath('message', 'Policy request already exists')
            ->assertJsonPath('data.id', $createdId);

        // Database still only has 1 record
        $this->assertEquals(1, PolicyRequest::where('agent_id', 'AGENT-007')->where('client_request_id', 'REQ-TEST-001')->count());
    }

    /**
     * 5. Successful insurer response creates exactly one policy number.
     */
    public function test_successful_submit_transitions_to_issued_with_one_policy_number(): void
    {
        $create = $this->postJson('/api/policy-requests', $this->validPayload);
        $id = $create->json('data.id');

        // Submit to mock insurer
        $submit = $this->postJson("/api/policy-requests/{$id}/submit", [
            'scenario' => 'SUCCESS',
        ]);

        $submit->assertStatus(200)
            ->assertJsonPath('data.status', 'ISSUED');

        $policyNumber = $submit->json('data.policy_number');
        $this->assertNotNull($policyNumber);

        // Submitting again (idempotent retry) does not change policy number
        $retry = $this->postJson("/api/policy-requests/{$id}/submit");
        $retry->assertStatus(200)
            ->assertJsonPath('data.policy_number', $policyNumber);

        $fresh = PolicyRequest::find($id);
        $this->assertEquals('ISSUED', $fresh->status);
        $this->assertEquals($policyNumber, $fresh->policy_number);
    }

    /**
     * 6. Timeout-after-success does not create duplicate policy and reconciles correctly.
     */
    public function test_timeout_after_success_moves_to_reconciliation_and_does_not_duplicate_policy(): void
    {
        $create = $this->postJson('/api/policy-requests', $this->validPayload);
        $id = $create->json('data.id');

        // Submit with TIMEOUT_AFTER_ISSUE
        $timeoutResponse = $this->postJson("/api/policy-requests/{$id}/submit", [
            'scenario' => 'TIMEOUT_AFTER_ISSUE',
        ]);

        $timeoutResponse->assertStatus(504)
            ->assertJsonPath('data.status', 'NEEDS_RECONCILIATION');

        $this->assertDatabaseHas('policy_requests', [
            'id' => $id,
            'status' => 'NEEDS_RECONCILIATION',
        ]);

        // Reconcile endpoint discovers the provider's already-issued policy
        $reconcile = $this->postJson("/api/policy-requests/{$id}/reconcile");
        $reconcile->assertStatus(200)
            ->assertJsonPath('data.status', 'ISSUED');

        $policyNumber = $reconcile->json('data.policy_number');
        $this->assertNotNull($policyNumber);

        // Subsequent reconcile or submit returns the exact same policy number
        $reconcileAgain = $this->postJson("/api/policy-requests/{$id}/reconcile");
        $reconcileAgain->assertStatus(200)
            ->assertJsonPath('data.policy_number', $policyNumber);
    }

    /**
     * 7. Duplicate callback does not change an already ISSUED request.
     */
    public function test_duplicate_callback_does_not_modify_issued_policy(): void
    {
        $create = $this->postJson('/api/policy-requests', $this->validPayload);
        $id = $create->json('data.id');

        // Submit with delayed callback scenario
        $submit = $this->postJson("/api/policy-requests/{$id}/submit", [
            'scenario' => 'DELAYED_CALLBACK',
        ]);
        $submit->assertStatus(202)
            ->assertJsonPath('data.status', 'PENDING_CONFIRMATION');

        $providerRef = $submit->json('data.provider_reference');

        // Callback 1: confirms issuance
        $callback1 = $this->postJson('/api/mock-insurer/callback', [
            'event_id' => 'EVT-001',
            'provider_reference' => $providerRef,
            'client_request_id' => 'REQ-TEST-001',
            'policy_number' => 'POL-UNIQUE-999',
            'status' => 'ISSUED',
        ]);
        $callback1->assertStatus(200)
            ->assertJsonPath('data.status', 'ISSUED')
            ->assertJsonPath('data.policy_number', 'POL-UNIQUE-999');

        $countAfterCallback1 = PolicyStateHistory::where('policy_request_id', $id)->count();

        // Callback 2: identical duplicate delivery
        $callback2 = $this->postJson('/api/mock-insurer/callback', [
            'event_id' => 'EVT-001',
            'provider_reference' => $providerRef,
            'client_request_id' => 'REQ-TEST-001',
            'policy_number' => 'POL-DIFFERENT-SHOULD-NOT-OVERWRITE',
            'status' => 'ISSUED',
        ]);
        $callback2->assertStatus(200)
            ->assertJsonPath('data.status', 'ISSUED');

        // Verify policy number was NOT overwritten and no spurious state transitions occurred
        $fresh = PolicyRequest::find($id);
        $this->assertEquals('POL-UNIQUE-999', $fresh->policy_number);
        $this->assertEquals('ISSUED', $fresh->status);
        $this->assertEquals($countAfterCallback1, PolicyStateHistory::where('policy_request_id', $id)->count());
    }

    /**
     * 8. State history is recorded correctly throughout lifecycle.
     */
    public function test_state_history_audit_trail_is_recorded_correctly(): void
    {
        $create = $this->postJson('/api/policy-requests', $this->validPayload);
        $id = $create->json('data.id');

        $this->postJson("/api/policy-requests/{$id}/submit", ['scenario' => 'SUCCESS']);

        $histories = PolicyStateHistory::where('policy_request_id', $id)->orderBy('id')->get();

        // Sequence: (NULL -> RECEIVED), (RECEIVED -> VALIDATED), (VALIDATED -> SUBMITTED), (SUBMITTED -> ISSUED)
        $this->assertCount(4, $histories);
        $this->assertNull($histories[0]->from_status);
        $this->assertEquals('RECEIVED', $histories[0]->to_status);

        $this->assertEquals('RECEIVED', $histories[1]->from_status);
        $this->assertEquals('VALIDATED', $histories[1]->to_status);

        $this->assertEquals('VALIDATED', $histories[2]->from_status);
        $this->assertEquals('SUBMITTED', $histories[2]->to_status);

        $this->assertEquals('SUBMITTED', $histories[3]->from_status);
        $this->assertEquals('ISSUED', $histories[3]->to_status);
    }

    /**
     * 9. GET detail returns full audit trail.
     */
    public function test_get_detail_returns_audit_trail_and_expected_structure(): void
    {
        $create = $this->postJson('/api/policy-requests', $this->validPayload);
        $id = $create->json('data.id');

        $this->postJson("/api/policy-requests/{$id}/submit", ['scenario' => 'SUCCESS']);

        $response = $this->getJson("/api/policy-requests/{$id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'client_request_id',
                    'agent_id',
                    'customer_name',
                    'date_of_birth',
                    'destination',
                    'trip_start',
                    'trip_end',
                    'plan_code',
                    'sum_insured',
                    'premium',
                    'status',
                    'provider_reference',
                    'policy_number',
                    'attempt_count',
                    'last_response',
                    'state_history' => [
                        '*' => [
                            'id',
                            'from_status',
                            'to_status',
                            'reason',
                            'created_at',
                        ],
                    ],
                ],
            ]);
    }

    /**
     * 10. List endpoint supports pagination and status filter.
     */
    public function test_list_endpoint_supports_status_filter_and_pagination(): void
    {
        // Create 2 RECEIVED policies
        $this->postJson('/api/policy-requests', array_merge($this->validPayload, [
            'client_request_id' => 'REQ-LIST-001',
        ]));
        $second = $this->postJson('/api/policy-requests', array_merge($this->validPayload, [
            'client_request_id' => 'REQ-LIST-002',
        ]));
        $secondId = $second->json('data.id');

        // Submit second policy to make it ISSUED
        $this->postJson("/api/policy-requests/{$secondId}/submit", ['scenario' => 'SUCCESS']);

        // Filter by ISSUED
        $issuedResponse = $this->getJson('/api/policy-requests?status=ISSUED');
        $issuedResponse->assertStatus(200)
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $secondId);

        // Filter by RECEIVED
        $receivedResponse = $this->getJson('/api/policy-requests?status=RECEIVED');
        $receivedResponse->assertStatus(200)
            ->assertJsonPath('total', 1);

        // All with pagination
        $allResponse = $this->getJson('/api/policy-requests?per_page=1');
        $allResponse->assertStatus(200)
            ->assertJsonPath('total', 2)
            ->assertJsonPath('per_page', 1);
    }
}
