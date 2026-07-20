<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Firebase Admin (server-side)
    |--------------------------------------------------------------------------
    | Used to verify Google/Firebase ID tokens sent from the frontend
    | (document/phase/12 §Firebase Login Flow). The service account JSON is
    | never committed — place it at storage/app/firebase/service-account.json
    | (path relative to storage/app). Auth stays disabled until configured.
    */

    'project_id' => env('FIREBASE_PROJECT_ID'),

    'credentials' => env('FIREBASE_CREDENTIALS', 'firebase/service-account.json'),
];
