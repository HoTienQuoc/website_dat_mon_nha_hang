<x-staff-layout title="Bàn ăn">
    <h1 class="page-title">{{ __('Bàn ăn') }}</h1><p class="muted mt-3">{{ __('Mỗi bàn nhận một đơn đang phục vụ. Bàn trống lại khi đơn hoàn tất hoặc bị hủy.') }}</p>
    @if(in_array(auth()->user()->role,['admin','manager']))
        <form class="panel my-6 grid gap-4 sm:grid-cols-4" method="post" action="{{ route('staff.tables.store') }}">@csrf
            <label class="label">{{ __('Tên bàn') }}<input class="input" name="name" maxlength="50" required></label><label class="label">{{ __('Số chỗ') }}<input class="input" name="capacity" type="number" min="1" max="30" value="4" required></label><input name="is_active" value="1" type="hidden"><button class="btn self-end">{{ __('Thêm bàn') }}</button>
        </form>
    @endif
    <div class="mt-6 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">@foreach($tables as $table)
        <section class="panel space-y-4"><div class="flex items-center justify-between gap-2"><h2 class="text-xl font-bold">{{ $table->name }}</h2><span class="badge">{{ !$table->is_active ? __('Tạm dừng') : ($table->active_orders_count ? __('Đang có khách') : __('Bàn trống')) }}</span></div><p class="muted">{{ $table->capacity }} {{ __('chỗ') }}</p>
            @foreach($table->activeOrders as $order)<a class="block text-orange-800 underline" href="{{ route('staff.orders.show',$order) }}">{{ __('Đơn #') }}{{ $order->id }} · {{ $order->statusLabel() }}</a>@endforeach
            @if(in_array(auth()->user()->role,['admin','manager']) && !$table->active_orders_count)
                <details><summary class="cursor-pointer text-sm font-semibold">{{ __('Sửa bàn') }}</summary><form method="post" action="{{ route('staff.tables.update',$table) }}" class="mt-4 space-y-3">@csrf @method('PUT')<label class="label">{{ __('Tên bàn') }}<input class="input" name="name" value="{{ $table->name }}" maxlength="50" required></label><label class="label">{{ __('Số chỗ') }}<input class="input" name="capacity" value="{{ $table->capacity }}" type="number" min="1" max="30" required></label><label class="label">{{ __('Trạng thái') }}<select class="input" name="is_active"><option value="1" @selected($table->is_active)>{{ __('Hoạt động') }}</option><option value="0" @selected(!$table->is_active)>{{ __('Tạm dừng') }}</option></select></label><button class="btn-secondary">{{ __('Lưu bàn') }}</button></form></details>
            @endif
        </section>
    @endforeach</div>
</x-staff-layout>
