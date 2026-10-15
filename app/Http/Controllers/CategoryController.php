<?php

namespace App\Http\Controllers;

use App\Http\Requests\Admin\Category\CreateCategoryRequest;
use App\Http\Requests\Admin\Category\UpdateCategoryRequest;
use App\Http\Resources\Admin\CategoryResource;
use App\Models\Category;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function createCategory(CreateCategoryRequest $request)
    {
        $validated = $request->validated();

        $category = Category::create([
            'name' => $validated['name'],
        ]);

        return response()->json([
            'message' => 'Category created successfully.',
            'category' => $category,
        ], 201);
    }
    public function getAllCategories()
    {
        $categories = Category::all();

        return CategoryResource::collection($categories);
    }
    public function getCategoryById(int $id)
    {
        $category = Category::find($id);
        if (is_null($category)) {
            return response()->json([
                'message' => 'Category not found',
            ],404);
        }

        return response()->json(new CategoryResource($category));
    }

    public function updateCategory(UpdateCategoryRequest $request, int $id)
    {
        $validated = $request->validated();

        $category = Category::find($id);

        if (is_null($category)) {
            return response()->json([
                'message' => 'Category not found',
            ],404);
        }

        $category->update([
            'name' => $validated['name'],
        ]);

        return new CategoryResource($category);
    }
    public function deleteCategory(int $id)
    {
        $category = Category::find($id);

        if (is_null($category)) {
            return response()->json([
                'message' => 'Category not found',
            ],404);
        }
        $category->delete();

        return response()->json([
            'message' => 'Category deleted successfully.',
        ]);
    }
}
