<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_shifts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->timestamp('from_at');
            $t->timestamp('to_at');
            $t->unsignedBigInteger('expected_cash');
            $t->unsignedBigInteger('counted_cash');
            $t->unsignedBigInteger('stripe_total');
            $t->unsignedBigInteger('demo_total');
            $t->unsignedInteger('order_count');
            $t->text('note')->nullable();
            $t->timestamps();
        });
        Schema::table('orders', function (Blueprint $t) {
            $t->foreignId('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('cash_shift_id')->nullable()->constrained()->restrictOnDelete();
        });
        Schema::table('order_items', function (Blueprint $t) {
            $t->string('preparation_status')->default('pending');
        });
        DB::table('order_items')->whereIn('order_id', DB::table('orders')->select('id')->whereIn('status', ['completed', 'served', 'delivering', 'delivered', 'delivery_failed']))->update(['preparation_status' => 'served']);
        DB::table('order_items')->whereIn('order_id', DB::table('orders')->select('id')->where('status', 'ready'))->update(['preparation_status' => 'ready']);
        DB::table('order_items')->whereIn('order_id', DB::table('orders')->select('id')->where('status', 'preparing'))->update(['preparation_status' => 'preparing']);
        Schema::table('payments', function (Blueprint $t) {
            $t->string('provider')->default('stripe');
        });
        Schema::create('order_additions', function (Blueprint $t) {
            $t->id();
            $t->uuid('request_key')->unique();
            $t->foreignId('order_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('action');
            $t->string('subject_type');
            $t->unsignedBigInteger('subject_id');
            $t->json('before')->nullable();
            $t->json('after')->nullable();
            $t->timestamps();
            $t->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('order_additions');
        Schema::table('payments', fn (Blueprint $t) => $t->dropColumn('provider'));
        Schema::table('order_items', fn (Blueprint $t) => $t->dropColumn('preparation_status'));
        Schema::table('orders', function (Blueprint $t) {
            $t->dropConstrainedForeignId('completed_by');
            $t->dropConstrainedForeignId('cash_shift_id');
        });
        Schema::dropIfExists('cash_shifts');
    }
};
