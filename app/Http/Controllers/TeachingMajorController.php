<?php

namespace App\Http\Controllers;

use App\Enums\Instructor\TeachingMajor;

class TeachingMajorController extends Controller
{
    // GET /teaching-majors: the values accepted by the teaching_major rule.
    public function index()
    {
        return response()->json([
            'data' => TeachingMajor::values(),
        ]);
    }
}
