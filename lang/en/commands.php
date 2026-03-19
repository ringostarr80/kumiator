<?php

return [

    'common' => [
        'ask_email' => 'E-Mail',
        'not_found'  => 'No user with the email address ":email" found.',
    ],

    'create_user' => [
        'title'                => 'Create new user',
        'ask_name'             => 'Name',
        'ask_password'         => 'Password',
        'ask_password_confirm' => 'Confirm password',
        'success'              => 'User ":name" (:email) was successfully created.',
    ],

    'delete_user' => [
        'title'          => 'Delete user',
        'user_found'     => 'User found: :name (:email)',
        'confirm_delete' => 'Do you really want to delete this user?',
        'aborted'        => 'Aborted.',
        'success'        => 'User ":name" (:email) was successfully deleted.',
    ],

    'list_users' => [
        'no_users'          => 'No users found.',
        'header_name'       => 'Name',
        'header_email'      => 'E-Mail',
        'header_created_at' => 'Created at',
        'total'             => 'Total: :count users',
    ],

    'verify_user' => [
        'title'            => 'Verify user',
        'already_verified' => 'User ":name" (:email) is already verified.',
        'success'          => 'User ":name" (:email) was successfully verified.',
    ],

];
