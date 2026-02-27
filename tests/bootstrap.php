<?php
/**
 * PHPUnit Bootstrap
 *
 * Initialise l'environnement de test
 */

// Definir les constantes pour les tests
define('TESTING', true);
define('PROJECT_ROOT', dirname(__DIR__));

// Charger l'autoloader Composer si disponible
$autoloadPath = PROJECT_ROOT . '/vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
}

// Configurer les variables d'environnement pour les tests
$_ENV['APP_ENV'] = 'testing';
$_ENV['DB_HOST'] = 'localhost';
$_ENV['DB_NAME'] = 'GSB_Reservation_Test';
$_ENV['DB_USER'] = 'root';
$_ENV['DB_PASS'] = '';
$_ENV['LOG_PATH'] = PROJECT_ROOT . '/tests/logs/';
$_ENV['APP_DEBUG'] = 'true';

// Creer le dossier de logs de test si necessaire
if (!is_dir($_ENV['LOG_PATH'])) {
    mkdir($_ENV['LOG_PATH'], 0755, true);
}

// Fonctions utilitaires pour les tests
function createTestDatabase(): PDO
{
    $pdo = new PDO(
        "mysql:host={$_ENV['DB_HOST']}",
        $_ENV['DB_USER'],
        $_ENV['DB_PASS'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $pdo->exec("CREATE DATABASE IF NOT EXISTS {$_ENV['DB_NAME']}");
    $pdo->exec("USE {$_ENV['DB_NAME']}");

    return $pdo;
}

function cleanTestDatabase(PDO $pdo): void
{
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

    $tables = ['Reservation_Options', 'Reservation', 'Salle_Equipement', 'Options', 'Salle', 'Utilisateur', 'Equipement'];
    foreach ($tables as $table) {
        $pdo->exec("DROP TABLE IF EXISTS {$table}");
    }

    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
}

function setupTestTables(PDO $pdo): void
{
    // Table Utilisateur
    $pdo->exec("CREATE TABLE IF NOT EXISTS Utilisateur (
        ID INT AUTO_INCREMENT PRIMARY KEY,
        Nom VARCHAR(100) NOT NULL,
        Prenom VARCHAR(100) NOT NULL,
        Email VARCHAR(255) NOT NULL UNIQUE,
        MotDePasse VARCHAR(255) NOT NULL,
        Role ENUM('Admin', 'Delegue', 'Employe') DEFAULT 'Employe',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");

    // Table Salle
    $pdo->exec("CREATE TABLE IF NOT EXISTS Salle (
        ID INT AUTO_INCREMENT PRIMARY KEY,
        Nom VARCHAR(100) NOT NULL,
        Description TEXT,
        Capacite INT NOT NULL DEFAULT 10,
        Statut ENUM('available', 'occupied', 'maintenance') DEFAULT 'available',
        Etage INT DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");

    // Table Options
    $pdo->exec("CREATE TABLE IF NOT EXISTS Options (
        ID INT AUTO_INCREMENT PRIMARY KEY,
        Nom VARCHAR(100) NOT NULL UNIQUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Table Reservation
    $pdo->exec("CREATE TABLE IF NOT EXISTS Reservation (
        ID INT AUTO_INCREMENT PRIMARY KEY,
        Reference VARCHAR(20) NOT NULL UNIQUE,
        Utilisateur_ID INT NOT NULL,
        Salle_ID INT NOT NULL,
        DateReservation DATE NOT NULL,
        HeureDebut TIME NOT NULL,
        HeureFin TIME NOT NULL,
        Objet VARCHAR(255),
        Statut ENUM('pending', 'confirmed', 'cancelled') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (Utilisateur_ID) REFERENCES Utilisateur(ID) ON DELETE CASCADE,
        FOREIGN KEY (Salle_ID) REFERENCES Salle(ID) ON DELETE CASCADE
    )");

    // Table Reservation_Options
    $pdo->exec("CREATE TABLE IF NOT EXISTS Reservation_Options (
        Reservation_ID INT NOT NULL,
        Option_ID INT NOT NULL,
        PRIMARY KEY (Reservation_ID, Option_ID),
        FOREIGN KEY (Reservation_ID) REFERENCES Reservation(ID) ON DELETE CASCADE,
        FOREIGN KEY (Option_ID) REFERENCES Options(ID) ON DELETE CASCADE
    )");

    // Table Equipement
    $pdo->exec("CREATE TABLE IF NOT EXISTS Equipement (
        ID INT AUTO_INCREMENT PRIMARY KEY,
        Nom VARCHAR(100) NOT NULL UNIQUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Table Salle_Equipement
    $pdo->exec("CREATE TABLE IF NOT EXISTS Salle_Equipement (
        Salle_ID INT NOT NULL,
        Equipement_ID INT NOT NULL,
        PRIMARY KEY (Salle_ID, Equipement_ID),
        FOREIGN KEY (Salle_ID) REFERENCES Salle(ID) ON DELETE CASCADE,
        FOREIGN KEY (Equipement_ID) REFERENCES Equipement(ID) ON DELETE CASCADE
    )");
}

function createTestUser(PDO $pdo, string $email = 'test@gsb.local', string $role = 'Employe'): int
{
    $password = password_hash('TestPassword123!@#', PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO Utilisateur (Nom, Prenom, Email, MotDePasse, Role) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute(['Test', 'User', $email, $password, $role]);
    return (int) $pdo->lastInsertId();
}

function createTestRoom(PDO $pdo, string $name = 'Salle Test'): int
{
    $stmt = $pdo->prepare("INSERT INTO Salle (Nom, Description, Capacite, Statut, Etage) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$name, 'Salle de test', 10, 'available', 1]);
    return (int) $pdo->lastInsertId();
}
