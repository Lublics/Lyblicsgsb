<?php
/**
 * API de gestion des utilisateurs
 *
 * @package GSB_Reservation
 * @version 2.0.0
 */

require_once __DIR__ . '/../config/database.php';

initSecureSession();
setCorsHeaders();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$db = Database::getInstance()->getConnection();
$method = getRequestMethod();

switch ($method) {
    case 'GET':
        if (isset($_GET['id'])) {
            getUser($db, $_GET['id']);
        } else {
            getUsers($db);
        }
        break;
    case 'POST':
        createUser($db);
        break;
    case 'PUT':
        updateUser($db);
        break;
    case 'DELETE':
        deleteUser($db);
        break;
    default:
        jsonResponse(['error' => 'Methode non autorisee'], 405);
}

/**
 * Recuperer tous les utilisateurs (admin seulement)
 */
function getUsers($db) {
    checkAdmin();

    $stmt = $db->query("SELECT id_utilisateur, nom_user, prenom_user, email_user, rôle_user, created_at FROM Utilisateur ORDER BY nom_user");
    $users = $stmt->fetchAll();

    $formattedUsers = array_map(function($user) {
        return [
            'id' => (int)$user['id_utilisateur'],
            'name' => $user['prenom_user'] . ' ' . $user['nom_user'],
            'nom' => $user['nom_user'],
            'prenom' => $user['prenom_user'],
            'email' => $user['email_user'],
            'role' => $user['rôle_user'],
            'created_at' => $user['created_at'] ?? null
        ];
    }, $users);

    jsonResponse($formattedUsers);
}

/**
 * Recuperer un utilisateur par son ID
 */
function getUser($db, $id) {
    $session = checkAuth();
    $id = (int)$id;

    // Un utilisateur peut voir ses propres infos, admin peut voir tout le monde
    if ($session['role'] !== 'Admin' && $session['user_id'] != $id) {
        jsonResponse(['error' => 'Non autorise'], 403);
    }

    $stmt = $db->prepare("SELECT id_utilisateur, nom_user, prenom_user, email_user, rôle_user, created_at FROM Utilisateur WHERE id_utilisateur = ?");
    $stmt->execute([$id]);
    $user = $stmt->fetch();

    if (!$user) {
        jsonResponse(['error' => 'Utilisateur non trouve'], 404);
    }

    jsonResponse([
        'id' => (int)$user['id_utilisateur'],
        'name' => $user['prenom_user'] . ' ' . $user['nom_user'],
        'nom' => $user['nom_user'],
        'prenom' => $user['prenom_user'],
        'email' => $user['email_user'],
        'role' => $user['rôle_user'],
        'created_at' => $user['created_at'] ?? null
    ]);
}

/**
 * Creer un nouvel utilisateur (admin seulement)
 */
