<?php
namespace App\Http\Controllers;

use Kreait\Firebase\Auth as FirebaseAuth;
use Kreait\Firebase\Exception\AuthException;
use App\Services\FirebaseService;
use App\Services\FirebaseAuthService;
use Illuminate\Http\Request;

class JobPostController extends Controller
{
    protected $auth;
    private $database;

    public function __construct(FirebaseAuthService $firebaseAuthService)
    {
        $this->auth = $firebaseAuthService->connect(); // Get the Firebase authentication service
        $this->database = FirebaseService::connect(); // Get the Firebase database service
    }

    

    // Show all job posts
    public function index()
    {
        try {
            // Fetch all job posts
            $jobPosts = $this->database->getReference('/jobs')->getValue();
            return response()->json($jobPosts, 200);
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
    
            // Prepare job post data
            $jobPostData = [
                'job_title' => $validatedData['job_title'],
                'job_description' => $validatedData['job_description'],
                'salary' => $validatedData['salary'],
                'location' => $validatedData['location'],
                'skills_required' => $validatedData['skills_required'],
                'employer_id' => $uid,
                'created_at' => now(),
            ];
    
            // Save the job post to the database
            $newJobPost = $this->database->getReference('/jobs')->push($jobPostData);
    
            return response()->json(['message' => 'Job post created successfully', 'job_post_id' => $newJobPost->getKey()], 201);
        } catch (\Kreait\Firebase\Exception\Auth\InvalidToken $e) {
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
            $user = $this->getCurrentUser($request); // Get the current authenticated user

            // Check if user is an employer
            if ($user->user_type !== 'employer') {
                return response()->json(['error' => 'Only employers can update job posts'], 403);
            }

            // Fetch the job post
            $jobPost = $this->database->getReference('/jobs/' . $jobPostId)->getValue();

            if (!$jobPost) {
                return response()->json(['error' => 'Job post not found'], 404);
            }

            // Check if the employer is the one who created the job post
            if ($jobPost['employer_id'] !== $user->uid) {
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

            // Update job post fields
            $updatedJobPostData = array_filter($validatedData);  // Remove null values

            // Update the job post in Firebase
            $this->database->getReference('/jobs/' . $jobPostId)->update($updatedJobPostData);

            return response()->json(['message' => 'Job post updated successfully'], 200);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Could not update job post: ' . $e->getMessage()], 400);
        }
    }

    // Delete a job post (only accessible to employers)
    public function delete(Request $request, $jobPostId)
    {
        try {
            $user = $this->getCurrentUser($request); // Get the current authenticated user

            // Check if user is an employer
            if ($user->user_type !== 'employer') {
                return response()->json(['error' => 'Only employers can delete job posts'], 403);
            }

            // Fetch the job post
            $jobPost = $this->database->getReference('/jobs/' . $jobPostId)->getValue();

            if (!$jobPost) {
                return response()->json(['error' => 'Job post not found'], 404);
            }

            // Check if the employer is the one who created the job post
            if ($jobPost['employer_id'] !== $user->uid) {
                return response()->json(['error' => 'You can only delete your own job posts'], 403);
            }

            // Delete the job post from Firebase
            $this->database->getReference('/jobs/' . $jobPostId)->remove();

            return response()->json(['message' => 'Job post deleted successfully'], 200);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Could not delete job post: ' . $e->getMessage()], 400);
        }
    }
}
