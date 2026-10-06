<?php

namespace App\Observers;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class AuditObserver
{
    private const FIELDS = ['quantity', 'preparation_status', 'merged_into_id', 'split_from_id', 'restaurant_table_id', 'address_verified_by', 'address_verified_at', 'name', 'name_en', 'role', 'is_active', 'price', 'is_available', 'status', 'payment_status', 'payment_method', 'total', 'amount', 'refunded_amount', 'fee', 'accepting_orders', 'enforce_hours', 'minimum_order', 'opens_at', 'closes_at', 'expected_cash', 'counted_cash', 'stripe_total', 'demo_total', 'order_count'];

    public function created(Model $model): void
    {
        $this->record($model, 'created');
    }

    public function updated(Model $model): void
    {
        $this->record($model, 'updated');
    }

    public function deleted(Model $model): void
    {
        $this->record($model, 'deleted');
    }

    private function record(Model $model, string $action): void
    {
        $after = array_intersect_key($action === 'updated' ? $model->getChanges() : $model->getAttributes(), array_flip(self::FIELDS));
        if ($action === 'updated' && ! $after) {
            return;
        }
        AuditLog::create(['user_id' => auth()->id(), 'action' => $action, 'subject_type' => class_basename($model), 'subject_id' => $model->getKey(), 'before' => $action === 'created' ? null : array_intersect_key($model->getRawOriginal(), $after), 'after' => $action === 'deleted' ? null : $after]);
    }
}
