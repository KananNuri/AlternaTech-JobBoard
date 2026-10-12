# AlternaTech Job Board — Frontend API Contract

## 1. Objectif

Ce document décrit les contrats API que le frontend doit respecter. Les réponses et champs ci-dessous sont basés sur les handlers PHP examinés.

**Point essentiel :** ne pas inventer de routes, de champs JSON ou de formats de réponse. Le frontend doit s'adapter au backend existant.

## 2. Environnement

- URL locale : `http://127.0.0.1:8080`
- Entrée du serveur : `api/integration-router.php`
- Stack backend : PHP, PDO, MySQL
- Corps des requêtes d'écriture : JSON
- Cookie de session : `alternatech_login`

Lancer le serveur depuis la racine du dépôt :

```powershell
php -S 127.0.0.1:8080 -t public api/integration-router.php
```

## 3. Authentification et session

### Obtenir un jeton CSRF

`GET /api/auth/csrf`

Réponse `200` :

```json
{
  "csrf_token": "..."
}
```

Pour toute écriture protégée, envoyer le jeton dans l'en-tête `X-CSRF-Token`. Une candidature invitée exige également ce jeton.

### Consulter la session

`GET /api/auth/session`

Réponse `200` :

```json
{
  "user": null,
  "csrf_token": null
}
```

Cet exemple représente une session invitée. Pour un utilisateur connecté, `user` contient les données du compte et `csrf_token` le jeton associé à la session.

### Inscription

`POST /api/auth/register`

Champs JSON : `firstname`, `lastname`, `email`, `phone`, `password`.

Réponse `201` :

```json
{
  "user": {},
  "csrf_token": "...",
  "redirect": "/"
}
```

Le rôle est déterminé par le backend. L'inscription publique ne doit pas permettre de choisir le rôle administrateur.

### Connexion

`POST /api/auth/login`

Champs JSON : `email`, `password`.

Réponse `200` :

```json
{
  "user": {},
  "csrf_token": "...",
  "redirect": "/"
}
```

Pour un administrateur, la destination de redirection est `/admin.php`. Des identifiants incorrects produisent `401`. Une limitation des tentatives peut produire `429`.

### Déconnexion

`POST /api/auth/logout`

Session authentifiée et en-tête `X-CSRF-Token` requis.

Réponse `200` :

```json
{
  "message": "Signed out"
}
```

### Compte

- `GET /api/account` : renvoie `{ "user": ... }`.
- `PATCH /api/account` : modifie les champs autorisés.

Champs modifiables : `firstname`, `lastname`, `email`, `phone`, `password`, `current_password`.

Les écritures exigent un jeton CSRF. La modification de l'adresse e-mail ou du mot de passe exige le mot de passe local actuel.

## 4. Catalogue public des annonces

### Liste paginée

`GET /api/catalog/ads`

Paramètres :
- `page` : numéro de page, par défaut `1`.
- `per_page` : éléments par page, par défaut `20`, maximum `100`.
- `q` : recherche dans le titre, la description ou le nom de l'entreprise.
- `location` : filtre sur le lieu.
- `category_id` : identifiant de catégorie.

Réponse `200` :

```json
{
  "items": [],
  "page": 1,
  "per_page": 20,
  "total": 0,
  "pages": 1
}
```

Chaque annonce contient les champs de la table `advertisements` ainsi que `company` et `category`. La catégorie peut être `null`.

### Catégories

`GET /api/categories`

Réponse `200` : tableau JSON d'objets `{ "id": ..., "name": ... }`.

### Annonces d'une entreprise

`GET /api/companies/{id}/ads`

Cette route utilise le catalogue paginé et renvoie la même structure que `/api/catalog/ads`, filtrée sur l'entreprise. Une entreprise inexistante produit `404`.

### Routes historiques des annonces

- `GET /api/ads` : renvoie directement un tableau JSON d'annonces.
- `GET /api/ads/{id}` : renvoie directement un objet annonce.

Une annonce inexistante produit `404`.

**Attention :** `/api/ads` ne renvoie pas l'objet paginé `{ "items": [...] }`.

## 5. Candidatures

### Déposer une candidature

`POST /api/ads/{id}/applications`

Cette route utilise `api/integration/apply.php`.

Les visiteurs et les utilisateurs connectés peuvent postuler.

