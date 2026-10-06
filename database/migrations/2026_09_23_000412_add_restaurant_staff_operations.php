<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_tables', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();
            $table->unsignedSmallInteger('capacity')->default(4);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('restaurant_table_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('table_name')->nullable();
            $table->timestamp('cod_collected_at')->nullable();
            $table->foreignId('cod_collected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('cod_settled_at')->nullable();
        });
        Schema::create('order_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('kind', 30);
            $table->foreignId('assigned_to')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->string('status')->default('pending');
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['order_id', 'kind']);
            $table->index(['assigned_to', 'status']);
        });
        DB::table('users')->where('role', 'manager')->update(['role' => 'admin']);
        DB::table('users')->where('role', 'kitchen')->update(['role' => 'head_chef']);
    }

    public function down(): void
    {
        Schema::dropIfExists('order_tasks');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('restaurant_table_id');
            $table->dropConstrainedForeignId('cod_collected_by');
            $table->dropColumn(['table_name', 'cod_collected_at', 'cod_settled_at']);
        });
        Schema::dropIfExists('restaurant_tables');
        DB::table('users')->where('role', 'admin')->update(['role' => 'manager']);
        DB::table('users')->where('role', 'head_chef')->update(['role' => 'kitchen']);
    }
};
