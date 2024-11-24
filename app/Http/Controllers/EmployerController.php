<?php

namespace App\Http\Controllers;

use App\Services\FirebaseRealtimeDatabaseService;
use App\Services\FirebaseAuthService;
use App\Services\FirebaseStorageService;
use Illuminate\Http\Request;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Auth;

class EmployerController extends Controller
{
    protected $auth;
    private $database;
    private $storage;

    public function __construct(FirebaseAuthService $firebaseAuthService)
    {
        $this->auth = $firebaseAuthService->connect(); // Get the Firebase authentication service
        $this->database = FirebaseRealtimeDatabaseService::connect(); // Get the Firebase database service
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
     * Get the authenticated employer's details.
     */
    public function show(Request $request)
    {
        try {
            // Verify the user and retrieve their UID
            $uid = $this->getAuthenticatedUserUid($request);

            // Ensure the UID belongs to an employer
            $this->ensureEmployer($uid);

            // Retrieve the employer's profile
            $employer = $this->database->getReference("/users/employers/{$uid}")->getValue();

            if (!$employer) {
                return response()->json(['error' => 'Employer not found'], 404);
            }

            return response()->json($employer, 200);
        } catch (\Kreait\Firebase\Exception\Auth\FailedToVerifyToken $e) {
            return response()->json(['error' => 'Invalid authentication token'], 401);
        } catch (\Kreait\Firebase\Exception\Auth\AuthError $e) {
            return response()->json(['error' => 'Authentication error'], 401);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Could not fetch employer: ' . $e->getMessage()], 400);
        }
    }

    /**
     * Update the authenticated employer's details.
     */
    public function update(Request $request)
    {
        try {
            // Verify the user and retrieve their UID
            $uid = $this->getAuthenticatedUserUid($request);

            // Ensure the UID belongs to an employer
            $this->ensureEmployer($uid);

            // Fetch the current data of the employer from Firebase
            $employerData = $this->database->getReference("/users/employers/{$uid}")->getValue();

            if (!$employerData) {
                return response()->json(['error' => 'Employer not found'], 404);
            }

            // Validate input data
            $validatedData = $request->validate([
                'company_name' => 'sometimes|string|max:255',
                'company_email_address' => 'sometimes|email',
                'company_phone_number' => 'sometimes|string|max:15',
                'company_location' => 'sometimes|string|max:255',
                'company_industry' => 'sometimes|string|max:255',
                'contact_person_name' => 'sometimes|string|max:255',
                'company_logo' => 'sometimes|file|mimes:png,jpeg,jpg|max:10240',
            ]);

            // Handle the new resume upload and deletion of old resume
            if ($request->company_logo) {
                // Delete the old resume if it exists
                if (!empty($employerData['company_logo_url'])) {
                    $company_logo_url = $employerData['company_logo_url'];

                    // Extract the path and delete the file only if the path exists
                    $path = parse_url($company_logo_url, PHP_URL_PATH);
                    $fileName = basename($path);

                    // Check if file exists in Firebase Storage
                    $storageObject = $this->storage->getBucket()->object('company_logos/' . $uid . '/' . $fileName);
                    if ($storageObject->exists()) {
                        $storageObject->delete();
                    }
                }

                // Process the new resume file
                $file = $request->file('company_logo');
                $filePath = $file->getPathname();
                $fileName = 'company_logos/' . $uid . '/' . time() . '_' . $file->getClientOriginalName();

                try {
                    $bucket = $this->storage->getBucket();
                    $object = $bucket->upload(
                        fopen($filePath, 'r'),
                        ['name' => $fileName]
                    );

                    // Generate a long-lived signed URL for the new file
                    $company_logo_url = $object->signedUrl(new \DateTime('+ 10 years'));
                } catch (\Exception $e) {
                    return response()->json(['error' => 'Company logo upload failed: ' . $e->getMessage()], 500);
                }
            }

            // Prepare the data to be updated
            $updatedData = [
                'user_type' => 'employer',
                'company_name' => $request->input('company_name', $employerData['company_name']),
                'company_email_address' => $request->input('company_email_address', $employerData['company_email_address']),
                'company_phone_number' => $request->input('company_phone_number', $employerData['company_phone_number']),
                'company_location' => $request->input('company_location', $employerData['company_location']),
                'company_industry' => $request->input('company_industry', $employerData['company_industry']),
                'contact_person_name' => $request->input('contact_person_name', $employerData['contact_person_name']),
                'company_logo_url' => $company_logo_url ?? $employerData['company_logo_url'],
            ];

            // // If no valid data to update, return an error
            // if (empty($updatedData)) {
            //     return response()->json(['error' => 'No valid data to update'], 400);
            // }

            // Update the employer's profile in Firebase
            $this->database->getReference("/users/employers/{$uid}")->update($updatedData);

            return response()->json(['message' => 'Employer updated successfully'], 200);
        } catch (\Kreait\Firebase\Exception\Auth\FailedToVerifyToken $e) {
            return response()->json(['error' => 'Invalid authentication token'], 401);
        } catch (\Kreait\Firebase\Exception\Auth\AuthError $e) {
            return response()->json(['error' => 'Authentication error'], 401);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Could not update employer: ' . $e->getMessage()], 400);
        }
    }

    /**
     * Delete the authenticated employer's account.
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

            // Check if the employer exists
            $employerData = $this->database->getReference('/users/employers/' . $uid)->getValue();
            if (!$employerData) {
                return response()->json(['error' => 'Employer profile not found'], 404);
            }

             // Check if a resume file exists for the employee
             if (isset($employeeData['company_logo'])) {
                $resumeUrl = $employerData['company_logo'];

                // Extract the path from the URL
                $path = parse_url($resumeUrl, PHP_URL_PATH);

                // Extract the filename from the path
                $fileName = basename($path);

                // Delete the resume file from Firebase Storage
                $this->storage->getBucket()->object('company_logos/' . $uid . '/' . $fileName)->delete();
            }

            // Delete the employer's data from the database
            $this->database->getReference('/users/employers/' . $uid)->remove();

            // Delete the employer's account from Firebase Authentication
            $this->auth->deleteUser($uid);

            return response()->json(['message' => 'Employer profile and account deleted successfully'], 200);
        } catch (\Kreait\Firebase\Exception\Auth\FailedToVerifyToken $e) {
            return response()->json(['error' => 'Invalid authentication token'], 401);
        } catch (\Kreait\Firebase\Exception\Auth\UserNotFound $e) {
            return response()->json(['error' => 'Authentication account not found'], 404);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Could not delete employer profile: ' . $e->getMessage()], 400);
        }
    }
}
