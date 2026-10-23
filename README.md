# PolicyFlow — Fault-Tolerant Insurance Issuance & Reconciliation Engine

PolicyFlow is a Laravel-based backend system that simulates a travel-insurance policy issuance workflow between insurance agents, a distributor platform, and an external insurer.

The main objective is not only to create an insurance policy, but to make the issuance workflow **reliable in the presence of duplicate requests, provider failures, timeouts, delayed responses, and duplicate callbacks**.

---

## 1. Problem Statement

A travel-insurance distributor receives policy purchase requests from agents.

A typical request goes through:

```text
Agent
  ↓
PolicyFlow
  ↓
Validate Request
  ↓
Calculate Premium
  ↓
Submit to External Insurer
  ↓
Receive Provider Response / Callback
  ↓
Update Final Policy State
```

The difficult part is that external systems are not always reliable.

For example:

- The same request may arrive twice.
- The insurer may temporarily fail.
- The insurer may issue a policy but our application may experience a timeout.
- The insurer may send the same callback multiple times.
- A delayed callback may arrive after the system has already changed state.

Therefore, PolicyFlow is designed around **idempotency, explicit state transitions, audit history, and reconciliation**.

---

# 2. Technology Stack

- **Backend:** PHP 8.2+, Laravel 11+
- **Database:** MySQL 8+
- **API:** REST
- **Testing:** Laravel/PHPUnit
- **Dependency Management:** Composer
- **Development:** Laravel Artisan
- **Version Control:** Git/GitHub

---

# 3. High-Level Architecture

```text
                  ┌──────────────────────┐
                  │       Agent          │
                  └──────────┬───────────┘
                             │
                             ▼
                  ┌──────────────────────┐
                  │   PolicyFlow API     │
                  └──────────┬───────────┘
                             │
              ┌──────────────┼──────────────┐
              │              │              │
              ▼              ▼              ▼
        Validation     PremiumCalculator   Database
              │              │
              └──────────────┘
                             │
                             ▼
                    Submit to Insurer
                             │
                             ▼
                  ┌──────────────────────┐
                  │    Mock Insurer      │
                  └──────────┬───────────┘
                             │
              ┌──────────────┼───────────────┐
              │              │               │
           SUCCESS       FAILURE          TIMEOUT
              │              │               │
              └──────────────┼───────────────┘
                             ▼
                     State Management
                             │
                             ▼
                    Policy State History
```

---

# 4. Core Business Flow

A policy request follows this general lifecycle:

```text
RECEIVED
   ↓
VALIDATED
   ↓
SUBMITTED
   ↓
PENDING_CONFIRMATION
   ↓
ISSUED
```

Failure paths can result in:

```text
SUBMITTED
   ↓
FAILED
```

or:

```text
PENDING_CONFIRMATION
   ↓
NEEDS_RECONCILIATION
```

The important design principle is:

> A policy state should only change through controlled state-transition logic.

This prevents invalid transitions such as:

```text
ISSUED → SUBMITTED
ISSUED → FAILED
ISSUED → RECEIVED
```

An already confirmed `ISSUED` policy should not accidentally be overwritten by a later duplicate or delayed response.

---

# 5. Policy Request Creation

The main endpoint is:

```text
POST /api/policy-requests
```

Example request:

```json
{
    "client_request_id": "AGT-2026-0002",
    "agent_id": "AGENT-17",
    "customer_name": "Rahul Sharma",
    "date_of_birth": "1998-04-10",
    "destination": "INTERNATIONAL",
    "trip_start": "2026-11-10",
    "trip_end": "2026-11-14",
    "plan_code": "PLUS",
    "sum_insured": 300000
}
```

The API validates:

- Required fields
- Date formats
- Trip date ordering
- Supported destination
- Supported plan
- Supported coverage

The request is then stored with:

```text
status = RECEIVED
attempt_count = 0
```

---

# 6. Idempotency / Duplicate Intake