Pour un visiteur, champs JSON :
- `firstname` : requis, maximum 100 caractères.
- `lastname` : requis, maximum 100 caractères.
- `email` : requis, maximum 150 caractères et adresse valide.
- `phone` : facultatif, maximum 30 caractères.
- `message` : facultatif, maximum 20 000 caractères.
- `advertisement_id` : facultatif ; s'il est fourni, il doit correspondre à l'identifiant dans l'URL.

Pour un utilisateur connecté, l'identité est récupérée depuis la session. Il n'est pas nécessaire de renvoyer les coordonnées du candidat.

Obtenir un jeton CSRF avec `GET /api/auth/csrf` avant l'envoi si nécessaire.

Réponse `201` :

```json
{
  "id": 12,
  "person_id": 5,
  "advertisement_id": 3,
  "message": "Exemple de message",
  "status": "pending"
}
```

Les valeurs de cet exemple sont fictives.

Erreurs notables : `403` pour un jeton CSRF invalide, `404` si l'annonce n'existe pas, `422` pour des champs invalides. Un conflit de base de données peut produire `409`.

### Consulter ses candidatures

`GET /api/applications/mine`

Authentification requise.

Réponse `200` :

```json
{
  "applications": [
    {
      "id": 12,
      "advertisement_id": 3,
      "message": "Exemple de message",
      "application_date": "2026-10-12 09:30:00",
      "status": "pending",
      "advertisement_title": "Exemple d'annonce",
      "location": "Nantes",
      "company": "Exemple d'entreprise"
    }
  ]
}
```

Les valeurs sont illustratives. Le backend identifie le candidat grâce à la session ; le frontend ne doit pas fournir lui-même `person_id`.

## 6. Espace recruteur

### Entreprises attribuées au recruteur

`GET /api/companies/mine`

Authentification requise.

Réponse `200` : tableau JSON d'entreprises contenant `id` et `name`. Seules les entreprises associées au profil avec le rôle `recruiter` sont renvoyées.

### Annonces gérées par le recruteur

`GET /api/companies/mine/ads`

Authentification requise.

Réponse `200` : tableau JSON contenant les champs suivants pour chaque annonce :

`id`, `title`, `short_description`, `description`, `salary`, `location`, `working_time`, `company_id`, `category_id`, `company`, `category`.

Cette route ne prévoit pas de pagination.

### Candidatures reçues par les entreprises du recruteur

`GET /api/companies/mine/applications`

Authentification requise.

Réponse `200` :

```json
{
  "applications": [
    {
      "application_id": 12,
      "advertisement_id": 3,
      "candidate_id": 5,
      "message": "Exemple de message",
      "application_date": "2026-10-12 09:30:00",
      "status": "pending",
      "advertisement_title": "Exemple d'annonce",
      "company_id": 1,
      "company": "Exemple d'entreprise"
    }
  ]
}
```

Cette route renvoie un objet contenant `applications`. Elle ne prévoit pas de pagination.

### Modifier le statut d'une candidature

`PATCH /api/companies/mine/applications`

Session authentifiée et en-tête `X-CSRF-Token` requis.

Corps JSON :

```json
{
  "application_id": 12,
  "status": "reviewed"
}
```

Statuts autorisés :
- `pending`
- `reviewed`
- `accepted`
- `rejected`

Réponse `200` :

```json
{
  "application_id": 12,
  "status": "reviewed"
}
```

Le recruteur doit être associé à l'entreprise de l'annonce concernée. Une candidature hors de son périmètre renvoie `404`.

### Liste paginée avec coordonnées des candidats

`GET /api/recruiter/applications`

Authentification requise. Paramètres `page` et `per_page`.

Réponse `200` : objet contenant `applications`, `page`, `pages` et `total`. Chaque élément contient notamment `application_id`, `advertisement_id`, `candidate_id`, `message`, `status`, `created_at`, `advertisement_title`, `company`, `firstname`, `lastname`, `email` et `phone`.

Cette route a un contrat différent de `/api/companies/mine/applications`. Ne pas les considérer comme interchangeables.

## 7. Gestion des annonces

Les routes suivantes utilisent le handler historique `api/ads/index.php` :

