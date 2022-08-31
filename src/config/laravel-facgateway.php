<?php

return [
    'test'           => env('FAC_TEST', true),
    'id'             => env('FAC_ID'),
    'password'       => env('FAC_PASSWORD'),
    'redirect'       => env('FAC_REDIRECT'),
    'success_action' => env('FAC_SUCCESS_ACTION', 'FacController@success'),
    'error_action'   => env('FAC_ERROR_ACTION', 'FacController@error'),
];
