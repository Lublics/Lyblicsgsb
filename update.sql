-- =====================================================
-- GSB Reservation - Script de mise a jour BDD
-- Version 2.1.0
-- Base de donnees: u283416404_gsb_reservatio
--
-- Ce script met a jour une base existante v2.0.0
-- sans supprimer les donnees existantes.
-- =====================================================

SET NAMES utf8mb4;

-- =====================================================
-- 1. CREATION DE LA TABLE BATIMENT
-- =====================================================

CREATE TABLE IF NOT EXISTS `Batiment` (
    `id_batiment` INT AUTO_INCREMENT PRIMARY KEY,
    `nom_batiment` VARCHAR(100) NOT NULL,
    `adresse_batiment` VARCHAR(255),
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 2. AJOUT DE LA COLONNE id_batiment SUR SALLE
-- =====================================================

-- Ajouter la colonne si elle n'existe pas
ALTER TABLE `Salle` ADD COLUMN IF NOT EXISTS `id_batiment` INT DEFAULT NULL AFTER `etat_salle`;

-- Ajouter la foreign key
ALTER TABLE `Salle` ADD CONSTRAINT `fk_salle_batiment`
    FOREIGN KEY (`id_batiment`) REFERENCES `Batiment`(`id_batiment`)
    ON UPDATE CASCADE ON DELETE SET NULL;

-- Ajouter l'index
ALTER TABLE `Salle` ADD INDEX IF NOT EXISTS `idx_batiment` (`id_batiment`);

-- =====================================================
-- 3. INSERTION DES BATIMENTS
-- =====================================================

INSERT INTO `Batiment` (`nom_batiment`, `adresse_batiment`) VALUES
('Batiment A - Siege', '12 rue de la Sante, 75013 Paris'),
('Batiment B - Annexe', '45 avenue des Sciences, 75013 Paris'),
('Batiment C - Centre de formation', '8 boulevard Pasteur, 75015 Paris');

-- =====================================================
-- 4. ASSOCIATION DES SALLES AUX BATIMENTS
-- =====================================================

-- Einstein, Newton, Hawking -> Batiment A (id 1)
UPDATE `Salle` SET `id_batiment` = 1 WHERE `nom_salle` = 'Salle Einstein';
UPDATE `Salle` SET `id_batiment` = 1 WHERE `nom_salle` = 'Salle Newton';
UPDATE `Salle` SET `id_batiment` = 1 WHERE `nom_salle` = 'Salle Hawking';

-- Curie, Darwin -> Batiment B (id 2)
UPDATE `Salle` SET `id_batiment` = 2 WHERE `nom_salle` = 'Salle Curie';
UPDATE `Salle` SET `id_batiment` = 2 WHERE `nom_salle` = 'Salle Darwin';

-- Tesla, Pasteur, Turing -> Batiment C (id 3)
UPDATE `Salle` SET `id_batiment` = 3 WHERE `nom_salle` = 'Salle Tesla';
UPDATE `Salle` SET `id_batiment` = 3 WHERE `nom_salle` = 'Salle Pasteur';
UPDATE `Salle` SET `id_batiment` = 3 WHERE `nom_salle` = 'Salle Turing';

-- =====================================================
-- 5. AJOUT DE L'OPTION "DEJEUNER"
-- =====================================================

INSERT IGNORE INTO `Option_Service` (`libelle_option`, `description_option`) VALUES
('Dejeuner', 'Plateau repas et boissons');

-- =====================================================
-- FIN DU SCRIPT DE MISE A JOUR
-- =====================================================
