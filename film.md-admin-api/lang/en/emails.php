<?php

return [
    'registration' => [
        'subject' => 'Confirm your filmoteca.md account',
        'heading' => 'Confirm your account',
        'intro' => 'Hi, :name! Use the code below or the confirmation button to finish creating your account.',
        'button' => 'Confirm account',
        'expires' => '{1} The code and link expire in one minute.|[2,*] The code and link expire in :minutes minutes.',
        'ignore' => 'If you did not request this account, you can ignore this message.',
    ],
    'password_reset' => [
        'subject' => 'Reset your filmoteca.md password',
        'heading' => 'Reset your password',
        'intro' => 'Hi, :name! Use the code below to choose a new password.',
        'expires' => '{1} The code expires in one minute.|[2,*] The code expires in :minutes minutes.',
        'ignore' => 'If you did not request a password reset, you can ignore this message — your password stays unchanged.',
    ],
];
