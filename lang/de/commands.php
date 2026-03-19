<?php

return [

    'common' => [
        'ask_email' => 'E-Mail',
        'not_found'  => 'Kein Benutzer mit der E-Mail-Adresse ":email" gefunden.',
    ],

    'create_user' => [
        'title'                => 'Neuen Benutzer anlegen',
        'ask_name'             => 'Name',
        'ask_password'         => 'Passwort',
        'ask_password_confirm' => 'Passwort bestätigen',
        'success'              => 'Benutzer ":name" (:email) wurde erfolgreich angelegt.',
    ],

    'delete_user' => [
        'title'          => 'Benutzer löschen',
        'user_found'     => 'Benutzer gefunden: :name (:email)',
        'confirm_delete' => 'Soll dieser Benutzer wirklich gelöscht werden?',
        'aborted'        => 'Abgebrochen.',
        'success'        => 'Benutzer ":name" (:email) wurde erfolgreich gelöscht.',
    ],

    'list_users' => [
        'no_users'          => 'Keine Benutzer vorhanden.',
        'header_name'       => 'Name',
        'header_email'      => 'E-Mail',
        'header_roles'      => 'Rollen',
        'header_created_at' => 'Erstellt am',
        'total'             => 'Gesamt: :count Benutzer',
    ],

    'verify_user' => [
        'title'            => 'Benutzer verifizieren',
        'already_verified' => 'Benutzer ":name" (:email) ist bereits verifiziert.',
        'success'          => 'Benutzer ":name" (:email) wurde erfolgreich verifiziert.',
    ],

];
