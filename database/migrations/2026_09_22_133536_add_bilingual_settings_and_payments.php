<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['categories', 'dishes', 'delivery_zones'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('name_en')->nullable();
            });
        }
        Schema::table('dishes', function (Blueprint $table) {
            $table->text('description_en')->nullable();
            $table->string('allergens')->nullable();
            $table->string('allergens_en')->nullable();
            $table->boolean('is_vegetarian')->default(false);
            $table->boolean('is_spicy')->default(false);
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_method')->default('cash');
            $table->string('payment_status')->default('unpaid');
            $table->string('locale', 2)->default('vi');
            $table->string('customer_email')->nullable();
            $table->string('demo_key')->nullable()->unique();
            $table->boolean('is_demo')->default(false);
            $table->timestamp('cancellation_requested_at')->nullable();
            $table->text('cancellation_request_reason')->nullable();
        });
        DB::table('orders')->whereNotNull('paid_at')->update(['payment_status' => 'paid']);
        Schema::table('order_items', fn (Blueprint $table) => $table->string('dish_name_en')->nullable());
        Schema::create('restaurant_settings', function (Blueprint $table) {
            $table->id();
            $table->string('name')->default('Bếp Nhà');
            $table->string('name_en')->default('Bep Nha Kitchen');
            $table->string('address')->nullable();
            $table->string('address_en')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('opens_at', 5)->default('09:00');
            $table->string('closes_at', 5)->default('22:00');
            $table->boolean('accepting_orders')->default(true);
            $table->boolean('enforce_hours')->default(false);
            $table->unsignedInteger('minimum_order')->default(0);
            $table->unsignedInteger('preparation_minutes')->default(25);
            $table->timestamps();
        });
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->uuid('attempt_key')->unique();
            $table->string('stripe_session_id')->nullable()->unique();
            $table->string('stripe_payment_intent')->nullable()->unique();
            $table->unsignedInteger('amount');
            $table->string('currency', 3)->default('vnd');
            $table->string('status')->default('created');
            $table->string('refund_id')->nullable()->unique();
            $table->uuid('refund_key')->nullable();
            $table->unsignedInteger('refunded_amount')->default(0);
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
        Schema::create('stripe_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('type');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_events');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('restaurant_settings');
        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn('dish_name_en'));
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['payment_method', 'payment_status', 'locale', 'customer_email', 'demo_key', 'is_demo', 'cancellation_requested_at', 'cancellation_request_reason']));
        Schema::table('dishes', fn (Blueprint $table) => $table->dropColumn(['description_en', 'allergens', 'allergens_en', 'is_vegetarian', 'is_spicy']));
        foreach (['categories', 'dishes', 'delivery_zones'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('name_en'));
        }
    }
};