function createUser($db) {
    $session = checkAdmin();
    verifyCsrf();

    $data = getRequestBody();

    // Validation des champs requis
    if (empty($data['nom']) || empty($data['prenom']) || empty($data['email']) || empty($data['password'])) {
        jsonResponse(['error' => 'Tous les champs sont requis'], 400);
    }

    $nom = sanitizeString($data['nom']);
    $prenom = sanitizeString($data['prenom']);
    $email = sanitizeString($data['email']);
    $role = sanitizeString($data['role'] ?? 'Employe');

    // Validation
    if (strlen($nom) < 2 || strlen($nom) > 50) {
        jsonResponse(['error' => 'Le nom doit contenir entre 2 et 50 caracteres'], 400);
    }

    if (strlen($prenom) < 2 || strlen($prenom) > 50) {
        jsonResponse(['error' => 'Le prenom doit contenir entre 2 et 50 caracteres'], 400);
    }

    if (!validateEmail($email)) {
        jsonResponse(['error' => 'Email invalide'], 400);
    }

    // Valider le role
    $validRoles = ['Admin', 'Delegue', 'Employe'];
    if (!in_array($role, $validRoles)) {
        jsonResponse(['error' => 'Role invalide'], 400);
    }

    // Validation du mot de passe
    $passwordValidation = PasswordValidator::validate($data['password']);
    if (!$passwordValidation['valid']) {
        jsonResponse([
            'error' => 'Mot de passe invalide',
            'details' => $passwordValidation['errors']
        ], 400);
    }

    // Verifier si l'email existe deja
    $stmt = $db->prepare("SELECT id_utilisateur FROM Utilisateur WHERE email_user = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        jsonResponse(['error' => 'Cet email est deja utilise'], 400);
    }

    $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT, ['cost' => 12]);

    try {
        $stmt = $db->prepare("INSERT INTO Utilisateur (nom_user, prenom_user, email_user, mot_de_passe, rôle_user, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$nom, $prenom, $email, $hashedPassword, $role]);

        $userId = $db->lastInsertId();

        Logger::audit('USER_CREATED_BY_ADMIN', $session['user_id'], [
            'new_user_id' => $userId,
            'email' => $email,
            'role' => $role
        ]);
        Logger::info('User created by admin', ['new_user_id' => $userId, 'admin_id' => $session['user_id']]);
        ActivityLogger::log('USER_CREATED', $session, 'utilisateur', $userId, $email);

        jsonResponse([
            'success' => true,
            'id' => $userId,
            'message' => 'Utilisateur cree avec succes'
        ], 201);

    } catch (PDOException $e) {
        Logger::error('User creation failed', ['error' => $e->getMessage()]);
        jsonResponse(['error' => 'Erreur lors de la creation'], 500);
    }
}

/**
 * Mettre a jour un utilisateur
 */
function updateUser($db) {
    $session = checkAuth();
    verifyCsrf();

    $data = getRequestBody();

    if (empty($data['id'])) {
        jsonResponse(['error' => 'ID utilisateur requis'], 400);
    }

    $userId = (int)$data['id'];

    // Seul l'admin peut modifier le role d'un utilisateur
    // Un utilisateur peut modifier ses propres infos (sauf role)
    if ($session['role'] !== 'Admin' && $session['user_id'] != $userId) {
        jsonResponse(['error' => 'Non autorise'], 403);
    }

    // Verifier que l'utilisateur existe
    $stmt = $db->prepare("SELECT * FROM Utilisateur WHERE id_utilisateur = ?");
    $stmt->execute([$userId]);
    $existingUser = $stmt->fetch();

    if (!$existingUser) {
        jsonResponse(['error' => 'Utilisateur non trouve'], 404);
    }

    $updates = [];
    $params = [];

    if (isset($data['nom'])) {
        $nom = sanitizeString($data['nom']);
        if (strlen($nom) < 2 || strlen($nom) > 50) {
            jsonResponse(['error' => 'Le nom doit contenir entre 2 et 50 caracteres'], 400);
        }
        $updates[] = "nom_user = ?";
        $params[] = $nom;
    }

    if (isset($data['prenom'])) {
        $prenom = sanitizeString($data['prenom']);
        if (strlen($prenom) < 2 || strlen($prenom) > 50) {
            jsonResponse(['error' => 'Le prenom doit contenir entre 2 et 50 caracteres'], 400);
        }
        $updates[] = "prenom_user = ?";
        $params[] = $prenom;
    }

    if (isset($data['email'])) {
        $email = sanitizeString($data['email']);
        if (!validateEmail($email)) {
            jsonResponse(['error' => 'Email invalide'], 400);
        }

        // Verifier que l'email n'est pas deja pris
        $stmt = $db->prepare("SELECT id_utilisateur FROM Utilisateur WHERE email_user = ? AND id_utilisateur != ?");
        $stmt->execute([$email, $userId]);
        if ($stmt->fetch()) {
            jsonResponse(['error' => 'Cet email est deja utilise'], 400);
        }
        $updates[] = "email_user = ?";
        $params[] = $email;
    }

    if (isset($data['password']) && !empty($data['password'])) {
        // Validation du mot de passe
        $passwordValidation = PasswordValidator::validate($data['password']);
        if (!$passwordValidation['valid']) {
            jsonResponse([
                'error' => 'Mot de passe invalide',
                'details' => $passwordValidation['errors']
            ], 400);
        }

        $updates[] = "mot_de_passe = ?";
        $params[] = password_hash($data['password'], PASSWORD_DEFAULT, ['cost' => 12]);
    }

    if (isset($data['role']) && $session['role'] === 'Admin') {
        $role = sanitizeString($data['role']);
        $validRoles = ['Admin', 'Delegue', 'Employe'];
        if (!in_array($role, $validRoles)) {
            jsonResponse(['error' => 'Role invalide'], 400);
        }
        $updates[] = "rôle_user = ?";
        $params[] = $role;
    }

    if (empty($updates)) {
        jsonResponse(['error' => 'Aucune donnee a mettre a jour'], 400);
    }

    $updates[] = "updated_at = NOW()";
    $params[] = $userId;

    try {
        $sql = "UPDATE Utilisateur SET " . implode(', ', $updates) . " WHERE id_utilisateur = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        // Mettre a jour la session si c'est l'utilisateur courant
        if ($session['user_id'] == $userId) {
            if (isset($data['nom'])) $_SESSION['nom'] = sanitizeString($data['nom']);
            if (isset($data['prenom'])) $_SESSION['prenom'] = sanitizeString($data['prenom']);
            if (isset($data['email'])) $_SESSION['email'] = sanitizeString($data['email']);
        }

        Logger::audit('USER_UPDATED', $session['user_id'], ['target_user_id' => $userId]);
        Logger::info('User updated', ['user_id' => $userId, 'updated_by' => $session['user_id']]);
        ActivityLogger::log('USER_UPDATED', $session, 'utilisateur', $userId, $data['email'] ?? '');

        jsonResponse(['success' => true, 'message' => 'Utilisateur mis a jour']);

    } catch (PDOException $e) {
        Logger::error('User update failed', ['error' => $e->getMessage(), 'user_id' => $userId]);
        jsonResponse(['error' => 'Erreur lors de la mise a jour'], 500);
    }
}

