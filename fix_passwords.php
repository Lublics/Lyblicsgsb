<?php
/**
 * Script pour corriger les mots de passe des utilisateurs
 * SUPPRIMER CE FICHIER APRES UTILISATION
 */

require_once __DIR__ . '/config/database.php';

try {
    $pdo = Database::getInstance()->getConnection();

    echo "<h2>Correction des mots de passe</h2>";
    echo "<pre>";

    // Mot de passe par defaut pour les comptes de demo
    $defaultPassword = 'Admin123!@#';
    $hashedDefault = password_hash($defaultPassword, PASSWORD_DEFAULT, ['cost' => 12]);

    // Mot de passe pour le compte test admin
    $testPassword = 'Test1234@';
    $hashedTest = password_hash($testPassword, PASSWORD_DEFAULT, ['cost' => 12]);

    // Mettre a jour tous les utilisateurs de demo avec le bon hash
    $stmt = $pdo->prepare("UPDATE Utilisateur SET mot_de_passe = ? WHERE email_user IN (?, ?, ?, ?, ?)");
    $stmt->execute([
        $hashedDefault,
        'admin@gsb.local',
        'sophie.martin@gsb.local',
        'thomas.durand@gsb.local',
        'marie.lambert@gsb.local',
        'pierre.bernard@gsb.local'
    ]);
    echo "Utilisateurs de demo mis a jour avec le mot de passe: $defaultPassword\n";

    // Verifier si le compte test.admin existe et le mettre a jour
    $checkStmt = $pdo->prepare("SELECT id_utilisateur FROM Utilisateur WHERE email_user = ?");
    $checkStmt->execute(['test.admin@gsb.local']);

    if ($checkStmt->fetch()) {
        $stmt = $pdo->prepare("UPDATE Utilisateur SET mot_de_passe = ? WHERE email_user = ?");
        $stmt->execute([$hashedTest, 'test.admin@gsb.local']);
        echo "Compte test.admin@gsb.local mis a jour avec le mot de passe: $testPassword\n";
    } else {
        // Creer le compte s'il n'existe pas
        $stmt = $pdo->prepare("INSERT INTO Utilisateur (nom_user, prenom_user, email_user, mot_de_passe, rôle_user) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute(['Admin', 'Test', 'test.admin@gsb.local', $hashedTest, 'Admin']);
        echo "Compte test.admin@gsb.local cree avec le mot de passe: $testPassword\n";
    }

    echo "\n</pre>";
    echo "<div style='background: #10b981; color: white; padding: 20px; border-radius: 8px; margin-top: 20px;'>";
    echo "<h3>Mots de passe corriges avec succes !</h3>";
    echo "<p><strong>Comptes disponibles:</strong></p>";
    echo "<ul>";
    echo "<li>admin@gsb.local / Admin123!@#</li>";
    echo "<li>test.admin@gsb.local / Test1234@</li>";
    echo "<li>sophie.martin@gsb.local / Admin123!@#</li>";
    echo "<li>thomas.durand@gsb.local / Admin123!@#</li>";
    echo "</ul>";
    echo "</div>";

    echo "<div style='background: #ef4444; color: white; padding: 20px; border-radius: 8px; margin-top: 20px;'>";
    echo "<strong>IMPORTANT:</strong> Supprimez ce fichier (fix_passwords.php) immediatement !";
    echo "</div>";

    echo "<div style='margin-top: 20px;'>";
    echo "<a href='index.html' style='display: inline-block; background: #3b82f6; color: white; padding: 12px 24px; border-radius: 8px; text-decoration: none;'>Retour a l'application</a>";
    echo "</div>";

} catch (PDOException $e) {
    echo "<div style='background: #ef4444; color: white; padding: 20px; border-radius: 8px;'>";
    echo "<h3>Erreur</h3>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
}
