# Documentation API GSB Reservation

Version: 2.0.0

## Introduction

L'API GSB Reservation est une API RESTful permettant de gerer les reservations de salles de reunion.

### URL de base

```
http://localhost/gsb-reservation/api/
```

### Format des reponses

Toutes les reponses sont au format JSON avec l'encodage UTF-8.

### Authentification

L'API utilise des sessions PHP. Apres connexion, un cookie de session est envoye et doit etre inclus dans les requetes subsequentes.

### Codes de reponse HTTP

| Code | Description |
|------|-------------|
| 200 | Succes |
| 201 | Creation reussie |
| 400 | Requete invalide |
| 401 | Non authentifie |
| 403 | Acces refuse |
| 404 | Ressource non trouvee |
| 405 | Methode non autorisee |
| 429 | Trop de requetes (rate limit) |
| 500 | Erreur serveur |

---

## Authentification

### POST /auth.php?action=login

Connexion utilisateur.

**Corps de la requete :**

```json
{
  "email": "admin@gsb.local",
  "password": "Admin123!@#"
}
```

**Reponse succes (200) :**

```json
{
  "success": true,
  "user": {
    "id": 1,
    "nom": "Dupont",
    "prenom": "Jean",
    "email": "admin@gsb.local",
    "role": "Admin"
  },
  "csrf_token": "abc123..."
}
```

**Reponse erreur (401) :**

```json
{
  "error": "Email ou mot de passe incorrect"
}
```

**Reponse rate limit (429) :**

```json
{
  "error": "Trop de tentatives. Reessayez dans 120 secondes.",
  "retry_after": 120
}
```

---

### POST /auth.php?action=register

Inscription d'un nouvel utilisateur.

**Corps de la requete :**

```json
{
  "nom": "Doe",
  "prenom": "John",
  "email": "john.doe@gsb.local",
  "password": "MonMotDePasse123!@#"
}
```

**Reponse succes (201) :**

```json
{
  "success": true,
  "user": {
    "id": 6,
    "nom": "Doe",
    "prenom": "John",
    "email": "john.doe@gsb.local",
    "role": "Employe"
  },
  "csrf_token": "abc123..."
}
```

**Regles mot de passe :**
- Minimum 12 caracteres
- Au moins une majuscule
- Au moins une minuscule
- Au moins un chiffre
- Au moins un caractere special

---

### POST /auth.php?action=logout

Deconnexion utilisateur.

**Reponse succes (200) :**

```json
{
  "success": true,
  "message": "Deconnexion reussie"
}
```

---

### GET /auth.php?action=session

Verifier la session active.

**Reponse authentifie (200) :**

```json
{
  "authenticated": true,
  "user": {
    "id": 1,
    "nom": "Dupont",
    "prenom": "Jean",
    "email": "admin@gsb.local",
    "role": "Admin"
  },
  "csrf_token": "abc123..."
}
```

**Reponse non authentifie (200) :**

```json
{
  "authenticated": false
}
```

---

### GET /auth.php?action=password-rules

Obtenir les regles de mot de passe.

**Reponse (200) :**

```json
{
  "minLength": 12,
  "requireUppercase": true,
  "requireLowercase": true,
  "requireNumber": true,
  "requireSpecial": true
}
```

---

## Salles

### GET /rooms.php

Liste toutes les salles.

**Reponse (200) :**

```json
[
  {
    "id": 1,
    "name": "Salle Einstein",
    "description": "Etage 1 - Salle de reunion moderne",
    "capacity": 8,
    "status": "available",
    "floor": 1,
    "equipment": ["WiFi", "Videoprojecteur", "Tableau blanc"],
    "created_at": "2024-01-15 10:00:00",
    "updated_at": "2024-01-15 10:00:00"
  }
]
```

**Statuts possibles :**
- `available` : Disponible
- `occupied` : Occupee
- `maintenance` : En maintenance

---

### GET /rooms.php?id={id}

Recuperer une salle par son ID.

