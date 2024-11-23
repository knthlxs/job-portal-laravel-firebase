# Laravel-Firebase Backend

This project is a **Laravel backend** fully integrated with **Firebase** for authentication, real-time database operations, and other Firebase services.

---

## Setup Guide

Follow these steps to set up and run the project locally after cloning it.

---

### Prerequisites

Before setting up the project, ensure you have the following installed:

- **PHP (version 8.0 or higher)**  
  Install PHP: [Download PHP](https://www.php.net/downloads.php)
  
- **Composer**  
  Install Composer: [Download Composer](https://getcomposer.org/download/)
  
- **Node.js and npm (optional)**  
  Install Node.js for frontend assets (if required): [Download Node.js](https://nodejs.org/)

---

### Set Up Firebase

Follow these steps to set up Firebase for use with your Laravel project:

1. **Create a Firebase Project**  
   Go to the [Firebase Console](https://console.firebase.google.com/) and create a new project.

2. **Enable Firebase Services**  
   Enable the following Firebase services for the project:
   - Authentication
   - Realtime Database

   For detailed instructions on integrating Firebase with Laravel, refer to this guide:  
   [How to integrate Firebase with Laravel](https://dev.to/aaronreddix/how-to-integrate-firebase-with-laravel-11-496j)

3. **Generate a Service Account Key**  
   - Navigate to **Project Settings > Service Accounts**.
   - Click **Generate new private key** and download the JSON file. You’ll use this key for Firebase authentication.

---

## Steps to Set Up the Project

### 1. Clone the Repository

Clone the project repository to your local machine:
```bash
 git clone https://github.com/knthlxs/job-portal-laravel-firebase.git
cd job-portal-laravel-firebase 
```

### 2. Install PHP Dependencies

```bash
 composer install
```

### 3. Set Up Firebase

* Install the Firebase PHP SDK via Composer:
```bash
 composer require kreait/laravel-firebase
```

* Publish the Firebase configuration file:
bash
~ php artisan vendor:publish --provider="Kreait\Laravel\Firebase\ServiceProvider" --tag=config

* Place the Firebase service account key JSON file in a secure 
location (e.g., storage/app/firebase/).

### 4. Set Up Environment Variables

* Copy the .env.example file to .env:
```bash
 cp .env.example .env
```

* Update the .env file with your app details and Firebase configurations:
env
## Firebase Config
FIREBASE_CREDENTIALS=/storage/app/firebase/<filename>.json
FIREBASE_DATABASE_URL=https://your-database-name.firebaseio.com/
FIREBASE_STORAGE_BUCKET=<project-id>.firebasestorage.app

### 5. Generate the Application Key

```bash
 php artisan key:generate
```

### 6. Run the Project

```bash
 php artisan serve
```
The backend will be accessible at http://127.0.0.1:8000.