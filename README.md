# Système de Gestion des Abonnés et Projets d'une Association

## 1. Présentation du projet

### 1.1 Contexte
Une association a besoin d'un système pour gérer :
- ses **membres/abonnés** (adhésions, cotisations, renouvellements)
- ses **projets** (suivi, participants, avancement, budget)
- la **communication** interne et externe

### 1.2 Objectifs
- Centraliser la gestion des adhérents et de leurs cotisations
- Suivre l'état d'avancement des projets de l'association
- Faciliter la communication (notifications, emails)
- Générer des rapports et statistiques (financiers, participation)
- Offrir un accès différencié selon les rôles (admin, membre, gestionnaire de projet)

### 1.3 Périmètre 
- Gestion des abonnés : oui
- Gestion des projets : oui
- Paiement en ligne : à confirmer (Stripe/PayPal ou suivi manuel)
- Application mobile : optionnel (v2)

---

## 2. Acteurs et rôles

| Rôle | Permissions principales |
|---|---|
| **Administrateur** | Gestion complète (utilisateurs, rôles, paramètres, finances) |
| **Gestionnaire de projet** | Création/suivi des projets, gestion des équipes projet |
| **Membre/Abonné** | Consultation profil, paiement cotisation, inscription aux projets |
| **Visiteur** | Consultation publique (page association, projets publics) |

---

## 3. Besoins fonctionnels (Modules à construire)

### Module 1 — Authentification & gestion des utilisateurs
- Inscription / connexion (email + mot de passe, éventuellement OAuth Google)
- Gestion des rôles et permissions (RBAC)
- Réinitialisation de mot de passe
- Profil utilisateur (photo, coordonnées, historique)