**Reponse (200) :**

```json
{
  "id": 1,
  "name": "Salle Einstein",
  "description": "Etage 1 - Salle de reunion moderne",
  "capacity": 8,
  "status": "available",
  "floor": 1,
  "equipment": ["WiFi", "Videoprojecteur"],
  "created_at": "2024-01-15 10:00:00",
  "updated_at": "2024-01-15 10:00:00"
}
```

---

### POST /rooms.php

Creer une nouvelle salle (Admin requis).

**Corps de la requete :**

```json
{
  "name": "Salle Marie Curie",
  "capacity": 10,
  "floor": 2,
  "description": "Nouvelle salle de reunion",
  "status": "available",
  "equipment": ["WiFi", "Videoprojecteur"]
}
```

**Reponse succes (201) :**

```json
{
  "success": true,
  "id": 9,
  "message": "Salle creee avec succes"
}
```

---

### PUT /rooms.php

Mettre a jour une salle (Admin requis).

**Corps de la requete :**

```json
{
  "id": 1,
  "name": "Salle Einstein v2",
  "capacity": 12,
  "status": "maintenance",
  "equipment": ["WiFi", "Videoprojecteur", "Visioconference"]
}
```

**Reponse succes (200) :**

```json
{
  "success": true,
  "message": "Salle mise a jour"
}
```

---

### DELETE /rooms.php

Supprimer une salle (Admin requis).

**Corps de la requete :**

```json
{
  "id": 1
}
```

**Reponse succes (200) :**

```json
{
  "success": true,
  "message": "Salle supprimee"
}
```

---

## Reservations

### GET /bookings.php

Liste les reservations.

**Parametres de requete :**

| Parametre | Type | Description |
|-----------|------|-------------|
| mine | boolean | Mes reservations uniquement |
| room_id | integer | Filtrer par salle |
| date | string | Filtrer par date (YYYY-MM-DD) |
| start_date | string | Date de debut de periode |
| end_date | string | Date de fin de periode |
| status | string | Filtrer par statut (confirmed, pending, cancelled) |
| page | integer | Numero de page |
| limit | integer | Nombre de resultats (max 100) |

**Exemple :**

```
GET /bookings.php?mine=true&status=confirmed&page=1&limit=20
```

**Reponse (200) :**

```json
[
  {
    "id": 1,
    "ref": "RES-001",
    "roomId": 1,
    "roomName": "Salle Einstein",
    "date": "2024-01-15",
    "start": "09:00",
    "end": "10:30",
    "subject": "Reunion equipe developpement",
    "status": "confirmed",
    "user": "Jean Dupont",
    "userEmail": "admin@gsb.local",
    "userId": 1,
    "options": ["Visioconference", "Machine a cafe"],
    "created_at": "2024-01-15 08:00:00",
    "updated_at": "2024-01-15 08:00:00"
  }
]
```

---

### GET /bookings.php?id={id}

Recuperer une reservation par son ID.

---

### POST /bookings.php

Creer une nouvelle reservation.

**Corps de la requete :**

```json
{
  "roomId": 1,
  "date": "2024-01-20",
  "start": "14:00",
  "end": "16:00",
  "subject": "Reunion projet X",
  "options": ["Visioconference", "Machine a cafe"]
}
```

**Reponse succes (201) :**

```json
{
  "success": true,
  "id": 10,
  "ref": "RES-010",
  "status": "pending",
  "message": "Reservation creee avec succes"
}
```

**Notes :**
- Les reservations des admins sont automatiquement confirmees
- Les autres utilisateurs ont un statut "En attente"
- Les conflits de reservation sont automatiquement detectes

---

### PUT /bookings.php

Mettre a jour une reservation.

**Corps de la requete :**

```json
{
  "id": 1,
  "status": "confirmed",
  "subject": "Nouveau titre"
}
```

**Reponse succes (200) :**

```json
{
  "success": true,
  "message": "Reservation mise a jour"
}
```

---

### DELETE /bookings.php

