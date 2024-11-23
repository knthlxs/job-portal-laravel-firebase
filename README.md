Laravel-Firebase Backend
This project is a Laravel backend fully integrated with Firebase for authentication, real-time database operations, and other Firebase services.

Setup Guide
Follow these steps to set up and run the project locally after cloning it.

Prerequisites
Install PHP and Composer
Ensure PHP (version 8.0 or higher) and Composer are installed on your system.

Download PHP
Download Composer
Install Node.js and npm (optional, for frontend assets, if needed)

Download Node.js

Set Up Firebase 

Create a Firebase project on the Firebase Console.
Enable the following services:
Authentication
Realtime Database.
For detailed instructions of setting up the firebase, follow the guide [here](https://dev.to/aaronreddix/how-to-integrate-firebase-with-laravel-11-496j)

Generate a Service Account Key:
Navigate to Project Settings > Service Accounts.
Click Generate new private key and download the JSON file.

Steps to Set Up the Project
1. Clone the Repository
bash
~ git clone https://github.com/knthlxs/job-portal-laravel-firebase.git
~ cd job-portal-laravel-firebase

2. Install PHP Dependencies
bash
~ composer install

3. Set Up Firebase
* Install the Firebase PHP SDK via Composer:
bash
~ composer require kreait/laravel-firebase

* Publish the Firebase configuration file:
bash
~ php artisan vendor:publish --provider="Kreait\Laravel\Firebase\ServiceProvider" --tag=config

* Place the Firebase service account key JSON file in a secure 
location (e.g., storage/app/firebase/).

4. Set Up Environment Variables
* Copy the .env.example file to .env:
bash
~ cp .env.example .env
Update the .env file with your app details and Firebase configurations:

env
# Firebase Config
FIREBASE_CREDENTIALS=/storage/app/firebase/<filename>.json
FIREBASE_DATABASE_URL=https://your-database-name.firebaseio.com/
FIREBASE_STORAGE_BUCKET=<project-id>.firebasestorage.app

5. Generate the Application Key
bash
~ php artisan key:generate

6. Run the Project
bash
~ php artisan serve

The backend will be accessible at http://127.0.0.1:8000.