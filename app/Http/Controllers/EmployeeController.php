<?php

namespace App\Http\Controllers;

use App\Services\FirebaseRealtimeDatabaseService;
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

    public function __construct()
    {
        $this->auth = FirebaseAuthService::connect(); // Get the Firebase authentication service
        $this->database = FirebaseRealtimeDatabaseService::connect(); // Get the Firebase database service
        $this->storage = FirebaseStorageService::connect(); // Get the Firebase storage service
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
        $verifiedIdToken = $this->auth->verifyIdToken($idToken); // Verify ID Token (to validate authentication)
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
     * Get a list of all employees (accessible by employers only).
     */
    public function listEmployees(Request $request)
    {
        try {
            // Verify the user and retrieve their UID
            // $uid = $this->getAuthenticatedUserUid($request);

            // Ensure the user is an employer
            // $employerData = $this->database->getReference("/users/employers/{$uid}")->getValue();
            // if (!$employerData) {
            //     return response()->json(['error' => 'Only employers can view all employee listings'], 403);
            // }

            // Get all employees from the database
            $employees = $this->database->getReference('/users/employees')->getValue();

            // If no employees found, return empty array
            if (!$employees) {
                return response()->json(['data' => []], 200);
            }

            // Transform the data to remove sensitive information
            $transformedEmployees = [];
            foreach ($employees as $employeeId => $employee) {
                $transformedEmployees[] = [
                    'employee_uid' => $employeeId,
                    'email' => $employee['email'] ?? null,
                    'name' => $employee['name'] ?? null,
                    'location' => $employee['location'] ?? null,
                    'skills' => $employee['skills'] ?? null,
                    // 'resume' => $employee['resume'] ?? null,
                ];
            }

            return response()->json($transformedEmployees, 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Could not fetch employees: ' . $e->getMessage()], 400);
        }
    }

    /**
     * Get a specific employee's profile (accessible by employers only).
     */
    public function getEmployeeProfile(Request $request, string $employeeId)
    {
        try {
            // Verify the user and retrieve their UID
            $uid = $this->getAuthenticatedUserUid($request);

            // Ensure the user is an employer
            $employerData = $this->database->getReference("/users/employers/{$uid}")->getValue();
            if (!$employerData) {
                return response()->json(['error' => 'Only employers can view employee profiles'], 403);
            }

            // Get the specific employee's data
            $employee = $this->database->getReference("/users/employees/{$employeeId}")->getValue();

            if (!$employee) {
                return response()->json(['error' => 'Employee not found']);
            }

            // Transform the data to remove sensitive information
            $transformedEmployee = [
                'employee_uid' => $employeeId,
                'name' => $employee['name'] ?? null,
                'email' => $employee['email'] ?? null,
                'location' => $employee['location'] ?? null,
                'birthday' => $employee['birthday'] ?? null,
                'phone_number' => $employee['phone_number'] ?? null,
                'skills' => $employee['skills'] ?? null,
                'resume' => $employee['resume'] ?? null,
                'profile_picture' => $employee['profile_picture'] ?? null,
            ];

            return response()->json(['data' => $transformedEmployee], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Could not fetch employee profile: ' . $e->getMessage()], 400);
        }
    }

    /**
     * Get the authenticated employee's details.
     */
    public function show(Request $request)
    {
        try {

            $uid = $this->getAuthenticatedUserUid($request); // Verify the user and retrieve their UID
            $this->ensureEmployee($uid); // Ensure the UID belongs to an employee

            $employee = $this->database->getReference("/users/employees/{$uid}")->getValue(); // Retrieve the employee's profile from realtime database

            if (!$employee) {
                return response()->json(['error' => 'Employee not found'], 404);
            }

            return response()->json($employee, 200);
        } catch (\Kreait\Firebase\Exception\Auth\FailedToVerifyToken $e) {
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
            $employeeData = $this->database->getReference("/users/employees/{$uid}")->getValue();

            if (!$employeeData) {
                return response()->json(['error' => 'Employee not found'], 404);
            }

            // Validate input data
            $validatedData = $request->validate([
                'name' => 'sometimes|string|max:255',
                'email' => 'sometimes|email',
                'birthday' => 'sometimes|date',
                'phone_number' => 'sometimes|string|max:15',
                'location' => 'sometimes|string|max:255',
                'skills' => 'sometimes|string',
                'resume' => 'sometimes|file|mimes:png,jpeg,jpg,pdf|max:10240',
                'profile_picture' => 'sometimes|file|mimes:png,jpeg,jpg,pdf|max:10240',
            ]);

            // Update email in Firebase Authentication if it has changed
            if (isset($validatedData['email']) && $validatedData['email'] !== $employeeData['email']) {
                try {
                    $this->auth->updateUser($uid, ['email' => $validatedData['email']]);
                } catch (\Kreait\Firebase\Exception\Auth\AuthError $e) {
                    return response()->json(['error' => 'Could not update email in Firebase Auth: ' . $e->getMessage()], 400);
                }
            }

            // Handle the resume file upload and old resume deletion
            $resumeUrl = $employeeData['resume'] ?? null; // Use existing resume URL or default to null

            if ($request->hasFile('resume')) {
                // Delete the old resume if it exists
                if (!empty($resumeUrl)) {
                    $path = parse_url($resumeUrl, PHP_URL_PATH);
                    $decodedPath = urldecode($path); // Decode the URL-encoded path
                  
                    $fileName = basename($decodedPath);

                    $storageObject = $this->storage->getBucket()->object("resumes/$uid/$fileName");
                    if ($storageObject->exists()) {
                        $storageObject->delete();
                    }
                }

                // Process the new resume file
                $file = $request->file('resume');
                $filePath = $file->getPathname();
                $fileName = 'resumes/' . $uid . '/' . time() . '_' . $file->getClientOriginalName();

                try {
                    $bucket = $this->storage->getBucket();
                    $object = $bucket->upload(
                        fopen($filePath, 'r'),
                        ['name' => $fileName]
                    );

                    // Generate a long-lived signed URL for the new file
                    $resumeUrl = $object->signedUrl(new \DateTime('+10 years'));
                } catch (\Exception $e) {
                    return response()->json(['error' => 'Resume upload failed: ' . $e->getMessage()], 500);
                }
            }

            // Handle the resume file upload and old profile picture deletion
            $profilePictureUrl = $employeeData['profile_picture'] ?? null; // Use existing profile picture URL or default to null

            if ($request->hasFile('profile_picture')) {
                // Delete the old profile picture if it exists
                if (!empty($profilePictureUrl)) {
                    $path = parse_url($profilePictureUrl, PHP_URL_PATH);
                    $decodedPath = urldecode($path); // Decode the URL-encoded path
                  
                    $fileName = basename($decodedPath);

                    $storageObject = $this->storage->getBucket()->object('profile_pictures/' . $uid . '/' . $fileName);
                    if ($storageObject->exists()) {
                        $storageObject->delete();
                    }
                }

                // Process the new profile picture file
                $file = $request->file('profile_picture');
                $filePath = $file->getPathname();
                $fileName = 'profile_pictures/' . $uid . '/' . time() . '_' . $file->getClientOriginalName();

                try {
                    $bucket = $this->storage->getBucket();
                    $object = $bucket->upload(
                        fopen($filePath, 'r'),
                        ['name' => $fileName]
                    );

                    // Generate a long-lived signed URL for the new file
                    $profilePictureUrl = $object->signedUrl(new \DateTime('+10 years'));
                } catch (\Exception $e) {
                    return response()->json(['error' => 'Profile picture upload failed: ' . $e->getMessage()], 500);
                }
            }

            // Prepare the data to be updated
            $updatedData = [
                'user_type' => 'employee',
                'name' => $request->input('name', $employeeData['name']),
                'email' => $request->input('email', $employeeData['email']),
                'birthday' => $request->input('birthday', $employeeData['birthday']),
                'phone_number' => $request->input('phone_number', $employeeData['phone_number']),
                'location' => $request->input('location', $employeeData['location']),
                'skills' => $request->input('skills', $employeeData['skills']),
                'resume' => $request->hasFile('resume') ? $resumeUrl : $employeeData['resume'], // Always use updated or existing resume URL
                'profile_picture' => $request->hasFile('profile_picture') ? $profilePictureUrl : $employeeData['profile_picture'], // Always use updated or existing profile picture URL
            ];

            // Update the employee's profile in Firebase
            $this->database->getReference("/users/employees/{$uid}")->update($updatedData);

            return response()->json($updatedData, 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Could not update employee: ' . $e->getMessage()], 400);
        }
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

            // Check if a resume file exists for the employee
            if (isset($employeeData['resume'])) {
                $resumeUrl = $employeeData['resume'];

                // Extract the path from the URL
                $path = parse_url($resumeUrl, PHP_URL_PATH);
                $decodedPath = urldecode($path); // Decode the URL-encoded path


                // Extract the filename from the path
                $fileName = basename($decodedPath);

                // Delete the resume file from Firebase Storage
                $storageObject = $this->storage->getBucket()->object('resumes/' . $uid . '/' . $fileName);
                if($storageObject->exists()) 
                {
                    $storageObject->delete();
                }
            }

            // Check if a profile picture file exists for the employee
            if (isset($employeeData['profile_picture'])) {
                $profilePictureUrl = $employeeData['profile_picture'];
            
                // Extract and decode the path from the URL
                $path = parse_url($profilePictureUrl, PHP_URL_PATH);
                $decodedPath = urldecode($path); // Decode the URL-encoded path
            
                // Extract the filename from the decoded path
                $fileName = basename($decodedPath);
            
                // Delete the profile picture file from Firebase Storage
                $storageObject = $this->storage->getBucket()->object("profile_pictures/$uid/$fileName");
                if ($storageObject->exists()) {
                    $storageObject->delete();
                } 
            }

            // Delete the employee's data from the database
            $this->database->getReference('/users/employees/' . $uid)->remove();

            // Delete the employee's account from Firebase Authentication
            $this->auth->deleteUser($uid);

            return response()->json(['message' => 'Employee profile, resume, and account deleted successfully'], 200);
        } catch (\Kreait\Firebase\Exception\Auth\FailedToVerifyToken $e) {
            return response()->json(['error' => 'Invalid authentication token'], 401);
        } catch (\Kreait\Firebase\Exception\Auth\UserNotFound $e) {
            return response()->json(['error' => 'Authentication account not found'], 404);
        } catch (\Google\Cloud\Core\Exception\NotFoundException $e) {
            // Handle case where the file does not exist in Firebase Storage
            return response()->json(['error' => 'Resume file not found: ' . $e->getMessage()], 404);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Could not delete employee profile: ' . $e->getMessage()], 400);
        }
    }

    /**
     * Update the authenticated employee's password.
     */
    public function updatePassword(Request $request)
    {
        try {
            // Verify the user and retrieve their UID
            $uid = $this->getAuthenticatedUserUid($request);

            // Ensure the UID belongs to an employee
            $this->ensureEmployee($uid);

            // Validate the input
            $validatedData = $request->validate([
                'current_password' => 'required|string',
                'new_password' => 'required|string|min:8|different:current_password',
                'confirm_password' => 'required|string|min:8|same:new_password',
            ]);

            // Retrieve the user's email from Firebase Authentication
            $user = $this->auth->getUser($uid);
            $email = $user->email;

            // Reauthenticate the user with the current password
            try {
                $this->auth->signInWithEmailAndPassword($email, $validatedData['current_password']);
            } catch (\Kreait\Firebase\Exception\Auth\AuthError $e) {
                return response()->json(['error' => 'Current password is incorrect'], 401);
            }

            // Update the password in Firebase Authentication
            $this->auth->changeUserPassword($uid, $validatedData['new_password']);

            return response()->json(['message' => 'Password updated successfully'], 200);
        } catch (\Kreait\Firebase\Exception\Auth\FailedToVerifyToken $e) {
            return response()->json(['error' => 'Invalid authentication token'], 401);
        } catch (\Kreait\Firebase\Exception\Auth\UserNotFound $e) {
            return response()->json(['error' => 'Authentication account not found'], 404);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Could not update password: ' . $e->getMessage()], 400);
        }
    }
}
