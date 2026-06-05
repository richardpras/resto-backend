<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_reimbursements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('claim_no', 40);
            $table->string('category', 30);
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->decimal('claim_amount', 15, 2);
            $table->date('expense_date');
            $table->string('status', 20)->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('payroll_run_item_id')->nullable()->constrained('payroll_run_items_v2')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('outlet_id');
            $table->index('employee_id');
            $table->index('status');
            $table->index('expense_date');
            $table->index('payroll_run_item_id');
        });

        Schema::create('employee_reimbursement_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reimbursement_id')->constrained('employee_reimbursements')->cascadeOnDelete();
            $table->string('file_name', 255);
            $table->string('file_path', 500);
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('mime_type', 120)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::table('payroll_run_items_v2', function (Blueprint $table) {
            $table->decimal('reimbursement_earning', 15, 2)->default(0)->after('pph21_amount');
            $table->decimal('remaining_reimbursement', 15, 2)->default(0)->after('reimbursement_earning');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_run_items_v2', function (Blueprint $table) {
            $table->dropColumn(['reimbursement_earning', 'remaining_reimbursement']);
        });

        Schema::dropIfExists('employee_reimbursement_attachments');
        Schema::dropIfExists('employee_reimbursements');
    }
};