One of the most important reliability requirements is preventing duplicate logical requests.

The unique business identity is:

```text
agent_id + client_request_id
```

For example:

```text
AGENT-17 + AGT-2026-0002
```

If the exact same request is received again, PolicyFlow returns the existing request instead of creating another logical request.

```text
First request
     ↓
Create request ID = 1

Same request again
     ↓
Find existing request
     ↓
Return existing request
```

There is also a database-level unique constraint:

```text
UNIQUE(agent_id, client_request_id)
```

This provides a second layer of protection.

### Why both application and database protection?

Application-level checking provides a clean API response.

Database uniqueness provides the final integrity guarantee if two requests reach the database concurrently.

---

# 7. Premium Calculation

Premium calculation is isolated inside:

```text
App\Services\PremiumCalculator
```

This keeps business pricing logic separate from the controller.

## Formula

```text
Premium =
Daily Rate
× Travel Days
× Age Multiplier
× Destination Multiplier
× Coverage Multiplier
```

Travel days are inclusive:

```text
trip_end - trip_start + 1
```

Therefore:

```text
10 Nov → 14 Nov = 5 days
```

## Plan Rates

| Plan | Daily Rate |
|---|---:|
| BASIC | ₹12 |
| PLUS | ₹20 |
| PREMIUM | ₹30 |

## Age Multipliers

| Age | Multiplier |
|---|---:|
| 0–17 | 1.10 |
| 18–60 | 1.00 |
| 61–70 | 1.50 |
| 71+ | 2.00 |

## Destination

| Destination | Multiplier |
|---|---:|
| DOMESTIC | 1.00 |
| INTERNATIONAL | 1.80 |

## Coverage

| Sum Insured | Multiplier |
|---:|---:|
| ₹100,000 | 1.00 |
| ₹300,000 | 1.25 |
| ₹500,000 | 1.50 |

The calculated premium is stored with the policy request so that the pricing decision becomes a **snapshot** of the request at creation time.

---

# 8. Example Premium Calculation

Suppose:

```text
Plan              = PLUS
Daily rate        = ₹20
Trip              = 5 days
Age               = 28
Age multiplier    = 1.00
Destination       = INTERNATIONAL
Destination mult. = 1.80
Coverage           = ₹300,000
Coverage mult.     = 1.25
```

Then:

```text
Premium
= 20 × 5 × 1.00 × 1.80 × 1.25

= ₹225
```

The system stores:

```text
premium = 225.00
```

---

# 9. State Management

The policy lifecycle is controlled through explicit states.

### RECEIVED

The request has been accepted by PolicyFlow.

### VALIDATED

The request has passed business validation.

### SUBMITTED

The request has been sent to the insurer.

### PENDING_CONFIRMATION

The insurer outcome is not yet conclusively known.

### ISSUED

The insurer has confirmed successful policy issuance.

### FAILED

The issuance attempt has definitively failed.

### NEEDS_RECONCILIATION

The local system cannot safely determine the final provider outcome and needs to verify the external insurer state.

---

# 10. Why State Transitions Matter

A naive implementation might simply do:

```php
$policy->status = 'ISSUED';
$policy->save();
```

This becomes dangerous when external callbacks arrive out of order.

For example:

```text
SUCCESS callback
       ↓
ISSUED

Later duplicate callback
       ↓
FAILED
```

That would incorrectly change an already issued policy.

PolicyFlow therefore treats state changes as controlled operations.

The important rule is:

> Once a request reaches a confirmed terminal state such as `ISSUED`, a duplicate or stale response must not incorrectly move it backwards.

---

# 11. State History / Audit Trail

Every important state change should be recorded.

Example:

```text
RECEIVED
   ↓
VALIDATED
   ↓
SUBMITTED
   ↓
PENDING_CONFIRMATION
   ↓
ISSUED
```

The `policy_state_history` table records:

