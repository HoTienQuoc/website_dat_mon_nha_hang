<section class="panel space-y-4">
    <h2 class="text-xl font-bold">{{ __('Phân công & bàn ăn') }}</h2>
    @forelse($order->tasks as $task)
        <div class="rounded-xl bg-stone-50 p-3 text-sm">
            <p class="font-semibold">{{ __(\App\Models\OrderTask::KINDS[$task->kind]) }} · {{ $task->assignee->name }}</p>
            <p class="muted">{{ __(\App\Models\OrderTask::STATUSES[$task->status]) }} @unless($task->assignee->is_active) · {{ __('Đã khóa') }} @endunless</p>
            @if($task->note)<p class="mt-2">{{ $task->note }}</p>@endif
            @if($task->kind==='preparation' && $task->assigned_to===auth()->id() && in_array($task->status,['pending','in_progress']) && in_array($order->status,['confirmed','preparing']))
                <form method="post" action="{{ route('staff.tasks.update',$task) }}" class="mt-3">@csrf @method('PATCH')<input type="hidden" name="expected_status" value="{{ $task->status }}"><input type="hidden" name="status" value="{{ $task->status==='pending' ? 'in_progress' : 'done' }}"><button class="btn-secondary">{{ $task->status==='pending' ? __('Bắt đầu sơ chế') : __('Hoàn tất sơ chế') }}</button></form>
            @endif
        </div>
    @empty<p class="muted">{{ __('Chưa phân công nhân viên.') }}</p>@endforelse
    @if((auth()->user()->coordinatesOrders() || auth()->user()->role==='head_chef') && !in_array($order->status,['completed','cancelled','delivered','served','delivering']))
        <details><summary class="cursor-pointer font-semibold text-orange-800">{{ __('Phân công nhân viên') }}</summary>
            @foreach(\App\Models\OrderTask::KINDS as $kind=>$label)
                @continue(auth()->user()->role==='head_chef' && !in_array($kind,['preparation','cooking']))
                @continue($kind==='service' && $order->fulfillment_type!=='dine_in')
                @continue($kind==='delivery' && $order->fulfillment_type!=='delivery')
                @continue(in_array($kind,['preparation','cooking']) && !in_array($order->status,['confirmed','preparing']))
                @continue($order->tasks->firstWhere('kind',$kind)?->status==='done')
                <form method="post" action="{{ route('staff.assignments.store',$order) }}" class="mt-4 space-y-3 border-t border-stone-200 pt-4">@csrf<input type="hidden" name="kind" value="{{ $kind }}">
                    <label class="label" for="assign-{{ $kind }}">{{ __($label) }}</label>
                    <select class="input" name="assigned_to" id="assign-{{ $kind }}" required><option value="">{{ __('Chọn nhân viên') }}</option>@foreach($staffMembers->whereIn('role',\App\Models\OrderTask::ROLES[$kind]) as $member)<option value="{{ $member->id }}" @selected($order->tasks->firstWhere('kind',$kind)?->assigned_to===$member->id)>{{ $member->name }} · {{ __(\App\Models\User::STAFF_ROLES[$member->role]) }}</option>@endforeach</select>
                    <label class="label">{{ __('Ghi chú công việc') }}<input class="input" name="note" maxlength="500" value="{{ $order->tasks->firstWhere('kind',$kind)?->note }}"></label><button class="btn-secondary">{{ __('Lưu phân công') }}</button>
                </form>
            @endforeach
        </details>
    @endif
    @if($order->fulfillment_type==='dine_in')
        <p class="text-sm">{{ __('Bàn ăn') }}: <strong>{{ $order->table_name ?? __('Chưa xếp bàn') }}</strong></p>
        @if(auth()->user()->coordinatesOrders() && in_array($order->status,['pending','confirmed','preparing','ready','served']))
            <form method="post" action="{{ route('staff.tables.assign',$order) }}" class="space-y-3">@csrf @method('PUT')<label class="label" for="table-select">{{ __('Xếp bàn') }}</label><select class="input" id="table-select" name="restaurant_table_id" required><option value="">{{ __('Chọn bàn') }}</option>@foreach($tables as $table)<option value="{{ $table->id }}" @selected($order->restaurant_table_id===$table->id)>{{ $table->name }} · {{ $table->capacity }} {{ __('chỗ') }}</option>@endforeach</select><button class="btn-secondary">{{ __('Lưu bàn') }}</button><p class="muted">{{ __('Mỗi bàn nhận một đơn đang phục vụ. Bàn trống lại khi đơn hoàn tất hoặc bị hủy.') }}</p></form>
        @endif
    @endif
</section>
