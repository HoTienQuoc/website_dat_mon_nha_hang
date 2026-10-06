<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CategoryController extends Controller
{
    public function index(): View
    {
        return view('admin.categories.index', ['categories' => Category::withCount('dishes')->orderBy('name')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:100', 'name_en' => 'nullable|string|max:100']);
        Category::create([...$data, 'slug' => Str::slug($data['name']).'-'.Str::lower(Str::random(8))]);

        return back()->with('success', __('Đã thêm nhóm món.'));
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        $category->update($request->validate(['name' => 'required|string|max:100', 'name_en' => 'nullable|string|max:100']));

        return back()->with('success', __('Đã cập nhật nhóm món.'));
    }

    public function destroy(Category $category): RedirectResponse
    {
        if ($category->dishes()->exists()) {
            return back()->withErrors(['category' => __('Chuyển hoặc xóa các món thuộc nhóm trước khi xóa nhóm.')]);
        }
        try {
            $category->delete();
        } catch (QueryException $e) {
            if (in_array($e->getCode(), ['23000', '23503'], true)) {
                return back()->withErrors(['category' => __('Nhóm đang có món, không thể xóa.')]);
            } throw $e;
        }

        return back()->with('success', __('Đã xóa nhóm món.'));
    }
}
