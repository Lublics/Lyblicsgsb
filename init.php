<?php
/**
 * Script d'initialisation de la base de donnees
 *
 * SECURITE: Ce script ne peut etre execute qu'en mode CLI ou avec un token secret
 *
 * @package GSB_Reservation
 * @version 2.0.0
 */

// Verification de securite
$isCliMode = (php_sapi_name() === 'cli');
$initToken = getenv('INIT_TOKEN') ?: 'gsb_init_secret_2024';
$providedToken = $_GET['token'] ?? '';

if (!$isCliMode && $providedToken !== $initToken) {
    http_response_code(403);
    echo "<div style='background: #ef4444; color: white; padding: 20px; border-radius: 8px; margin: 20px; font-family: sans-serif;'>";
    echo "<h3 style='margin: 0 0 10px 0;'>Acces refuse</h3>";
    echo "<p style='margin: 0;'>Ce script d'initialisation est protege. Pour l'executer :</p>";
    echo "<ul style='margin: 10px 0;'>";
    echo "<li>Via CLI: <code>php init.php</code></li>";
    echo "<li>Via navigateur: <code>init.php?token=VOTRE_TOKEN_SECRET</code></li>";
    echo "</ul>";
    echo "<p style='margin: 0; font-size: 0.9em;'>Configurez INIT_TOKEN dans votre fichier .env</p>";
    echo "</div>";
    exit;
}

// Charger la configuration
require_once __DIR__ . '/config/database.php';

$host = DB_HOST;
$user = DB_USER;
$pass = DB_PASS;
$dbname = DB_NAME;