```text
policy_request_id
from_status
to_status
reason
created_at
```

Example:

```text
RECEIVED → VALIDATED
Reason: Request validation successful

VALIDATED → SUBMITTED
Reason: Sent to insurer

SUBMITTED → PENDING_CONFIRMATION
Reason: Provider response timed out

PENDING_CONFIRMATION → ISSUED
Reason: Provider confirmed policy issuance
```

This makes the system easier to debug and explain during an incident.

---

# 12. External Insurer Simulation

Because a real insurer API is not available, the project uses a **Mock Insurer**.

Supported scenarios:

```text
SUCCESS
TEMPORARY_FAILURE
TIMEOUT_AFTER_ISSUE
DELAYED_CALLBACK
DUPLICATE_CALLBACK
```

These scenarios simulate real distributed-system problems.

---

# 13. Successful Issuance

Normal flow:

```text
Client
  ↓
POST /policy-requests
  ↓
RECEIVED
  ↓
Submit
  ↓
SUBMITTED
  ↓
Mock Insurer
  ↓
SUCCESS
  ↓
ISSUED
```

The system stores:

```text
provider_reference
policy_number
last_response
attempt_count
```

The generated policy number must remain unique.

---

# 14. Temporary Failure

Example:

```text
PolicyFlow
    ↓
Insurer
    ↓
TEMPORARY_FAILURE
```

The system should not immediately mark a potentially recoverable provider issue as a successful issuance.

Depending on the implemented retry strategy, the request can be retried safely while respecting idempotency and state rules.

---

# 15. Timeout After Issue

This is one of the most important reliability scenarios.

Consider:

```text
PolicyFlow
    ↓
Insurer: Issue policy
    ↓
Insurer successfully issues policy
    ↓
Network timeout
    ↓
PolicyFlow does NOT receive success response
```

The insurer may already have:

```text
Policy Number = POL-12345
Status = ISSUED
```

while PolicyFlow only knows:

```text
Status = PENDING_CONFIRMATION
```

### Dangerous approach

Simply retry the issuance:

```text
TIMEOUT
   ↓
Retry
   ↓
Issue another policy
```

This could create duplicate policies.

### Safer approach

Reconcile with the provider:

```text
PENDING_CONFIRMATION
        ↓
Check provider state
        ↓
Provider says ISSUED
        ↓
Store existing policy number
        ↓
ISSUED
```

Therefore:

> Reconciliation means bringing our local state back in sync with the actual external provider state.

---

# 16. Duplicate Callback Handling

External systems may send the same callback more than once.

Example:

```text
Callback #1
POL-12345 → ISSUED

Callback #2
POL-12345 → ISSUED

Callback #3
POL-12345 → ISSUED
```

PolicyFlow must not create:

```text
POL-12345
POL-12346
POL-12347
```

The callback processing must be idempotent.

The expected result is:

```text
One policy request
One policy number
ISSUED
```

regardless of repeated identical callbacks.

---

# 17. Important Reliability Principle

The external provider is **not assumed to be perfectly reliable**.

Therefore the application is designed around:

```text
Idempotency
+
State Machine
+
Audit History
+
Provider Reference
+
Policy Number
+
Controlled Retry/Reconciliation
```

These mechanisms work together to prevent duplicate issuance and incorrect final states.

---

# 18. API Overview

## Create Policy Request

```text
POST /api/policy-requests
```

Creates and prices a policy request.

---

## Get Policy Request

```text
GET /api/policy-requests/{id}
```

Returns policy details and, where implemented, state history.

---

## Submit Policy Request

```text
POST /api/policy-requests/{id}/submit
```

Attempts submission to the external insurer.

The operation updates:

```text
attempt_count
status
provider_reference
last_response
```

according to the provider outcome.

---

## Mock Insurer Callback

```text
POST /api/mock-insurer/callback
```

Simulates asynchronous provider callbacks.

---

## List Policy Requests

