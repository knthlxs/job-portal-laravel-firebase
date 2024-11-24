<?php

namespace App\Services;

use Kreait\Firebase\Factory;

class FirebaseRealtimeDatabaseService
{
    // This method connects to Firebase services
    public static function connect()
    {
        // Create a Firebase factory instance with credentials and database URI
        $firebase = (new Factory)
            ->withServiceAccount(base_path(env('FIREBASE_CREDENTIALS')))
            ->withDatabaseUri(env("FIREBASE_DATABASE_URL"));

        return $firebase->createDatabase();
    }

}
