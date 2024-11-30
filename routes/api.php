<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EmployerController;
use App\Http\Controllers\JobApplicationController;
use App\Http\Controllers\JobPostController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Auth Endpoints
Route::post('/login', [AuthController::class, 'signIn']);
Route::post('/register', [AuthController::class, 'signUp']);
Route::post('/logout', [AuthController::class, 'logout']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/verify', [AuthController::class, 'verifyIdToken']);

// Job Posts Endpoints (All routes will start at /jobs/)
Route::prefix('/jobs')->group(function () {
    Route::get('/', [JobPostController::class, 'index']); // Show all job posts (for both employees and employers)
    Route::post('/', [JobPostController::class, 'create']); // Create a job post (only for employers)
    Route::put('/{jobPostId}', [JobPostController::class, 'update']); // Update a job post (only for employers)
    Route::delete('/{jobPostId}', [JobPostController::class, 'delete']); // Delete a job post (only for employers)
    Route::get('/{jobPostId}/applications', [JobApplicationController::class, 'viewAllEmployeeApplications']);
});

// Employee Profile Endpoints (All routes will start at /employees/)
Route::prefix('employee')->group(function () {
    Route::get('/', [EmployeeController::class, 'show']); // Get Authenticated Employee's Profile (only for employees)
    Route::post('/', [EmployeeController::class, 'update']); // Update Authenticated Employee's Profile (only for employees)
    Route::delete('/', [EmployeeController::class, 'destroy']); // Delete Authenticated Employee's Profile (only for employees)
    Route::get('/my-applications', [JobApplicationController::class, 'myApplications']); // Get all employee applications (only for employees)
    Route::post('/update-password', [EmployeeController::class, 'updatePassword']); // Update the authenticated employee's password (only for employees)

    // New routes for viewing employees who applied for specific job post
    Route::get('/all', [EmployeeController::class, 'listEmployees']); // Get list of all employees (for both employees and employers)
    Route::get('/{employeeId}', [EmployeeController::class, 'getEmployeeProfile']); // Get specific employee profile (only for employers)
});

// Employer Profile Endpoints (All routes will start at /employers/)
Route::prefix('employer')->group(function () {
    Route::get('/', [EmployerController::class, 'show']); // Get Authenticated Employer's Profile (only for employers)
    Route::post('/', [EmployerController::class, 'update']); // Update Authenticated Employer's Profile (only for employers)
    Route::delete('/', [EmployerController::class, 'destroy']); // Delete Authenticated Employer's Profile (only for employers)
    Route::post('/update-password', [EmployerController::class, 'updatePassword']); // Update the authenticated employer's password (only for employers)

    // New routes for viewing employers
    Route::get('/all', [EmployerController::class, 'listEmployers']); // Get list of all employers (for both employees and employers)
    //    Route::get('/{employerId}', [EmployerController::class, 'getEmployerProfile']); // Get specific employer profile (for both employees and employers) 
    Route::get('/view-job-postings', [EmployerController::class, 'showOwnedJobPosts']);

    Route::get('/{employer_uid}/jobs/{job_id}', [JobPostController::class, 'showJobPostById']);
});

// Job Application Endpoints (All routes will start at /employers/{employerId}/jobs/{jobPostingId}/applications/)
Route::prefix('/employers/{employerId}/jobs/{jobPostingId}/applications')->group(function () {
    Route::post('/', [JobApplicationController::class, 'store']); // Create a job application for a specific job posting (only for employees) - This route will let employee apply for the job posting from employer
    Route::get('/', [JobApplicationController::class, 'show']); // Get all job applications for a specific job posting (only for employers) - This route will let employers who own job posting see who apply for their job posting

    Route::put('/{applicationId}', [JobApplicationController::class, 'update']); // Update a specific job application (only for employers)
    // Route::delete('/{applicationId}', [JobApplicationController::class, 'destroy']); // Delete a specific job application (only for employers)
});
