<?php

// Generic API success/error response text — every controller that returns a
// plain {"message": "..."} response (not a validation error, which comes
// from validation.php instead) pulls its text from here, so it's translated
// consistently in one place rather than scattered as literals per controller.
return [

    'auth' => [
        'invalid_credentials' => 'Identifiants invalides',
        'logged_out' => 'Déconnecté',
    ],

    'donation' => [
        'deleted' => 'Don supprimé avec succès.',
    ],

    'expense' => [
        'import_exceeds_budget' => "L'importation dépasse le budget restant du projet. Aucune dépense n'a été importée.",
        'deleted' => 'Dépense supprimée avec succès.',
    ],

    'member' => [
        'deleted' => 'Membre supprimé',
    ],

    'member_portal' => [
        'no_profile' => 'Aucun profil de membre n\'est associé à ce compte.',
        'too_many_login_attempts' => 'Trop de tentatives. Veuillez réessayer dans 15 minutes.',
        'invalid_passkey' => 'Code d\'accès invalide.',
        'too_many_requests' => 'Trop de requêtes. Veuillez réessayer plus tard.',
        'passkey_reset_sent' => 'Si cette adresse e-mail est enregistrée et vérifiée, un nouveau code d\'accès a été envoyé.',
    ],

    'notifications' => [
        'all_marked_read' => 'Toutes les notifications ont été marquées comme lues.',
        'deleted' => 'Notification supprimée.',
    ],

    'project' => [
        'deleted' => 'Projet supprimé avec succès.',
    ],

    'project_deletion_request' => [
        'project_gone' => "Ce projet n'existe plus.",
    ],

    'project_member' => [
        'assigned' => 'Membre affecté avec succès.',
        'removed' => 'Membre retiré avec succès.',
        'resigned' => 'Démission enregistrée avec succès.',
        'replaced' => 'Membre remplacé avec succès.',
        'needs_verified_subscription' => 'Ce membre doit avoir une cotisation vérifiée pour être affecté à un comité de projet.',
        'already_assigned' => 'Ce membre est déjà affecté à ce comité de projet.',
        'not_assigned' => "Ce membre n'est actuellement pas affecté à ce comité de projet.",
        'choose_different_member' => 'Choisissez un autre membre pour le remplacement.',
        'replacement_already_assigned' => 'Le membre de remplacement est déjà affecté à ce comité de projet.',
        'replacement_needs_verified_subscription' => 'Le membre de remplacement doit avoir une cotisation vérifiée pour être affecté à un comité de projet.',
    ],

    'subscription' => [
        'deleted' => 'Cotisation supprimée',
    ],

];