try {
    // Connexion sans base de donnees pour la creer
    $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    output("<h2>Initialisation de GSB Reservation v2.0</h2>");
    output("<pre>");

    // Creer la base de donnees
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$dbname`");
    output("Base de donnees creee", "success");

    // Supprimer les tables existantes (dans l'ordre des dependances)
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    $tables = ['ActivityLog', 'Reservation_Option', 'Notification',
               'Reservation', 'Salle_Equipement', 'Equipement', 'Option_Service', 'Salle', 'Batiment', 'Utilisateur'];
    foreach ($tables as $table) {
        $pdo->exec("DROP TABLE IF EXISTS `$table`");
    }
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    output("Tables existantes supprimees", "success");

    // ====== CREATION DES TABLES ======

    // Table Utilisateur
    $pdo->exec("
        CREATE TABLE Utilisateur (
            id_utilisateur INT AUTO_INCREMENT PRIMARY KEY,
            nom_user VARCHAR(50) NOT NULL,
            prenom_user VARCHAR(50) NOT NULL,
            email_user VARCHAR(100) NOT NULL UNIQUE,
            mot_de_passe VARCHAR(255) NOT NULL,
            rôle_user ENUM('Admin', 'Delegue', 'Employe') NOT NULL DEFAULT 'Employe',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_email (email_user),
            INDEX idx_role (rôle_user)
        ) ENGINE=InnoDB
    ");
    output("Table Utilisateur creee", "success");

    // Table Batiment
    $pdo->exec("
        CREATE TABLE Batiment (
            id_batiment INT AUTO_INCREMENT PRIMARY KEY,
            nom_batiment VARCHAR(100) NOT NULL,
            adresse_batiment VARCHAR(255),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB
    ");
    output("Table Batiment creee", "success");

    // Table Salle
    $pdo->exec("
        CREATE TABLE Salle (
            id_salle INT AUTO_INCREMENT PRIMARY KEY,
            nom_salle VARCHAR(50) NOT NULL,
            description_salle VARCHAR(255),
            capacite_salle INT NOT NULL CHECK (capacite_salle > 0 AND capacite_salle <= 500),
            etat_salle ENUM('Disponible', 'Occupee', 'Maintenance') NOT NULL DEFAULT 'Disponible',
            id_batiment INT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (id_batiment) REFERENCES Batiment(id_batiment) ON UPDATE CASCADE ON DELETE SET NULL,
            INDEX idx_etat (etat_salle),
            INDEX idx_capacite (capacite_salle),
            INDEX idx_batiment (id_batiment)
        ) ENGINE=InnoDB
    ");
    output("Table Salle creee", "success");

    // Table Equipement (nouvelle)
    $pdo->exec("
        CREATE TABLE Equipement (
            id_equipement INT AUTO_INCREMENT PRIMARY KEY,
            nom_equipement VARCHAR(50) NOT NULL UNIQUE,
            description_equipement VARCHAR(255),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB
    ");
    output("Table Equipement creee", "success");

    // Table de liaison Salle_Equipement (nouvelle)
    $pdo->exec("
        CREATE TABLE Salle_Equipement (
            id_salle INT NOT NULL,
            id_equipement INT NOT NULL,
            PRIMARY KEY (id_salle, id_equipement),
            FOREIGN KEY (id_salle) REFERENCES Salle(id_salle) ON UPDATE CASCADE ON DELETE CASCADE,
            FOREIGN KEY (id_equipement) REFERENCES Equipement(id_equipement) ON UPDATE CASCADE ON DELETE CASCADE
        ) ENGINE=InnoDB
    ");
    output("Table Salle_Equipement creee", "success");

    // Table Option_Service
    $pdo->exec("
        CREATE TABLE Option_Service (
            id_option INT AUTO_INCREMENT PRIMARY KEY,
            libelle_option VARCHAR(50) NOT NULL UNIQUE,
            description_option VARCHAR(255),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB
    ");
    output("Table Option_Service creee", "success");

    // Table Reservation
    $pdo->exec("
        CREATE TABLE Reservation (
            id_reservation INT AUTO_INCREMENT PRIMARY KEY,
            date_debut DATETIME NOT NULL,
            date_fin DATETIME NOT NULL,
            objet_reservation VARCHAR(100) NOT NULL,
            statut_reservation ENUM('Confirmee', 'En attente', 'Annulee') NOT NULL DEFAULT 'En attente',
            id_utilisateur INT NOT NULL,
            id_salle INT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (id_utilisateur) REFERENCES Utilisateur(id_utilisateur) ON UPDATE CASCADE ON DELETE CASCADE,
            FOREIGN KEY (id_salle) REFERENCES Salle(id_salle) ON UPDATE CASCADE ON DELETE RESTRICT,
            INDEX idx_date_debut (date_debut),
            INDEX idx_date_fin (date_fin),
            INDEX idx_salle (id_salle),
            INDEX idx_utilisateur (id_utilisateur),
            INDEX idx_statut (statut_reservation),
            INDEX idx_salle_dates (id_salle, date_debut, date_fin)
        ) ENGINE=InnoDB
    ");
    output("Table Reservation creee", "success");

    // Table Reservation_Option
    $pdo->exec("
        CREATE TABLE Reservation_Option (
            id_reservation INT NOT NULL,
            id_option INT NOT NULL,
            PRIMARY KEY (id_reservation, id_option),
            FOREIGN KEY (id_reservation) REFERENCES Reservation(id_reservation) ON UPDATE CASCADE ON DELETE CASCADE,
            FOREIGN KEY (id_option) REFERENCES Option_Service(id_option) ON UPDATE CASCADE ON DELETE CASCADE
        ) ENGINE=InnoDB
    ");
    output("Table Reservation_Option creee", "success");

    // Table Notification
    $pdo->exec("
        CREATE TABLE Notification (
            id_notification INT AUTO_INCREMENT PRIMARY KEY,
            id_reservation INT NOT NULL,
            type_notification ENUM('Creation', 'Modification', 'Annulation', 'Rappel') NOT NULL,
            date_envoi DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            lu BOOLEAN DEFAULT FALSE,
            FOREIGN KEY (id_reservation) REFERENCES Reservation(id_reservation) ON UPDATE CASCADE ON DELETE CASCADE,
            INDEX idx_reservation (id_reservation),
            INDEX idx_date (date_envoi)
        ) ENGINE=InnoDB
    ");
    output("Table Notification creee", "success");

    // Table ActivityLog
    $pdo->exec("
        CREATE TABLE ActivityLog (
            id_log INT AUTO_INCREMENT PRIMARY KEY,
            action_type VARCHAR(50) NOT NULL,
            actor_id INT DEFAULT NULL,
            actor_name VARCHAR(100) NOT NULL,
            actor_role VARCHAR(20) NOT NULL,
            target_type VARCHAR(50) NOT NULL,
            target_id INT DEFAULT NULL,
            target_label VARCHAR(255) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (actor_id) REFERENCES Utilisateur(id_utilisateur) ON UPDATE CASCADE ON DELETE SET NULL,
            INDEX idx_action (action_type),
            INDEX idx_actor (actor_id),
            INDEX idx_date (created_at),
            INDEX idx_target (target_type, target_id, created_at)
        ) ENGINE=InnoDB
    ");
    output("Table ActivityLog creee", "success");

    // ====== INSERTION DES DONNEES DE DEMONSTRATION ======

    // Equipements
    $equipments = [
        ['WiFi', 'Connexion Internet haut debit'],
        ['Videoprojecteur', 'Projecteur Full HD'],
        ['Visioconference', 'Systeme de visioconference HD'],
        ['Tableau blanc', 'Tableau blanc magnetique'],
        ['Ecran interactif', 'Ecran tactile 65 pouces'],
        ['Machine a cafe', 'Machine Nespresso'],
        ['Climatisation', 'Climatisation reversible'],
        ['Systeme audio', 'Systeme de sonorisation']
    ];

    $stmtEquip = $pdo->prepare("INSERT INTO Equipement (nom_equipement, description_equipement) VALUES (?, ?)");
    foreach ($equipments as $eq) {
        $stmtEquip->execute($eq);
    }
    output(count($equipments) . " equipements crees", "success");

    // Batiments
    $stmtBat = $pdo->prepare("INSERT INTO Batiment (nom_batiment, adresse_batiment) VALUES (?, ?)");
    $batiments = [
        ['Batiment A - Siege', '12 rue de la Sante, 75013 Paris'],
        ['Batiment B - Annexe', '45 avenue des Sciences, 75013 Paris'],
        ['Batiment C - Centre de formation', '8 boulevard Pasteur, 75015 Paris']
    ];
    foreach ($batiments as $bat) {
        $stmtBat->execute($bat);
    }
    output(count($batiments) . " batiments crees", "success");

    // Utilisateurs (mot de passe securise: Admin123!@#)
    $securePassword = password_hash('Admin123!@#', PASSWORD_DEFAULT, ['cost' => 12]);

    $stmt = $pdo->prepare("INSERT INTO Utilisateur (nom_user, prenom_user, email_user, mot_de_passe, rôle_user) VALUES (?, ?, ?, ?, ?)");

    $users = [
        ['Dupont', 'Jean', 'admin@gsb.local', $securePassword, 'Admin'],
        ['Martin', 'Sophie', 'sophie.martin@gsb.local', $securePassword, 'Delegue'],
        ['Durand', 'Thomas', 'thomas.durand@gsb.local', $securePassword, 'Employe'],
        ['Lambert', 'Marie', 'marie.lambert@gsb.local', $securePassword, 'Employe'],
        ['Bernard', 'Pierre', 'pierre.bernard@gsb.local', $securePassword, 'Delegue']
    ];

    foreach ($users as $user) {
        $stmt->execute($user);
    }
    output(count($users) . " utilisateurs crees", "success");

    // Salles
    $stmt = $pdo->prepare("INSERT INTO Salle (nom_salle, description_salle, capacite_salle, etat_salle, id_batiment) VALUES (?, ?, ?, ?, ?)");

    $rooms = [
        ['Salle Einstein', 'Etage 1 - Salle de reunion moderne avec vue sur le jardin', 8, 'Disponible', 1],
        ['Salle Newton', 'Etage 1 - Grande salle ideale pour les formations', 12, 'Disponible', 1],
        ['Salle Curie', 'Etage 2 - Espace intimiste pour petites reunions', 6, 'Disponible', 2],
        ['Salle Darwin', 'Etage 2 - Salle de conference principale', 20, 'Disponible', 2],
        ['Salle Tesla', 'Etage 3 - Petit bureau pour reunions rapides', 4, 'Maintenance', 3],
        ['Salle Pasteur', 'RDC - Salle polyvalente', 15, 'Disponible', 3],
        ['Salle Turing', 'Etage 3 - Salle high-tech avec equipement dernier cri', 10, 'Disponible', 3],
        ['Salle Hawking', 'Etage 1 - Auditorium pour grandes presentations', 25, 'Disponible', 1]
    ];

    foreach ($rooms as $room) {
        $stmt->execute($room);
    }
    output(count($rooms) . " salles creees", "success");

    // Associer equipements aux salles
    $roomEquipments = [
        1 => [1, 2, 4, 7],           // Einstein: WiFi, Videopro, Tableau, Clim
        2 => [1, 2, 3, 4, 5, 7],     // Newton: WiFi, Videopro, Visio, Tableau, Ecran, Clim
        3 => [1, 4, 6],              // Curie: WiFi, Tableau, Cafe
        4 => [1, 2, 3, 4, 5, 7, 8],  // Darwin: Tout
        5 => [1, 4],                 // Tesla: WiFi, Tableau
        6 => [1, 2, 4, 6, 7],        // Pasteur: WiFi, Videopro, Tableau, Cafe, Clim
        7 => [1, 2, 3, 4, 5, 7, 8],  // Turing: Tout
        8 => [1, 2, 3, 5, 7, 8]      // Hawking: WiFi, Videopro, Visio, Ecran, Clim, Audio
    ];

    $stmtRoomEquip = $pdo->prepare("INSERT INTO Salle_Equipement (id_salle, id_equipement) VALUES (?, ?)");
    foreach ($roomEquipments as $roomId => $equipIds) {
        foreach ($equipIds as $equipId) {
            $stmtRoomEquip->execute([$roomId, $equipId]);
        }
    }
    output("Equipements associes aux salles", "success");

    // Options/Services
    $stmt = $pdo->prepare("INSERT INTO Option_Service (libelle_option, description_option) VALUES (?, ?)");

    $options = [
        ['Visioconference', 'Configuration du systeme de visioconference'],
        ['Videoprojecteur', 'Installation et configuration du videoprojecteur'],
        ['Tableau blanc', 'Mise a disposition de feutres et effaceur'],
        ['WiFi', 'Code d\'acces WiFi invite'],
        ['Machine a cafe', 'Capsules et gobelets fournis'],
        ['Petit dejeuner', 'Viennoiseries et boissons chaudes'],
        ['Dejeuner', 'Plateau repas et boissons'],
        ['Climatisation', 'Reglage de la temperature'],
        ['Ecran interactif', 'Configuration de l\'ecran tactile']
    ];

    foreach ($options as $option) {
        $stmt->execute($option);
    }
    output(count($options) . " options creees", "success");

    // Reservations de demonstration
    $today = date('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $nextWeek = date('Y-m-d', strtotime('+7 days'));

    $stmt = $pdo->prepare("INSERT INTO Reservation (date_debut, date_fin, objet_reservation, statut_reservation, id_utilisateur, id_salle) VALUES (?, ?, ?, ?, ?, ?)");

    $bookings = [
        ["$today 09:00:00", "$today 10:30:00", 'Reunion equipe developpement', 'Confirmee', 1, 1],
        ["$today 14:00:00", "$today 16:00:00", 'Formation nouveaux employes', 'Confirmee', 2, 2],
        ["$today 10:00:00", "$today 12:00:00", 'Conference clients', 'En attente', 1, 4],
        ["$today 11:00:00", "$today 12:30:00", 'Presentation projet Q1', 'Confirmee', 3, 8],
        ["$tomorrow 09:30:00", "$tomorrow 11:00:00", 'Point hebdomadaire', 'Confirmee', 1, 3],
        ["$tomorrow 14:00:00", "$tomorrow 15:30:00", 'Entretien annuel', 'En attente', 4, 1],
        ["$nextWeek 10:00:00", "$nextWeek 12:00:00", 'Comite de direction', 'Confirmee', 1, 4],
        ["$nextWeek 14:00:00", "$nextWeek 17:00:00", 'Workshop innovation', 'Confirmee', 2, 7]
    ];

    foreach ($bookings as $booking) {
        $stmt->execute($booking);
    }
    output(count($bookings) . " reservations creees", "success");

    // Lier quelques options aux reservations
    $stmt = $pdo->prepare("INSERT INTO Reservation_Option (id_reservation, id_option) VALUES (?, ?)");
    $reservationOptions = [
        [1, 1], [1, 5],  // Reservation 1 - Visioconference, Cafe
        [2, 2], [2, 3],  // Reservation 2 - Videopro, Tableau
        [3, 1], [3, 6],  // Reservation 3 - Visioconference, Petit dej
        [4, 2], [4, 8],  // Reservation 4 - Videopro, Ecran interactif
        [7, 1], [7, 5], [7, 6]  // Reservation 7 - Visio, Cafe, Petit dej
    ];

    foreach ($reservationOptions as $ro) {
        $stmt->execute($ro);
    }
    output("Options liees aux reservations", "success");

    // Creer quelques notifications
    $stmt = $pdo->prepare("INSERT INTO Notification (id_reservation, type_notification) VALUES (?, ?)");
    for ($i = 1; $i <= 8; $i++) {
        $stmt->execute([$i, 'Creation']);
    }
    output("Notifications creees", "success");

    output("</pre>");

    // Message de succes
    output("<div style='background: #10b981; color: white; padding: 20px; border-radius: 8px; margin-top: 20px;'>");
    output("<h3 style='margin: 0 0 10px 0;'>Initialisation terminee avec succes !</h3>");
    output("<p style='margin: 0;'>La base de donnees GSB Reservation v2.0 est prete.</p>");
    output("</div>");

    // Instructions de connexion (securise - ne pas afficher le mot de passe en production)
    if (APP_ENV === 'development') {
        output("<div style='background: #3b82f6; color: white; padding: 20px; border-radius: 8px; margin-top: 20px;'>");
        output("<h3 style='margin: 0 0 10px 0;'>Comptes de demonstration</h3>");
        output("<p style='margin: 0;'><strong>Admin:</strong> admin@gsb.local</p>");
        output("<p style='margin: 0;'><strong>Mot de passe:</strong> Admin123!@#</p>");
        output("<p style='margin: 10px 0 0 0; font-size: 0.9em;'>Ce message n'apparait qu'en mode developpement.</p>");
        output("</div>");
    }

    output("<div style='margin-top: 20px;'>");
    output("<a href='index.html' style='display: inline-block; background: #3b82f6; color: white; padding: 12px 24px; border-radius: 8px; text-decoration: none;'>Acceder a l'application</a>");
    output("</div>");

    // Log de l'initialisation
    Logger::info('Database initialized', ['version' => '2.0.0', 'mode' => $isCliMode ? 'CLI' : 'WEB']);

} catch (PDOException $e) {
    output("<div style='background: #ef4444; color: white; padding: 20px; border-radius: 8px;'>");
    output("<h3 style='margin: 0 0 10px 0;'>Erreur</h3>");
    output("<p style='margin: 0;'>" . htmlspecialchars($e->getMessage()) . "</p>");
    output("</div>");

    Logger::error('Database initialization failed', ['error' => $e->getMessage()]);
}

/**
 * Fonction d'affichage compatible CLI et Web
 */
function output($message, $type = null) {
    $isCliMode = (php_sapi_name() === 'cli');

    if ($isCliMode) {
        // Mode CLI - texte simple
        $cleanMessage = strip_tags($message);
        if ($type === 'success') {
            echo "[OK] $cleanMessage\n";
        } else {
            echo "$cleanMessage\n";
        }
    } else {
        // Mode Web - HTML
        if ($type === 'success') {
            echo "<span style='color: #10b981;'>$message</span>\n";
        } else {
            echo "$message\n";
        }
    }
}
