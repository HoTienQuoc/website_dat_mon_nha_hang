<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['kind', 'assigned_to', 'assigned_by', 'status', 'note'])]
class OrderTask extends Model
{
    public const KINDS = ['preparation' => 'Sơ chế', 'cooking' => 'Chế biến', 'service' => 'Phục vụ tại bàn', 'delivery' => 'Giao hàng'];

    public const ROLES = ['preparation' => ['kitchen_assistant'], 'cooking' => ['chef', 'head_chef'], 'service' => ['waiter'], 'delivery' => ['courier']];

    public const STATUSES = ['pending' => 'Chờ thực hiện', 'in_progress' => 'Đang thực hiện', 'done' => 'Đã xong', 'failed' => 'Thất bại'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
