<?php

use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\DeliveryZoneController;
use App\Http\Controllers\Admin\DishController;
use App\Http\Controllers\Admin\RevenueController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\CustomerAccountController;
use App\Http\Controllers\CustomerAuthController;
use App\Http\Controllers\CustomerPasswordResetController;
use App\Http\Controllers\DemoPaymentController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\MenuController;
use App\Http\Controllers\OrderDocumentController;
use App\Http\Controllers\OrderLookupController;
use App\Http\Controllers\OrderUpdatesController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\Staff\AddressVerificationController;
use App\Http\Controllers\Staff\AssignmentController;
use App\Http\Controllers\Staff\KitchenController;
use App\Http\Controllers\Staff\OrderAdjustmentController;
use App\Http\Controllers\Staff\OrderAlertsController;
use App\Http\Controllers\Staff\OrderController;
use App\Http\Controllers\Staff\OrderTransferController;
use App\Http\Controllers\Staff\PosController;
use App\Http\Controllers\Staff\ShiftController;
use App\Http\Controllers\Staff\TableController;
use App\Http\Controllers\Staff\TaskController;
use App\Http\Controllers\StripeWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', [MenuController::class, 'index'])->name('menu.index');
Route::get('/gio-hang', [CartController::class, 'index'])->name('cart.index');
Route::post('/gio-hang/{dish}', [CartController::class, 'store'])->name('cart.store')->block();
Route::patch('/gio-hang/{dish}', [CartController::class, 'update'])->name('cart.update')->block();
Route::delete('/gio-hang/{dish}', [CartController::class, 'destroy'])->name('cart.destroy')->block();
Route::get('/dat-mon', [CheckoutController::class, 'create'])->name('checkout.create');
Route::post('/dat-mon/xac-nhan', [CheckoutController::class, 'review'])->name('checkout.review')->block();
Route::get('/dat-mon/xac-nhan/{key}', [CheckoutController::class, 'confirm'])->name('checkout.confirm');
Route::post('/don-hang', [CheckoutController::class, 'store'])->name('orders.store')->middleware('throttle:20,1')->block();
Route::get('/don-hang/{order:code}', [CheckoutController::class, 'show'])->name('orders.show');
Route::get('/tra-cuu', [OrderLookupController::class, 'create'])->name('orders.lookup');
Route::post('/tra-cuu', [OrderLookupController::class, 'store'])->name('orders.lookup.submit')->middleware('throttle:lookup');
Route::middleware('guest')->group(function () {
    Route::get('/nhan-vien/dang-nhap', [AuthController::class, 'create'])->name('login');
    Route::post('/nhan-vien/dang-nhap', [AuthController::class, 'store'])->name('login.store')->middleware('throttle:login');
});
Route::post('/dang-xuat', [AuthController::class, 'destroy'])->name('logout')->middleware('auth');
Route::prefix('nhan-vien')->name('staff.')->middleware(['auth', 'role:admin,manager,cashier,head_chef,chef,kitchen_assistant,waiter,courier'])->group(function () {
    Route::get('/don-hang', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/don-hang/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::patch('/don-hang/{order}', [OrderController::class, 'update'])->name('orders.update');
});
Route::prefix('quan-ly')->name('admin.')->middleware(['auth', 'role:admin,manager'])->group(function () {
    Route::resource('dishes', DishController::class)->except('show');
    Route::resource('categories', CategoryController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::resource('zones', DeliveryZoneController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::resource('customers', CustomerController::class)->only(['index', 'store', 'edit', 'update']);
    Route::resource('staff', StaffController::class)->only(['index', 'store', 'update']);
    Route::get('/doanh-thu', [RevenueController::class, 'index'])->name('revenue')->middleware('role:admin');
});

Route::post('/ngon-ngu', [LocaleController::class, 'update'])->name('locale.update');
Route::post('/stripe/webhook', StripeWebhookController::class)->name('stripe.webhook');
Route::post('/don-hang/{order:code}/thanh-toan', [PaymentController::class, 'store'])->name('payments.store')->middleware('throttle:10,1')->block();
Route::get('/don-hang/{order:code}/stripe-return', [PaymentController::class, 'returned'])->name('payments.return')->middleware('throttle:20,1');
Route::middleware(['auth', 'role:admin,manager,cashier'])->group(function () {
    Route::post('/nhan-vien/don-hang/{order}/doi-chieu', [PaymentController::class, 'sync'])->name('payments.sync');
    Route::post('/nhan-vien/don-hang/{order}/dong-phien', [PaymentController::class, 'expire'])->name('payments.expire');
});
Route::post('/quan-ly/don-hang/{order}/hoan-tien', [PaymentController::class, 'refund'])->name('payments.refund')->middleware(['auth', 'role:admin']);

Route::middleware(['auth', 'role:admin'])->group(function () {
    Route::get('/quan-ly/cau-hinh', [SettingsController::class, 'edit'])->name('admin.settings.edit');
    Route::put('/quan-ly/cau-hinh', [SettingsController::class, 'update'])->name('admin.settings.update');
});

Route::get('/don-hang/{order:code}/phieu', [OrderDocumentController::class, 'receipt'])->name('orders.receipt');
Route::post('/don-hang/{order:code}/yeu-cau-huy', [OrderDocumentController::class, 'requestCancellation'])->name('orders.cancel-request')->middleware('throttle:5,1');

Route::prefix('tai-khoan')->name('customer.')->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('/dang-nhap', [CustomerAuthController::class, 'login'])->name('login');
        Route::post('/dang-nhap', [CustomerAuthController::class, 'authenticate'])->name('login.store')->middleware('throttle:login');
        Route::get('/dang-ky', [CustomerAuthController::class, 'register'])->name('register');
        Route::post('/dang-ky', [CustomerAuthController::class, 'store'])->name('register.store')->middleware('throttle:5,1');
    });
    Route::middleware(['auth', 'role:customer'])->group(function () {
        Route::get('/', [CustomerAccountController::class, 'index'])->name('account');
        Route::patch('/', [CustomerAccountController::class, 'update'])->name('update');
        Route::put('/mat-khau', [CustomerAccountController::class, 'password'])->name('password')->middleware('throttle:5,1');
    });
});

