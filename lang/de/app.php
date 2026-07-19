<?php

declare(strict_types=1);

return [

    // Navigation
    'app_name' => 'AssociationManager',
    'dashboard' => 'Dashboard',
    'login' => 'Anmelden',
    'log_in' => 'Anmelden',
    'log_out' => 'Abmelden',
    'register' => 'Registrieren',
    'manage_account' => 'Konto verwalten',
    'manage_team' => 'Team verwalten',
    'team_settings' => 'Team-Einstellungen',
    'create_new_team' => 'Neues Team erstellen',
    'switch_teams' => 'Team wechseln',
    'api_tokens' => 'API-Tokens',
    'profile' => 'Profil',

    // Welcome page
    'welcome_title' => 'Willkommen beim AssociationManager',
    'welcome_subtitle' => 'Diese Web-Applikation dient der zentralen Verwaltung deines Vereins. Mitglieder,'
        . ' Beiträge und weitere vereinsinterne Daten lassen sich hier übersichtlich erfassen und pflegen.',
    'to_dashboard' => 'Zum Dashboard',

    // Language switcher
    'language' => 'Sprache',
    'lang_de' => 'Deutsch',
    'lang_en' => 'English',

    // Common
    'email' => 'E-Mail',
    'password' => 'Passwort',
    'name' => 'Name',
    'save' => 'Speichern',
    'saved' => 'Gespeichert.',
    'cancel' => 'Abbrechen',
    'confirm' => 'Bestätigen',
    'delete' => 'Löschen',
    'close' => 'Schließen',
    'create' => 'Erstellen',
    'enable' => 'Aktivieren',
    'disable' => 'Deaktivieren',
    'unknown' => 'Unbekannt',
    'done' => 'Erledigt.',
    'created' => 'Erstellt.',
    'whoops' => 'Hoppla! Etwas ist schiefgelaufen.',

    // Auth - Login
    'remember_me' => 'Angemeldet bleiben',
    'forgot_password' => 'Passwort vergessen?',
    'forgot_password_description' => 'Passwort vergessen? Kein Problem. Gib einfach deine E-Mail-Adresse ein und'
        . ' wir senden dir einen Link zum Zurücksetzen deines Passworts.',
    'email_password_reset_link' => 'Link zum Zurücksetzen senden',
    'reset_password' => 'Passwort zurücksetzen',
    'confirm_password' => 'Passwort bestätigen',
    'already_registered' => 'Bereits registriert?',

    // Auth - Two Factor
    'two_factor_auth' => 'Zwei-Faktor-Authentifizierung',
    'two_factor_description' => 'Füge deinem Konto zusätzliche Sicherheit durch Zwei-Faktor-Authentifizierung hinzu.',
    'two_factor_finish_enabling' => 'Aktivierung der Zwei-Faktor-Authentifizierung abschließen.',
    'two_factor_enabled' => 'Du hast die Zwei-Faktor-Authentifizierung aktiviert.',
    'two_factor_not_enabled' => 'Du hast die Zwei-Faktor-Authentifizierung nicht aktiviert.',
    'two_factor_prompt' => 'Wenn die Zwei-Faktor-Authentifizierung aktiviert ist, wirst du bei der Anmeldung nach'
        . ' einem sicheren, zufälligen Token gefragt. Du kannst diesen Token aus der Google Authenticator-App auf'
        . ' deinem Smartphone abrufen.',
    'two_factor_scan_qr_finish' => 'Um die Aktivierung der Zwei-Faktor-Authentifizierung abzuschließen,'
        . ' scanne den folgenden QR-Code mit der Authenticator-App auf deinem Smartphone oder gib den'
        . ' Einrichtungsschlüssel ein und trage den generierten OTP-Code ein.',
    'two_factor_scan_qr_enabled' => 'Die Zwei-Faktor-Authentifizierung ist jetzt aktiviert. Scanne den folgenden'
        . ' QR-Code mit der Authenticator-App auf deinem Smartphone oder gib den Einrichtungsschlüssel ein.',
    'setup_key' => 'Einrichtungsschlüssel',
    'two_factor_recovery_store' => 'Speichere diese Wiederherstellungscodes in einem sicheren Passwort-Manager.'
        . ' Sie können verwendet werden, um den Zugriff auf dein Konto wiederherzustellen,'
        . ' falls dein Zwei-Faktor-Authentifizierungsgerät verloren geht.',
    'code' => 'Code',
    'recovery_code' => 'Wiederherstellungscode',
    'use_recovery_code' => 'Wiederherstellungscode verwenden',
    'use_auth_code' => 'Authentifizierungscode verwenden',
    'regenerate_recovery_codes' => 'Wiederherstellungscodes neu generieren',
    'show_recovery_codes' => 'Wiederherstellungscodes anzeigen',
    'two_factor_confirm_code' => 'Bitte bestätige den Zugriff auf dein Konto, indem du den Authentifizierungscode'
        . ' aus deiner Authenticator-App eingibst.',
    'two_factor_confirm_recovery' => 'Bitte bestätige den Zugriff auf dein Konto, indem du einen deiner'
        . ' Notfall-Wiederherstellungscodes eingibst.',

    // Auth - Confirm Password
    'confirm_password_title' => 'Passwort bestätigen',
    'confirm_password_security' => 'Bitte bestätige aus Sicherheitsgründen dein Passwort, um fortzufahren.',
    'secure_area' => 'Dies ist ein geschützter Bereich der Anwendung. Bitte bestätige dein Passwort,'
        . ' bevor du fortfährst.',

    // Auth - Email Verification
    'verify_email_description' => 'Bevor du fortfährst, bestätige bitte deine E-Mail-Adresse, indem du auf den'
        . ' Link klickst, den wir dir gerade per E-Mail geschickt haben. Falls du die E-Mail nicht erhalten hast,'
        . ' senden wir dir gerne eine neue.',
    'verification_link_sent_profile' => 'Ein neuer Bestätigungslink wurde an die in deinen Profileinstellungen'
        . ' angegebene E-Mail-Adresse gesendet.',
    'resend_verification_email' => 'Bestätigungs-E-Mail erneut senden',
    'edit_profile' => 'Profil bearbeiten',
    'email_already_verified_message' => 'Deine E-Mail-Adresse war bereits bestätigt.',
    'email_verified_pending_approval_message' => 'E-Mail-Adresse bestätigt. Wir benachrichtigen dich, sobald'
        . ' ein Administrator dein Konto freigeschaltet hat.',

    // Auth - Registration Pending
    'registration_pending_title' => 'Konto wartet auf Freischaltung',
    'registration_pending_message' => 'Vielen Dank für deine Registrierung. Ein Administrator prüft dein Konto'
        . ' und schaltet es zeitnah frei. Du erhältst eine Benachrichtigung, sobald du dich anmelden kannst.',

    // Auth - Registration
    'agree_terms' => 'Ich stimme den :terms_of_service und der :privacy_policy zu',
    'terms_of_service' => 'Nutzungsbedingungen',
    'privacy_policy' => 'Datenschutzrichtlinie',

    // Profile Information
    'profile_information' => 'Profilinformationen',
    'profile_information_description' => 'Aktualisiere die Profilinformationen und E-Mail-Adresse deines Kontos.',
    'photo' => 'Foto',
    'select_new_photo' => 'Neues Foto auswählen',
    'remove_photo' => 'Foto entfernen',
    'profile_photo_max_size' => 'Maximale Dateigröße: :size',
    'profile_photo_limited_by_server' => '(durch Server-Konfiguration begrenzt)',
    'profile_photo_upload_failed' => 'Das Foto konnte nicht hochgeladen werden — '
        . 'es überschreitet vermutlich das zulässige Limit von :size.',

    // Profilfoto-Optimizer: Exception-Messages für nicht-erwartbare GD-/IO-Fehler.
    // Werden nur im 500-Fehlerpfad sichtbar (Logs, Debug-Ausgabe).
    'profile_photo_optimizer_read_failed' => 'Das hochgeladene Profilfoto konnte nicht gelesen werden.',
    'profile_photo_optimizer_not_an_image' => 'Das hochgeladene Profilfoto konnte nicht als Bild gelesen werden.',
    'profile_photo_optimizer_too_many_pixels' => 'Das Profilfoto hat zu viele Pixel'
        . ' (maximal :max_megapixels Megapixel).',
    'profile_photo_optimizer_rotation_failed' => 'Das Profilfoto konnte nicht in die richtige Lage gedreht werden.',
    'profile_photo_optimizer_thumbnail_buffer_failed' => 'Für das Profilfoto-Thumbnail konnte kein Bildpuffer'
        . ' angelegt werden.',
    'profile_photo_optimizer_temp_file_failed' => 'Für das optimierte Profilfoto konnte keine temporäre Datei'
        . ' angelegt werden.',
    'profile_photo_optimizer_encode_failed' => 'Das Profilfoto konnte nicht als AVIF kodiert werden.',

    'role' => 'Rolle',
    'email_unverified' => 'Deine E-Mail-Adresse ist nicht verifiziert.',
    'resend_verification' => 'Klicke hier, um die Bestätigungs-E-Mail erneut zu senden.',
    'verification_link_sent_email' => 'Ein neuer Bestätigungslink wurde an deine E-Mail-Adresse gesendet.',

    // Update Password
    'update_password' => 'Passwort ändern',
    'update_password_description' => 'Stelle sicher, dass dein Konto ein langes, zufälliges Passwort verwendet,'
        . ' um sicher zu bleiben.',
    'current_password' => 'Aktuelles Passwort',
    'new_password' => 'Neues Passwort',

    // Browser Sessions
    'browser_sessions' => 'Browser-Sitzungen',
    'browser_sessions_description' => 'Verwalte und beende deine aktiven Sitzungen auf anderen Browsern und Geräten.',
    'browser_sessions_info' => 'Falls nötig, kannst du alle deine anderen Browser-Sitzungen auf all deinen Geräten'
        . ' beenden. Einige deiner letzten Sitzungen sind unten aufgelistet; diese Liste ist jedoch möglicherweise'
        . ' nicht vollständig. Wenn du glaubst, dass dein Konto kompromittiert wurde, solltest du auch dein Passwort'
        . ' ändern.',
    'this_device' => 'Dieses Gerät',
    'last_active' => 'Zuletzt aktiv',
    'logout_other_sessions' => 'Andere Browser-Sitzungen beenden',
    'logout_other_sessions_confirm' => 'Bitte gib dein Passwort ein, um zu bestätigen, dass du alle anderen'
        . ' Browser-Sitzungen auf all deinen Geräten beenden möchtest.',

    // Delete Account
    'delete_account' => 'Konto löschen',
    'delete_account_description' => 'Dein Konto dauerhaft löschen.',
    'delete_account_info' => 'Sobald dein Konto gelöscht wird, werden alle zugehörigen Daten unwiderruflich gelöscht.'
        . ' Bitte lade vor dem Löschen alle Daten herunter, die du behalten möchtest.',
    'delete_account_confirm' => 'Bist du sicher, dass du dein Konto löschen möchtest? Sobald dein Konto gelöscht wird,'
        . ' werden alle zugehörigen Daten unwiderruflich gelöscht. Bitte gib dein Passwort ein, um die dauerhafte'
        . ' Löschung deines Kontos zu bestätigen.',

    // API Tokens
    'create_api_token' => 'API-Token erstellen',
    'api_tokens_description' => 'API-Tokens ermöglichen es Drittanbieter-Diensten, sich in deinem Namen bei unserer'
        . ' Anwendung zu authentifizieren.',
    'token_name' => 'Token-Name',
    'permissions' => 'Berechtigungen',
    'manage_api_tokens' => 'API-Tokens verwalten',
    'api_tokens_delete_info' => 'Du kannst vorhandene Tokens löschen, wenn sie nicht mehr benötigt werden.',
    'last_used' => 'Zuletzt verwendet',
    'api_token' => 'API-Token',
    'api_token_copy' => 'Bitte kopiere deinen neuen API-Token. Aus Sicherheitsgründen wird er nicht erneut angezeigt.',
    'api_token_permissions' => 'API-Token-Berechtigungen',
    'delete_api_token' => 'API-Token löschen',
    'delete_api_token_confirm' => 'Bist du sicher, dass du diesen API-Token löschen möchtest?',

    // Team Invitation Email
    'team_invitation' => 'Du wurdest eingeladen, dem Team :team beizutreten!',
    'team_invitation_no_account' => 'Wenn du noch kein Konto hast, kannst du eines erstellen, indem du auf die'
        . ' Schaltfläche unten klickst. Nach der Kontoerstellung kannst du auf die Einladungsannahme-Schaltfläche'
        . ' in dieser E-Mail klicken, um die Teameinladung anzunehmen:',
    'create_account' => 'Konto erstellen',
    'team_invitation_has_account' => 'Wenn du bereits ein Konto hast, kannst du diese Einladung annehmen, indem du'
        . ' auf die Schaltfläche unten klickst:',
    'team_invitation_accept' => 'Du kannst diese Einladung annehmen, indem du auf die Schaltfläche unten klickst:',
    'accept_invitation' => 'Einladung annehmen',
    'team_invitation_discard' => 'Wenn du keine Einladung zu diesem Team erwartet hast,'
        . ' kannst du diese E-Mail ignorieren.',

    // Passkeys
    'passkeys' => 'Passkeys',
    'passkeys_description' => 'Verwalte deine Passkeys für eine passwortlose Anmeldung.'
        . ' Passkeys sind sicherer als Passwörter und deutlich einfacher zu verwenden.',
    'passkeys_empty' => 'Du hast noch keine Passkeys registriert.',
    'add_passkey' => 'Passkey hinzufügen',
    'passkey_name' => 'Passkey-Name',
    'passkey_name_placeholder' => 'z.B. iPhone, MacBook, YubiKey',
    'passkey_default_name' => 'Mein Passkey',
    'passkey_added' => 'Passkey erfolgreich hinzugefügt.',
    'passkey_deleted' => 'Passkey gelöscht.',
    'passkey_delete_confirm' => 'Möchtest du diesen Passkey wirklich löschen?',
    'passkey_rename' => 'Umbenennen',
    'passkey_renamed' => 'Passkey umbenannt.',
    'passkey_registered_at' => 'Registriert am',
    'passkey_last_used' => 'Zuletzt verwendet',
    'passkey_never_used' => 'Noch nicht verwendet',
    'sign_in_with_passkey' => 'Mit Passkey anmelden',
    'passkey_auth_error' => 'Passkey-Anmeldung fehlgeschlagen. Bitte versuche es erneut.',
    'passkey_credential_not_found' => 'Passkey nicht gefunden.',
    'passkey_invalid_response_type' => 'Ungültiger Authentifikator-Antworttyp für die Authentifizierung.',
    'passkey_orphaned_credential' => 'Der Credential-Datensatz verweist auf einen nicht vorhandenen Benutzer'
        . ' (Datenintegritätsverletzung).',
    'passkey_session_expired' => 'Authentifizierungs-Sitzung abgelaufen. Bitte versuche es erneut.',
    'passkey_registration_session_expired' => 'Registrierungs-Sitzung abgelaufen. Bitte versuche es erneut.',
    'passkey_empty_request' => 'Leerer Anfrage-Body.',
    'passkey_authentication_failed' => 'Authentifizierung fehlgeschlagen.',
    'passkey_registration_failed' => 'Passkey-Registrierung fehlgeschlagen.',
    'unsupported_media_type' => 'Nicht unterstützter Medientyp.',
    'request_payload_too_large' => 'Die Anfrage ist zu groß.',

    // Welcome Component (Jetstream Dashboard)
    'welcome_jetstream' => 'Willkommen in deiner Jetstream-Anwendung!',
    'welcome_jetstream_description' => 'Laravel Jetstream bietet einen eleganten und robusten Ausgangspunkt für deine'
        . ' nächste Laravel-Anwendung. Laravel wurde entwickelt, um dir beim Erstellen deiner Anwendung mit einer'
        . ' einfachen, leistungsstarken und angenehmen Entwicklungsumgebung zu helfen.',
    'documentation' => 'Dokumentation',
    'documentation_description' => 'Laravel verfügt über eine hervorragende Dokumentation, die jeden Aspekt des'
        . ' Frameworks abdeckt. Egal ob du neu im Framework bist oder bereits Erfahrung hast, wir empfehlen dir,'
        . ' die gesamte Dokumentation von Anfang bis Ende zu lesen.',
    'explore_documentation' => 'Dokumentation erkunden',
    'laracasts' => 'Laracasts',
    'laracasts_description' => 'Laracasts bietet Tausende von Video-Tutorials zur Entwicklung mit Laravel, PHP und'
        . ' JavaScript. Schau sie dir an und verbessere deine Entwicklungsfähigkeiten dabei enorm.',
    'start_watching_laracasts' => 'Laracasts ansehen',
    'tailwind' => 'Tailwind',
    'tailwind_description' => 'Laravel Jetstream basiert auf Tailwind, einem großartigen Utility-First-CSS-Framework,'
        . ' das dir nicht im Weg steht. Du wirst erstaunt sein, wie einfach du frische, moderne Designs erstellen und'
        . ' pflegen kannst.',
    'authentication' => 'Authentifizierung',
    'authentication_description' => 'Authentifizierungs- und Registrierungsansichten sind in Laravel Jetstream'
        . ' enthalten, ebenso wie die Unterstützung für E-Mail-Verifizierung und das Zurücksetzen vergessener'
        . ' Passwörter. So kannst du direkt mit dem Wichtigsten beginnen: dem Erstellen deiner Anwendung.',

    // Activity-Log (Admin)
    'activity_log' => 'Aktivitätsprotokoll',
    'activity_log_empty' => 'Es sind keine Einträge im Aktivitätsprotokoll vorhanden.',
    'activity_log_when' => 'Zeitpunkt',
    'activity_log_log_name' => 'Kategorie',
    'activity_log_event' => 'Ereignis',
    'activity_log_causer' => 'Ausgelöst von',
    'activity_log_subject' => 'Betroffener Datensatz',
    'activity_log_description' => 'Beschreibung',
    'activity_log_properties' => 'Details',
    'activity_log_properties_show' => 'Details anzeigen',
    'activity_log_properties_modal_title' => 'Aktivitäts-Details',
    'activity_log_properties_modal_close' => 'Schließen',
    'activity_log_deleted_record' => ':type (gelöscht)',
    'activity_log_filter_toggle' => 'Filter',
    'activity_log_filter_date_from' => 'Von',
    'activity_log_filter_date_to' => 'Bis',
    'activity_log_filter_all' => 'Alle',
    'activity_log_filter_reset' => 'Filter zurücksetzen',
    'activity_log_filter_date_invalid' => 'Bitte ein gültiges Datum (JJJJ-MM-TT) angeben.',
    'activity_log_filter_date_range' => 'Das Bis-Datum darf nicht vor dem Von-Datum liegen.',
    'activity_log_no_matches' => 'Keine Einträge für die aktuelle Filterauswahl.',

    // Activity-Log: fachliche Ereignis-Beschreibungen
    'activity_passkey_registered' => 'Passkey registriert',
    'activity_passkey_renamed' => 'Passkey umbenannt',
    'activity_passkey_removed' => 'Passkey gelöscht',
    'activity_passkey_login_succeeded' => 'Passkey-Anmeldung erfolgreich',
    'activity_passkey_login_failed' => 'Passkey-Anmeldung fehlgeschlagen',
    'activity_passkey_registration_failed' => 'Passkey-Registrierung fehlgeschlagen',
    'activity_authorization_denied' => 'Autorisierung verweigert',
    'activity_activity_log_viewed' => 'Aktivitätsprotokoll eingesehen',
    'activity_password_login_succeeded' => 'Passwort-Anmeldung erfolgreich',
    'activity_logout' => 'Abmeldung',
    'activity_login_failed' => 'Anmeldung fehlgeschlagen',
    'activity_login_unapproved' => 'Anmeldung abgelehnt (Konto nicht freigeschaltet)',
    'activity_login_locked_out' => 'Anmeldung gesperrt (zu viele Fehlversuche)',
    'activity_password_updated' => 'Passwort geändert',
    'activity_password_update_failed' => 'Passwortänderung fehlgeschlagen',
    'activity_password_confirmation_failed' => 'Passwort-Bestätigung fehlgeschlagen',
    'activity_password_reset' => 'Passwort zurückgesetzt',
    'activity_password_reset_requested' => 'Passwort-Reset-Link angefordert',
    'activity_user_self_registered' => 'Konto durch Selbstregistrierung angelegt',
    'activity_user_created' => 'Konto angelegt',
    'activity_user_approved' => 'Konto freigeschaltet',
    'activity_user_renamed' => 'Name geändert',
    'activity_user_deleted' => 'Konto gelöscht (soft-delete)',
    'activity_user_restored' => 'Konto wiederhergestellt',
    'activity_email_verified' => 'E-Mail-Adresse bestätigt',
    'activity_email_verification_requested' => 'Bestätigungs-E-Mail erneut angefordert',
    'activity_email_change_requested' => 'E-Mail-Änderung beantragt',
    'activity_email_changed' => 'E-Mail-Adresse geändert',
    'activity_email_change_cancelled' => 'E-Mail-Änderung abgebrochen',
    'activity_email_change_confirmation_rejected' => 'E-Mail-Änderungs-Bestätigung abgelehnt',
    'activity_email_change_request_failed' => 'E-Mail-Änderung fehlgeschlagen',
    'activity_email_verification_failed' => 'E-Mail-Bestätigung fehlgeschlagen',
    'activity_other_sessions_logged_out' => 'Andere Browser-Sessions abgemeldet',
    'activity_other_devices_logged_out' => 'Andere Geräte abgemeldet',
    'activity_account_self_deleted' => 'Konto durch Nutzer selbst gelöscht (anonymisiert)',
    'activity_account_admin_force_deleted' => 'Konto durch Administrator endgültig gelöscht (anonymisiert)',
    'activity_api_token_created' => 'API-Token erstellt',
    'activity_api_token_revoked' => 'API-Token widerrufen',
    'activity_api_token_permissions_changed' => 'API-Token-Berechtigungen geändert',
    'activity_profile_photo_updated' => 'Profilfoto aktualisiert',
    'activity_profile_photo_removed' => 'Profilfoto entfernt',
    'activity_2fa_enabled' => 'Zwei-Faktor-Authentifizierung aktiviert',
    'activity_2fa_confirmed' => 'Zwei-Faktor-Authentifizierung bestätigt',
    'activity_2fa_disabled' => 'Zwei-Faktor-Authentifizierung deaktiviert',
    'activity_2fa_setup_aborted' => 'Zwei-Faktor-Setup abgebrochen',
    'activity_2fa_recovery_codes_regenerated' => 'Recovery-Codes neu erzeugt',
    'activity_2fa_recovery_code_used' => 'Recovery-Code verwendet',
    'activity_2fa_failed' => 'Zwei-Faktor-Code ungültig',
    'activity_role_attached' => 'Rolle zugewiesen',
    'activity_role_detached' => 'Rolle entzogen',
    'activity_role_created' => 'Rolle angelegt',
    'activity_role_deleted' => 'Rolle gelöscht',
    'activity_permission_attached' => 'Berechtigung zugewiesen',
    'activity_permission_detached' => 'Berechtigung entzogen',

    // Morph-Typen (Anzeigenamen für die in AppServiceProvider registrierte Morph-Map)
    'morph_user' => 'Benutzer',
    'morph_passkey' => 'Passkey',
    'morph_role' => 'Rolle',

    // E-Mail-Änderung: Mail-Texte
    'email_change_verify_subject' => 'Bestätige deine neue E-Mail-Adresse',
    'email_change_verify_greeting' => 'Hallo :name,',
    'email_change_verify_intro' => 'für dein Konto wurde eine Änderung der E-Mail-Adresse auf :email beantragt.'
        . ' Klicke auf den folgenden Button, um die neue Adresse zu bestätigen — erst dann wird sie übernommen.',
    'email_change_verify_action' => 'Neue E-Mail-Adresse bestätigen',
    'email_change_verify_ttl' => 'Aus Sicherheitsgründen läuft dieser Link nach 60 Minuten ab.',
    'email_change_verify_ignore' => 'Wenn du diese Änderung nicht angefragt hast, ignoriere diese E-Mail einfach.'
        . ' Eine zusätzliche Hinweis-Mail mit Abbruch-Link wurde an deine alte Adresse gesendet.',
    'email_change_requested_subject' => 'Sicherheitshinweis: Änderung deiner E-Mail-Adresse beantragt',
    'email_change_requested_greeting' => 'Hallo :name,',
    'email_change_requested_intro' => 'für dein Konto wurde eine Änderung der E-Mail-Adresse auf :email beantragt.'
        . ' Diese Adresse erhält parallel eine Bestätigungs-Mail.',
    'email_change_requested_warning' => 'Falls du diese Änderung NICHT selbst veranlasst hast, brich sie umgehend ab.'
        . ' Bis zur Bestätigung über die neue Adresse bleibt diese aktuelle Adresse für deinen Login gültig.',
    'email_change_requested_cancel_action' => 'Änderung abbrechen',
    'email_change_requested_ttl' => 'Die Anfrage läuft nach 60 Minuten automatisch ab.',
    'email_change_requested_self_hint' => 'Wenn du die Änderung selbst angestoßen hast, ist nichts weiter zu tun.',

    // E-Mail-Änderung: Landingpages (GET nebenwirkungsfrei, Aktion per POST)
    'email_change_confirm_prompt' => 'Hier bestätigst du die Änderung deiner E-Mail-Adresse. Erst mit dem Klick'
        . ' auf den Button wird die neue Adresse übernommen.',
    'email_change_confirm_button' => 'Neue E-Mail-Adresse bestätigen',
    'email_change_cancel_prompt' => 'Hier brichst du die beantragte Änderung deiner E-Mail-Adresse ab. Deine'
        . ' bisherige Adresse bleibt erhalten.',
    'email_change_cancel_button' => 'Änderung abbrechen',

    // E-Mail-Änderung: View-Nachrichten
    'email_change_confirmed_message' => 'Deine neue E-Mail-Adresse ist jetzt aktiv. Du kannst dich ab sofort mit'
        . ' ihr anmelden.',
    'email_change_expired_message' => 'Dieser Bestätigungslink ist abgelaufen. Bitte stoße die E-Mail-Änderung in'
        . ' deinem Profil erneut an.',
    'email_change_conflict_message' => 'Diese E-Mail-Adresse wird inzwischen von einem anderen Konto verwendet.'
        . ' Bitte wähle eine andere Adresse und stoße die Änderung erneut an.',
    'email_change_invalid_message' => 'Dieser Link ist ungültig.',
    'email_change_cancelled_message' => 'Sofern eine offene E-Mail-Änderung zu diesem Link existierte, wurde sie'
        . ' abgebrochen. Deine bestehende E-Mail-Adresse bleibt unverändert.',

    // E-Mail-Änderung: Profil-Formular (Re-Authentifizierung)
    'email_change_current_password_hint' => 'Bestätige die Änderung deiner E-Mail-Adresse mit deinem'
        . ' aktuellen Passwort.',
    'email_change_current_password_required' => 'Für die Änderung der E-Mail-Adresse ist dein aktuelles'
        . ' Passwort erforderlich.',

];
