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
        'header_created_at' => 'Erstellt am',
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

    'verify_user' => [
        'title' => 'Benutzer verifizieren',
        'already_verified' => 'Benutzer ":name" (:email) ist bereits verifiziert.',
        'success' => 'Benutzer ":name" (:email) wurde erfolgreich verifiziert.',
    ],

];
