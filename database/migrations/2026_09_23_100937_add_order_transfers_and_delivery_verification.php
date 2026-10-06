<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->foreignId('merged_into_id')->nullable()->constrained('orders')->restrictOnDelete();
            $t->foreignId('split_from_id')->nullable()->constrained('orders')->restrictOnDelete();
            $t->string('delivery_ward')->nullable();
            $t->timestamp('address_verified_at')->nullable();
            $t->foreignId('address_verified_by')->nullable()->constrained('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->dropConstrainedForeignId('merged_into_id');
            $t->dropConstrainedForeignId('split_from_id');
            $t->dropConstrainedForeignId('address_verified_by');
            $t->dropColumn(['delivery_ward', 'address_verified_at']);
        });
    }
};
