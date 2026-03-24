<?php

declare(strict_types=1);

return [

    // Navigation
    'app_name' => 'AssociationManager',
    'dashboard' => 'Dashboard',
    'login' => 'Log in',
    'log_in' => 'Log in',
    'log_out' => 'Log Out',
    'register' => 'Register',
    'manage_account' => 'Manage Account',
    'manage_team' => 'Manage Team',
    'team_settings' => 'Team Settings',
    'create_new_team' => 'Create New Team',
    'switch_teams' => 'Switch Teams',
    'api_tokens' => 'API Tokens',
    'profile' => 'Profile',

    // Welcome page
    'welcome_title' => 'Welcome to AssociationManager',
    'welcome_subtitle' => 'This web application is designed for the central management of your association.'
        . ' Members, contributions, and other internal data can be recorded and maintained here in a clear'
        . ' and organized manner.',
    'to_dashboard' => 'Go to Dashboard',

    // Language switcher
    'language' => 'Language',
    'lang_de' => 'Deutsch',
    'lang_en' => 'English',

    // Common
    'email' => 'Email',
    'password' => 'Password',
    'name' => 'Name',
    'save' => 'Save',
    'saved' => 'Saved.',
    'cancel' => 'Cancel',
    'confirm' => 'Confirm',
    'delete' => 'Delete',
    'close' => 'Close',
    'create' => 'Create',
    'enable' => 'Enable',
    'disable' => 'Disable',
    'unknown' => 'Unknown',
    'done' => 'Done.',
    'created' => 'Created.',
    'whoops' => 'Whoops! Something went wrong.',

    // Auth - Login
    'remember_me' => 'Remember me',
    'forgot_password' => 'Forgot your password?',
    'forgot_password_description' => 'Forgot your password? No problem. Just let us know your email address'
        . ' and we will email you a password reset link that will allow you to choose a new one.',
    'email_password_reset_link' => 'Email Password Reset Link',
    'reset_password' => 'Reset Password',
    'confirm_password' => 'Confirm Password',
    'already_registered' => 'Already registered?',

    // Auth - Two Factor
    'two_factor_auth' => 'Two Factor Authentication',
    'two_factor_description' => 'Add additional security to your account using two factor authentication.',
    'two_factor_finish_enabling' => 'Finish enabling two factor authentication.',
    'two_factor_enabled' => 'You have enabled two factor authentication.',
    'two_factor_not_enabled' => 'You have not enabled two factor authentication.',
    'two_factor_prompt' => 'When two factor authentication is enabled, you will be prompted for a secure,'
        . ' random token during authentication. You may retrieve this token from your phone\'s'
        . ' Google Authenticator application.',
    'two_factor_scan_qr_finish' => 'To finish enabling two factor authentication, scan the following QR code'
        . ' using your phone\'s authenticator application or enter the setup key and provide the generated OTP code.',
    'two_factor_scan_qr_enabled' => 'Two factor authentication is now enabled. Scan the following QR code'
        . ' using your phone\'s authenticator application or enter the setup key.',
    'setup_key' => 'Setup Key',
    'two_factor_recovery_store' => 'Store these recovery codes in a secure password manager. They can be used'
        . ' to recover access to your account if your two factor authentication device is lost.',
    'code' => 'Code',
    'recovery_code' => 'Recovery Code',
    'use_recovery_code' => 'Use a recovery code',
    'use_auth_code' => 'Use an authentication code',
    'regenerate_recovery_codes' => 'Regenerate Recovery Codes',
    'show_recovery_codes' => 'Show Recovery Codes',
    'two_factor_confirm_code' => 'Please confirm access to your account by entering the authentication code'
        . ' provided by your authenticator application.',
    'two_factor_confirm_recovery' => 'Please confirm access to your account by entering one of your emergency'
        . ' recovery codes.',

    // Auth - Confirm Password
    'confirm_password_title' => 'Confirm Password',
    'confirm_password_security' => 'For your security, please confirm your password to continue.',
    'secure_area' => 'This is a secure area of the application. Please confirm your password before continuing.',

    // Auth - Email Verification
    'verify_email_description' => 'Before continuing, could you verify your email address by clicking on the link'
        . ' we just emailed to you? If you didn\'t receive the email, we will gladly send you another.',
    'verification_link_sent_profile' => 'A new verification link has been sent to the email address you provided'
        . ' in your profile settings.',
    'resend_verification_email' => 'Resend Verification Email',
    'edit_profile' => 'Edit Profile',

    // Auth - Registration
    'agree_terms' => 'I agree to the :terms_of_service and :privacy_policy',
    'terms_of_service' => 'Terms of Service',
    'privacy_policy' => 'Privacy Policy',

    // Profile Information
    'profile_information' => 'Profile Information',
    'profile_information_description' => 'Update your account\'s profile information and email address.',
    'photo' => 'Photo',
    'select_new_photo' => 'Select A New Photo',
    'remove_photo' => 'Remove Photo',
    'role' => 'Role',
    'email_unverified' => 'Your email address is unverified.',
    'resend_verification' => 'Click here to re-send the verification email.',
    'verification_link_sent_email' => 'A new verification link has been sent to your email address.',

    // Update Password
    'update_password' => 'Update Password',
    'update_password_description' => 'Ensure your account is using a long, random password to stay secure.',
    'current_password' => 'Current Password',
    'new_password' => 'New Password',

    // Browser Sessions
    'browser_sessions' => 'Browser Sessions',
    'browser_sessions_description' => 'Manage and log out your active sessions on other browsers and devices.',
    'browser_sessions_info' => 'If necessary, you may log out of all of your other browser sessions across all'
        . ' of your devices. Some of your recent sessions are listed below; however, this list may not be exhaustive.'
        . ' If you feel your account has been compromised, you should also update your password.',
    'this_device' => 'This device',
    'last_active' => 'Last active',
    'logout_other_sessions' => 'Log Out Other Browser Sessions',
    'logout_other_sessions_confirm' => 'Please enter your password to confirm you would like to log out of your'
        . ' other browser sessions across all of your devices.',

    // Delete Account
    'delete_account' => 'Delete Account',
    'delete_account_description' => 'Permanently delete your account.',
    'delete_account_info' => 'Once your account is deleted, all of its resources and data will be permanently deleted.'
        . ' Before deleting your account, please download any data or information that you wish to retain.',
    'delete_account_confirm' => 'Are you sure you want to delete your account? Once your account is deleted,'
        . ' all of its resources and data will be permanently deleted. Please enter your password to confirm you'
        . ' would like to permanently delete your account.',

    // API Tokens
    'create_api_token' => 'Create API Token',
    'api_tokens_description' => 'API tokens allow third-party services to authenticate with our application on'
        . ' your behalf.',
    'token_name' => 'Token Name',
    'permissions' => 'Permissions',
    'manage_api_tokens' => 'Manage API Tokens',
    'api_tokens_delete_info' => 'You may delete any of your existing tokens if they are no longer needed.',
    'last_used' => 'Last used',
    'api_token' => 'API Token',
    'api_token_copy' => 'Please copy your new API token. For your security, it won\'t be shown again.',
    'api_token_permissions' => 'API Token Permissions',
    'delete_api_token' => 'Delete API Token',
    'delete_api_token_confirm' => 'Are you sure you would like to delete this API token?',

    // Team Invitation Email
    'team_invitation' => 'You have been invited to join the :team team!',
    'team_invitation_no_account' => 'If you do not have an account, you may create one by clicking the button below.'
        . ' After creating an account, you may click the invitation acceptance button in this email to accept the'
        . ' team invitation:',
    'create_account' => 'Create Account',
    'team_invitation_has_account' => 'If you already have an account, you may accept this invitation by clicking'
        . ' the button below:',
    'team_invitation_accept' => 'You may accept this invitation by clicking the button below:',
    'accept_invitation' => 'Accept Invitation',
    'team_invitation_discard' => 'If you did not expect to receive an invitation to this team, you may discard'
        . ' this email.',

    // Welcome Component (Jetstream Dashboard)
    'welcome_jetstream' => 'Welcome to your Jetstream application!',
    'welcome_jetstream_description' => 'Laravel Jetstream provides a beautiful, robust starting point for your'
        . ' next Laravel application. Laravel is designed to help you build your application using a development'
        . ' environment that is simple, powerful, and enjoyable. We believe you should love expressing your'
        . ' creativity through programming, so we have spent time carefully crafting the Laravel ecosystem to be a'
        . ' breath of fresh air. We hope you love it.',
    'documentation' => 'Documentation',
    'documentation_description' => 'Laravel has wonderful documentation covering every aspect of the framework.'
        . ' Whether you\'re new to the framework or have previous experience, we recommend reading all of the'
        . ' documentation from beginning to end.',
    'explore_documentation' => 'Explore the documentation',
    'laracasts' => 'Laracasts',
    'laracasts_description' => 'Laracasts offers thousands of video tutorials on Laravel, PHP, and JavaScript'
        . ' development. Check them out, see for yourself, and massively level up your development skills in'
        . ' the process.',
    'start_watching_laracasts' => 'Start watching Laracasts',
    'tailwind' => 'Tailwind',
    'tailwind_description' => 'Laravel Jetstream is built with Tailwind, an amazing utility first CSS framework'
        . ' that doesn\'t get in your way. You\'ll be amazed how easily you can build and maintain fresh, modern'
        . ' designs with this wonderful framework at your fingertips.',
    'authentication' => 'Authentication',
    'authentication_description' => 'Authentication and registration views are included with Laravel Jetstream,'
        . ' as well as support for user email verification and resetting forgotten passwords. So, you\'re free to'
        . ' get started with what matters most: building your application.',
];
