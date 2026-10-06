<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\DishRequest;
use App\Models\Category;
use App\Models\Dish;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class DishController extends Controller
{
    public function index(Request $request): View
    {
        $data = $request->validate(['q' => 'nullable|string|max:100']);
        $query = Dish::with('category');
        if (! empty($data['q'])) {
            $query->where(fn ($query) => $query->where('name', 'like', '%'.$data['q'].'%')->orWhere('name_en', 'like', '%'.$data['q'].'%'));
        }

        return view('admin.dishes.index', ['dishes' => $query->latest('id')->paginate(15)->withQueryString()]);
    }

    public function create(): View
    {
        return view('admin.dishes.form', ['dish' => new Dish(['is_available' => true]), 'categories' => Category::orderBy('name')->get()]);
    }

    public function store(DishRequest $request): RedirectResponse
    {
        $data = $request->safe()->except(['image', 'remove_image']);
        $data['slug'] = Str::slug($data['name']).'-'.Str::lower(Str::random(8));
        $path = $request->file('image')?->store('dishes', 'public');
        $data['image_path'] = $path;
        try {
            Dish::create($data);
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('public')->delete($path);
            } throw $e;
        }

        return redirect()->route('admin.dishes.index')->with('success', __('Đã thêm món mới.'));
    }

    public function edit(Dish $dish): View
    {
        return view('admin.dishes.form', ['dish' => $dish, 'categories' => Category::orderBy('name')->get()]);
    }

    public function update(DishRequest $request, Dish $dish): RedirectResponse
    {
        $data = $request->safe()->except(['image', 'remove_image']);
        $old = $dish->image_path;
        $path = $request->file('image')?->store('dishes', 'public');
        if ($path) {
            $data['image_path'] = $path;
        } elseif ($request->boolean('remove_image')) {
            $data['image_path'] = null;
        }
        try {
            $dish->update($data);
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('public')->delete($path);
            } throw $e;
        }
        if ($old && $old !== $dish->image_path && ! Dish::where('image_path', $old)->exists()) {
            Storage::disk('public')->delete($old);
        }

        return redirect()->route('admin.dishes.index')->with('success', __('Đã cập nhật món. Giá của đơn cũ được giữ nguyên.'));
    }

    public function destroy(Dish $dish): RedirectResponse
    {
        $path = $dish->image_path;
        $dish->delete();
        if ($path && ! Dish::where('image_path', $path)->exists()) {
            Storage::disk('public')->delete($path);
        }

        return back()->with('success', __('Đã xóa món. Chi tiết đơn cũ vẫn được giữ.'));
    }
}
