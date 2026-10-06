<x-layout title="Thanh toán mô phỏng">
<div class="mx-auto max-w-xl"><h1 class="page-title">{{ __('Thanh toán mô phỏng') }}</h1><p class="mt-4 rounded-xl bg-orange-50 p-4 text-orange-900">{{ __('Đây là bản demo, không phải Stripe thật. Không dùng thẻ thật. Thông tin thẻ không được lưu.') }}</p><p class="mt-4 text-xl font-bold">{{ number_format($order->total,0,',','.') }} ₫</p><p class="muted mt-2">{{ __('Thẻ mẫu: 4242 4242 4242 4242 · hạn dùng trong tương lai · CVC 123.') }}</p>
<form class="panel mt-6 space-y-4" method="post" action="{{ route('demo-payments.store',$order->code) }}" autocomplete="off">@csrf
<label class="label">{{ __('Tên trên thẻ') }}<input class="input" name="cardholder" required minlength="2" maxlength="100"></label>
<label class="label">Email<input class="input" name="email" type="email" required maxlength="255"></label>
<label class="label">{{ __('Số thẻ thử nghiệm') }}<input class="input" name="card_number" inputmode="numeric" required minlength="13" maxlength="23" pattern="[0-9 ]{13,23}" placeholder="4242 4242 4242 4242"></label>
<div class="grid grid-cols-2 gap-4"><label class="label">{{ __('Hạn dùng (MM/YY)') }}<input class="input" name="expiry" required maxlength="5" pattern="(0[1-9]|1[0-2])/[0-9]{2}" placeholder="12/30"></label><label class="label">CVC<input class="input" name="cvc" type="password" inputmode="numeric" required minlength="3" maxlength="4" pattern="[0-9]{3,4}"></label></div>
<label class="label">{{ __('Địa chỉ thanh toán mẫu') }}<input class="input" name="billing_address" required minlength="8" maxlength="255" placeholder="12 Duong Mau, Nha Trang"></label>
<label class="label">{{ __('Quốc gia') }}<select class="input" name="country"><option value="VN">{{ __('Việt Nam') }}</option></select></label>
<label class="label">{{ __('Mã bưu chính (5 số)') }}<input class="input" name="postal_code" inputmode="numeric" required pattern="[0-9]{5}" maxlength="5"></label>
<button class="btn w-full">{{ __('Xác nhận thanh toán mô phỏng') }}</button><a class="btn-secondary w-full" href="{{ route('orders.show',$order->code) }}">{{ __('Quay lại') }}</a>
</form></div></x-layout>
