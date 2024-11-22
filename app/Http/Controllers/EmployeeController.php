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
            'full_name' => 'sometimes|string|max:255',
            'email_address' => 'sometimes|email',
            'birthday' => 'sometimes|date',
            'phone_number' => 'sometimes|string|max:15',
            'location' => 'sometimes|string|max:255',
            'skills' => 'sometimes|string',
            'resume' => 'sometimes|file|mimes:png,jpeg,jpg,pdf|max:10240',
        ]);

        // Update email in Firebase Authentication if it has changed
        if (isset($validatedData['email_address']) && $validatedData['email_address'] !== $employeeData['email_address']) {
            try {
                $this->auth->updateUser($uid, ['email' => $validatedData['email_address']]);
            } catch (\Kreait\Firebase\Exception\Auth\AuthError $e) {
                return response()->json(['error' => 'Could not update email in Firebase Auth: ' . $e->getMessage()], 400);
            }
        }

        // Handle the resume file upload and old resume deletion
        $resumeUrl = $employeeData['resume_url'] ?? null; // Use existing resume URL or default to null

        if ($request->hasFile('resume')) {
            // Delete the old resume if it exists
            if (!empty($resumeUrl)) {
                $path = parse_url($resumeUrl, PHP_URL_PATH);
                $fileName = basename($path);

                $storageObject = $this->storage->getBucket()->object('resumes/' . $uid . '/' . $fileName);
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

        // Prepare the data to be updated
        $updatedData = [
            'user_type' => 'employee',
            'full_name' => $request->input('full_name', $employeeData['full_name']),
            'email_address' => $request->input('email_address', $employeeData['email_address']),
            'birthday' => $request->input('birthday', $employeeData['birthday']),
            'phone_number' => $request->input('phone_number', $employeeData['phone_number']),
            'location' => $request->input('location', $employeeData['location']),
            'skills' => $request->input('skills', $employeeData['skills']),
            'resume_url' => $resumeUrl, // Always use updated or existing resume URL
        ];

        // Update the employee's profile in Firebase
        $this->database->getReference("/users/employees/{$uid}")->update($updatedData);

        return response()->json([
            'message' => 'Employee updated successfully',
            'resume_url' => $updatedData['resume_url'] ?? null,
            'updated_data' => $updatedData
        ], 200);
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
            if (isset($employeeData['resume_url'])) {
                $resumeUrl = $employeeData['resume_url'];

                // Extract the path from the URL
                $path = parse_url($resumeUrl, PHP_URL_PATH);

                // Extract the filename from the path
                $fileName = basename($path);

                // Delete the resume file from Firebase Storage
                $this->storage->getBucket()->object('resumes/' . $uid . '/' . $fileName)->delete();
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
                'new_password' => 'required|string|min:6|different:current_password',
                'confirm_password' => 'required|string|min:6|same:new_password',
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
