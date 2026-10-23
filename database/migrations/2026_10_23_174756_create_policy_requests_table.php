<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('policy_requests', function (Blueprint $table) {
            $table->id();

            $table->string('client_request_id', 100);
            $table->string('agent_id', 100);

            $table->string('customer_name');
            $table->date('date_of_birth');

            $table->string('destination', 30);
            $table->date('trip_start');
            $table->date('trip_end');

            $table->string('plan_code', 30);
            $table->unsignedBigInteger('sum_insured');

            $table->decimal('premium', 10, 2);

            $table->string('status', 40)->default('RECEIVED');

            $table->string('provider_reference', 100)->nullable();
            $table->string('policy_number', 100)->nullable();

            $table->unsignedInteger('attempt_count')->default(0);

            $table->text('last_response')->nullable();

            $table->timestamps();

            // Prevent duplicate logical requests
            $table->unique(
                ['agent_id', 'client_request_id'],
                'unique_agent_client_request'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_requests');
    }
};