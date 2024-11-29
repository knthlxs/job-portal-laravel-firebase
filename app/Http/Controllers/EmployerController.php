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
     * Get a list of all employers.
     */
    public function listEmployers(Request $request)
    {
        try {
            // Verify the user and retrieve their UID
            // $uid = $this->getAuthenticatedUserUid($request);

            // Ensure the user is an employee
            // $employeeData = $this->database->getReference("/users/employees/{$uid}")->getValue();
            // if (!$employeeData) {
            //     return response()->json(['error' => 'Only employees can view employer listings'], 403);
            // }

            // Get all employers from the database
            $employers = $this->database->getReference('/users/employers')->getValue();

            // If no employers found, return empty array
            if (!$employers) {
                return response()->json(['data' => []], 200);
            }

            // Transform the data to remove sensitive information
            $transformedEmployers = [];
            foreach ($employers as $employerId => $employer) {
                $transformedEmployers[] = [
                    'employer_uid' => $employerId,
                    'name' => $employer['name'] ?? null,
                    'industry' => $employer['industry'] ?? null,
                    'location' => $employer['location'] ?? null,
                    'company_logo' => $employer['company_logo'] ?? null,
                    // Add any other fields that should be visible in the listing
                ];
            }

            return response()->json(['employers' => $transformedEmployers], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Could not fetch employers: ' . $e->getMessage()], 400);
        }
    }

    /**
     * View a specific employer's public profile by uid.
     */
    public function showOwnedJobPosts(Request $request)
    {
        try {
            // Verify the user and retrieve their UID
            $uid = $this->getAuthenticatedUserUid($request);

            // Ensure the user is an employer
            $this->ensureEmployer($uid);

            // Get all jobs owned by the employer who logged in
            $employer = $this->database->getReference("/users/employers/{$uid}/jobs")->getValue();
            if (!$employer) {
                return response()->json(['error' => 'You do not have any job posting yet.'], 400);
            }
            return response()->json($employer, 200);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 401);
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
                'name' => 'sometimes|string|max:255',
                'email' => 'sometimes|email',
                'phone_number' => 'sometimes|string|max:15',
                'location' => 'sometimes|string|max:255',
                'industry' => 'sometimes|string|max:255',
                'contact_person_name' => 'sometimes|string|max:255',
                'company_logo' => 'sometimes|file|mimes:png,jpeg,jpg|max:10240',
            ]);

            // Update email in Firebase Authentication if it has changed
            if (isset($validatedData['email']) && $validatedData['email'] !== $employerData['email']) {
                try {
                    $this->auth->updateUser($uid, ['email' => $validatedData['email']]);
                } catch (\Kreait\Firebase\Exception\Auth\AuthError $e) {
                    return response()->json(['error' => 'Could not update email in Firebase Auth: ' . $e->getMessage()], 400);
                }
            }

            // Get the existing company logo URL if it exists
            $companyLogoUrl = $employerData['company_logo'] ?? null;

            // Handle the company logo upload and deletion of old logo
            if ($request->hasFile('company_logo')) {
                // Delete the old logo if it exists
                if ($companyLogoUrl) {
                    $path = parse_url($companyLogoUrl, PHP_URL_PATH);
                    $decodedPath = urldecode($path); // Decode the URL-encoded path

                    $fileName = basename($decodedPath);

                    $storageObject = $this->storage->getBucket()->object('company_logos/' . $uid . '/' . $fileName);
                    if ($storageObject->exists()) {
                        $storageObject->delete();
                    }
                }

                // Process the new company logo
                $file = $request->file('company_logo');
                $filePath = $file->getPathname();
                $fileName = 'company_logos/' . $uid . '/' . time() . '_' . $file->getClientOriginalName();

                try {
                    $bucket = $this->storage->getBucket();
                    $object = $bucket->upload(
                        fopen($filePath, 'r'),
                        ['name' => $fileName]
                    );

                    // Generate a long-lived signed URL for the new logo
                    $companyLogoUrl = $object->signedUrl(new \DateTime('+10 years'));
                } catch (\Exception $e) {
                    return response()->json(['error' => 'Company logo upload failed: ' . $e->getMessage()], 500);
                }
            }

            // Prepare the data to be updated
            $updatedData = [
                'user_type' => 'employer',
                'name' => $request->input('name', $employerData['name']),
                'email' => $request->input('email', $employerData['email']),
                'phone_number' => $request->input('phone_number', $employerData['phone_number']),
                'location' => $request->input('location', $employerData['location']),
                'industry' => $request->input('industry', $employerData['industry']),
                'contact_person_name' => $request->input('contact_person_name', $employerData['contact_person_name']),
                'company_logo' => $companyLogoUrl,  // Ensure the logo URL is updated if a new one is uploaded
            ];

            // Update the employer's profile in Firebase
            $this->database->getReference("/users/employers/{$uid}")->update($updatedData);

            return response()->json(['message' => 'Employer updated successfully', 'employer' => $updatedData, 'company_logo' => $companyLogoUrl], 200);
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

            // Check if a companyLogo file exists for the employee
            if (isset($employerData['company_logo'])) {
                $companyLogoUrl = $employerData['company_logo'];

                // Extract the path from the URL
                $path = parse_url($companyLogoUrl, PHP_URL_PATH);
                $decodedPath = urldecode($path); // Decode the URL-encoded path

                // Extract the filename from the path
                $fileName = basename($decodedPath);

                // Delete the companyLogo file from Firebase Storage
                $storageObject = $this->storage->getBucket()->object('company_logos/' . $uid . '/' . $fileName);
                if ($storageObject->exists()) {
                    $storageObject->delete();
                }
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

    /**
     * Update the authenticated employer's password.
     */
    public function updatePassword(Request $request)
    {
        try {
            // Verify the user and retrieve their UID
            $uid = $this->getAuthenticatedUserUid($request);

            // Ensure the UID belongs to an employer
            $this->ensureEmployer($uid);

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