- `POST /api/ads` : création.
- `PUT /api/ads/{id}` : remplacement des champs modifiables.
- `PATCH /api/ads/{id}` : modification partielle.
- `DELETE /api/ads/{id}` : suppression.

Les écritures exigent une session, un jeton CSRF et un rôle `recruiter` pour l'entreprise concernée. Un recruteur ne peut pas déplacer une annonce existante vers une autre entreprise via une modification.

Réponses :
- Création : `201`, objet annonce.
- Modification : `200`, objet annonce.
- Suppression : `204`, sans corps.

Les erreurs de validation renvoient `422`. Un accès non autorisé à l'entreprise renvoie `403`.

## 8. Administration

Toutes les routes `/api/admin` exigent une session administrateur. Les écritures exigent également `X-CSRF-Token`.

### Métadonnées

`GET /api/admin`

Réponse `200` : objet `{ "resources": ... }` décrivant les ressources et leurs champs modifiables.

### Routes CRUD

- `GET /api/admin/{table}` : liste paginée.
- `POST /api/admin/{table}` : création.
- `GET /api/admin/{table}/{key}` : lecture d'un enregistrement.
- `PUT /api/admin/{table}/{key}` : remplacement des champs modifiables.
- `PATCH /api/admin/{table}/{key}` : modification partielle.
- `DELETE /api/admin/{table}/{key}` : suppression.

Tables prises en charge :
`users`, `people`, `companies`, `categories`, `advertisements`, `applications`, `company_members`, `google_identities`.

Liste : `200` avec `items`, `page`, `per_page`, `total`, `pages`.

Création : `201` avec `{ "key": ..., "message": "Record saved" }`.

Modification : `200` avec `{ "key": ..., "message": "Record saved" }`.

Suppression : `204`, sans corps.

Clés particulières :
- `company_members` : `company_id:person_id`.
- `google_identities` : sujet Google, encodé pour l'URL.

Les réponses utilisateurs ne révèlent pas les empreintes de mots de passe. Le backend empêche la suppression ou la rétrogradation de l'administrateur actif et protège le dernier administrateur. Les profils liés à un compte doivent être gérés via ce compte.

## 9. Comportements communs et erreurs

Pour les routes utilisant `api/integration/common.php` :

- `400` : JSON invalide.
- `401` : authentification requise ou expirée.
- `403` : jeton CSRF invalide ou accès refusé.
- `405` : méthode HTTP non autorisée.
- `409` : conflit d'intégrité en base.
- `413` : corps trop volumineux.
- `415` : type de contenu incorrect.
- `422` : champs ou objet JSON invalides.
- `503` : service indisponible.

Les erreurs renvoient généralement un objet JSON contenant `error`. Certaines routes historiques ont leurs propres messages et traitements : le frontend ne doit pas supposer que tous les endpoints ont exactement le même format d'erreur.

Un `204` ne contient aucun JSON : le frontend ne doit pas essayer de parser son corps.

## 10. Identifiants et règles d'intégration

- `users.id` est l'identifiant du compte.
- `people.id` est l'identifiant du profil.
- Ces identifiants peuvent être différents.
- La session intégrée stocke `account_id` et l'identifiant de profil historique `user_id`.
- Les appartenances aux entreprises et les candidatures utilisent les identifiants de profil.
- Les profils invités ne sont pas automatiquement associés à un compte existant sur la seule base de l'adresse e-mail.
- Ne pas coder en dur les identifiants de base, les comptes de test ou les rôles.
- Consulter la session pour connaître l'état d'authentification.
- Ne pas inventer de routes, de champs ou de formats de réponse.
- Conserver `api/integration-router.php` comme point d'entrée du serveur.

## 11. Différences à ne pas oublier

1. `/api/catalog/ads` renvoie un objet paginé ; `/api/ads` renvoie un tableau.
2. `/api/companies/{id}/ads` utilise le catalogue paginé ; `/api/companies/mine/ads` renvoie un tableau brut.
3. `/api/companies/mine/applications` renvoie les candidatures sans pagination ; `/api/recruiter/applications` fournit les coordonnées des candidats et une pagination.
4. `POST /api/ads/{id}/applications` accepte les visiteurs dans le routeur intégré ; le handler historique de candidature attend un profil authentifié.
5. Le frontend doit respecter les contrats existants et ne pas inventer de comportement côté serveur.