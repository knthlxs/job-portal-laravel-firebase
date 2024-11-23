<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\JobPostController;
use App\Http\Controllers\TestController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');


Route::post('/login', [AuthController::class, 'signIn']);
Route::post('/register', [AuthController::class, 'signUp']);
Route::post('/verify', [AuthController::class, 'verifyIdToken']);

Route::get('/download/{fileName}', [AuthController::class, 'downloadFile']);


// Job Posts
// Show all job posts
Route::get('/job-posts', [JobPostController::class, 'index']);

// Create a job post (only for employers)
Route::post('/job-posts', [JobPostController::class, 'create']);

// Update a job post (only for employers)
Route::put('/job-posts/{jobPostId}', [JobPostController::class, 'update']);

// Delete a job post (only for employers)
Route::delete('/job-posts/{jobPostId}', [JobPostController::class, 'delete']);