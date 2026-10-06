<?php

namespace App\Providers;

use App\Models\CashShift;
use App\Models\DeliveryZone;
use App\Models\Dish;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\RestaurantSetting;
use App\Models\User;
use App\Observers\AuditObserver;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        foreach ([OrderItem::class, User::class, Dish::class, Order::class, Payment::class, RestaurantSetting::class, DeliveryZone::class, CashShift::class] as $model) {
            $model::observe(AuditObserver::class);
        }
        ResetPassword::toMailUsing(function ($user, string $token) {
            $url = rtrim(config('app.url'), '/').route('customer.password.reset', ['token' => $token, 'email' => $user->email], false);

            return (new MailMessage)
                ->subject(__('Đặt lại mật khẩu Bếp Nhà'))
                ->greeting(__('Xin chào!'))
                ->line(__('Bạn đã yêu cầu đặt lại mật khẩu tài khoản khách hàng.'))
                ->action(__('Đặt lại mật khẩu'), $url)
                ->line(__('Liên kết có hiệu lực trong :minutes phút.', ['minutes' => config('auth.passwords.users.expire')]))
                ->line(__('Nếu bạn không yêu cầu, hãy bỏ qua email này.'));
        });
        View::composer(['components.layout', 'components.staff-layout'], function ($view) {
            $view->with('restaurant', RestaurantSetting::current());
        });
        RateLimiter::for('login', fn (Request $r) => [Limit::perMinute(15)->by($r->ip()), Limit::perMinute(5)->by(hash('sha256', strtolower((string) $r->input('email')).'|'.$r->ip()))]);
        RateLimiter::for('lookup', fn (Request $r) => Limit::perMinute(10)->by($r->ip()));
    }
}
