<?php

return [

    'passkey_reset' => [
        'subject' => 'Votre nouveau code d\'accès :association',
        'hello' => 'Bonjour, :name',
        'intro' => "Nous avons reçu une demande de réinitialisation de votre code d'accès au Portail des membres. Votre ancien code d'accès a été désactivé — utilisez le nouveau ci-dessous pour vous connecter.",
        'new_passkey_label' => "Votre nouveau code d'accès",
        'open_portal' => 'Ouvrir le Portail des membres',
        'not_requested' => "Si vous n'êtes pas à l'origine de cette demande, veuillez contacter :association — il se peut qu'une autre personne ait saisi votre adresse e-mail enregistrée.",
        'footer_automated' => "Ceci est un message automatique du Portail des membres de :association.",
    ],

    'welcome' => [
        'subject' => 'Bienvenue chez :association',
        'heading' => 'Bienvenue, :name !',
        'intro' => 'Votre adhésion a été vérifiée. Vous avez désormais accès au Portail des membres, où vous pouvez consulter à tout moment votre historique de cotisations et de dons.',
        'passkey_label' => "Votre code d'accès au Portail des membres",
        'passkey_explanation' => "Ce code d'accès à 6 chiffres est requis pour vous connecter au Portail des membres — il n'y a ni identifiant ni mot de passe. Saisissez-le simplement au lien ci-dessous.",
        'open_portal' => 'Ouvrir le Portail des membres',
        'forgot_passkey' => 'Code d\'accès oublié ? Utilisez le lien « Code d\'accès oublié ? » sur la page de connexion du portail et saisissez votre adresse e-mail enregistrée — un nouveau code d\'accès vous sera envoyé et celui ci-dessus cessera immédiatement de fonctionner.',
        'footer_unexpected' => "Si vous ne vous attendiez pas à recevoir cet e-mail, veuillez contacter :association directement.",
    ],

];
