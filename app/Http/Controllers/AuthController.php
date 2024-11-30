<?php

namespace App\Http\Controllers;

use App\Services\FirebaseRealtimeDatabaseService;
use App\Services\FirebaseAuthService;
use App\Services\FirebaseStorageService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    protected $auth;
    private $database;
    private $storage;

    public function __construct()
    {
        $this->auth = FirebaseAuthService::connect();
        $this->database = FirebaseRealtimeDatabaseService::connect();
        $this->storage = FirebaseStorageService::connect();
    }

    public function signUp(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'email' => 'required|email',
                'password' => 'required|min:8',
                'confirm_password' => 'required|same:password',
                'user_type' => 'required|in:employee,employer',
                'resume' => 'nullable|file|mimes:png,jpeg,jpg,pdf|max:10240',
                'company_logo' => 'nullable|file|mimes:png,jpeg,jpg,pdf|max:10240',
                'profile_picture' => 'nullable|file|mimes:png,jpeg,jpg,pdf|max:10240',
                'name' => 'required|string',
                'phone_number' => 'required|string',
                'location' => 'required|string',
            ]);

            $user = $this->auth->createUserWithEmailAndPassword(
                $validatedData['email'],
                $validatedData['password']
            );

            $resumeUrl = null;
            $profilePictureUrl = null;
            $companyLogoUrl = null;

            // Handle profile picture upload for employees
            if ($validatedData['user_type'] == 'employee' && $request->hasFile('profile_picture')) {
                $file = $request->file('profile_picture');
                $filePath = $file->getPathname();
                $fileName = 'profile_pictures/' . $user->uid . '/' . time() . '_' . $file->getClientOriginalName();

                $bucket = $this->storage->getBucket();
                $bucket->upload(
                    fopen($filePath, 'r'),
                    ['name' => $fileName]
                );

                $object = $bucket->object($fileName);
                $profilePictureUrl = $object->signedUrl(new \DateTime('+10 years'));
            }

            // Handle resume upload for employees
            if ($validatedData['user_type'] == 'employee' && $request->hasFile('resume')) {
                $file = $request->file('resume');
                $filePath = $file->getPathname();
                $fileName = 'resumes/' . $user->uid . '/' . time() . '_' . $file->getClientOriginalName();

                $bucket = $this->storage->getBucket();
                $bucket->upload(
                    fopen($filePath, 'r'),
                    ['name' => $fileName]
                );

                $object = $bucket->object($fileName);
                $resumeUrl = $object->signedUrl(new \DateTime('+10 years'));
            }

            // Handle company logo upload for employers
            if ($validatedData['user_type'] == 'employer' && $request->hasFile('company_logo')) {
                $file = $request->file('company_logo');
                $filePath = $file->getPathname();
                $fileName = 'company_logos/' . $user->uid . '/' . time() . '_' . $file->getClientOriginalName();

                $bucket = $this->storage->getBucket();
                $bucket->upload(
                    fopen($filePath, 'r'),
                    ['name' => $fileName]
                );

                $object = $bucket->object($fileName);
                $companyLogoUrl = $object->signedUrl(new \DateTime('+10 years'));
            }

            $userDataPath = '/users/' . $validatedData['user_type'] . 's';
            $userData = $this->database->getReference($userDataPath)->getChild($user->uid);

            switch ($validatedData['user_type']) {
                case 'employee': {
                        $request->validate([
                            'birthday' => 'required|string',
                            'skills' => 'required|string',
                        ]);
                        $userData->set([
                            'employee_uid' => $user->uid,
                            'user_type' => 'employee',
                            'name' => $request['name'],
                            'email' => $request['email'],
                            'birthday' => $request['birthday'],
                            'phone_number' => $request['phone_number'],
                            'location' => $request['location'],
                            'skills' => $request['skills'],
                            'resume' => $resumeUrl,
                            'profile_picture' => $profilePictureUrl
                        ]);
                        break;
                    }
                case 'employer': {
                        $request->validate([
                            'industry' => 'required|string',
                            'contact_person_name' => 'required|string',
                        ]);
                        $userData->set([
                            'employer_uid' => $user->uid,
                            'user_type' => 'employer',
                            'name' => $request['name'],
                            'email' => $request['email'],
                            'phone_number' => $request['phone_number'],
                            'location' => $request['location'],
                            'industry' => $request['industry'],
                            'contact_person_name' => $request['contact_person_name'],
                            'company_logo' => $companyLogoUrl,
                        ]);
                        break;
                    }
            }

            return response()->json([
                'message' => 'User created successfully',
                'user' => $user
            ], 201);
        } catch (\Kreait\Firebase\Exception\AuthException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        } catch (ValidationException $e) {
            return response()->json(['validation error' => $e->errors()], 422);
        } catch (Exception $e) {
            return response()->json([
                'error' => 'An unexpected error occurred',
                'message' => $e->getMessage()
            ], 500);
        }
    }



    // Log in a user with email and password
    public function signIn(Request $request)
    {
        try {
            // Validate the request input
            $validatedData = $request->validate([
                'email' => 'required|email',
                'password' => 'required',
            ]);
            // Sign in the user with email and password using Firebase Authentication
            $signInResult = $this->auth->signInWithEmailAndPassword(
                $validatedData['email'],
                $validatedData['password']
            );
            $uid = $signInResult->firebaseUserId();
            $employee = $this->database->getReference('/users/employees/' . $uid)->getValue();
            // Check if user is employee
            if ($employee) {
                $user_type = "employee";
            } else {
                // Check if user is employer
                $employer = $this->database->getReference('/users/employers/' . $uid)->getValue();
                if ($employer) {
                    $user_type = "employer";
                } else {
                    return response()->json(['error' => 'User not found.'], 400);
                }
            }

            return response()->json([
                'message' => 'User signed in successfully',
                'uid' =>  $signInResult->firebaseUserId(),
                'id_token' => $signInResult->idToken(),
                'user_type' =>  $user_type
            ], 200);
        } catch (\Kreait\Firebase\Exception\AuthException $e) {
            // Handle Firebase authentication errors
            return response()->json(['authentication error' => 'Authentication failed: ' . $e->getMessage()], 400);
        } catch (ValidationException $e) {
            return response()->json(['validation error' => $e->errors()], 422);
        } catch (Exception $e) {
            return response()->json([
                'error' => 'An unexpected error occurred',
                'message' => $e->getMessage()
            ], 500);
        }
    }




    // Verify ID Token (to validate authentication)
    public function verifyToken(Request $request)
    {

        try {
            $idToken = $request->input('id_token');

            // Verify the Firebase ID token
            $verifiedIdToken = $this->auth->verifyIdToken($idToken);
            return response()->json([
                'message' => 'Token is valid',
                'user_id' => $verifiedIdToken->claims()->get('sub') // The Firebase user ID
            ], 200);
        } catch (\Kreait\Firebase\Exception\AuthException $e) {
            return response()->json(['error' => 'Invalid token: ' . $e->getMessage()], 400);
        } catch (ValidationException $e) {
            return response()->json(['validation error' => $e->errors()], 422);
        } catch (Exception $e) {
            return response()->json([
                'error' => 'An unexpected error occurred',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Extract the UID of the authenticated user from the request.
     */
    private function getAuthenticatedUserUid(Request $request)
    {
        try {
            $authHeader = $request->header('Authorization');
            if (!$authHeader) {
                throw new Exception('Authorization token missing');
            }
            $idToken = str_replace('Bearer ', '', $authHeader);
            $verifiedIdToken = $this->auth->verifyIdToken($idToken); // Verify ID Token (to validate authentication)
            return $verifiedIdToken->claims()->get('sub');
        } catch (ValidationException $e) {
            return response()->json(['validation error' => $e->errors()], 422);
        }
    }

    public function logout(Request $request)
    {
        try {
            $uid = $this->getAuthenticatedUserUid($request); // Verify the user and retrieve their UID

            // Revoke refresh tokens for the user
            $this->auth->revokeRefreshTokens($uid);

            return response()->json([
                'message' => 'User logged out successfully'
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'error' => 'Logout failed: ' . $e->getMessage()
            ], 400);
        }
    }

    public function forgotPassword(Request $request)
    {
        try {
            $request->validate([
                'email' => ['required', 'email']
            ]);
            $this->auth->sendPasswordResetLink($request->email);
            return response()->json([
                'message' => 'Password reset link has been sent to your email'
            ], 200);
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
