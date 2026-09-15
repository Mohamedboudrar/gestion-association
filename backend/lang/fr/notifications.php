<?php

return [

    'people' => [
        'committee_member' => 'Un membre du comité',
        'subscriber' => 'Un abonné',
        'member' => 'Un membre',
        'committee_leader' => 'Un responsable de comité',
        'a_project' => 'un projet',
    ],

    'donation' => [
        'pending_title' => "Don en attente d'approbation",
        'resubmitted_title' => 'Don resoumis pour approbation',
        'submitted_message' => ':actor a soumis le don n° :id (:project).',
        'resubmitted_message' => ':actor a resoumis le don n° :id (:project).',
        'approved_title' => 'Don approuvé',
        'approved_message' => 'Le don n° :id (:project) a été approuvé.',
        'rejected_title' => 'Don rejeté',
        'rejected_message' => 'Le don n° :id (:project) a été rejeté : :reason',
    ],

    'expense' => [
        'pending_title' => "Dépense en attente d'approbation",
        'resubmitted_title' => 'Dépense resoumise pour approbation',
        'submitted_message' => ':actor a soumis la dépense n° :id (:project).',
        'resubmitted_message' => ':actor a resoumis la dépense n° :id (:project).',
        'approved_title' => 'Dépense approuvée',
        'approved_message' => 'La dépense n° :id (:project) a été approuvée.',
        'rejected_title' => 'Dépense rejetée',
        'rejected_message' => 'La dépense n° :id (:project) a été rejetée : :reason',
    ],

    'subscription' => [
        'pending_title' => 'Cotisation en attente de vérification',
        'pending_message' => ':actor a soumis un paiement de cotisation pour vérification.',
        'receipt_uploaded_title' => 'Reçu de cotisation déposé',
        'receipt_uploaded_message' => ':actor a déposé un reçu en attente de vérification.',
        'approved_title' => 'Cotisation vérifiée',
        'approved_message' => 'Votre paiement de cotisation a été vérifié.',
        'expired_member_title' => 'Cotisation expirée',
        'expired_member_message' => 'Votre cotisation a expiré.',
        'expired_roles_title' => 'Cotisation expirée',
        'expired_roles_message' => 'La cotisation de :name a expiré.',
        'expiring_today_title' => "La cotisation expire aujourd'hui",
        'expiring_days_title' => 'La cotisation expire dans :days jours',
        'expiring_today_message' => "Votre cotisation expire aujourd'hui.",
        'expiring_days_message' => 'Votre cotisation expire dans :days jours (le :date).',
    ],

    'project' => [
        'closed_title' => 'Projet clôturé',
        'closed_message' => 'Le projet :name a été clôturé — rapport disponible.',
        'completed_title' => 'Projet terminé',
        'completed_message' => 'Le projet « :name » est terminé.',
        'report_generated_title' => 'Rapport final disponible',
        'report_generated_message' => 'Le rapport final du projet « :name » est désormais disponible.',
        'overdue_title' => 'Projet en retard',
        'overdue_message' => 'Le projet :name a dépassé sa date de fin (:date) et est toujours au statut :status.',
    ],

    'project_deletion' => [
        'pending_title' => "Demande de suppression de projet en attente d'examen",
        'pending_message' => ':actor a demandé la suppression de :project.',
        'approved_title' => 'Suppression de projet approuvée',
        'approved_message' => 'Votre demande de suppression de :project a été approuvée. Le projet a été supprimé.',
        'rejected_title' => 'Suppression de projet rejetée',
        'rejected_message' => 'Votre demande de suppression de :project a été rejetée : :reason',
    ],

    'committee' => [
        'assigned_title' => 'Affecté au projet',
        'assigned_message' => 'Vous avez été affecté au comité de :project.',
        'removed_title' => 'Retiré du projet',
        'removed_message' => 'Vous avez été retiré du comité de :project.',
        'replaced_message' => 'Vous avez été remplacé au sein du comité de :project.',
    ],

    'phase_request' => [
        'pending_title' => "Demande de changement de phase en attente d'examen",
        'pending_message' => ':actor a demandé à faire passer :project à la phase suivante.',
        'approved_title' => 'Demande de changement de phase approuvée',
        'approved_message' => 'Votre demande de passage de :project à la phase suivante a été approuvée.',
        'rejected_title' => 'Demande de changement de phase rejetée',
        'rejected_message' => 'Votre demande de passage de :project à la phase suivante a été rejetée : :reason',
    ],

    'due' => [
        'created_title' => 'Montant de la cotisation annuelle',
        'created_message' => 'Votre cotisation annuelle :year s\'élève à :amount. Vos paiements seront comptabilisés progressivement sur ce montant.',
        'overdue_member_title' => 'Cotisation annuelle en retard',
        'overdue_member_message' => 'Votre cotisation annuelle :year est en retard. Solde restant : :balance.',
        'overdue_roles_title' => 'Cotisation annuelle en retard',
        'overdue_roles_message' => ':actor a une cotisation :year en retard.',
        'paid_title' => 'Cotisation annuelle payée intégralement',
        'paid_member_message' => 'Votre cotisation annuelle :year a été payée intégralement.',
        'paid_roles_message' => ':actor a payé intégralement sa cotisation :year.',
    ],

];
