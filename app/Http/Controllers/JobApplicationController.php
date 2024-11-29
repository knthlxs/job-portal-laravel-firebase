<?php

namespace App\Http\Controllers;

use App\Services\FirebaseRealtimeDatabaseService;
use App\Services\FirebaseAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class JobApplicationController extends Controller
{
    protected $auth;
    private $database;

    public function __construct()
    {
        $this->auth = FirebaseAuthService::connect(); // Get the Firebase authentication service
        $this->database = FirebaseRealtimeDatabaseService::connect(); // Get the Firebase database service
    }

    /**
     * View all job applications submitted by the authenticated employee. Only employee can make this request.
     */
    public function myApplications(Request $request)
    {
        try {
            $uid = $this->getAuthenticatedUserUid($request); // Verify the user and retrieve their UID

            $this->ensureEmployee($uid); // Ensure the UID belongs to an employee

            $applications = $this->database->getReference("/users/employees/{$uid}/job_applications")
                ->getValue(); // Retrieve all applications under this employee

            // If no applications are found, return a message
            if (!$applications) {
                return response()->json(['message' => 'No job applications found'], 404);
            }

            // Convert the Firebase data structure to a simple array of values (removing the keys)
            $applicationsArray = array_values($applications);

            // Return the applications in the desired format
            return response()->json($applicationsArray, 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Could not retrieve your job applications: ' . $e->getMessage()], 400);
        }
    }


    /**
     * Extract the UID of the authenticated user from the request.
     */
    private function getAuthenticatedUserUid(Request $request)
    {
        $authHeader = $request->header('Authorization');
        if (!$authHeader) {
            throw new \Exception('Authorization token missing');
        }
        $idToken = str_replace('Bearer ', '', $authHeader);
        $verifiedIdToken = $this->auth->verifyIdToken($idToken);
        return $verifiedIdToken->claims()->get('sub');
    }

    /**
     * Check if the user is an employee.
     */
    private function ensureEmployee(string $uid)
    {
        $employeeData = $this->database->getReference("/users/employees/{$uid}")->getValue();
        if (!$employeeData) {
            throw new \Exception('User is not an employee or does not exist');
        }
    }

    /**
     * Ensure that the authenticated user is an employer.
     */
    private function ensureEmployer(string $uid)
    {
        $employerData = $this->database->getReference("/users/employers/{$uid}")->getValue();
        if (!$employerData) {
            throw new \Exception('User is not an employer or does not exist');
        }
    }

    /**
     * Create a new job application for the authenticated employee under the employer's job posting. Only employee can apply for a job.
     */
    public function store(Request $request, $employerId, $jobPostingId)
    {
        try {
            // Verify the user and retrieve their UID
            $uid = $this->getAuthenticatedUserUid($request);

            // Ensure the UID belongs to an employee
            $this->ensureEmployee($uid);

            // Reference to all job applications for the employee
            $employeeApplicationsRef = $this->database
                ->getReference("/users/employees/{$uid}/job_applications/")
                ->getValue();

            // Check if the employee has already applied to this job
            if (!empty($employeeApplicationsRef)) {
                foreach ($employeeApplicationsRef as $application) {
                    if (isset($application['job_id']) && $application['job_id'] === $jobPostingId) {
                        return response()->json(['error' => 'You have already applied to this job posting'], 409);
                    }
                }
            }

            // Get the job details based on jobPostingId
            $jobPostRef = $this->database->getReference("/users/employers/{$employerId}/jobs/{$jobPostingId}")->getValue();

            if (empty($jobPostRef)) {
                return response()->json(['error' => 'Job posting not found'], 404);
            }

            // Get the employer details (name, email, etc.)
            $employerRef = $this->database->getReference("/users/employers/{$employerId}")->getValue();
            if (empty($employerRef)) {
                return response()->json(['error' => 'Employer not found'], 404);
            }

            // Get the employee details (name, email, etc.)
            $employeeRef = $this->database->getReference("/users/employees/{$uid}")->getValue();
            if (empty($employeeRef)) {
                return response()->json(['error' => 'Employee not found'], 404);
            }

            // Get reference and auto-generated ID for the new application
            $applicationRef = $this->database
                ->getReference("/users/employers/{$employerId}/jobs/{$jobPostingId}/applications")
                ->push();

            $applicationId = $applicationRef->getKey();

            // Prepare the data to be stored, including job and user details
            $applicationData = [
                'application_id' => $applicationId,
                'employee_uid' => $employeeRef['employee_uid'] ?? null,
                'employee_name' => $employeeRef['name'] ?? null,  // Null coalescing operator for null checks
                'employee_email' => $employeeRef['email'] ?? null,
                'employee_phone_number' => $employeeRef['phone_number'] ?? null,
                'employee_location' => $employeeRef['location'] ?? null,
                'employee_birthday' => $employeeRef['birthday'] ?? null,
                'employee_skills' => $employeeRef['skills'] ?? null,
                'employee_resume' => $employeeRef['resume'] ?? null,
                'employee_profile_picture' => $employeeRef['profile_picture'] ?? null,
                'employer_uid' => $employerRef['employer_uid'] ?? null,
                'employer_name' => $employerRef['name'] ?? null,
                'employer_email' => $employerRef['email'] ?? null,
                'employer_phone_number' => $employerRef['phone_number'] ?? null,
                'employer_location' => $employerRef['location'] ?? null,
                'employer_industry' => $employerRef['industry'] ?? null,
                'employer_contact_person_name' => $employerRef['contact_person_name'] ?? null,
                'employer_logo' => $employerRef['company_logo'] ?? null,
                'job_id' => $jobPostingId,
                'job_title' => $jobPostRef['job_title'] ?? null,
                'job_description' => $jobPostRef['job_description'] ?? null,
                'location' => $jobPostRef['location'] ?? null,
                'min_salary' => $jobPostRef['min_salary'] ?? null,
                'max_salary' => $jobPostRef['min_salary'] ?? null,
                'employment_type' => $jobPostRef['employment_type'] ?? null,
                'skills_required' => $jobPostRef['skills_required'] ?? null,
                'already_applied' => true,
                'application_status' => 'pending',
                'created_at' => now(),
            ];


            // Save the application data under the employer's job posting reference
            $applicationRef->set($applicationData);

            // Save the job application under the employee's reference
            $this->database
                ->getReference("/users/employees/{$uid}/job_applications/")
                ->push($applicationData);

            return response()->json([
                'application_data' => $applicationData
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Could not create job application: ' . $e->getMessage()], 400);
        }
    }

    /**
     * Get all job applications for a specific job posting under an employer.
     */
    public function show(Request $request, $employerId, $jobPostingId)
    {
        try {
            // Verify the user and retrieve their UID
            $uid = $this->getAuthenticatedUserUid($request);

            $this->ensureEmployer($uid);


            // Ensure the employer is trying to access their own job posting
            if ($uid !== $employerId) {
                return response()->json(['error' => 'You do not own this job posting. You are not authorized to view applications for this job posting'], 403);
            }

            // Retrieve the job applications for the specific job posting
            $applications = $this->database->getReference("/users/employers/{$employerId}/jobs/{$jobPostingId}/applications")->getValue();

            if (!$applications) {
                return response()->json(['message' => 'No job applications found'], 404);
            }

            return response()->json($applications, 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Could not retrieve job applications: ' . $e->getMessage()], 400);
        }
    }

    // /**
    //  * Update a job application for the authenticated employee under the employer's job posting.
    //  */
    public function update(Request $request, $employerId, $jobPostingId, $applicationId)
    {
        try {
            // Verify the user and retrieve their UID
            $uid = $this->getAuthenticatedUserUid($request);

            $this->ensureEmployer($uid);

            $request->validate([
                'application_status' => 'required|string'
            ]);

            // Ensure the employer is trying to access their own job posting
            if ($uid !== $employerId) {
                return response()->json(['error' => 'You do not own this job posting. You are not authorized to update applications for this job posting'], 403);
            }

            // Fetch current application data
            $currentData = $this->database->getReference("/users/employers/{$employerId}/jobs/{$jobPostingId}/applications/{$applicationId}")->getValue();

            if (!$currentData) {
                return response()->json(['error' => 'Job application not found'], 404);
            }

            $updatedData = array_merge($currentData, [
                // 'already_applied' => true,
                'application_status' => $request->application_status,
                'updated_at' => now()
            ]);

            // Update both records
            $updates = [
                "/users/employees/{$currentData['employee_uid']}/job_applications/{$jobPostingId}" => $updatedData, // for employee
                "/users/employers/{$employerId}/jobs/{$jobPostingId}/applications/{$applicationId}" => $updatedData, // for employer
            ];

            $this->database->getReference()->update($updates);

            // Delete the post and application from database after marking the job application as approved
            // $this->database->getReference()->remove();

            return response()->json(['message' => 'Job application updated successfully'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Could not update job application: ' . $e->getMessage()], 400);
        }
    }

    // /**
    //  * Delete a job application for the authenticated employee under the employer's job posting.
    //  */
    // public function destroy(Request $request, $employerId, $jobPostingId, $applicationId)
    // {
    //     try {
    //         // Verify the user and retrieve their UID
    //         $uid = $this->getAuthenticatedUserUid($request);

    //     $this->ensureEmployer($uid);

    //     // Ensure the employer is trying to access their own job posting
    //     if ($uid !== $employerId) {
    //        return response()->json(['error' => 'You do not own this job posting. You are not authorized to update applications for this job posting'], 403);
    //    }


    //         // Fetch the job application from Firebase
    //         $applicationData = $this->database->getReference("/users/employers/{$employerId}/job_postings/{$jobPostingId}/applications/{$applicationId}")->getValue();

    //         if (!$applicationData) {
    //             return response()->json(['error' => 'Job application not found'], 404);
    //         }

    //         // Ensure the application belongs to the authenticated employee
    //         if ($applicationData['employee_uid'] !== $uid) {
    //             return response()->json(['error' => 'You are not authorized to delete this application'], 403);
    //         }

    //         // Delete the job application from Firebase
    //         $this->database->getReference("/users/employers/{$employerId}/job_postings/{$jobPostingId}/applications/{$applicationId}")->remove();

    //         return response()->json(['message' => 'Job application deleted successfully'], 200);
    //     } catch (\Exception $e) {
    //         return response()->json(['error' => 'Could not delete job application: ' . $e->getMessage()], 400);
    //     }
    // }
}