```text
GET /api/policy-requests
```

Supports:

```text
status filtering
pagination
```

Example:

```text
GET /api/policy-requests?status=ISSUED&page=1
```

---

# 19. Database Design

## policy_requests

Main business table.

Important fields:

```text
id
client_request_id
agent_id
customer_name
date_of_birth
destination
trip_start
trip_end
plan_code
sum_insured
premium
status
provider_reference
policy_number
attempt_count
last_response
created_at
updated_at
```

### Important constraint

```text
UNIQUE(agent_id, client_request_id)
```

This protects duplicate intake.

---

## policy_state_history

Audit table.

```text
id
policy_request_id
from_status
to_status
reason
created_at
updated_at
```

Relationship:

```text
PolicyRequest
    |
    | 1 : N
    ↓
PolicyStateHistory
```

One policy request can have many state-history records.

---

# 20. Why PremiumCalculator Is a Separate Service

Instead of putting the pricing formula directly inside the controller:

```text
Controller
   ↓
PremiumCalculator
   ↓
Premium
```

This gives us:

- Easier testing
- Cleaner controller
- Separation of concerns
- Reusable pricing logic
- Easier future modification

For example, if insurance pricing changes later, the pricing service can be modified without making the API controller unnecessarily large.

---

# 21. Why Database Constraints Matter

Application logic alone is not enough.

For example:

```text
Request A checks:
"No duplicate found"

Request B checks:
"No duplicate found"
```

Both requests could theoretically proceed concurrently.

A database unique constraint provides a final protection layer.

Therefore:

```text
Application validation
        +
Database constraint
        =
Stronger data integrity
```

---

# 22. Testing Strategy

The system should be tested at both the business and reliability level.

Important test cases include:

### Valid request

```text
Input
  ↓
Expected premium
  ↓
RECEIVED
```

### Invalid dates

```text
trip_end < trip_start
        ↓
422 Validation Error
```

### Unsupported plan

```text
GOLD
 ↓
422 Validation Error
```

### Duplicate intake

```text
Same agent_id + client_request_id
        ↓
No second logical request
```

### Successful issuance

```text
SUCCESS
   ↓
ISSUED
   ↓
Exactly one policy number
```

### Timeout after issue

```text
Provider issued
        +
Local timeout
        ↓
Reconciliation
        ↓
ISSUED
```

### Duplicate callback

```text
Same callback × multiple times
        ↓
Still one policy
```

### Terminal-state protection

```text
ISSUED
  ↓
stale/duplicate callback
  ↓
still ISSUED
```

---

# 23. How I Explain This Project in an Interview

If the interviewer asks:

### "Explain your project."

A strong answer is:

> "I built PolicyFlow, a Laravel-based fault-tolerant travel insurance issuance engine. The system receives policy requests from agents, validates them, calculates premiums based on plan, travel duration, age, destination and coverage, and then submits the request to a simulated external insurer.
>
> The main challenge I focused on was reliability because an external insurer can timeout, fail temporarily, delay callbacks, or send duplicate callbacks. So I implemented idempotent request handling using agent ID plus client request ID, explicit policy state transitions, state-history auditing, provider references and protection against duplicate issuance.
>
> One important scenario is timeout-after-issue. The insurer may successfully issue a policy while our application doesn't receive the response because of a timeout. Instead of blindly retrying and potentially creating a second policy, the system uses reconciliation to determine the provider's actual state and bring our local state back to the correct final state.
>
> I also use automated tests to verify duplicate requests, successful issuance, timeout scenarios, duplicate callbacks and terminal-state protection."

---

# 24. If Interviewer Asks: "What Was the Hardest Part?"

Answer:

> "The hardest part was not the CRUD implementation. It was handling distributed-system failure scenarios correctly. For example, a timeout doesn't necessarily mean the insurer failed. The insurer might have already issued the policy. So treating every timeout as a failure and retrying can create duplicate policies. I therefore designed the workflow around explicit states, idempotency and reconciliation."

