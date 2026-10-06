@props(['restaurant'])
<footer class="mt-16 bg-[#203b32] text-stone-100">
    <div class="border-b border-white/15">
        <div class="mx-auto flex max-w-6xl flex-col gap-5 px-5 py-8 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-widest text-orange-200">{{ __('Một bữa ngon, một ngày vui') }}</p>
                <h2 class="mt-2 text-2xl font-semibold tracking-tight">{{ __('Hẹn bạn ở Bếp Nhà.') }}</h2>
                <p class="mt-2 text-sm leading-6 text-stone-300">{{ __('Dùng tại nhà hàng / mang về / giao hàng trong Nha Trang') }}</p>
            </div>
            <a href="{{ route('menu.index') }}" class="inline-flex min-h-12 shrink-0 items-center justify-center gap-4 self-start rounded-full bg-orange-100 px-6 py-3 text-sm font-semibold text-[#203b32] transition hover:bg-orange-200 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-orange-200 sm:self-auto">{{ __('Chọn món yêu thích') }} <span aria-hidden="true">↗</span></a>
        </div>
    </div>
    <div class="mx-auto max-w-6xl px-5">
        <div class="grid gap-10 py-12 sm:grid-cols-2 lg:grid-cols-[1.4fr_0.8fr_1.2fr] lg:gap-16">
            <div>
                <a href="{{ route('menu.index') }}" class="inline-flex items-center gap-3 rounded-lg focus-visible:outline-2 focus-visible:outline-orange-200">
                    <span class="flex size-14 items-center justify-center rounded-2xl bg-orange-100 text-orange-900" aria-hidden="true">
                        <svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="size-10"><path d="M8 23 24 10l16 13M13 21v17h22V21M17 29h14c0 5-3 8-7 8s-7-3-7-8ZM21 24c-3-3 3-4 0-7M27 24c-3-3 3-4 0-7"/></svg>
                    </span>
                    <span><span class="block text-2xl font-bold tracking-tight">{{ $restaurant->display_name }}</span><span class="mt-1 block text-xs tracking-widest text-orange-200">{{ __('BẾP ẤM · BỮA NGON') }}</span></span>
                </a>
                <p class="mt-5 max-w-sm text-sm leading-7 text-stone-300">{{ __('Món quen, vị nhà. Bếp Nhà cùng bạn chăm chút từng bữa ăn, từ bàn ăn tại quán đến những bữa ngon giao tận cửa ở Nha Trang.') }}</p>
                <div class="mt-5 inline-flex items-center gap-2 rounded-full border border-white/20 px-3 py-2 text-xs text-orange-100"><span class="size-2 rounded-full bg-orange-200" aria-hidden="true"></span>{{ __('Chỉ phục vụ tại Nha Trang') }}</div>
            </div>
            <nav aria-label="{{ __('Khám phá Bếp Nhà') }}">
                <h3 class="text-sm font-semibold text-orange-100">{{ __('Khám phá Bếp Nhà') }}</h3>
                <ul class="mt-4 space-y-1 text-sm text-stone-300">
                    @foreach([route('menu.index') => 'Thực đơn', route('cart.index') => 'Giỏ hàng', route('orders.lookup') => 'Tra cứu đơn', route('customer.account') => 'Tài khoản của tôi'] as $url => $label)
                    <li><a href="{{ $url }}" class="inline-block rounded py-2 transition hover:text-orange-200 hover:underline focus-visible:outline-2 focus-visible:outline-orange-200">{{ __($label) }}</a></li>
                    @endforeach
                </ul>
            </nav>
            <div>
                <h3 class="text-sm font-semibold text-orange-100">{{ __('Ghé quán & liên hệ') }}</h3>
                <dl class="mt-5 space-y-4 text-sm leading-6">
                    <div><dt class="text-xs text-stone-400">{{ __('Địa chỉ nhà hàng') }}</dt><dd class="mt-1 text-stone-200">{{ $restaurant->display_address ?: __('Nha Trang, Khánh Hòa') }}</dd></div>
                    <div><dt class="text-xs text-stone-400">{{ __('Giờ mở cửa') }}</dt><dd class="mt-1 text-stone-200">{{ $restaurant->opens_at }} – {{ $restaurant->closes_at }}</dd></div>
                    @if($restaurant->phone)<div><dt class="text-xs text-stone-400">{{ __('Điện thoại hỗ trợ') }}</dt><dd class="mt-1"><a href="tel:{{ preg_replace('/[^+0-9]/', '', $restaurant->phone) }}" class="text-lg font-semibold text-orange-100 hover:underline">{{ $restaurant->phone }}</a></dd></div>@endif
                    @if($restaurant->email)<div><dt class="sr-only">Email</dt><dd><a href="mailto:{{ $restaurant->email }}" class="break-all text-stone-200 hover:text-orange-200 hover:underline">{{ $restaurant->email }}</a></dd></div>@endif
                </dl>
            </div>
        </div>
        <section aria-labelledby="footer-policies" class="border-t border-white/15 py-8">
            <div class="mb-5 flex flex-wrap items-baseline justify-between gap-2"><h3 id="footer-policies" class="text-sm font-semibold text-orange-100">{{ __('Thông tin & chính sách') }}</h3><p class="text-xs text-stone-400">{{ __('Để bạn yên tâm trước khi đặt món') }}</p></div>
            <div class="grid items-start gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach([
                    'Đặt món & giao hàng' => 'Bạn có thể dùng tại nhà hàng, mang về hoặc nhận giao trong Nha Trang. Phí giao hiển thị trước khi xác nhận. Vui lòng cung cấp địa chỉ chi tiết; nhân viên sẽ kiểm tra phạm vi phục vụ khi tiếp nhận đơn.',
                    'Thanh toán' => 'Bạn có thể chọn tiền mặt hoặc thẻ mô phỏng. Tổng tiền hiển thị trước khi xác nhận. Thẻ mô phỏng chỉ phục vụ trình diễn, không thu tiền thật và không kết nối Stripe.',
                    'Hủy đơn & hoàn tiền' => 'Gửi yêu cầu hủy tại trang chi tiết đơn hoặc liên hệ nhà hàng kèm mã đơn. Nhân viên xem xét theo tình trạng chuẩn bị món và thanh toán. Yêu cầu hủy không đồng nghĩa đơn đã được hủy; hãy theo dõi kết quả trên website.',
                    'Thông tin cá nhân & cookie' => 'Website dùng thông tin liên hệ để xử lý đơn; cookie và phiên đăng nhập duy trì giỏ hàng, tài khoản và ngôn ngữ. Trang thanh toán mô phỏng chỉ dùng dữ liệu thử, không lưu số thẻ hoặc CVC. Tuyệt đối không nhập thông tin thẻ thật.'
                ] as $heading => $body)
                <details class="group rounded-xl border border-white/15 bg-white/5 open:bg-white/10">
                    <summary class="flex min-h-14 cursor-pointer list-none items-center justify-between gap-3 rounded-xl px-4 py-4 text-sm font-medium focus-visible:outline-2 focus-visible:outline-orange-200 [&::-webkit-details-marker]:hidden">{{ __($heading) }}<span aria-hidden="true" class="text-lg text-orange-200 transition-transform group-open:rotate-45">+</span></summary>
                    <p class="px-4 pb-5 text-sm leading-7 text-stone-300">{{ __($body) }}</p>
                </details>
                @endforeach
            </div>
        </section>
        <div class="flex flex-col gap-3 border-t border-white/15 py-6 text-xs leading-6 text-stone-400 sm:flex-row sm:items-center sm:justify-between">
            <p>© {{ date('Y') }} {{ $restaurant->display_name }}. {{ __('Cảm ơn bạn đã chọn bữa ngon cùng chúng tôi.') }}</p>
            <div class="flex flex-wrap gap-2"><span class="rounded border border-white/20 px-2.5 py-1">{{ __('Tiền mặt') }}</span><span class="rounded border border-white/20 px-2.5 py-1">{{ __('Thẻ mô phỏng') }}</span></div>
        </div>
    </div>
</footer>
