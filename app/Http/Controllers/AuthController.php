<?php

namespace App\Http\Controllers;

use App\Services\FirebaseRealtimeDatabaseService;
use App\Services\FirebaseAuthService;
use App\Services\FirebaseStorageService;
use Illuminate\Http\Request;

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
    // Conditionally validate fields based on the user type
    $validatedData = $request->validate([
        'email' => 'required|email',
        'password' => 'required|min:6',
        'user_type' => 'required|in:employee,employer', // Ensure user_type is either 'employee' or 'employer'
        'resume' => $request->input('user_type') == 'employee' ? 'required|file|mimes:png,jpeg,jpg|max:10240' : 'nullable', // Resume is required only for employees
        'company_logo' => $request->input('user_type') == 'employer' ? 'required|file|mimes:png,jpeg,jpg|max:10240' : 'nullable', // Company logo is required only for employers
    ]);

    try {
        // Create the new user
        $user = $this->auth->createUserWithEmailAndPassword(
            $validatedData['email'],
            $validatedData['password']
        );

        $resumeUrl = null;
        $companyLogoUrl = null;

        // Handle file upload to Firebase Storage based on user type
        if ($validatedData['user_type'] == 'employee') {
            // Upload employee resume
            $file = $request->file('resume');
            $filePath = $file->getPathname();
            $fileName = 'resumes/' . $user->uid . '/' . time() . '_' . $file->getClientOriginalName();

            $bucket = $this->storage->getBucket();
            $bucket->upload(
                fopen($filePath, 'r'),
                ['name' => $fileName]
            );

            $object = $bucket->object($fileName);
            $resumeUrl = $object->signedUrl(new \DateTime('+10 years')); // Long-lived URL
        } elseif ($validatedData['user_type'] == 'employer') {
            // Upload employer company logo
            $file = $request->file('company_logo');
            $filePath = $file->getPathname();
            $fileName = 'company_logos/' . $user->uid . '/' . time() . '_' . $file->getClientOriginalName();

            $bucket = $this->storage->getBucket();
            $bucket->upload(
                fopen($filePath, 'r'),
                ['name' => $fileName]
            );

            $object = $bucket->object($fileName);
            $companyLogoUrl = $object->signedUrl(new \DateTime('+10 years')); // Long-lived URL
        }

        // Determine the correct path based on user type
        $userDataPath = '/users/' . $validatedData['user_type'] . 's'; // '/users/employees' or '/users/employers'

        // Use the Firebase UID as the unique key for storing user data
        $userData = $this->database->getReference($userDataPath)->getChild($user->uid);

        // Set the user data based on user type
        if ($validatedData['user_type'] == 'employee') {
            $userData->set([
                'user_type' => 'employee',
                'full_name' => $request['full_name'],
                'email_address' => $request['email'],
                'birthday' => $request['birthday'],
                'phone_number' => $request['phone_number'],
                'location' => $request['location'],
                'skills' => $request['skills'],
                'resume_url' => $resumeUrl, // Store the resume URL
            ]);
        } else {
            $userData->set([
                'user_type' => 'employer',
                'company_name' => $request['company_name'],
                'company_email_address' => $request['company_email_address'],
                'company_phone_number' => $request['company_phone_number'],
                'company_location' => $request['company_location'],
                'company_industry' => $request['company_industry'],
                'contact_person_name' => $request['contact_person_name'],
                'company_logo_url' => $companyLogoUrl, // Store the company logo URL
            ]);
        }

        // Return success response with user info
        return response()->json([
            'message' => 'User created successfully',
            'user' => $user
        ], 201);
    } catch (\Kreait\Firebase\Exception\AuthException $e) {
        return response()->json(['error' => $e->getMessage()], 400);
    }
}



    // Log in a user with email and password
    public function signIn(Request $request)
    {
        // Validate the request input
        $validatedData = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        try {
            // Sign in the user with email and password using Firebase Authentication
            $signInResult = $this->auth->signInWithEmailAndPassword(
                $validatedData['email'],
                $validatedData['password']
            );

            return response()->json([
                'message' => 'User signed in successfully',
                'uid' =>  $signInResult->firebaseUserId(),
                'id_token' => $signInResult->idToken(),
            ], 200);
        } catch (\Kreait\Firebase\Exception\AuthException $e) {
            // Handle Firebase authentication errors
            return response()->json(['error' => 'Authentication failed: ' . $e->getMessage()], 400);
        }
    }




    // Verify ID Token (to validate authentication)
    public function verifyToken(Request $request)
    {
        $idToken = $request->input('id_token');

        try {
            // Verify the Firebase ID token
            $verifiedIdToken = $this->auth->verifyIdToken($idToken);
            return response()->json([
                'message' => 'Token is valid',
                'user_id' => $verifiedIdToken->claims()->get('sub') // The Firebase user ID
            ], 200);
        } catch (\Kreait\Firebase\Exception\AuthException $e) {
            return response()->json(['error' => 'Invalid token: ' . $e->getMessage()], 400);
        }
    }
}
