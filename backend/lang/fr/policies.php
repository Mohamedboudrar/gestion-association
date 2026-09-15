<?php

// Authorization-denial text surfaced via Illuminate\Auth\Access\Response::deny()
// from Policy classes and the shared lock/lifecycle helpers they call into.
// This becomes the 403 response's "message" field, so it's user-facing.
return [

    'project' => [
        'locked' => 'Ce projet est au statut :status et est désormais en lecture seule.',
        'must_be_active' => 'Ce projet doit être actif avant de pouvoir enregistrer ceci.',
        'needs_committee_for_allocations' => "Ce projet a besoin d'un comité affecté avant de pouvoir recevoir des allocations de fonds.",
        'only_funding_ready_can_start' => 'Seuls les projets prêts pour financement peuvent être démarrés.',
        'only_active_can_close' => 'Seuls les projets actifs peuvent être clôturés.',
    ],

    'donation' => [
        'already_decided_edit' => 'Ce don a déjà fait l\'objet d\'une décision et ne peut plus être modifié.',
        'already_decided_delete' => 'Ce don a déjà fait l\'objet d\'une décision et ne peut plus être supprimé.',
        'receipt_locked' => 'Le reçu de ce don ne peut plus être remplacé.',
        'only_draft_or_rejected_receipt_can_submit' => 'Seuls les dons en brouillon, ou les dons rejetés pour un problème de reçu, peuvent être soumis pour approbation.',
        'only_pending_can_approve' => 'Seuls les dons en attente peuvent être approuvés.',
        'cannot_approve_own' => 'Vous ne pouvez pas approuver un don que vous avez enregistré.',
        'only_pending_can_reject' => 'Seuls les dons en attente peuvent être rejetés.',
        'cannot_reject_own' => 'Vous ne pouvez pas rejeter un don que vous avez enregistré.',
    ],

    'expense' => [
        'already_decided_edit' => 'Cette dépense a déjà fait l\'objet d\'une décision et ne peut plus être modifiée.',
        'already_decided_delete' => 'Cette dépense a déjà fait l\'objet d\'une décision et ne peut plus être supprimée.',
        'invoice_locked' => 'La facture de cette dépense ne peut plus être remplacée.',
        'only_draft_or_rejected_invoice_can_submit' => 'Seules les dépenses en brouillon, ou les dépenses rejetées pour un problème de facture, peuvent être soumises pour approbation.',
        'only_pending_can_approve' => 'Seules les dépenses en attente peuvent être approuvées.',
        'cannot_approve_own' => 'Vous ne pouvez pas approuver une dépense que vous avez créée.',
        'only_pending_can_reject' => 'Seules les dépenses en attente peuvent être rejetées.',
        'cannot_reject_own' => 'Vous ne pouvez pas rejeter une dépense que vous avez créée.',
        'only_approved_can_mark_paid' => 'Seules les dépenses approuvées peuvent être marquées comme payées.',
    ],

    'project_deletion_request' => [
        'only_pending_can_review' => 'Seules les demandes de suppression en attente peuvent être examinées.',
    ],

    'phase_request' => [
        'only_pending_can_review' => 'Seules les demandes de changement de phase en attente peuvent être examinées.',
    ],

];
