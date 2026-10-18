<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CourseController;

use App\Http\Controllers\InstructorController;
use App\Http\Controllers\LessonController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\SectionController;
use App\Http\Controllers\StudentController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/payments/stripe/webhook', [PaymentController::class, 'webhook']);

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
//    Route::post('login/manager', [AuthController::class, 'loginManager']);
    Route::post('/register', [AuthController::class, 'registerStudent']);
    Route::get('/profile', [AuthController::class, 'profile'])->middleware('auth:sanctum');
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
});
//admin////
Route::prefix('admin')->middleware(['auth:sanctum','role:admin',])->group(function () {
    Route::post('/instructors', [AuthController::class, 'registerInstructor']);
    Route::get('/users', [AdminController::class, 'getAllUsers']);
    Route::get('/users/{id}', [AdminController::class, 'getUserById']);
    Route::patch('/users/{id}/status', [AdminController::class, 'updateUserStatus']);
    Route::post('/categories', [CategoryController::class, 'createCategory']);
    Route::get('/categories', [CategoryController::class, 'getAllCategories']);
    Route::get('/categories/{id}', [CategoryController::class, 'getCategoryById']);
    Route::put('/categories/{id}', [CategoryController::class, 'updateCategory']);
    Route::delete('/categories/{id}', [CategoryController::class, 'deleteCategory']);
    Route::get('/courses', [AdminController::class, 'getAllCourses']);
    Route::get('/courses/{courseId}', [AdminController::class, 'getCourseById']);
    Route::patch('/courses/{courseId}/approve', [AdminController::class, 'approveCourse']);
    Route::patch('/courses/{courseId}/reject', [AdminController::class, 'rejectCourse']);
    });
//instructor/////./
Route::prefix('instructor')->middleware(['auth:sanctum','role:instructor','user.status'])->group(function () {

    Route::put('/profile', [InstructorController::class, 'updateProfile']);

    Route::post('/courses', [CourseController::class, 'createCourse']);

    Route::post('/courses/{courseId}/sections', [SectionController::class, 'createSection']);

    Route::post('/sections/{sectionId}/lessons', [LessonController::class, 'createLesson']);
    Route::post('/lessons/{lessonVideoId}/video/retry', [LessonController::class, 'retryVideo']);
    Route::get('/sections/{sectionId}/lessons', [LessonController::class, 'getLessons']);//بجيب دروس السكشن
    Route::get('lessons/{lessonId}', [LessonController::class, 'getLessonById']);
    Route::put('/lessons/{lessonId}', [LessonController::class, 'updateLesson']);
    Route::delete('/lessons/{lessonId}', [LessonController::class, 'deleteLesson']);

    Route::get('/courses/{courseId}/sections', [SectionController::class, 'getSections']);//بحيب كل السكشن تبع الكورس

    Route::get('/sections/{sectionId}', [SectionController::class, 'getSectionById']);

    Route::put('/sections/{sectionId}', [SectionController::class, 'updateSection']);

    Route::delete('/sections/{sectionId}', [SectionController::class, 'deleteSection']);

    Route::get('/courses', [CourseController::class, 'getCourses']);
    Route::get('/courses/{courseId}', [CourseController::class, 'getCourseById']);
    Route::put('/courses/{courseId}', [CourseController::class, 'updateCourse']);
    Route::post('/courses/{courseId}', [CourseController::class, 'updateCourse']);//هدول التنين مكررين شي بخص الباك
    Route::delete('/courses/{courseId}', [CourseController::class, 'deleteCourse']);
    Route::post('/courses/{courseId}/submit', [CourseController::class, 'submitCourse']);


    Route::patch('/courses/{courseId}/archive', [CourseController::class, 'archiveCourse']);

    Route::patch('/courses/{courseId}/restore', [CourseController::class, 'restoreCourse']);
});
//student
Route::prefix('student')->middleware(['auth:sanctum','role:student','user.status'])->group(function () {
    Route::put('/profile', [StudentController::class, 'updateProfile']);
    Route::post('/courses/{courseId}/enroll', [StudentController::class, 'enrollCourse']);
    Route::get('/my-courses', [StudentController::class, 'getMyCourses']);
    Route::get('/courses', [StudentController::class, 'getCourses',]);
    Route::get('/courses/{courseId}', [StudentController::class, 'showCourse']);
    Route::get('/lessons/{lessonId}/video', [StudentController::class, 'getLessonVideo']);
//    Route::post('/courses/{courseId}/payment', [PaymentController::class, 'createPayment']);
});
