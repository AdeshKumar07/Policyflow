# AI Usage Disclosure & Engineering Log

## 1. AI Tools Used
- **ChatGPT / Gemini** assisted during architecture design, error diagnosis, test case authoring, and documentation generation.

---

## 2. What AI Helped With
AI was utilized as an assistive pair-programmer to accelerate routine scaffolding, write automated tests, and explore fault tolerance strategies for the policy issuance workflow:
1. **Scaffolding Automated Test Cases**:
   Generated boilerplate feature and unit test structures in `tests/Feature/PolicyFlowTest.php` and `tests/Unit/PremiumCalculatorTest.php` covering the 10+ required lifecycle and failure scenarios.
2. **Transition Matrix & State Guard Design**:
   Assisted in drafting the transition map in `PolicyStateService` to prevent invalid regressions out of the `ISSUED` state.
3. **Database Schema & Eloquent Mapping Verification**:
   Helped identify the Eloquent table name discrepancy between singular `policy_state_history` and Eloquent's default pluralization `policy_state_histories`.

---

## 3. Concrete Ways AI Was Used
1. **Identifying Overwritten Controller Code**:
   During initial codebase inspection, discovered that `app/Http/Controllers/PolicyRequestController.php` had accidentally been overwritten with a migration snippet, causing `ReflectionException: Class "App\Http\Controllers\PolicyRequestController" does not exist`. AI helped immediately locate the error and reconstruct the controller.
2. **Deterministic Mock Insurer Design**:
   Used AI to brainstorm how to simulate a stateful mock insurer with cache persistence so that when `TIMEOUT_AFTER_ISSUE` happens, the insurer retains the generated policy, enabling idempotent recovery during reconciliation.
3. **Validation Error Response Formatting**:
   Diagnosed why Laravel test assertions (`assertJsonValidationErrors`) failed when the controller returned `details` instead of standard `errors` key in the JSON payload, standardizing the response.

---

## 4. Incorrect or Incomplete AI Suggestion Encountered
- **Prematurely Suggesting `php artisan migrate:fresh`**:
  An earlier AI prompt suggested running `php artisan migrate:fresh` to resolve the duplicate migration issue with `policy_state_history`.
- **How It Was Handled**:
  This suggestion was **rejected** because running `migrate:fresh` would wipe existing production/assignment records (including Request ID 1). Instead, a non-destructive approach was followed: inspecting table schema using `Schema::getColumnListing()` and adding `protected $table = 'policy_state_history';` to map cleanly to the existing migrated table without altering database history.

---

## 5. Candidate Verification Statement
All code, models, database transactions, services, and tests were thoroughly inspected, verified against the assignment requirements, executed locally against MySQL and in-memory SQLite, and confirmed to pass all 14 automated tests and manual end-to-end integration flows.
