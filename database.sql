-- =====================================================
-- GSB Reservation - Script d'initialisation BDD
-- Version 2.0.0
-- Base de donnees: GSB_Reservation
-- =====================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =====================================================
-- SUPPRESSION DES TABLES EXISTANTES
-- =====================================================

DROP TABLE IF EXISTS `ActivityLog`;
DROP TABLE IF EXISTS `Reservation_Option`;
DROP TABLE IF EXISTS `Notification`;
DROP TABLE IF EXISTS `Reservation`;
DROP TABLE IF EXISTS `Salle_Equipement`;
DROP TABLE IF EXISTS `Equipement`;
DROP TABLE IF EXISTS `Option_Service`;
DROP TABLE IF EXISTS `Salle`;
DROP TABLE IF EXISTS `Batiment`;
DROP TABLE IF EXISTS `Utilisateur`;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================
-- CREATION DES TABLES
-- =====================================================

-- Table Utilisateur
CREATE TABLE `Utilisateur` (
    `id_utilisateur` INT AUTO_INCREMENT PRIMARY KEY,
    `nom_user` VARCHAR(50) NOT NULL,
    `prenom_user` VARCHAR(50) NOT NULL,
    `email_user` VARCHAR(100) NOT NULL UNIQUE,
    `mot_de_passe` VARCHAR(255) NOT NULL,
    `rôle_user` ENUM('Admin', 'Delegue', 'Employe') NOT NULL DEFAULT 'Employe',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_email` (`email_user`),
    INDEX `idx_role` (`rôle_user`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table Batiment
CREATE TABLE `Batiment` (
    `id_batiment` INT AUTO_INCREMENT PRIMARY KEY,
    `nom_batiment` VARCHAR(100) NOT NULL,
    `adresse_batiment` VARCHAR(255),
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table Salle
CREATE TABLE `Salle` (
    `id_salle` INT AUTO_INCREMENT PRIMARY KEY,
    `nom_salle` VARCHAR(50) NOT NULL,
    `description_salle` VARCHAR(255),
    `capacite_salle` INT NOT NULL,
    `etat_salle` ENUM('Disponible', 'Occupee', 'Maintenance') NOT NULL DEFAULT 'Disponible',
    `id_batiment` INT DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`id_batiment`) REFERENCES `Batiment`(`id_batiment`) ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX `idx_etat` (`etat_salle`),
    INDEX `idx_capacite` (`capacite_salle`),
    INDEX `idx_batiment` (`id_batiment`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table Equipement
CREATE TABLE `Equipement` (
    `id_equipement` INT AUTO_INCREMENT PRIMARY KEY,
    `nom_equipement` VARCHAR(50) NOT NULL UNIQUE,
    `description_equipement` VARCHAR(255),
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table de liaison Salle_Equipement
CREATE TABLE `Salle_Equipement` (
    `id_salle` INT NOT NULL,
    `id_equipement` INT NOT NULL,
    PRIMARY KEY (`id_salle`, `id_equipement`),
    FOREIGN KEY (`id_salle`) REFERENCES `Salle`(`id_salle`) ON UPDATE CASCADE ON DELETE CASCADE,
    FOREIGN KEY (`id_equipement`) REFERENCES `Equipement`(`id_equipement`) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table Option_Service
CREATE TABLE `Option_Service` (
    `id_option` INT AUTO_INCREMENT PRIMARY KEY,
    `libelle_option` VARCHAR(50) NOT NULL UNIQUE,
    `description_option` VARCHAR(255),
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table Reservation
CREATE TABLE `Reservation` (
    `id_reservation` INT AUTO_INCREMENT PRIMARY KEY,
    `date_debut` DATETIME NOT NULL,
    `date_fin` DATETIME NOT NULL,
    `objet_reservation` VARCHAR(100) NOT NULL,
    `statut_reservation` ENUM('Confirmee', 'En attente', 'Annulee') NOT NULL DEFAULT 'En attente',
    `id_utilisateur` INT NOT NULL,
    `id_salle` INT NOT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`id_utilisateur`) REFERENCES `Utilisateur`(`id_utilisateur`) ON UPDATE CASCADE ON DELETE CASCADE,
    FOREIGN KEY (`id_salle`) REFERENCES `Salle`(`id_salle`) ON UPDATE CASCADE ON DELETE RESTRICT,
    INDEX `idx_date_debut` (`date_debut`),
    INDEX `idx_date_fin` (`date_fin`),
    INDEX `idx_salle` (`id_salle`),
    INDEX `idx_utilisateur` (`id_utilisateur`),
    INDEX `idx_statut` (`statut_reservation`),
    INDEX `idx_salle_dates` (`id_salle`, `date_debut`, `date_fin`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table Reservation_Option
CREATE TABLE `Reservation_Option` (
    `id_reservation` INT NOT NULL,
    `id_option` INT NOT NULL,
    PRIMARY KEY (`id_reservation`, `id_option`),
    FOREIGN KEY (`id_reservation`) REFERENCES `Reservation`(`id_reservation`) ON UPDATE CASCADE ON DELETE CASCADE,
    FOREIGN KEY (`id_option`) REFERENCES `Option_Service`(`id_option`) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table Notification
CREATE TABLE `Notification` (
    `id_notification` INT AUTO_INCREMENT PRIMARY KEY,
    `id_reservation` INT NOT NULL,
    `type_notification` ENUM('Creation', 'Modification', 'Annulation', 'Rappel') NOT NULL,
    `date_envoi` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `lu` BOOLEAN DEFAULT FALSE,
    FOREIGN KEY (`id_reservation`) REFERENCES `Reservation`(`id_reservation`) ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX `idx_reservation` (`id_reservation`),
    INDEX `idx_date` (`date_envoi`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table ActivityLog
CREATE TABLE `ActivityLog` (
    `id_log` INT AUTO_INCREMENT PRIMARY KEY,
    `action_type` VARCHAR(50) NOT NULL,
    `actor_id` INT DEFAULT NULL,
    `actor_name` VARCHAR(100) NOT NULL,
    `actor_role` VARCHAR(20) NOT NULL,
    `target_type` VARCHAR(50) NOT NULL,
    `target_id` INT DEFAULT NULL,
    `target_label` VARCHAR(255) DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`actor_id`) REFERENCES `Utilisateur`(`id_utilisateur`) ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX `idx_action` (`action_type`),
    INDEX `idx_actor` (`actor_id`),
    INDEX `idx_date` (`created_at`),
    INDEX `idx_target` (`target_type`, `target_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- INSERTION DES DONNEES
-- =====================================================

-- Equipements
INSERT INTO `Equipement` (`nom_equipement`, `description_equipement`) VALUES
('WiFi', 'Connexion Internet haut debit'),
('Videoprojecteur', 'Projecteur Full HD'),
('Visioconference', 'Systeme de visioconference HD'),
('Tableau blanc', 'Tableau blanc magnetique'),
('Ecran interactif', 'Ecran tactile 65 pouces'),
('Machine a cafe', 'Machine Nespresso'),
('Climatisation', 'Climatisation reversible'),
('Systeme audio', 'Systeme de sonorisation');

-- Batiments
INSERT INTO `Batiment` (`nom_batiment`, `adresse_batiment`) VALUES
('Batiment A - Siege', '12 rue de la Sante, 75013 Paris'),
('Batiment B - Annexe', '45 avenue des Sciences, 75013 Paris'),
('Batiment C - Centre de formation', '8 boulevard Pasteur, 75015 Paris');

-- Utilisateurs (mot de passe: Admin123!@#)
-- Le hash est genere avec password_hash() PHP, cost=12
INSERT INTO `Utilisateur` (`nom_user`, `prenom_user`, `email_user`, `mot_de_passe`, `rôle_user`) VALUES
('Dupont', 'Jean', 'admin@gsb.local', '$2y$12$LQv3c1yqBWVHxkd0LHAkCOYz6TtxMQJqhN8/X4iT6GWKN6.qVHPHy', 'Admin'),
('Martin', 'Sophie', 'sophie.martin@gsb.local', '$2y$12$LQv3c1yqBWVHxkd0LHAkCOYz6TtxMQJqhN8/X4iT6GWKN6.qVHPHy', 'Delegue'),
('Durand', 'Thomas', 'thomas.durand@gsb.local', '$2y$12$LQv3c1yqBWVHxkd0LHAkCOYz6TtxMQJqhN8/X4iT6GWKN6.qVHPHy', 'Employe'),
('Lambert', 'Marie', 'marie.lambert@gsb.local', '$2y$12$LQv3c1yqBWVHxkd0LHAkCOYz6TtxMQJqhN8/X4iT6GWKN6.qVHPHy', 'Employe'),
('Bernard', 'Pierre', 'pierre.bernard@gsb.local', '$2y$12$LQv3c1yqBWVHxkd0LHAkCOYz6TtxMQJqhN8/X4iT6GWKN6.qVHPHy', 'Delegue');

-- Salles
INSERT INTO `Salle` (`nom_salle`, `description_salle`, `capacite_salle`, `etat_salle`, `id_batiment`) VALUES
('Salle Einstein', 'Etage 1 - Salle de reunion moderne avec vue sur le jardin', 8, 'Disponible', 1),
('Salle Newton', 'Etage 1 - Grande salle ideale pour les formations', 12, 'Disponible', 1),
('Salle Curie', 'Etage 2 - Espace intimiste pour petites reunions', 6, 'Disponible', 2),
('Salle Darwin', 'Etage 2 - Salle de conference principale', 20, 'Disponible', 2),
('Salle Tesla', 'Etage 3 - Petit bureau pour reunions rapides', 4, 'Maintenance', 3),
('Salle Pasteur', 'RDC - Salle polyvalente', 15, 'Disponible', 3),
('Salle Turing', 'Etage 3 - Salle high-tech avec equipement dernier cri', 10, 'Disponible', 3),
('Salle Hawking', 'Etage 1 - Auditorium pour grandes presentations', 25, 'Disponible', 1);

-- Association Salle-Equipement
INSERT INTO `Salle_Equipement` (`id_salle`, `id_equipement`) VALUES
-- Einstein: WiFi, Videopro, Tableau, Clim
(1, 1), (1, 2), (1, 4), (1, 7),
-- Newton: WiFi, Videopro, Visio, Tableau, Ecran, Clim
(2, 1), (2, 2), (2, 3), (2, 4), (2, 5), (2, 7),
-- Curie: WiFi, Tableau, Cafe
(3, 1), (3, 4), (3, 6),
-- Darwin: Tout
(4, 1), (4, 2), (4, 3), (4, 4), (4, 5), (4, 7), (4, 8),
-- Tesla: WiFi, Tableau
(5, 1), (5, 4),
-- Pasteur: WiFi, Videopro, Tableau, Cafe, Clim
(6, 1), (6, 2), (6, 4), (6, 6), (6, 7),
-- Turing: Tout
(7, 1), (7, 2), (7, 3), (7, 4), (7, 5), (7, 7), (7, 8),
-- Hawking: WiFi, Videopro, Visio, Ecran, Clim, Audio
(8, 1), (8, 2), (8, 3), (8, 5), (8, 7), (8, 8);

-- Options/Services
INSERT INTO `Option_Service` (`libelle_option`, `description_option`) VALUES
('Visioconference', 'Configuration du systeme de visioconference'),
('Videoprojecteur', 'Installation et configuration du videoprojecteur'),
('Tableau blanc', 'Mise a disposition de feutres et effaceur'),
('WiFi', 'Code d\'acces WiFi invite'),
('Machine a cafe', 'Capsules et gobelets fournis'),
('Petit dejeuner', 'Viennoiseries et boissons chaudes'),
('Dejeuner', 'Plateau repas et boissons'),
('Climatisation', 'Reglage de la temperature'),
('Ecran interactif', 'Configuration de l\'ecran tactile');

-- Reservations de demonstration (dates relatives a aujourd'hui)
INSERT INTO `Reservation` (`date_debut`, `date_fin`, `objet_reservation`, `statut_reservation`, `id_utilisateur`, `id_salle`) VALUES
(CONCAT(CURDATE(), ' 09:00:00'), CONCAT(CURDATE(), ' 10:30:00'), 'Reunion equipe developpement', 'Confirmee', 1, 1),
(CONCAT(CURDATE(), ' 14:00:00'), CONCAT(CURDATE(), ' 16:00:00'), 'Formation nouveaux employes', 'Confirmee', 2, 2),
(CONCAT(CURDATE(), ' 10:00:00'), CONCAT(CURDATE(), ' 12:00:00'), 'Conference clients', 'En attente', 1, 4),
(CONCAT(CURDATE(), ' 11:00:00'), CONCAT(CURDATE(), ' 12:30:00'), 'Presentation projet Q1', 'Confirmee', 3, 8),
(CONCAT(DATE_ADD(CURDATE(), INTERVAL 1 DAY), ' 09:30:00'), CONCAT(DATE_ADD(CURDATE(), INTERVAL 1 DAY), ' 11:00:00'), 'Point hebdomadaire', 'Confirmee', 1, 3),
(CONCAT(DATE_ADD(CURDATE(), INTERVAL 1 DAY), ' 14:00:00'), CONCAT(DATE_ADD(CURDATE(), INTERVAL 1 DAY), ' 15:30:00'), 'Entretien annuel', 'En attente', 4, 1),
(CONCAT(DATE_ADD(CURDATE(), INTERVAL 7 DAY), ' 10:00:00'), CONCAT(DATE_ADD(CURDATE(), INTERVAL 7 DAY), ' 12:00:00'), 'Comite de direction', 'Confirmee', 1, 4),
(CONCAT(DATE_ADD(CURDATE(), INTERVAL 7 DAY), ' 14:00:00'), CONCAT(DATE_ADD(CURDATE(), INTERVAL 7 DAY), ' 17:00:00'), 'Workshop innovation', 'Confirmee', 2, 7);

-- Options liees aux reservations
INSERT INTO `Reservation_Option` (`id_reservation`, `id_option`) VALUES
(1, 1), (1, 5),  -- Reservation 1: Visioconference, Cafe
(2, 2), (2, 3),  -- Reservation 2: Videopro, Tableau
(3, 1), (3, 6),  -- Reservation 3: Visioconference, Petit dej
(4, 2), (4, 8),  -- Reservation 4: Videopro, Ecran interactif
(7, 1), (7, 5), (7, 6);  -- Reservation 7: Visio, Cafe, Petit dej

-- Notifications
INSERT INTO `Notification` (`id_reservation`, `type_notification`) VALUES
(1, 'Creation'),
(2, 'Creation'),
(3, 'Creation'),
(4, 'Creation'),
(5, 'Creation'),
(6, 'Creation'),
(7, 'Creation'),
(8, 'Creation');

-- =====================================================
-- FIN DU SCRIPT
-- =====================================================
