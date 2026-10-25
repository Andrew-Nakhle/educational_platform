<?php

use Illuminate\Support\Facades\Route;

//
//Route::get('/reset-password', function () {
//    return response()->json([
//        'message' => 'Use the reset token with the reset-password API endpoint.',
//    ]);
//})->name('password.reset');

Route::get('/', function () {
    return view('welcome');
});
