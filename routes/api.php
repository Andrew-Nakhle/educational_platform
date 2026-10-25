<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ContactMessageController;
use App\Http\Controllers\CourseController;

use App\Http\Controllers\InstructorController;
use App\Http\Controllers\LessonController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\SectionController;
use App\Http\Controllers\StatsController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\TeachingMajorController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Mail;

Route::post('/payments/stripe/webhook', [PaymentController::class, 'webhook']);
Route::prefix('student')->group(function () {
    Route::get('/courses', [StudentController::class, 'getCourses']);
    Route::get('/courses/{courseId}', [StudentController::class, 'showCourse']);
});
Route::get('/courses/{courseId}/ratings', [ReviewController::class, 'getCourseRating']);
Route::get('/instructors/{instructorProfileId}/ratings', [ReviewController::class, 'getInstructorRating']);
Route::get('/categories',[StudentController::class, 'getAllCategories']);
Route::get('/stats', [StatsController::class, 'index']);
Route::get('/teaching-majors', [TeachingMajorController::class, 'index']);
Route::post('/contact', [ContactMessageController::class, 'store'])->middleware('throttle:5,1');

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
//    Route::post('login/manager', [AuthController::class, 'loginManager']);
    Route::post('/register', [AuthController::class, 'registerStudent']);
    Route::get('/profile', [AuthController::class, 'profile'])->middleware('auth:sanctum');
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    Route::post('/change-password', [AuthController::class, 'changePassword'])->middleware('auth:sanctum');
    Route::post('/register-instructor', [AuthController::class, 'registerInstructor',]);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword',]);
    Route::post('/reset-password', [AuthController::class, 'resetPassword',]);
    Route::put('/change-password', [AuthController::class, 'changePassword',])->middleware('auth:sanctum');
});
//admin////
Route::prefix('admin')->middleware(['auth:sanctum','role:admin',])->group(function () {
//    Route::post('/instructors', [AuthController::class, 'registerInstructor']);
    Route::get('/stats', [StatsController::class, 'admin']);
    Route::get('/contact-messages', [ContactMessageController::class, 'index']);
    Route::patch('/contact-messages/{id}/read', [ContactMessageController::class, 'markAsRead']);
    Route::get('/users', [AdminController::class, 'getAllUsers']);
    Route::get('/payments', [AdminController::class, 'getAllPayments']);
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
    Route::get('/instructor-requests', [AdminController::class, 'getInstructorRequests',]);
    Route::get('/instructor-requests/{userId}', [AdminController::class, 'getInstructorRequest',]);
    Route::patch('/instructor-requests/{userId}/approve', [AdminController::class, 'approveInstructor',]);
    Route::patch('/instructor-requests/{userId}/reject', [AdminController::class, 'rejectInstructor',]);
    });
//instructor/////./
Route::prefix('instructor')->middleware(['auth:sanctum','role:instructor','user.status'])->group(function () {

    Route::put('/profile', [InstructorController::class, 'updateProfile']);

    Route::post('/courses', [CourseController::class, 'createCourse']);

    Route::post('/courses/{courseId}/sections', [SectionController::class, 'createSection']);

    Route::post('/sections/{sectionId}/lessons', [LessonController::class, 'createLesson']);
    Route::post('/lessons/{lessonVideoId}/video/retry', [LessonController::class, 'retryVideo']);
    Route::post('/lessons/{lessonId}/video', [LessonController::class, 'replaceVideo']);
    Route::get('/sections/{sectionId}/lessons', [LessonController::class, 'getLessons']);//بجيب دروس السكشن
    Route::get('lessons/{lessonId}', [LessonController::class, 'getLessonById']);
    Route::put('/lessons/{lessonId}', [LessonController::class, 'updateLesson']);
    Route::delete('/lessons/{lessonId}', [LessonController::class, 'deleteLesson']);

    Route::patch('/courses/{courseId}/sections/reorder', [SectionController::class, 'reorderSections']);
    Route::patch('/sections/{sectionId}/lessons/reorder', [LessonController::class, 'reorderLessons']);
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
//    Route::get('/courses', [StudentController::class, 'getCourses',]);
//    Route::get('/courses/{courseId}', [StudentController::class, 'showCourse']);
    Route::get('/lessons/{lessonId}/video', [StudentController::class, 'getLessonVideo']);
//    Route::post('/courses/{courseId}/payment', [PaymentController::class, 'createPayment']);
    Route::post('/courses/{courseId}/rate', [ReviewController::class, 'rateCourse']);
    Route::post('/courses/{courseId}/rate-instructor', [ReviewController::class, 'rateInstructor']);
    Route::put('/courses/{courseId}/rate', [ReviewController::class, 'updateCourseRating']);
    Route::put('/courses/{courseId}/rate-instructor', [ReviewController::class, 'updateInstructorRating']);
    Route::post('/lessons/{lessonId}/complete', [StudentController::class, 'completeLesson']);
    Route::delete('/lessons/{lessonId}/complete', [StudentController::class, 'uncompleteLesson']);


});
