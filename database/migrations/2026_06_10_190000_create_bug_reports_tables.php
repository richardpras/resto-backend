<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bug_reports', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('outlet_id')->nullable()->index();
            $table->unsignedBigInteger('reporter_user_id')->index();
            $table->string('title', 200);
            $table->text('message');
            $table->string('severity', 20)->default('medium');
            $table->string('status', 30)->default('open')->index();
            $table->string('current_route', 500)->nullable();
            $table->string('browser', 200)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('viewport', 50)->nullable();
            $table->string('app_version', 50)->nullable();
            $table->json('diagnostics_json')->nullable();
            $table->unsignedBigInteger('assigned_to_user_id')->nullable()->index();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index('created_at');
            $table->index('severity');
        });

        Schema::create('bug_report_attachments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('bug_report_id')->index();
            $table->string('file_path', 500);
            $table->string('file_type', 100)->nullable();
            $table->unsignedInteger('file_size')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('bug_report_id')
                ->references('id')
                ->on('bug_reports')
                ->cascadeOnDelete();
        });

        Schema::create('bug_report_comments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('bug_report_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->text('comment');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('bug_report_id')
                ->references('id')
                ->on('bug_reports')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bug_report_comments');
        Schema::dropIfExists('bug_report_attachments');
        Schema::dropIfExists('bug_reports');
    }
};
