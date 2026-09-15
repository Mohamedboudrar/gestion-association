<?php

return [

    'common' => [
        'page_prefix' => 'Page ',
        'confidential' => 'Confidentiel — usage interne uniquement',
        'generated' => 'Généré le :date',
        'generated_by' => 'Généré le :date à :time par :name',
        'system' => 'Système',
        'unknown_donor' => 'Donateur inconnu',
    ],

    'members' => [
        'title' => 'Rapport des adhérents',
        'total_members' => 'Total des adhérents',
        'directory_title' => 'Annuaire des adhérents',
        'table' => [
            'name' => 'Nom',
            'email' => 'E-mail',
            'phone' => 'Téléphone',
        ],
        'empty' => 'Aucun adhérent enregistré.',
    ],

    'subscriptions' => [
        'title' => 'Rapport des cotisations',
        'total_subscriptions' => 'Total des cotisations',
        'total_amount' => 'Montant total',
        'verified' => 'Vérifiées',
        'pending' => 'En attente',
        'chart_title' => 'Cotisations par statut',
        'detail_title' => 'Détail des cotisations',
        'table' => [
            'member' => 'Adhérent',
            'email' => 'E-mail',
            'amount' => 'Montant',
            'status' => 'Statut',
            'payment_date' => 'Date de paiement',
            'expiration' => 'Expiration',
            'verified_by' => 'Vérifié par',
        ],
        'empty' => 'Aucune cotisation enregistrée.',
    ],

    'dues' => [
        'title' => 'Rapport des cotisations annuelles',
        'expected' => 'Attendu',
        'collected' => 'Encaissé',
        'outstanding' => 'Solde restant',
        'collection_rate' => 'Taux de recouvrement',
        'chart_title' => 'Cotisations annuelles par statut',
        'detail_title' => 'Détail des cotisations annuelles',
        'table' => [
            'member' => 'Adhérent',
            'email' => 'E-mail',
            'year' => 'Année',
            'amount_due' => 'Montant dû',
            'amount_paid' => 'Montant payé',
            'balance' => 'Solde',
            'status' => 'Statut',
            'due_date' => "Date d'échéance",
        ],
        'empty' => 'Aucune cotisation annuelle enregistrée.',
    ],

    'projects' => [
        'title' => 'Rapport des projets',
        'total_projects' => 'Total des projets',
        'active' => 'Actifs',
        'completed' => 'Terminés',
        'total_budget' => 'Budget total',
        'chart_title' => 'Projets par statut',
        'detail_title' => 'Détail des projets',
        'table' => [
            'name' => 'Nom',
            'manager' => 'Responsable',
            'status' => 'Statut',
            'budget' => 'Budget',
            'start' => 'Début',
            'end' => 'Fin',
            'members' => 'Membres',
        ],
        'empty' => 'Aucun projet enregistré.',
    ],

    'project_closure' => [
        'title' => 'Rapport de clôture de projet',
        'subtitle' => ':name · :start au :end',
        'kpi' => [
            'budget' => 'Budget',
            'donations' => 'Dons',
            'allocations' => 'Allocations',
            'expenses' => 'Dépenses',
            'returned_to_pool' => 'Reversé au pool',
        ],
        'financial_breakdown_title' => 'Répartition financière',
        'donations_title' => 'Dons (:count)',
        'donations_note' => "L'historique complet figure ci-dessous — seuls les dons approuvés comptent dans le total des dons ci-dessus.",
        'donations_table' => [
            'donor' => 'Donateur',
            'amount' => 'Montant',
            'status' => 'Statut',
            'date' => 'Date',
        ],
        'donations_empty' => 'Aucun don enregistré.',
        'expenses_title' => 'Dépenses (:count)',
        'expenses_note' => "L'historique complet figure ci-dessous — seules les dépenses approuvées/payées comptent dans le total des dépenses ci-dessus.",
        'expenses_table' => [
            'supplier' => 'Fournisseur',
            'amount' => 'Montant',
            'description' => 'Description',
            'status' => 'Statut',
            'date' => 'Date',
        ],
        'expenses_empty' => 'Aucune dépense enregistrée.',
        'allocations_title' => 'Allocations de fonds',
        'allocations_table' => [
            'amount' => 'Montant',
            'date' => 'Date',
            'recorded_by' => 'Enregistré par',
        ],
        'allocations_empty' => 'Aucune allocation de fonds enregistrée.',
    ],

    // Display labels for stored status values — keys are the raw lowercase
    // DB/enum values, never translated themselves (see report_status_label()
    // in pdf-helpers.blade.php). Mirrors frontend src/locales/*/common.json's
    // status block so a status reads identically in the UI and in PDFs.
    'status' => [
        'pending' => 'En attente',
        'approved' => 'Approuvé',
        'rejected' => 'Rejeté',
        'paid' => 'Payé',
        'completed' => 'Terminé',
        'draft' => 'Brouillon',
        'cancelled' => 'Annulé',
        'verified' => 'Vérifié',
        'expired' => 'Expiré',
        'partial' => 'Partiel',
        'overdue' => 'En retard',
        'waived' => 'Exonéré',
        'committee_ready' => 'Comité constitué',
        'funding_ready' => 'Prêt pour financement',
        'active' => 'Actif',
        'planned' => 'Planifié',
        'planning' => 'Planification',
        'preparation' => 'Préparation',
        'in_progress' => 'En cours',
        'finishing' => 'Finalisation',
    ],

];