/**
 * Supprimer un utilisateur (admin seulement)
 */
function deleteUser($db) {
    $session = checkAdmin();
    verifyCsrf();

    $data = getRequestBody();

    if (empty($data['id'])) {
        jsonResponse(['error' => 'ID utilisateur requis'], 400);
    }

    $userId = (int)$data['id'];

    // Ne pas permettre de supprimer son propre compte
    if ($session['user_id'] == $userId) {
        jsonResponse(['error' => 'Vous ne pouvez pas supprimer votre propre compte'], 400);
    }

    // Verifier que l'utilisateur existe
    $stmt = $db->prepare("SELECT * FROM Utilisateur WHERE id_utilisateur = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        jsonResponse(['error' => 'Utilisateur non trouve'], 404);
    }

    // Verifier s'il y a des reservations actives
    $stmt = $db->prepare("SELECT COUNT(*) FROM Reservation WHERE id_utilisateur = ? AND date_fin > NOW() AND statut_reservation != 'Annulee'");
    $stmt->execute([$userId]);
    $activeBookings = $stmt->fetchColumn();

    if ($activeBookings > 0) {
        jsonResponse(['error' => 'Impossible de supprimer : l\'utilisateur a des reservations actives'], 400);
    }

    try {
        $stmt = $db->prepare("DELETE FROM Utilisateur WHERE id_utilisateur = ?");
        $stmt->execute([$userId]);

        Logger::audit('USER_DELETED', $session['user_id'], [
            'deleted_user_id' => $userId,
            'email' => $user['email_user']
        ]);
        Logger::info('User deleted', ['user_id' => $userId, 'deleted_by' => $session['user_id']]);
        ActivityLogger::log('USER_DELETED', $session, 'utilisateur', $userId, $user['email_user']);

        jsonResponse(['success' => true, 'message' => 'Utilisateur supprime']);

    } catch (PDOException $e) {
        Logger::error('User deletion failed', ['error' => $e->getMessage(), 'user_id' => $userId]);
        jsonResponse(['error' => 'Erreur lors de la suppression'], 500);
    }
}
