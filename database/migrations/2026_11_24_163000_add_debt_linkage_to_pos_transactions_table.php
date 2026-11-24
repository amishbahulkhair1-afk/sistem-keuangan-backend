<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_transactions', function (Blueprint $table) {
            $table->foreignId('source_debt_tx_id')->nullable()->constrained('debt_transactions')->nullOnDelete();
            $table->string('payment_status', 20)->default('lunas'); // 'lunas' / 'hutang'
        });
    }

    public function down(): void
    {
        Schema::table('pos_transactions', function (Blueprint $table) {
            $table->dropForeign(['source_debt_tx_id']);
            $table->dropColumn(['source_debt_tx_id', 'payment_status']);
        });
    }
};
