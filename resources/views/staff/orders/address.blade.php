@if($order->fulfillment_type==='delivery' && auth()->user()->coordinatesOrders())
<section class="panel"><h2 class="font-bold">{{ __('Đối chiếu địa chỉ Nha Trang') }}</h2>
@if($order->address_verified_at)<p class="mt-3 text-green-800">{{ __('Đã xác minh địa chỉ giao hàng.') }} · {{ $order->delivery_ward }} · {{ $order->address_verified_at->format('H:i d/m/Y') }}</p>
@elseif(in_array($order->status,['pending','confirmed','preparing','ready','delivery_failed']))
<form class="mt-3 space-y-3" method="post" action="{{ route('staff.address.verify',$order) }}">@csrf<input type="hidden" name="expected_address" value="{{ $order->address }}"><p>{{ $order->address }}</p><label class="label">{{ __('Phường tại Nha Trang') }}<select class="input" name="delivery_ward" required>@foreach(\App\Models\DeliveryZone::WARDS as $ward)<option @selected($order->delivery_ward===$ward)>{{ $ward }}</option>@endforeach</select></label><label class="flex gap-3 text-sm"><input type="checkbox" name="confirmed" value="1" required>{{ __('Đã đối chiếu địa chỉ với khách / bản đồ và xác nhận thuộc khu vực phục vụ Nha Trang.') }}</label><button class="btn-secondary">{{ __('Xác nhận địa chỉ') }}</button></form>
@endif</section>
@endif