---

# 25. If Interviewer Asks: "What Is Idempotency?"

Answer:

> "Idempotency means processing the same logical request multiple times should not create multiple effects. In my system, the combination of agent ID and client request ID identifies a logical request. If the same request arrives again, the existing request is returned instead of creating another one. The database also has a unique constraint as an additional protection."

---

# 26. If Interviewer Asks: "What Is Reconciliation?"

Answer:

> "Reconciliation is the process of comparing our local policy state with the actual provider state when we cannot determine the outcome reliably. For example, if the insurer issued a policy but our request timed out, our system may remain in pending confirmation. Reconciliation checks the provider state and, if the provider confirms issuance, updates our local record with the existing policy number instead of retrying issuance."

---

# 27. If Interviewer Asks: "Why Do You Need State History?"

Answer:

> "The current status tells us where the policy is now, but state history tells us how it got there. This is useful for debugging, auditing and understanding failure scenarios. For example, we can see that a request moved from SUBMITTED to PENDING_CONFIRMATION because of a timeout and later became ISSUED after provider confirmation."

---

# 28. If Interviewer Asks: "Why Can't You Just Retry After Timeout?"

Answer:

> "Because timeout only tells us that we didn't receive the response. It doesn't prove that the insurer didn't process the request. The insurer may have already issued the policy. Blindly retrying could therefore create duplicate issuance. We need to determine the provider's actual state before deciding whether retrying is safe."

---

# 29. Key Design Principles

The project is built around five major principles:

```text
1. Idempotency
       ↓
Prevent duplicate logical requests

2. Explicit state transitions
       ↓
Prevent invalid state changes

3. Audit history
       ↓
Know how a request reached its current state

4. Reconciliation
       ↓
Handle unknown external outcomes safely

5. Automated testing
       ↓
Verify reliability scenarios
```

---

# 30. Quick Interview Cheat Sheet

| Concept | One-line explanation |
|---|---|
| Idempotency | Same logical request should not create duplicate effects |
| State Machine | Controls valid policy lifecycle transitions |
| Reconciliation | Synchronizes local state with actual provider state |
| Duplicate Callback | Same provider response received multiple times |
| Timeout After Issue | Provider issued policy but our application didn't receive response |
| Provider Reference | Identifier used to correlate with external insurer |
| Policy Number | Final issued policy identifier |
| State History | Audit trail of status transitions |
| Premium Snapshot | Premium stored at request time |
| Unique Constraint | Database-level duplicate protection |
| Mock Insurer | Simulates external insurer behavior |

---

# 31. Final Mental Model

When explaining the project, remember this single flow:

```text
                POLICY REQUEST
                      │
                      ▼
                 VALIDATION
                      │
                      ▼
              PREMIUM CALCULATION
                      │
                      ▼
                   RECEIVED
                      │
                      ▼
                  SUBMITTED
                      │
          ┌───────────┼────────────┐
          │           │            │
          ▼           ▼            ▼
       SUCCESS      FAILURE      TIMEOUT
          │           │            │
          ▼           ▼            ▼
       ISSUED       FAILED    PENDING_CONFIRMATION
                                   │
                                   ▼
                              RECONCILIATION
                                   │
                          ┌────────┴────────┐
                          ▼                 ▼
                       ISSUED            FAILED
```

And around the entire flow:

```text
       ┌─────────────────────────────────────┐
       │          RELIABILITY LAYER          │
       │                                     │
       │  Idempotency                        │
       │  State transitions                  │
       │  Duplicate callback protection      │
       │  Audit history                      │
       │  Reconciliation                     │
       │  Automated tests                    │
       └─────────────────────────────────────┘
```

**The one sentence to remember for the interview:**

> **"The core of my project is not simply issuing an insurance policy; it is making sure that an unreliable external insurer cannot cause duplicate issuance or an incorrect final policy state."**