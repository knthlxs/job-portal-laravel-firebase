<?php

namespace App\Http\Controllers;

use App\Services\FirebaseRealtimeDatabaseService;
use App\Services\FirebaseAuthService;
use Illuminate\Http\Request;

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
                        $allJobPosts[] = [
                            'employer_id' => $employerId,
                            'job_id' => $jobId,
                            'job_data' => $jobData,
                        ];
                    }
                }
            }

            if (empty($allJobPosts)) {
                return response()->json(['message' => 'No job posts found'], 404);
            }

            return response()->json($allJobPosts, 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Could not fetch job posts: ' . $e->getMessage()], 400);
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
                    $userData = $employerData;
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
                'salary' => 'required|numeric',
                'location' => 'required|string',
                'skills_required' => 'required|string',
            ]);


            // Save the job post to the database
            $newJobPost = $this->database->getReference('/users/employers/' . $uid . '/jobs')->push([
                'job_title' => $validatedData['job_title'],
                'job_description' => $validatedData['job_description'],
                'salary' => $validatedData['salary'],
                'location' => $validatedData['location'],
                'skills_required' => $validatedData['skills_required'],
                'employer_id' => $uid,
                'created_at' => now(),
            ]);

            return response()->json(['message' => 'Job post created successfully', 'job_post_id' => $newJobPost->getKey()], 201);
        } catch (\Kreait\Firebase\Exception\Auth\FailedToVerifyToken $e) {
            return response()->json(['error' => 'Invalid authentication token'], 401);
        } catch (\Kreait\Firebase\Exception\Auth\AuthError $e) {
            return response()->json(['error' => 'Authentication error'], 401);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
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
                'salary' => 'sometimes|numeric',
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
        } catch (\Exception $e) {
            return response()->json(['error' => 'Could not update job post: ' . $e->getMessage()], 400);
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
            if ($jobPost['employer_id'] !== $uid) {
                return response()->json(['error' => 'You can only delete your own job posts'], 403);
            }

            // Delete the job post from Firebase
            $this->database->getReference('users/employers/' . $uid . '/jobs/' . $jobPostId)->remove();

            return response()->json(['message' => 'Job post deleted successfully'], 200);
        } catch (\Kreait\Firebase\Exception\Auth\FailedToVerifyToken $e) {
            return response()->json(['error' => 'Invalid authentication token'], 401);
        } catch (\Kreait\Firebase\Exception\Auth\AuthError $e) {
            return response()->json(['error' => 'Authentication error'], 401);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Could not delete job post: ' . $e->getMessage()], 400);
        }
    }
}
