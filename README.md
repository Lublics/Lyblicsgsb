# GSB Reservation

Systeme de gestion de reservations de salles de reunion pour GSB (Galaxy Swiss Bourdin).

## Fonctionnalites

- **Gestion des salles** : Creation, modification, suppression de salles avec equipements
- **Reservations** : Reservation de salles avec gestion des conflits
- **Calendrier** : Vue planning hebdomadaire des reservations
- **Utilisateurs** : Gestion des utilisateurs avec roles (Admin, Delegue, Employe)
- **Options/Services** : Services additionnels pour les reservations
- **Notifications** : Systeme de notifications pour les reservations

## Prerequis

- PHP 8.2 ou superieur
- MySQL 8.0 ou superieur
- Serveur web (Apache/Nginx)
- Extension PHP PDO MySQL

## Installation

### 1. Cloner le projet

```bash
git clone <url-du-repo>
cd gsb-reservation
```

### 2. Configuration

Copier le fichier d'exemple et configurer les variables d'environnement :

```bash
cp .env.example .env
```

Editer le fichier `.env` avec vos parametres :

```env
# Base de donnees
DB_HOST=localhost
DB_NAME=GSB_Reservation
DB_USER=root
DB_PASS=votre_mot_de_passe

# Application
APP_ENV=development
APP_DEBUG=true
APP_URL=http://localhost/gsb-reservation

# Securite
SESSION_LIFETIME=120
CORS_ALLOWED_ORIGINS=http://localhost

# Rate Limiting
RATE_LIMIT_REQUESTS=5
RATE_LIMIT_WINDOW=60

# Logging
LOG_LEVEL=debug
LOG_PATH=logs/
```

### 3. Initialisation de la base de donnees

**Option A - Via CLI (recommande)** :

```bash
php init.php
```

**Option B - Via navigateur** :

Acceder a `http://localhost/gsb-reservation/init.php?token=gsb_init_secret_2024`

> Note: Configurez `INIT_TOKEN` dans votre `.env` pour securiser l'acces.

### 4. Lancer l'application

Acceder a `http://localhost/gsb-reservation/`

## Comptes de demonstration

| Role | Email | Mot de passe |
|------|-------|--------------|
| Admin | admin@gsb.local | Admin123!@# |
| Delegue | sophie.martin@gsb.local | Admin123!@# |
| Employe | thomas.durand@gsb.local | Admin123!@# |

## Structure du projet

```
gsb-reservation/
├── api/                    # Endpoints API REST
│   ├── auth.php           # Authentification
│   ├── bookings.php       # Reservations
│   ├── options.php        # Options/Services
│   ├── rooms.php          # Salles
│   └── users.php          # Utilisateurs
├── config/
│   └── database.php       # Configuration et classes utilitaires
├── docs/
│   └── API.md             # Documentation API
├── logs/                   # Fichiers de logs (genere automatiquement)
├── .env                    # Configuration locale (non versionnee)
├── .env.example           # Exemple de configuration
├── .gitignore
├── composer.json
├── index.html             # Application frontend
├── init.php               # Script d'initialisation BDD
└── README.md
```

## API

L'API REST est documentee dans [docs/API.md](docs/API.md).

### Endpoints principaux

| Methode | Endpoint | Description |
|---------|----------|-------------|
| POST | `/api/auth.php?action=login` | Connexion |
| POST | `/api/auth.php?action=register` | Inscription |
| GET | `/api/rooms.php` | Liste des salles |
| POST | `/api/bookings.php` | Creer une reservation |
| GET | `/api/bookings.php` | Liste des reservations |

## Securite

### Fonctionnalites implementees

- **Authentification securisee** : Sessions PHP avec regeneration d'ID
- **Protection CSRF** : Tokens CSRF pour les operations sensibles
- **Rate Limiting** : Protection contre les attaques par force brute
- **Validation des mots de passe** : Minimum 12 caracteres avec complexite
- **Sanitization** : Nettoyage des entrees utilisateur
- **CORS configure** : Origines autorisees configurables
- **Cookies securises** : HttpOnly, SameSite, Secure (HTTPS)
- **Logging/Audit** : Journalisation des actions critiques

### Bonnes pratiques

- Ne jamais commiter le fichier `.env`
- Utiliser des mots de passe forts pour la base de donnees
- Activer HTTPS en production
- Configurer `APP_ENV=production` en production
- Changer le token d'initialisation `INIT_TOKEN`

## Roles utilisateurs

| Role | Permissions |
|------|-------------|
| Admin | Acces complet, gestion des utilisateurs et salles |
| Delegue | Reservations, visualisation |
| Employe | Reservations personnelles uniquement |

## Logs

Les logs sont generes dans le dossier `logs/` :

- `YYYY-MM-DD.log` : Logs journaliers
- `audit_YYYY-MM.log` : Audit des actions utilisateurs

## Developpement

### Lancer en mode developpement

1. Configurer `APP_ENV=development` dans `.env`
2. Configurer `APP_DEBUG=true`
3. Les erreurs detaillees seront affichees

### Tests

```bash
# Installer PHPUnit
composer install

# Lancer les tests
./vendor/bin/phpunit tests/
```

## Contribution

1. Fork le projet
2. Creer une branche (`git checkout -b feature/nouvelle-fonctionnalite`)
3. Commiter les changements (`git commit -am 'Ajout nouvelle fonctionnalite'`)
4. Push la branche (`git push origin feature/nouvelle-fonctionnalite`)
5. Creer une Pull Request

## Licence

Projet interne GSB - Tous droits reserves.

## Support

Pour toute question ou probleme, contacter l'equipe IT GSB.