Route::prefix('tai-khoan')->name('customer.')->middleware('guest')->group(function () {
    Route::get('/quen-mat-khau', [CustomerPasswordResetController::class, 'request'])->name('password.request');
    Route::post('/quen-mat-khau', [CustomerPasswordResetController::class, 'email'])->name('password.email')->middleware('throttle:5,1');
    Route::get('/dat-lai-mat-khau/{token}', [CustomerPasswordResetController::class, 'reset'])->name('password.reset');
    Route::post('/dat-lai-mat-khau', [CustomerPasswordResetController::class, 'update'])->name('password.reset.update')->middleware('throttle:5,1');
});

Route::middleware(['auth', 'role:admin,manager,cashier,head_chef,chef,kitchen_assistant,waiter,courier'])->prefix('nhan-vien')->name('staff.')->group(function () {
    Route::post('/don-hang/{order}/phan-cong', [AssignmentController::class, 'store'])->name('assignments.store');
    Route::patch('/cong-viec/{task}', [TaskController::class, 'update'])->name('tasks.update');
    Route::get('/ban-an', [TableController::class, 'index'])->name('tables.index')->middleware('role:admin,manager,cashier,waiter');
    Route::post('/ban-an', [TableController::class, 'store'])->name('tables.store')->middleware('role:admin,manager');
    Route::put('/ban-an/{table}', [TableController::class, 'update'])->name('tables.update')->middleware('role:admin,manager');
    Route::put('/don-hang/{order}/ban-an', [TableController::class, 'assign'])->name('tables.assign')->middleware('role:admin,manager,cashier');
});

Route::middleware('throttle:20,1')->group(function () {
    Route::get('/don-hang/{order:code}/thanh-toan-demo', [DemoPaymentController::class, 'create'])->name('demo-payments.create');
    Route::post('/don-hang/{order:code}/thanh-toan-demo', [DemoPaymentController::class, 'store'])->name('demo-payments.store');
});
Route::post('/quan-ly/don-hang/{order}/hoan-tien-demo', [DemoPaymentController::class, 'refund'])->middleware(['auth', 'role:admin'])->name('demo-payments.refund');
Route::middleware(['auth', 'role:admin,manager,cashier,waiter'])->prefix('nhan-vien')->name('staff.')->group(function () {
    Route::get('/tao-don', [PosController::class, 'create'])->name('pos.create');
    Route::post('/tao-don', [PosController::class, 'store'])->name('pos.store')->block();
    Route::get('/don-hang/{order}/them-mon', [PosController::class, 'edit'])->name('pos.edit');
    Route::post('/don-hang/{order}/them-mon', [PosController::class, 'update'])->name('pos.update')->block();
});
Route::middleware(['auth', 'role:admin,manager,head_chef,chef,kitchen_assistant,waiter'])->prefix('nhan-vien')->name('staff.')->group(function () {
    Route::get('/bep', [KitchenController::class, 'index'])->name('kitchen.index');
    Route::patch('/mon-trong-don/{item}', [KitchenController::class, 'update'])->name('kitchen.update');
});
Route::middleware(['auth', 'role:admin,cashier'])->prefix('nhan-vien')->name('staff.')->group(function () {
    Route::get('/doi-soat-ca', [ShiftController::class, 'index'])->name('shifts.index');
    Route::post('/doi-soat-ca', [ShiftController::class, 'store'])->name('shifts.store');
});
Route::get('/quan-ly/nhat-ky', [AuditController::class, 'index'])->middleware(['auth', 'role:admin'])->name('admin.audit');

Route::middleware(['auth', 'role:admin,manager,cashier,waiter'])->patch('/nhan-vien/mon-trong-don/{item}/dieu-chinh', [OrderAdjustmentController::class, 'update'])->name('staff.items.adjust');
Route::middleware(['auth', 'role:admin,manager,cashier'])->group(function () {
    Route::post('/nhan-vien/don-hang/{order}/gop', [OrderTransferController::class, 'merge'])->name('staff.orders.merge');
    Route::post('/nhan-vien/don-hang/{order}/tach', [OrderTransferController::class, 'split'])->name('staff.orders.split');
});

Route::get('/quan-ly/doanh-thu/csv', [RevenueController::class, 'index'])->middleware(['auth', 'role:admin'])->name('admin.revenue.export');
Route::get('/nhan-vien/doi-soat-ca/csv', [ShiftController::class, 'export'])->middleware(['auth', 'role:admin,cashier'])->name('staff.shifts.export');
Route::post('/nhan-vien/don-hang/{order}/xac-minh-dia-chi', [AddressVerificationController::class, 'store'])->middleware(['auth', 'role:admin,manager,cashier'])->name('staff.address.verify');

Route::get('/don-hang/{order:code}/cap-nhat', OrderUpdatesController::class)->middleware('throttle:60,1')->name('orders.updates');
Route::get('/nhan-vien/thong-bao-don', OrderAlertsController::class)->middleware(['auth', 'role:admin,manager,cashier,head_chef,chef,kitchen_assistant,waiter,courier', 'throttle:60,1'])->name('staff.orders.alerts');
