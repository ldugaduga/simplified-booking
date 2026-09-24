<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Admin Account
    |--------------------------------------------------------------------------
    |
    | There is no public sign-up. `php artisan db:seed` creates the single
    | admin account from these values.
    |
    */

    'admin' => [
        'name' => env('ADMIN_NAME', 'Admin'),
        'email' => env('ADMIN_EMAIL', 'admin@example.com'),
        'password' => env('ADMIN_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Booking Timezone
    |--------------------------------------------------------------------------
    |
    | The timezone the admin's working hours are defined in. Appointments are
    | always stored in UTC and shown in the visitor's own timezone.
    |
    */

    'timezone' => env('BOOKING_TIMEZONE', 'UTC'),

];
