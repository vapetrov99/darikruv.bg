<?php

/**
 * Firebase Cloud Messaging (FCM) and browser Web Push configuration.
 *
 * End-to-end flow:
 * 1. Client calls GET push_public_config — receives only public keys and Firebase web config.
 * 2. Browser initializes Firebase, registers the service worker, obtains an FCM device token
 *    using the VAPID public key (paired with the private key in Firebase Console).
 * 3. Client POSTs the token to save_push_token (verified donors only).
 * 4. When a blood request is created, NotificationService uses the service account JSON to obtain
 *    a Google OAuth access token and sends messages via FCM HTTP v1.
 *
 * Values come from environment variables so secrets are not committed to the repository.
 */
return [
    /** Public VAPID key for Web Push; used by the browser in messaging.getToken({ vapidKey }). */
    'vapid_public_key' => getenv('FCM_VAPID_PUBLIC_KEY') ?: '',

    /**
     * Full JSON of the Firebase/Google service account (as downloaded from the console).
     * Alternative: FIREBASE_SERVICE_ACCOUNT_JSON_BASE64 when embedding multiline JSON in env is awkward.
     */
    'service_account_json' => getenv('FIREBASE_SERVICE_ACCOUNT_JSON') ?: '',

    /** Same JSON as service_account_json, Base64-encoded on a single line. */
    'service_account_json_base64' => getenv('FIREBASE_SERVICE_ACCOUNT_JSON_BASE64') ?: '',

    /**
     * Public Firebase web app config (Project settings in Firebase Console).
     * Safe to expose to the frontend; not equivalent to the service account private key.
     */
    'firebase' => [
        'api_key' => getenv('FIREBASE_API_KEY') ?: '',
        'auth_domain' => getenv('FIREBASE_AUTH_DOMAIN') ?: '',
        'project_id' => getenv('FIREBASE_PROJECT_ID') ?: '',
        'storage_bucket' => getenv('FIREBASE_STORAGE_BUCKET') ?: '',
        'messaging_sender_id' => getenv('FIREBASE_MESSAGING_SENDER_ID') ?: '',
        'app_id' => getenv('FIREBASE_APP_ID') ?: ''
    ]
];
