<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EmployerController;
use App\Http\Controllers\JobApplicationController;
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

Route::prefix('employees')->group(function () {
    // Create/Register Employee
    Route::post('/', [EmployeeController::class, 'store']);

    // Get Authenticated Employee's Profile
    Route::get('/', [EmployeeController::class, 'show']);

    // Update Authenticated Employee's Profile
    Route::put('/', [EmployeeController::class, 'update']);

    // Delete Authenticated Employee's Profile
    Route::delete('/', [EmployeeController::class, 'destroy']);
});
Route::prefix('employers')->group(function () {
    // Create/Register Employee
    Route::post('/', [EmployerController::class, 'store']);

    // Get Authenticated Employee's Profile
    Route::get('/', [EmployerController::class, 'show']);

    // Update Authenticated Employee's Profile
    Route::put('/', [EmployerController::class, 'update']);

    // Delete Authenticated Employee's Profile
    Route::delete('/', [EmployerController::class, 'destroy']);
});
Route::prefix('/employers/{employerId}/job_postings/{jobPostingId}/applications')->group(function () {
// Create a job application for a specific job posting (POST)
Route::post('/', [JobApplicationController::class, 'store']);

// Get all job applications for a specific job posting (GET)
Route::get('/', [JobApplicationController::class, 'index']);

 // Update a specific job application (PUT)
// Route::put('/{applicationId}', [JobApplicationController::class, 'update']);

 // Delete a specific job application (DELETE)
// Route::delete('/{applicationId}', [JobApplicationController::class, 'destroy']);
});


Route::get('/my-applications', [JobApplicationController::class, 'myApplications']); // All employee applications


