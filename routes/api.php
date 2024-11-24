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


// Job Posts Endpoints (All routes will start at /jobs/)
Route::prefix('/jobs')->group(function () {
    Route::get('/', [JobPostController::class, 'index']); // Show all job posts (for both employees and employers)
    Route::post('/', [JobPostController::class, 'create']); // Create a job post (only for employers)
    Route::put('/{jobPostId}', [JobPostController::class, 'update']); // Update a job post (only for employers)
    Route::delete('/{jobPostId}', [JobPostController::class, 'delete']); // Delete a job post (only for employers)
});

// Employee Profile Endpoints (All routes will start at /employees/)
Route::prefix('employees')->group(function () {
    Route::get('/', [EmployeeController::class, 'show']); // Get Authenticated Employee's Profile (only for employees)
    Route::post('/', [EmployeeController::class, 'update']); // Update Authenticated Employee's Profile (only for employees)
    Route::delete('/', [EmployeeController::class, 'destroy']); // Delete Authenticated Employee's Profile (only for employees)
    Route::get('/my-applications', [JobApplicationController::class, 'myApplications']); // Get all employee applications (only for employees)
});

// Employee Profile Endpoints (All routes will start at /employers/)
Route::prefix('employers')->group(function () {
    Route::get('/', [EmployerController::class, 'show']); // Get Authenticated Employer's Profile (only for employers)
    Route::put('/', [EmployerController::class, 'update']); // Update Authenticated Employer's Profile (only for employers)
    Route::delete('/', [EmployerController::class, 'destroy']); // Delete Authenticated Employer's Profile (only for employers)
});

// Job Application Endpoints (All routes will start at /employers/{employerId}/jobs/{jobPostingId}/applications/)
Route::prefix('/employers/{employerId}/jobs/{jobPostingId}/applications')->group(function () {
    Route::post('/', [JobApplicationController::class, 'store']); // Create a job application for a specific job posting (only for employees) - This route will let employee apply for the job posting from employer
    Route::get('/', [JobApplicationController::class, 'index']); // Get all job applications for a specific job posting (only for employers) - This route will let employers who own job posting see who apply for their job posting
    
    // Route::put('/{applicationId}', [JobApplicationController::class, 'update']); // Update a specific job application (only for employers)
    // Route::delete('/{applicationId}', [JobApplicationController::class, 'destroy']); // Delete a specific job application (only for employers)
});



