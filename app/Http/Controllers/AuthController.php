<?php

namespace App\Http\Controllers;

use App\Services\FirebaseService;
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
        $this->database = FirebaseService::connect();
        $this->storage = FirebaseStorageService::connect();
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

    // Register a new user with email and password
    public function signUp(Request $request)
    {
        // Conditionally validate the resume field based on the user type
        $validatedData = $request->validate([
            'email' => 'required|email',
            'password' => 'required|min:6',
            'user_type' => 'required|in:employee,employer', // Ensure user_type is either 'employee' or 'employer'
            'resume' => $request->input('user_type') == 'employee' ? 'required|file|mimes:pdf,docx|max:10240' : 'nullable', // Resume is required only for employees
        ]);

        try {
            // Create the new user
            $user = $this->auth->createUserWithEmailAndPassword(
                $validatedData['email'],
                $validatedData['password']
            );

            // Handle resume file upload to Firebase Storage if the user is an employee
            if ($validatedData['user_type'] == 'employee') {
                $file = $request->file('resume');
                $filePath = $file->getPathname();
                $fileName = 'resumes/' . time() . '_' . $file->getClientOriginalName(); // Generate a unique name for the file

                // Upload the resume to Firebase Storage
                $this->uploadFile($filePath, $fileName);

                // Get the download URL of the uploaded resume
                $resumeUrl = $this->getDownloadUrl($fileName);
            } else {
                $resumeUrl = null; // Employers don't have resumes
            }

            // Determine the correct path based on user type
            $userDataPath = '/users/' . $validatedData['user_type'] . 's';  // For example: '/users/employees' or '/users/employers'

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
                    'resume_url' => $resumeUrl,  // Store the resume URL in the database
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
    // Log in a user with email and password
    // Example of using the Firebase UID to fetch user data
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
                'user_id' => $verifiedIdToken->getClaim('sub') // The Firebase user ID
            ], 200);
        } catch (\Kreait\Firebase\Exception\AuthException $e) {
            return response()->json(['error' => 'Invalid token: ' . $e->getMessage()], 400);
        }
    }
}
