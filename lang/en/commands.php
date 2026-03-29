<?php

declare(strict_types=1);

return [

    'common' => [
        'ask_email' => 'E-Mail',
        'not_found' => 'No user with the email address ":email" found.',
    ],

    'create_user' => [
        'title' => 'Create new user',
        'ask_name' => 'Name',
        'ask_password' => 'Password',
        'ask_password_confirm' => 'Confirm password',
        'ask_role' => 'Role',
        'no_roles' => 'No roles found. Please create a role first using role:create.',
        'success' => 'User ":name" (:email) was successfully created.',
    ],

    'delete_user' => [
        'title' => 'Delete user',
        'user_found' => 'User found: :name (:email)',
        'confirm_delete' => 'Do you really want to delete this user?',
        'aborted' => 'Aborted.',
        'success' => 'User ":name" (:email) was successfully deleted.',
    ],

    'list_users' => [
        'no_users' => 'No users found.',
        'header_name' => 'Name',
        'header_email' => 'E-Mail',
        'header_role' => 'Role',
        'header_verified' => 'Verified',
        'header_approved' => 'Approved',
        'header_created_at' => 'Created at',
        'total' => 'Total: :count users',
    ],

    'create_role' => [
        'title' => 'Create new role',
        'ask_name' => 'Role name',
        'success' => 'Role ":name" was successfully created.',
    ],

    'delete_role' => [
        'title' => 'Delete role',
        'ask_name' => 'Role name',
        'role_found' => 'Role found: :name (:users_count users assigned)',
        'confirm_delete' => 'Do you really want to delete this role?',
        'has_sole_users' => 'Role ":name" cannot be deleted because :count users have only this role assigned.',
        'aborted' => 'Aborted.',
        'success' => 'Role ":name" was successfully deleted.',
    ],

    'list_roles' => [
        'no_roles' => 'No roles found.',
        'header_name' => 'Name',
        'header_users_count' => 'Users',
        'header_created_at' => 'Created at',
        'total' => 'Total: :count roles',
    ],

    'assign_role' => [
        'title' => 'Assign role',
        'ask_role' => 'Role',
        'no_roles' => 'No roles found. Please create a role first using role:create.',
        'success' => 'User ":name" (:email) was successfully assigned the role ":role".',
    ],

    'approve_user' => [
        'title' => 'Approve user',
        'already_approved' => 'User ":name" (:email) is already approved.',
        'success' => 'User ":name" (:email) was successfully approved.',
    ],

    'verify_user' => [
        'title' => 'Verify user',
        'already_verified' => 'User ":name" (:email) is already verified.',
        'success' => 'User ":name" (:email) was successfully verified.',
    ],

    'enable_two_factor' => [
        'title' => 'Enable two-factor authentication',
        'already_enabled' => 'User ":name" (:email) already has two-factor authentication enabled.',
        'secret_label' => 'TOTP secret:',
        'qr_code_label' => 'TOTP URL (for setting up in an authenticator app):',
        'ask_code' => 'Please enter the 6-digit code from your authenticator app',
        'invalid_code' => 'Invalid code. Two-factor authentication was not enabled.',
        'success' => 'Two-factor authentication for user ":name" (:email) was successfully enabled.',
        'recovery_codes_label' => 'Recovery codes:',
    ],

    'disable_two_factor' => [
        'title' => 'Disable two-factor authentication',
        'not_enabled' => 'User ":name" (:email) does not have two-factor authentication enabled.',
        'user_found' => 'User found: :name (:email)',
        'confirm_disable' => 'Do you really want to disable two-factor authentication?',
        'aborted' => 'Aborted.',
        'success' => 'Two-factor authentication for user ":name" (:email) was successfully disabled.',
    ],

    'reset_password' => [
        'title' => 'Reset user password',
        'ask_password' => 'New password',
        'ask_password_confirm' => 'Confirm new password',
        'success' => 'Password for user ":name" (:email) was successfully reset.',
    ],

];
