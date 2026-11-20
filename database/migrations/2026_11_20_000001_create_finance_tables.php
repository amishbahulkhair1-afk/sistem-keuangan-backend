<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Program Categories
        Schema::create('program_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 255);
            $table->string('qris_unit_digit', 10)->nullable();
            $table->string('category_type', 50)->default('proyek_pembangunan');
            $table->string('pic_name', 150)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 2. Accounts (Kas / Bank / Piutang)
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 100);
            $table->double('initial_balance')->default(0);
            $table->double('current_balance')->default(0);
            $table->timestamps();
        });

        // 3. Donors
        Schema::create('donors', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255)->index();
            $table->string('phone', 50)->nullable();
            $table->text('address')->nullable();
            $table->timestamps();
        });

        // 4. Transactions (Donasi & Kas Pusat)
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->date('transaction_date')->index();
            $table->string('type', 20); // 'income' / 'expense'
            $table->foreignId('account_id')->constrained('accounts')->onDelete('cascade');
            $table->foreignId('category_id')->constrained('program_categories')->onDelete('cascade');
            $table->foreignId('donor_id')->nullable()->constrained('donors')->nullOnDelete();
            $table->string('donor_name_raw', 255)->nullable();
            $table->double('amount');
            $table->text('description')->nullable();
            $table->string('receipt_photo_path', 255)->nullable();
            $table->string('qris_reff_id', 100)->unique()->nullable();
            $table->string('qris_bill_no', 100)->nullable();
            $table->timestamps();
        });

        // 5. Donation Items (Natura / Barang)
        Schema::create('donation_items', function (Blueprint $table) {
            $table->id();
            $table->date('transaction_date')->index();
            $table->string('donor_name', 255);
            $table->string('item_category', 100);
            $table->string('item_name', 255);
            $table->double('quantity');
            $table->string('unit', 50);
            $table->double('estimated_value')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('category_id')->nullable()->constrained('program_categories')->nullOnDelete();
            $table->string('photo_path', 255)->nullable();
            $table->timestamps();
        });

        // 6. Pos Transactions (Kas Internal Pos / Lapangan)
        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('program_categories')->onDelete('cascade');
            $table->date('transaction_date')->index();
            $table->string('type', 20); // 'income' / 'expense'
            $table->string('category_tx_type', 50)->default('operasional_lapangan');
            $table->foreignId('source_transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->double('amount');
            $table->string('recipient_or_sender', 255)->nullable();
            $table->text('description')->nullable();
            $table->string('receipt_photo_path', 255)->nullable();
            $table->timestamps();
        });

        // 7. Debt Transactions (Hutang & Piutang)
        Schema::create('debt_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('program_categories')->onDelete('cascade');
            $table->date('transaction_date')->index();
            $table->string('type', 30); // 'hutang_pinjam', 'hutang_bayar', 'piutang_pinjam', 'piutang_bayar'
            $table->string('party_name', 255);
            $table->double('amount');
            $table->text('description')->nullable();
            $table->string('receipt_photo_path', 255)->nullable();
            $table->foreignId('source_pos_tx_id')->nullable()->constrained('pos_transactions')->nullOnDelete();
            $table->timestamps();
        });

        // 8. Material Items (Katalog Barang)
        Schema::create('material_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('program_categories')->onDelete('cascade');
            $table->string('code', 50);
            $table->string('name', 255);
            $table->string('unit', 50);
            $table->double('unit_price')->default(0);
            $table->double('initial_stock')->default(0);
            $table->double('current_stock')->default(0);
            $table->timestamps();
        });

        // 9. Material Transactions (Mutasi Masuk & Pakai Lapangan)
        Schema::create('material_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_id')->constrained('material_items')->onDelete('cascade');
            $table->foreignId('category_id')->constrained('program_categories')->onDelete('cascade');
            $table->date('transaction_date')->index();
            $table->string('type', 20); // 'masuk' / 'keluar'
            $table->double('quantity');
            $table->double('unit_price')->default(0);
            $table->double('total_price')->default(0);
            $table->string('supplier_or_source', 255)->nullable();
            $table->text('usage_for')->nullable();
            $table->string('photo_path', 255)->nullable();
            $table->foreignId('source_pos_tx_id')->nullable()->constrained('pos_transactions')->nullOnDelete();
            $table->timestamps();
        });

        // 10. Custom Balance Sheet Items (Neraca Pos)
        Schema::create('custom_balance_sheet_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('program_categories')->onDelete('cascade');
            $table->string('period', 50);
            $table->string('side', 20); // 'aktiva' / 'passiva'
            $table->string('sub_category', 100);
            $table->string('code', 50)->nullable();
            $table->string('name', 255);
            $table->double('amount_prev_month')->default(0);
            $table->double('amount_current_month')->default(0);
            $table->double('amount_total')->default(0);
            $table->boolean('is_auto')->default(false);
            $table->timestamps();
        });

        // 11. Closing Periods & Period Balances
        Schema::create('closing_periods', function (Blueprint $table) {
            $table->id();
            $table->integer('year');
            $table->integer('month');
            $table->string('auditor_name', 150)->default('Auditor');
            $table->string('in_charge_name', 150)->default('Penanggung Jawab Keuangan');
            $table->boolean('is_locked')->default(false);
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('period_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('closing_period_id')->constrained('closing_periods')->onDelete('cascade');
            $table->foreignId('category_id')->constrained('program_categories')->onDelete('cascade');
            $table->double('starting_balance')->default(0);
            $table->double('total_income')->default(0);
            $table->double('total_expense')->default(0);
            $table->double('ending_balance')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('period_balances');
        Schema::dropIfExists('closing_periods');
        Schema::dropIfExists('custom_balance_sheet_items');
        Schema::dropIfExists('material_transactions');
        Schema::dropIfExists('material_items');
        Schema::dropIfExists('debt_transactions');
        Schema::dropIfExists('pos_transactions');
        Schema::dropIfExists('donation_items');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('donors');
        Schema::dropIfExists('accounts');
        Schema::dropIfExists('program_categories');
    }
};