### Module 2 — Gestion des abonnés (Membres)
- Fiche adhérent (infos personnelles, type d'adhésion)
- Types de cotisation (mensuelle, annuelle, étudiant, honoraire...)
- Suivi des paiements et échéances
- Historique des adhésions
- Relances automatiques (rappel avant expiration)
- Export liste des membres (PDF/Excel)

### Module 3 — Gestion des projets
- Création de projet (nom, description, dates, budget, statut)
- Affectation de membres/équipe à un projet
- Suivi d'avancement (tâches, jalons — type mini Kanban)
- Suivi budgétaire (dépenses vs budget alloué)
- Documents liés au projet (upload de fichiers)
- Historique / journal d'activité

### Module 4 — Paiements & finances
- Enregistrement des cotisations payées
- Intégration paiement en ligne (Stripe/PayPal) — optionnel
- Génération de reçus/factures (PDF)
- Tableau de bord financier (revenus, dépenses par projet)

### Module 5 — Communication & notifications
- Notifications par email (confirmation, rappel cotisation, actualité projet)
- Système de messagerie interne (optionnel)
- Newsletter / annonces aux membres

### Module 6 — Tableau de bord & rapports (Reporting)
- Statistiques membres (nombre d'adhérents, évolution)
- Statistiques projets (en cours, terminés, budget consommé)
- Export de rapports (PDF/Excel)
- Graphiques (charts) de visualisation

### Module 7 — Administration
- Gestion des paramètres de l'association
- Gestion des rôles et droits d'accès
- Logs et audit des actions sensibles

---

## 4. Besoins non-fonctionnels

- **Sécurité** : authentification sécurisée (JWT/session), hachage des mots de passe (bcrypt), protection CSRF/XSS, permissions par rôle
- **Performance** : pagination des listes, mise en cache si besoin
- **Scalabilité** : architecture modulaire (facilite l'ajout futur de modules)
- **Disponibilité** : sauvegardes régulières de la base de données
- **Ergonomie** : interface responsive (mobile/desktop)
- **Conformité** : RGPD si association basée en UE (gestion des données personnelles)
- **Maintenabilité** : code documenté, tests automatisés, conventions de codage

---

## 5. Architecture technique

### 5.1 Vue d'ensemble (architecture 3-tiers)

```
┌─────────────────────────────────────────────────────┐
│                   CLIENT (Frontend)                  │
│        React / Vue.js / Angular (SPA)                │
│        + Responsive design (Tailwind/Bootstrap)      │
└───────────────────────┬───────────────────────────────┘
                         │ HTTPS / REST API (JSON)
                         │
┌───────────────────────▼───────────────────────────────┐
│                  SERVEUR (Backend)                    │
│   Node.js/Express | Django/Laravel | Spring Boot       │
│   - API REST                                           │
│   - Authentification (JWT)                             │
│   - Logique métier (services)                          │
│   - Middlewares (validation, sécurité)                 │
└───────────────────────┬───────────────────────────────┘
                         │
┌───────────────────────▼───────────────────────────────┐
│               BASE DE DONNÉES                         │
│         PostgreSQL / MySQL (relationnel)               │
│         + éventuellement Redis (cache/sessions)        │
└─────────────────────────────────────────────────────────┘

Services externes : Envoi d'emails (SendGrid/Mailgun),
Paiement (Stripe/PayPal), Stockage fichiers (AWS S3/local)
```

### 5.2 Stack retenue : Laravel (backend) + React (frontend)

**Pourquoi ce choix :**
- Laravel fournit auth, ORM, validation, migrations, mail, queues "out of the box" → moins de temps perdu à assembler des libs pour un projet de stage avec deadline.
- Eloquent ORM colle naturellement au modèle de données (relations `hasMany`, `belongsToMany` pour Projet↔Membre).
- React côté front permet une SPA réactive, découplée de l'API, avec un large écosystème de composants.
- Communication via API REST JSON — architecture découplée, chacun peut évoluer indépendamment.

### 5.3 Architecture logicielle backend (Laravel — couches)

```
app/
├── Http/
│   ├── Controllers/
│   │   └── Api/
│   │       ├── AuthController.php
│   │       ├── MembreController.php
│   │       ├── CotisationController.php
│   │       ├── ProjetController.php
│   │       └── RapportController.php
│   ├── Middleware/          # Auth, rôles (custom middleware)
│   ├── Requests/            # Form Requests (validation)
│   └── Resources/           # API Resources (formatage JSON des réponses)
├── Models/
│   ├── User.php
│   ├── Membre.php
│   ├── Cotisation.php
│   ├── Projet.php
│   ├── ProjetMembre.php     # pivot
│   ├── Tache.php
│   └── Document.php
├── Policies/                # Autorisations par rôle (Gate/Policy)
├── Services/                 # Logique métier complexe (ex: CotisationService)
├── Notifications/             # Emails/rappels (ex: RappelCotisation)
├── Jobs/                      # Tâches asynchrones (queue) : envoi d'emails, exports
└── Providers/

database/
├── migrations/                # Schéma versionné de la BDD
├── seeders/                   # Données de test
└── factories/                 # Génération de données factices (tests)

routes/
├── api.php                    # Toutes les routes API (préfixées /api)
└── web.php                    # (quasi inutilisé si SPA découplée)

tests/
├── Feature/                   # Tests d'intégration (endpoints API)
└── Unit/                      # Tests unitaires (services, helpers)
```

### 5.4 Architecture frontend (React)

```
src/
├── api/                # Client Axios + fonctions d'appel API par domaine
│   ├── axiosClient.js
│   ├── membres.api.js
│   ├── projets.api.js
│   └── auth.api.js
├── components/          # Composants réutilisables (UI)
│   ├── ui/              # Boutons, inputs, modals (design system)
│   └── layout/          # Navbar, Sidebar, Footer
├── features/             # Organisation par domaine métier
│   ├── auth/
│   ├── membres/
│   │   ├── MembresList.jsx
│   │   ├── MembreForm.jsx
│   │   └── membresSlice.js   # état (Redux/Zustand) si besoin
│   ├── projets/
│   └── dashboard/
├── hooks/                # Hooks custom (useAuth, useFetch...)
├── context/              # Context API (ex: AuthContext)
├── pages/                # Pages = assemblage de features + routing
├── routes/               # Configuration React Router (routes protégées par rôle)
├── utils/
└── App.jsx
```

### 5.5 Choix technologiques détaillés

| Composant | Choix |
|---|---|
| **Backend** | Laravel 11 (PHP 8.3) |
| **Frontend** | React 18 (+ Vite pour le build) |
| **Style** | Tailwind CSS |
| **Base de données** | MySQL ou PostgreSQL |
| **ORM** | Eloquent (natif Laravel) |
| **Authentification API** | Laravel Sanctum (tokens SPA) |
| **Autorisation par rôle** | Laravel Policies/Gates + package `spatie/laravel-permission` |
| **Validation** | Form Requests (Laravel) |
| **Requêtes HTTP (front)** | Axios |
| **Routing front** | React Router |
| **Gestion d'état** | Context API (léger) ou Zustand/Redux Toolkit (si état complexe) |
| **Emails** | Laravel Mail + Notifications (SMTP ou Mailgun/SendGrid) |
| **Files d'attente** | Laravel Queues (rappels, exports lourds) |
| **Paiement** | Stripe (via `laravel/cashier` si besoin d'abonnements récurrents) |
| **Stockage fichiers** | Laravel Storage (local en dev → S3 en prod) |
| **Génération PDF** | `barryvdh/laravel-dompdf` ou `spatie/laravel-pdf` |
| **Documentation API** | Swagger/OpenAPI via `darkaonline/l5-swagger`, ou Postman collection |
| **Tests backend** | PHPUnit / Pest |
| **Tests frontend** | Vitest + React Testing Library, Cypress (E2E) |
| **Déploiement** | Docker (Laravel Sail en dev) + Forge/Railway/VPS (OVH, DigitalOcean) |
| **Versioning** | Git + GitHub/GitLab |
| **CI/CD** | GitHub Actions (lint, tests, build) |

### 5.6 Communication Frontend ↔ Backend

- API REST JSON, préfixe `/api/v1/...`
- Authentification par **token Sanctum** (SPA authentication, cookies HttpOnly recommandés pour la sécurité)
- Format de réponse standardisé via **API Resources** Laravel :
```json
{
  "data": { "id": 1, "nom": "...", "email": "..." },
  "message": "Membre créé avec succès"
}
```
- Gestion centralisée des erreurs côté React (intercepteur Axios) pour afficher les messages de validation Laravel (422) proprement.

---

## 6. Modèle de données (schéma simplifié)

### Entités principales

**User**
- id, nom, prénom, email, mot_de_passe, rôle, date_création, statut

**Membre** (extension de User)
- id, user_id, type_adhésion, date_adhésion, date_expiration, statut_cotisation

**Cotisation**
- id, membre_id, montant, date_paiement, méthode_paiement, statut, période

**Projet**
- id, nom, description, date_début, date_fin, budget, statut, responsable_id

**ProjetMembre** (table de liaison many-to-many)
- id, projet_id, membre_id, rôle_dans_projet, date_affectation

**Tâche** (optionnel, pour suivi projet)
- id, projet_id, titre, description, statut, échéance, assigné_à

**Document**
- id, projet_id (ou membre_id), nom_fichier, url, date_upload

**Notification**
- id, user_id, type, contenu, lu, date_création

### Relations clés
- Un **Membre** peut participer à plusieurs **Projets** (many-to-many via ProjetMembre)
- Un **Membre** a plusieurs **Cotisations** (historique)
- Un **Projet** a plusieurs **Tâches** et **Documents**

---

## 7. Sécurité (points d'attention)

- Hachage des mots de passe (bcrypt, natif via `Hash::make()` dans Laravel)
- Validation stricte des entrées via **Form Requests** (protection injections SQL/XSS, Eloquent protège nativement des injections SQL)
- Contrôle d'accès par rôle via **Policies** + `spatie/laravel-permission` sur chaque endpoint
- Authentification API sécurisée avec **Sanctum** (cookies HttpOnly + protection CSRF native Laravel)
- HTTPS obligatoire en production
- Variables sensibles dans `.env` (jamais commit dans Git — vérifier `.gitignore`)
- Limitation du taux de requêtes (`throttle` middleware Laravel) sur login et endpoints sensibles
- Logs d'audit pour les actions sensibles (package `spatie/laravel-activitylog` recommandé)

---

## 8. Roadmap / Plan de développement suggéré

### Phase 1 — Cadrage (1-2 semaines)
- Recueil des besoins précis avec l'association
- Rédaction du cahier des charges détaillé
- Choix définitif de la stack technique
- Maquettes UI/UX (Figma)
- Conception du modèle de données (MCD/MLD)

### Phase 2 — Socle technique (1-2 semaines)
- Setup projet (repo Git, structure backend/frontend)
- Mise en place base de données + migrations
- Système d'authentification et gestion des rôles

### Phase 3 — Module Abonnés (2-3 semaines)
- CRUD membres
- Gestion cotisations
- Notifications de rappel

### Phase 4 — Module Projets (2-3 semaines)
- CRUD projets
- Affectation des membres
- Suivi d'avancement (tâches/Kanban)

### Phase 5 — Finances & Reporting (1-2 semaines)
- Tableau de bord
- Export PDF/Excel
- Statistiques et graphiques

### Phase 6 — Tests, sécurisation, déploiement (1-2 semaines)
- Tests unitaires/intégration
- Corrections de bugs
- Déploiement (staging puis production)
- Documentation technique et utilisateur

