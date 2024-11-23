<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\JobPostController;
use App\Http\Controllers\TestController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Route for showing all blog posts
Route::get('/', [TestController::class, 'index']);

// Route for creating a new blog post
Route::post('/', [TestController::class, 'create']);

// Route for showing a specific blog post by ID
Route::get('/{id}', [TestController::class, 'show']); // Show a specific blog post by ID

// Route for editing a blog post by ID
Route::put('/{id}', [TestController::class, 'edit']); // Edit a blog post by ID

// Route for deleting a blog post by ID
Route::delete('/{id}', [TestController::class, 'delete']); // Delete a blog post by ID

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