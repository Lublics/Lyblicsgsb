<?php
/**
 * Script temporaire pour creer un utilisateur admin
 * SUPPRIMER CE FICHIER APRES UTILISATION
 */

require_once __DIR__ . '/config/database.php';

try {
    $pdo = Database::getInstance()->getConnection();

    // Donnees du nouvel admin
    $nom = 'Admin';
    $prenom = 'Test';
    $email = 'test.admin@gsb.local';
    $password = 'Test1234@';
    $role = 'Admin';

    // Hash du mot de passe
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT, ['cost' => 12]);

    // Verification si l'email existe deja
    $checkStmt = $pdo->prepare("SELECT id_utilisateur FROM Utilisateur WHERE email_user = ?");
    $checkStmt->execute([$email]);

    if ($checkStmt->fetch()) {
        echo "Erreur: Un utilisateur avec cet email existe deja.\n";
        exit(1);
    }

    // Insertion
    $stmt = $pdo->prepare("
        INSERT INTO Utilisateur (nom_user, prenom_user, email_user, mot_de_passe, rôle_user)
        VALUES (?, ?, ?, ?, ?)
    ");

    $stmt->execute([$nom, $prenom, $email, $hashedPassword, $role]);

    echo "===========================================\n";
    echo "Utilisateur admin cree avec succes !\n";
    echo "===========================================\n";
    echo "Email: $email\n";
    echo "Mot de passe: $password\n";
    echo "Role: $role\n";
    echo "===========================================\n";
    echo "\n";
    echo "IMPORTANT: Supprimez ce fichier (create_admin.php) apres utilisation !\n";

} catch (PDOException $e) {
    echo "Erreur: " . $e->getMessage() . "\n";
    exit(1);
}
