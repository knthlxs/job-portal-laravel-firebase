<?php

namespace App\Http\Controllers;

use App\Services\FirebaseService;
use App\Services\FirebaseAuthService;
use App\Services\FirebaseStorageService;
use Illuminate\Http\Request;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Auth;

class EmployeeController extends Controller
{
    protected $auth;
    private $database;
    private $storage;

    public function __construct(FirebaseAuthService $firebaseAuthService)
    {
        $this->auth = $firebaseAuthService->connect(); // Get the Firebase authentication service
        $this->database = FirebaseService::connect(); // Get the Firebase database service
        $this->storage = FirebaseStorageService::connect();

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
     * Ensure that the authenticated user is an employee.
     */
    private function ensureEmployee(string $uid)
    {
        $employeeData = $this->database->getReference("/users/employees/{$uid}")->getValue();
        if (!$employeeData) {
            throw new \Exception('User is not an employee or does not exist');
        }
    }

    /**
     * Get the authenticated employee's details.
     */
    public function show(Request $request)
    {
        try {
            // Verify the user and retrieve their UID
            $uid = $this->getAuthenticatedUserUid($request);

            // Ensure the UID belongs to an employee
            $this->ensureEmployee($uid);

            // Retrieve the employee's profile
            $employee = $this->database->getReference("/users/employees/{$uid}")->getValue();

            if (!$employee) {
                return response()->json(['error' => 'Employee not found'], 404);
            }

            return response()->json($employee, 200);
        } catch (\Kreait\Firebase\Exception\Auth\InvalidToken $e) {
            return response()->json(['error' => 'Invalid authentication token'], 401);
        } catch (\Kreait\Firebase\Exception\Auth\AuthError $e) {
            return response()->json(['error' => 'Authentication error'], 401);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Could not fetch employee: ' . $e->getMessage()], 400);
        }
    }


    /**
     * Update the authenticated employee's details.
     */
    public function update(Request $request)
    {
        try {
            // Verify the user and retrieve their UID
            $uid = $this->getAuthenticatedUserUid($request);
    
            // Ensure the UID belongs to an employee
            $this->ensureEmployee($uid);
    
            // Fetch the current data of the employee from Firebase
            $currentData = $this->database->getReference("/users/employees/{$uid}")->getValue();
    
            if (!$currentData) {
                return response()->json(['error' => 'Employee not found'], 404);
            }
            
    
            // Validate input data, including the resume file (if provided)
            $validatedData = $request->validate([
                'full_name' => 'sometimes|string|max:255',
                'email_address' => 'sometimes|email',
                'birthday' => 'sometimes|date',
                'phone_number' => 'sometimes|string|max:15',
                'location' => 'sometimes|string|max:255',
                'skills' => 'sometimes|string',
                'resume_file' => 'sometimes|file|mimes:pdf,docx|max:10240',  // Add file validation here
            ]);
    
            // Prepare the data to be updated, including the resume URL if a file is uploaded
            $updatedData = [
                'user_type' => 'employee',
                'full_name' => $request->input('full_name', $currentData['full_name']), // Default to current value if not provided
                'email_address' => $request->input('email_address', $currentData['email_address']),
                'birthday' => $request->input('birthday', $currentData['birthday']),
                'phone_number' => $request->input('phone_number', $currentData['phone_number']),
                'location' => $request->input('location', $currentData['location']),
                'skills' => $request->input('skills', $currentData['skills']),
            ];
    
          
    
            // If no valid data to update, return an error
            if (empty($updatedData)) {
                return response()->json(['error' => 'No valid data to update'], 400);
            }
    
            // Update the employee's profile in Firebase
            $this->database->getReference("/users/employees/{$uid}")->update($updatedData);
    
            return response()->json(['message' => 'Employee updated successfully'], 200);
        } catch (\Kreait\Firebase\Exception\Auth\InvalidToken $e) {
            return response()->json(['error' => 'Invalid authentication token'], 401);
        } catch (\Kreait\Firebase\Exception\Auth\AuthError $e) {
            return response()->json(['error' => 'Authentication error'], 401);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Could not update employee: ' . $e->getMessage()], 400);
        }
    }
    
    
    public function downloadFile($fileName)
    {
        try {
            // Get the download URL
            $downloadUrl = $this->getDownloadUrl($fileName);

            // Return the download URL as a response
            return response()->json([
                'download_url' => $downloadUrl
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Could not retrieve download URL: ' . $e->getMessage()
            ], 400);
        }
    }

    public function uploadFile($filePath, $fileName)
    {
        $bucket = $this->storage->getBucket();
        $bucket->upload(
            fopen($filePath, 'r'),  // File to upload
            [
                'name' => $fileName  // Destination file name in Firebase Storage
            ]
        );
    }


    public function getDownloadUrl($fileName)
    {
        $bucket = $this->storage->getBucket();
        $object = $bucket->object($fileName);
        return $object->signedUrl(now()->addMinutes(5));  // Temporary download URL valid for 5 minutes
    }


    public function deleteFile($fileName)
    {
        $bucket = $this->storage->getBucket();
        $bucket->object($fileName)->delete();
    }


 

    /**
     * Delete the authenticated employee's account.
     */
    public function destroy(Request $request)
    {
        try {
            // Get the Authorization token
            $authHeader = $request->header('Authorization');
            if (!$authHeader) {
                return response()->json(['error' => 'Authorization token missing'], 401);
            }

            $idToken = str_replace('Bearer ', '', $authHeader);

            // Verify the ID token and extract the UID
            $verifiedIdToken = $this->auth->verifyIdToken($idToken);
            $uid = $verifiedIdToken->claims()->get('sub');

            // Check if the employee exists
            $employeeData = $this->database->getReference('/users/employees/' . $uid)->getValue();
            if (!$employeeData) {
                return response()->json(['error' => 'Employee profile not found'], 404);
            }

            // Delete the employee's data from the database
            $this->database->getReference('/users/employees/' . $uid)->remove();

            // Delete the employee's account from Firebase Authentication
            $this->auth->deleteUser($uid);

            return response()->json(['message' => 'Employee profile and account deleted successfully'], 200);
        } catch (\Kreait\Firebase\Exception\Auth\InvalidToken $e) {
            return response()->json(['error' => 'Invalid authentication token'], 401);
        } catch (\Kreait\Firebase\Exception\Auth\UserNotFound $e) {
            return response()->json(['error' => 'Authentication account not found'], 404);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Could not delete employee profile: ' . $e->getMessage()], 400);
        }
    }
}