Annuler une reservation.

**Corps de la requete :**

```json
{
  "id": 1
}
```

**Reponse succes (200) :**

```json
{
  "success": true,
  "message": "Reservation annulee"
}
```

---

## Utilisateurs

### GET /users.php

Liste tous les utilisateurs (Admin requis).

**Reponse (200) :**

```json
[
  {
    "id": 1,
    "name": "Jean Dupont",
    "nom": "Dupont",
    "prenom": "Jean",
    "email": "admin@gsb.local",
    "role": "Admin",
    "created_at": "2024-01-15 10:00:00"
  }
]
```

---

### GET /users.php?id={id}

Recuperer un utilisateur par son ID.

---

### POST /users.php

Creer un nouvel utilisateur (Admin requis).

**Corps de la requete :**

```json
{
  "nom": "Nouveau",
  "prenom": "Utilisateur",
  "email": "nouveau@gsb.local",
  "password": "MotDePasse123!@#",
  "role": "Employe"
}
```

---

### PUT /users.php

Mettre a jour un utilisateur.

**Corps de la requete :**

```json
{
  "id": 1,
  "nom": "Dupont",
  "prenom": "Jean-Pierre",
  "email": "jp.dupont@gsb.local",
  "role": "Admin"
}
```

---

### DELETE /users.php

Supprimer un utilisateur (Admin requis).

**Corps de la requete :**

```json
{
  "id": 5
}
```

---

## Options/Services

### GET /options.php

Liste toutes les options disponibles.

**Reponse (200) :**

```json
[
  {
    "id": 1,
    "name": "Visioconference"
  },
  {
    "id": 2,
    "name": "Videoprojecteur"
  }
]
```

---

### POST /options.php

Creer une nouvelle option (Admin requis).

**Corps de la requete :**

```json
{
  "name": "Nouvelle option"
}
```

---

### PUT /options.php

Mettre a jour une option (Admin requis).

**Corps de la requete :**

```json
{
  "id": 1,
  "name": "Nouveau nom"
}
```

---

### DELETE /options.php

Supprimer une option (Admin requis).

**Corps de la requete :**

```json
{
  "id": 1
}
```

---

## Exemples cURL

### Connexion

```bash
curl -X POST http://localhost/gsb-reservation/api/auth.php?action=login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@gsb.local","password":"Admin123!@#"}' \
  -c cookies.txt
```

### Liste des salles

```bash
curl http://localhost/gsb-reservation/api/rooms.php \
  -b cookies.txt
```

### Creer une reservation

```bash
curl -X POST http://localhost/gsb-reservation/api/bookings.php \
  -H "Content-Type: application/json" \
  -d '{"roomId":1,"date":"2024-01-20","start":"14:00","end":"16:00","subject":"Reunion"}' \
  -b cookies.txt
```

---

## Rate Limiting

L'API implemente un rate limiting pour prevenir les abus :

- **Login** : 5 tentatives par minute par IP
- **Register** : 3 tentatives par 5 minutes par IP
- **Forgot Password** : 3 tentatives par 10 minutes par IP

En cas de depassement, une erreur 429 est retournee avec le temps d'attente.

---

## Securite

### CORS

Les origines autorisees sont configurees via la variable `CORS_ALLOWED_ORIGINS` dans le fichier `.env`.

### CSRF

Un token CSRF est retourne lors de la connexion. Il doit etre inclus dans l'en-tete `X-CSRF-Token` pour les operations sensibles.

### Sessions

- Les sessions expirent apres 120 minutes (configurable)
- L'ID de session est regenere periodiquement
- Les cookies sont marques HttpOnly et SameSite

---

## Changelog

### Version 2.0.0

- Ajout du rate limiting
- Ajout de la validation de mot de passe renforcee
- Ajout du systeme de logging
- Ajout des timestamps sur toutes les tables
- Ajout de la gestion des equipements en BDD
- Ajout des index pour les performances
- Securisation du script init.php
- Configuration via fichier .env
