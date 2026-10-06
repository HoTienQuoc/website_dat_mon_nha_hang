@props(['order','staff'=>false])
<section class="rounded-xl border border-stone-200 bg-stone-50 p-4 space-y-3"><h2 class="font-semibold">{{ __('Thanh toán') }}</h2><p>{{ $order->payment_method==='demo_card' ? __('Thẻ mô phỏng · không thu tiền thật') : ($order->payment_method==='stripe' ? __('Thẻ qua Stripe (Test Mode)') : __('Tiền mặt khi nhận món')) }} · <strong>{{ $order->paymentLabel() }}</strong></p>
@if($order->is_demo)<p class="text-sm text-amber-800">{{ __('Đơn dữ liệu mẫu, không phải giao dịch Stripe thực.') }}</p>@endif
@if(!$staff && $order->payment_method==='stripe' && $order->status==='pending' && in_array($order->payment_status,['unpaid','pending']))<form method="post" action="{{ route('payments.store',$order->code) }}">@csrf<button class="btn">{{ __('Thanh toán / thử lại trên Stripe') }}</button></form><p class="muted">{{ __('Thẻ thử: 4242 4242 4242 4242 · ngày hết hạn trong tương lai · CVC bất kỳ. Không sử dụng thẻ thật.') }}</p>@endif
@if($staff && $order->payment_method==='stripe' && auth()->user()->coordinatesOrders())
<div class="flex flex-wrap gap-3"><form method="post" action="{{ route('payments.sync',$order) }}">@csrf<button class="btn-secondary">{{ __('Đối chiếu Stripe') }}</button></form>
@if($order->payment_status==='pending')<form method="post" action="{{ route('payments.expire',$order) }}">@csrf<button class="btn-secondary">{{ __('Đóng phiên thanh toán') }}</button></form>@endif</div>
@if(auth()->user()->role==='admin' && $order->status==='cancelled' && in_array($order->payment_status,['paid','refund_pending','refund_failed']))<form method="post" action="{{ route('payments.refund',$order) }}" class="space-y-3">@csrf<label class="flex gap-2 text-sm"><input type="checkbox" name="confirm" value="1" required> {{ __('Xác nhận hoàn toàn bộ tiền cho đơn đã hủy') }}</label><button class="btn-danger">{{ __('Hoàn tiền Stripe / thử lại') }}</button></form>@endif
@foreach($order->payments as $payment)<p class="break-all text-xs text-stone-500">Stripe: {{ $payment->stripe_session_id ?? '—' }} · {{ $payment->status }}</p>@endforeach
@endif
@if(!$staff && $order->payment_method==='demo_card' && $order->status==='pending' && $order->payment_status==='unpaid')
<a class="btn" href="{{ route('demo-payments.create',$order->code) }}">{{ __('Thanh toán mô phỏng') }}</a>
@endif
@if($staff && auth()->user()->role==='admin' && $order->payment_method==='demo_card' && $order->status==='cancelled' && $order->payment_status==='paid')
<form method="post" action="{{ route('demo-payments.refund',$order) }}">@csrf<label class="label"><input type="checkbox" name="confirm" value="1" required> {{ __('Xác nhận hoàn tiền mô phỏng') }}</label><button class="btn-secondary">{{ __('Hoàn tiền mô phỏng') }}</button></form>
@endif
</section>
