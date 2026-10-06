<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->nullable();
            $table->boolean('is_active')->default(true);
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->string('checkout_owner', 64)->nullable();
            $table->string('request_fingerprint', 64)->nullable();
            $table->string('delivery_zone_name')->nullable();
            $table->text('note')->nullable();
        });
        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->text('reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_histories');
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['checkout_owner', 'request_fingerprint', 'delivery_zone_name', 'note']));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['role', 'is_active']));
    }
};
