<?php

namespace App\Services;

use Kreait\Firebase\Factory;

class FirebaseStorageService
{
    public static function connect()
    {
        $firebase = (new Factory)
            ->withServiceAccount(base_path(env('FIREBASE_CREDENTIALS')))
            ->withDatabaseUri(env('FIREBASE_DATABASE_URL'))
            ->withDefaultStorageBucket(env('FIREBASE_STORAGE_BUCKET'));
            
        $storage = $firebase->createStorage();
        return $storage;
    }
}
