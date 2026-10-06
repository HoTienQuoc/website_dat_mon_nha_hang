<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Dish;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MenuController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['q' => 'nullable|string|max:100', 'category' => 'nullable|integer|exists:categories,id']);
        $query = Dish::query()->with('category');
        if (! empty($filters['q'])) {
            $query->where(fn ($q) => $q->where('name', 'like', '%'.$filters['q'].'%')->orWhere('name_en', 'like', '%'.$filters['q'].'%'));
        }
        if (! empty($filters['category'])) {
            $query->where('category_id', $filters['category']);
        }

        return view('menu.index', ['dishes' => $query->orderBy('name')->paginate(12)->withQueryString(), 'categories' => Category::orderBy('name')->get()]);
    }
}
