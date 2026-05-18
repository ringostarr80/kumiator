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
    'email_already_verified_message' => 'Your email address was already verified.',
    'email_verified_pending_approval_message' => 'Email address verified. We will notify you once an administrator'
        . ' has approved your account.',

    // Auth - Registration Pending
    'registration_pending_title' => 'Account awaiting approval',
    'registration_pending_message' => 'Thank you for registering. An administrator is reviewing your account and'
        . ' will approve it shortly. You will be notified as soon as you can sign in.',

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
    'profile_photo_max_size' => 'Maximum file size: :size',
    'profile_photo_limited_by_server' => '(limited by server configuration)',
    'profile_photo_upload_failed' => 'The photo could not be uploaded — '
        . 'it likely exceeds the allowed limit of :size.',

    // Profile photo optimizer: exception messages for unexpected GD/IO failures.
    // Only surfaced via the 500 error path (logs, debug output).
    'profile_photo_optimizer_read_failed' => 'The uploaded profile photo could not be read.',
    'profile_photo_optimizer_not_an_image' => 'The uploaded profile photo could not be parsed as an image.',
    'profile_photo_optimizer_rotation_failed' => 'The profile photo could not be rotated into the correct orientation.',
    'profile_photo_optimizer_thumbnail_buffer_failed' => 'Could not allocate an image buffer for the profile photo'
        . ' thumbnail.',
    'profile_photo_optimizer_temp_file_failed' => 'Could not create a temporary file for the optimized profile photo.',
    'profile_photo_optimizer_encode_failed' => 'The profile photo could not be encoded as AVIF.',

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

    // Passkeys
    'passkeys' => 'Passkeys',
    'passkeys_description' => 'Manage your passkeys for passwordless sign-in.'
        . ' Passkeys are more secure than passwords and much easier to use.',
    'passkeys_empty' => 'You have not registered any passkeys yet.',
    'add_passkey' => 'Add Passkey',
    'passkey_name' => 'Passkey Name',
    'passkey_name_placeholder' => 'e.g. iPhone, MacBook, YubiKey',
    'passkey_default_name' => 'My Passkey',
    'passkey_added' => 'Passkey added successfully.',
    'passkey_deleted' => 'Passkey deleted.',
    'passkey_delete_confirm' => 'Are you sure you want to delete this passkey?',
    'passkey_rename' => 'Rename',
    'passkey_renamed' => 'Passkey renamed.',
    'passkey_registered_at' => 'Registered on',
    'passkey_last_used' => 'Last used',
    'passkey_never_used' => 'Never used',
    'sign_in_with_passkey' => 'Sign in with Passkey',
    'passkey_auth_error' => 'Passkey authentication failed. Please try again.',
    'passkey_credential_not_found' => 'Credential not found.',
    'passkey_invalid_response_type' => 'Invalid authenticator response type for authentication.',
    'passkey_orphaned_credential' => 'Credential record references a non-existent user (data integrity violation).',
    'passkey_session_expired' => 'Authentication session expired. Please try again.',
    'passkey_registration_session_expired' => 'Registration session expired. Please try again.',
    'passkey_empty_request' => 'Empty request body.',
    'passkey_authentication_failed' => 'Authentication failed.',
    'passkey_registration_failed' => 'Passkey registration failed.',
    'unsupported_media_type' => 'Unsupported Media Type.',
    'request_payload_too_large' => 'Request payload too large.',

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

    // Activity log (admin)
    'activity_log' => 'Activity log',
    'activity_log_empty' => 'No entries in the activity log yet.',
    'activity_log_when' => 'When',
    'activity_log_log_name' => 'Category',
    'activity_log_event' => 'Event',
    'activity_log_causer' => 'Triggered by',
    'activity_log_subject' => 'Subject',
    'activity_log_description' => 'Description',
    'activity_log_properties' => 'Details',
    'activity_log_properties_show' => 'Show details',
    'activity_log_properties_modal_title' => 'Activity details',
    'activity_log_properties_modal_close' => 'Close',
    'activity_log_deleted_record' => ':type (deleted)',

    // Activity log: domain-specific event descriptions
    'activity_passkey_registered' => 'Passkey registered',
    'activity_passkey_renamed' => 'Passkey renamed',
    'activity_passkey_removed' => 'Passkey deleted',
    'activity_passkey_login_succeeded' => 'Passkey login succeeded',
    'activity_passkey_login_failed' => 'Passkey login failed',
    'activity_password_login_succeeded' => 'Password login succeeded',
    'activity_logout' => 'Logout',
    'activity_login_failed' => 'Login failed',
    'activity_login_unapproved' => 'Login denied (account not approved)',
    'activity_login_locked_out' => 'Login locked out (too many failed attempts)',
    'activity_password_updated' => 'Password changed',
    'activity_password_reset' => 'Password reset',
    'activity_user_self_registered' => 'Account created via self-registration',
    'activity_user_created' => 'Account created',
    'activity_user_approved' => 'Account approved',
    'activity_user_renamed' => 'Name changed',
    'activity_user_deleted' => 'Account deleted (soft delete)',
    'activity_user_restored' => 'Account restored',
    'activity_email_verified' => 'Email address verified',
    'activity_email_change_requested' => 'Email change requested',
    'activity_email_changed' => 'Email address changed',
    'activity_email_change_cancelled' => 'Email change cancelled',
    'activity_email_change_confirmation_rejected' => 'Email change confirmation rejected',
    'activity_email_verification_failed' => 'Email verification failed',
    'activity_other_sessions_logged_out' => 'Other browser sessions logged out',
    'activity_other_devices_logged_out' => 'Other devices logged out',
    'activity_account_self_deleted' => 'Account self-deleted by user (anonymised)',
    'activity_account_admin_force_deleted' => 'Account permanently deleted by administrator (anonymised)',
    'activity_api_token_created' => 'API token created',
    'activity_api_token_revoked' => 'API token revoked',
    'activity_profile_photo_updated' => 'Profile photo updated',
    'activity_profile_photo_removed' => 'Profile photo removed',
    'activity_2fa_enabled' => 'Two-factor authentication enabled',
    'activity_2fa_confirmed' => 'Two-factor authentication confirmed',
    'activity_2fa_disabled' => 'Two-factor authentication disabled',
    'activity_2fa_setup_aborted' => 'Two-factor setup aborted',
    'activity_2fa_recovery_codes_regenerated' => 'Recovery codes regenerated',
    'activity_2fa_recovery_code_used' => 'Recovery code used',
    'activity_2fa_failed' => 'Two-factor code invalid',
    'activity_role_attached' => 'Role assigned',
    'activity_role_detached' => 'Role revoked',
    'activity_role_created' => 'Role created',
    'activity_role_deleted' => 'Role deleted',
    'activity_permission_attached' => 'Permission granted',
    'activity_permission_detached' => 'Permission revoked',

    // Morph types (display names for the morph map registered in AppServiceProvider)
    'morph_user' => 'User',
    'morph_passkey' => 'Passkey',
    'morph_role' => 'Role',

    // Email change: mail texts
    'email_change_verify_subject' => 'Confirm your new email address',
    'email_change_verify_greeting' => 'Hello :name,',
    'email_change_verify_intro' => 'A change of the email address for your account to :email has been requested.'
        . ' Click the following button to confirm the new address — only then will it be applied.',
    'email_change_verify_action' => 'Confirm new email address',
    'email_change_verify_ttl' => 'For security reasons, this link expires after 60 minutes.',
    'email_change_verify_ignore' => 'If you did not request this change, simply ignore this email.'
        . ' An additional notice email with a cancel link has been sent to your previous address.',
    'email_change_requested_subject' => 'Security notice: email address change requested',
    'email_change_requested_greeting' => 'Hello :name,',
    'email_change_requested_intro' => 'A change of the email address for your account to :email has been requested.'
        . ' That address will receive a separate confirmation email.',
    'email_change_requested_warning' => 'If you did NOT initiate this change, cancel it immediately.'
        . ' Until the new address is confirmed, this current address remains valid for your login.',
    'email_change_requested_cancel_action' => 'Cancel change',
    'email_change_requested_ttl' => 'The request expires automatically after 60 minutes.',
    'email_change_requested_self_hint' => 'If you initiated the change yourself, no further action is required.',

    // Email change: view messages
    'email_change_confirmed_message' => 'Your new email address is now active. You can log in with it from now on.',
    'email_change_expired_message' => 'This confirmation link has expired. Please restart the email change from'
        . ' your profile.',
    'email_change_conflict_message' => 'This email address is now in use by another account. Please choose a'
        . ' different address and restart the change.',
    'email_change_invalid_message' => 'This link is invalid.',
    'email_change_cancelled_message' => 'If a pending email change matched this link, it has been cancelled.'
        . ' Your existing email address remains unchanged.',
];
