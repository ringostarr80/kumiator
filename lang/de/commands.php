<?php

declare(strict_types=1);

return [

    'common' => [
        'ask_email' => 'E-Mail',
        'not_found' => 'Kein Benutzer mit der E-Mail-Adresse ":email" gefunden.',
    ],

    'create_user' => [
        'title' => 'Neuen Benutzer anlegen',
        'ask_name' => 'Name',
        'ask_password' => 'Passwort',
        'ask_password_confirm' => 'Passwort bestätigen',
        'ask_role' => 'Rolle',
        'no_roles' => 'Keine Rollen vorhanden. Bitte zuerst eine Rolle mit role:create anlegen.',
        'success' => 'Benutzer ":name" (:email) wurde erfolgreich angelegt.',
    ],

    'delete_user' => [
        'title' => 'Benutzer löschen',
        'user_found' => 'Benutzer gefunden: :name (:email)',
        'confirm_delete' => 'Soll dieser Benutzer wirklich gelöscht werden?',
        'aborted' => 'Abgebrochen.',
        'success' => 'Benutzer ":name" (:email) wurde erfolgreich gelöscht.',
    ],

    'list_users' => [
        'no_users' => 'Keine Benutzer vorhanden.',
        'header_name' => 'Name',
        'header_email' => 'E-Mail',
        'header_role' => 'Rolle',
        'header_verified' => 'Verifiziert',
        'header_approved' => 'Freigeschaltet',
        'header_created_at' => 'Erstellt am',
        'header_deleted_at' => 'Gelöscht am',
        'total' => 'Gesamt: :count Benutzer',
    ],

    'create_role' => [
        'title' => 'Neue Rolle anlegen',
        'ask_name' => 'Rollenname',
        'success' => 'Rolle ":name" wurde erfolgreich angelegt.',
    ],

    'delete_role' => [
        'title' => 'Rolle löschen',
        'ask_name' => 'Rollenname',
        'role_found' => 'Rolle gefunden: :name (:users_count Benutzer zugewiesen)',
        'confirm_delete' => 'Soll diese Rolle wirklich gelöscht werden?',
        'has_sole_users' => 'Rolle ":name" kann nicht gelöscht werden.'
            . ' :count Benutzer haben nur diese Rolle.',
        'aborted' => 'Abgebrochen.',
        'success' => 'Rolle ":name" wurde erfolgreich gelöscht.',
    ],

    'list_roles' => [
        'no_roles' => 'Keine Rollen vorhanden.',
        'header_name' => 'Name',
        'header_users_count' => 'Benutzer',
        'header_created_at' => 'Erstellt am',
        'total' => 'Gesamt: :count Rollen',
    ],

    'assign_role' => [
        'title' => 'Rolle zuweisen',
        'ask_role' => 'Rolle',
        'no_roles' => 'Keine Rollen vorhanden. Bitte zuerst eine Rolle mit role:create anlegen.',
        'success' => 'Benutzer ":name" (:email) wurde die Rolle ":role" erfolgreich zugewiesen.',
    ],

    'approve_user' => [
        'title' => 'Benutzer freischalten',
        'already_approved' => 'Benutzer ":name" (:email) ist bereits freigeschaltet.',
        'success' => 'Benutzer ":name" (:email) wurde erfolgreich freigeschaltet.',
    ],

    'verify_user' => [
        'title' => 'Benutzer verifizieren',
        'already_verified' => 'Benutzer ":name" (:email) ist bereits verifiziert.',
        'success' => 'Benutzer ":name" (:email) wurde erfolgreich verifiziert.',
    ],

    'enable_two_factor' => [
        'title' => 'Zwei-Faktor-Authentifizierung aktivieren',
        'already_enabled' => 'Benutzer ":name" (:email) hat die Zwei-Faktor-Authentifizierung bereits aktiviert.',
        'secret_label' => 'TOTP-Secret:',
        'qr_code_label' => 'TOTP-URL (zum Einrichten in einer Authenticator-App):',
        'ask_code' => 'Bitte den 6-stelligen Code aus der Authenticator-App eingeben',
        'invalid_code' => 'Ungültiger Code. Die Zwei-Faktor-Authentifizierung wurde nicht aktiviert.',
        'success' => 'Zwei-Faktor-Authentifizierung für Benutzer ":name" (:email) wurde erfolgreich aktiviert.',
        'recovery_codes_label' => 'Wiederherstellungscodes:',
    ],

    'disable_two_factor' => [
        'title' => 'Zwei-Faktor-Authentifizierung deaktivieren',
        'not_enabled' => 'Benutzer ":name" (:email) hat die Zwei-Faktor-Authentifizierung nicht aktiviert.',
        'user_found' => 'Benutzer gefunden: :name (:email)',
        'confirm_disable' => 'Soll die Zwei-Faktor-Authentifizierung wirklich deaktiviert werden?',
        'aborted' => 'Abgebrochen.',
        'success' => 'Zwei-Faktor-Authentifizierung für Benutzer ":name" (:email) wurde erfolgreich deaktiviert.',
    ],

    'reset_password' => [
        'title' => 'Passwort zurücksetzen',
        'ask_password' => 'Neues Passwort',
        'ask_password_confirm' => 'Neues Passwort bestätigen',
        'success' => 'Das Passwort für Benutzer ":name" (:email) wurde erfolgreich zurückgesetzt.',
    ],

];
