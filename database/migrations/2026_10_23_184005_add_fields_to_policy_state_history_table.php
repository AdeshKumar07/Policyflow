<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('policy_state_history', function (Blueprint $table) {
            $table->foreignId('policy_request_id')
                ->after('id')
                ->constrained('policy_requests')
                ->cascadeOnDelete();

            $table->string('from_status', 40)
                ->nullable()
                ->after('policy_request_id');

            $table->string('to_status', 40)
                ->after('from_status');

            $table->text('reason')
                ->nullable()
                ->after('to_status');
        });
    }

    public function down(): void
    {
        Schema::table('policy_state_history', function (Blueprint $table) {
            $table->dropForeign(['policy_request_id']);
            $table->dropColumn([
                'policy_request_id',
                'from_status',
                'to_status',
                'reason',
            ]);
        });
    }
};