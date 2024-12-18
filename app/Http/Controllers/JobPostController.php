<?php

namespace App\Http\Controllers;

use App\Services\FirebaseRealtimeDatabaseService;
use App\Services\FirebaseAuthService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Exception;

class JobPostController extends Controller
{
    protected $auth;
    private $database;

    public function __construct(FirebaseAuthService $firebaseAuthService)
    {
        $this->auth = $firebaseAuthService->connect(); // Get the Firebase authentication service
        $this->database = FirebaseRealtimeDatabaseService::connect(); // Get the Firebase database service
    }

    // Show all job posts
    public function index()
    {
        try {
            // Fetch all employers
            $employers = $this->database->getReference('/users/employers')->getValue();

            if (!$employers) {
                return response()->json(['error' => 'No employers found'], 404);
            }

            $allJobPosts = [];

            // Iterate through each employer's jobs
            foreach ($employers as $employerId => $employerData) {
                if (isset($employerData['jobs'])) {
                    foreach ($employerData['jobs'] as $jobId => $jobData) {
                        $allJobPosts[] = $jobData;
                    }
                }
            }

            if (empty($allJobPosts)) {
                return response()->json([], 200);
            }

            return response()->json($allJobPosts, 200);
        } catch (ValidationException $e) {
            return response()->json(['validation error' => $e->errors()], 422);
        } catch (Exception $e) {
            return response()->json([
                'error' => 'An unexpected error occurred',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Create a new job post (only accessible to employers)
    public function create(Request $request)
    {
        try {
            // Extract the Authorization token from the request header
            $authHeader = $request->header('Authorization');

            if (!$authHeader) {
                return response()->json(['error' => 'Authorization token missing'], 401);
            }

            $idToken = str_replace('Bearer ', '', $authHeader);

            // Verify the ID token and get the user details
            $verifiedIdToken = $this->auth->verifyIdToken($idToken);

            // Access the 'sub' claim properly using claims() method
            $uid = $verifiedIdToken->claims()->get('sub');

            // Check if the user exists as an employee
            $employeeData = $this->database->getReference('/users/employees/' . $uid)->getValue();
            if ($employeeData) {
                $userType = 'employee';
                $userData = $employeeData;
            } else {
                // Check if the user exists as an employer
                $employerData = $this->database->getReference('/users/employers/' . $uid)->getValue();
                if ($employerData) {
                    $userType = 'employer';
                    // $userData = $employerData;
                } else {
                    return response()->json(['error' => 'User not found'], 404);
                }
            }

            // Check if the user is an employer
            if ($userType !== 'employer') {
                return response()->json(['error' => 'Only employers can create job posts'], 403);
            }

            // Validate incoming data
            $validatedData = $request->validate([
                'job_title' => 'required|string|max:255',
                'job_description' => 'required|string',
                'max_salary' => 'required|numeric',
                'min_salary' => 'required|numeric',
                'employment_type' => 'required|string',
                'location' => 'required|string',
                'skills_required' => 'required|string',
            ]);


            // Get reference for the new job
            $newJobRef = $this->database->getReference('/users/employers/' . $uid . '/jobs')->push();

            // Get the auto-generated key
            $jobId = $newJobRef->getKey();

            // Save the job post to the database with the auto-generated ID
            $newJobRef->set([
                'job_id' => $jobId,  // Now using the auto-generated key
                'job_title' => $validatedData['job_title'],
                'job_description' => $validatedData['job_description'],
                'max_salary' => $validatedData['max_salary'],
                'min_salary' => $validatedData['min_salary'],
                'employment_type' => $validatedData['employment_type'],
                'location' => $validatedData['location'],
                'skills_required' => $validatedData['skills_required'],
                'employer_uid' => $uid,
                'created_at' => now(),
            ]);

            return response()->json(['message' => 'Job post created successfully', 'job' => $newJobRef->getValue()], 201);
        } catch (\Kreait\Firebase\Exception\Auth\FailedToVerifyToken $e) {
            return response()->json(['error' => 'Invalid authentication token'], 401);
        } catch (\Kreait\Firebase\Exception\Auth\AuthError $e) {
            return response()->json(['error' => 'Authentication error'], 401);
        } catch (ValidationException $e) {
            return response()->json(['validation error' => $e->errors()], 422);
        } catch (Exception $e) {
            return response()->json([
                'error' => 'An unexpected error occurred',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Update a job post (only accessible to employers)
    public function update(Request $request, $jobPostId)
    {
        try {
            // Extract the Authorization token from the request header
            $authHeader = $request->header('Authorization');
            if (!$authHeader) {
                return response()->json(['error' => 'Authorization token missing'], 401);
            }

            $idToken = str_replace('Bearer ', '', $authHeader);

            // Verify the ID token and get the user details
            $verifiedIdToken = $this->auth->verifyIdToken($idToken);
            $uid = $verifiedIdToken->claims()->get('sub');

            // Check if the user is an employer
            $userData = $this->database->getReference('/users/employers/' . $uid)->getValue();
            if (!$userData) {
                return response()->json(['error' => 'Only employers can update job posts'], 403);
            }

            // Fetch the job post
            $jobPost = $this->database->getReference('users/employers/' . $uid . '/jobs/' . $jobPostId)->getValue();
            if (!$jobPost) {
                return response()->json(['error' => 'Job post not found'], 404);
            }

            // Check if the employer is the one who created the job post
            if ($jobPost['employer_id'] !== $uid) {
                return response()->json(['error' => 'You can only update your own job posts'], 403);
            }

            // Validate the updated data
            $validatedData = $request->validate([
                'job_title' => 'sometimes|string|max:255',
                'job_description' => 'sometimes|string',
                'max_salary' => 'sometimes|numeric',
                'min_salary' => 'sometimes|numeric',
                'employment_type' => 'sometimes|string',
                'location' => 'sometimes|string',
                'skills_required' => 'sometimes|string',
            ]);

            // Filter out null values from validated data
            $updatedJobPostData = array_filter($validatedData);

            // If no valid data to update, return an error
            if (empty($updatedJobPostData)) {
                return response()->json(['error' => 'No valid data to update'], 400);
            }

            // Update the job post in Firebase
            $this->database->getReference('users/employers/' . $uid . '/jobs/' . $jobPostId)->update($updatedJobPostData);

            return response()->json(['message' => 'Job post updated successfully'], 200);
        } catch (\Kreait\Firebase\Exception\Auth\FailedToVerifyToken $e) {
            return response()->json(['error' => 'Invalid authentication token'], 401);
        } catch (\Kreait\Firebase\Exception\Auth\AuthError $e) {
            return response()->json(['error' => 'Authentication error'], 401);
        } catch (ValidationException $e) {
            return response()->json(['validation error' => $e->errors()], 422);
        } catch (Exception $e) {
            return response()->json([
                'error' => 'An unexpected error occurred',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Delete a job post (only accessible to employers)
    public function delete(Request $request, $jobPostId)
    {
        try {
            // Extract the Authorization token from the request header
            $authHeader = $request->header('Authorization');
            if (!$authHeader) {
                return response()->json(['error' => 'Authorization token missing'], 401);
            }

            $idToken = str_replace('Bearer ', '', $authHeader);

            // Verify the ID token and get the user details
            $verifiedIdToken = $this->auth->verifyIdToken($idToken);
            $uid = $verifiedIdToken->claims()->get('sub');

            // Check if the user is an employer
            $userData = $this->database->getReference('/users/employers/' . $uid)->getValue();
            if (!$userData) {
                return response()->json(['error' => 'Only employers can delete job posts'], 403);
            }

            // Fetch the job post
            $jobPost = $this->database->getReference('users/employers/' . $uid . '/jobs/' . $jobPostId)->getValue();
            if (!$jobPost) {
                return response()->json(['error' => 'Job post not found'], 404);
            }

            // Check if the employer is the one who created the job post
            if ($jobPost['employer_uid'] !== $uid) {
                return response()->json(['error' => 'You can only delete your own job posts'], 403);
            }

            // Delete the job post from Firebase
            $this->database->getReference('users/employers/' . $uid . '/jobs/' . $jobPostId)->remove();

            return response()->json(['message' => 'Job post deleted successfully'], 200);
        } catch (\Kreait\Firebase\Exception\Auth\FailedToVerifyToken $e) {
            return response()->json(['error' => 'Invalid authentication token'], 401);
        } catch (\Kreait\Firebase\Exception\Auth\AuthError $e) {
            return response()->json(['error' => 'Authentication error'], 401);
        } catch (ValidationException $e) {
            return response()->json(['validation error' => $e->errors()], 422);
        } catch (Exception $e) {
            return response()->json([
                'error' => 'An unexpected error occurred',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Show job post by id
    public function showJobPostById(Request $request, $employer_uid, $job_id)
    {
        // Extract the Authorization token from the request header
        $authHeader = $request->header('Authorization');
        if (!$authHeader) {
            return response()->json(['error' => 'Authorization token missing'], 401);
        }

        try {
            $jobPost = $this->database->getReference('/users/employers/' . $employer_uid . '/jobs/' . $job_id)->getValue();

            // $jobPostArray = array_values($jobPost);
            return response()->json($jobPost, 200);
        } catch (ValidationException $e) {
            return response()->json(['validation error' => $e->errors()], 422);
        } catch (Exception $e) {
            return response()->json([
                'error' => 'An unexpected error occurred',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
