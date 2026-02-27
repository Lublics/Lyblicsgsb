<?php
/**
 * API d'authentification
 *
 * Gere la connexion, l'inscription, la deconnexion et la gestion de session
 *
 * @package GSB_Reservation
 * @version 2.0.0
 */

require_once __DIR__ . '/../config/database.php';

// Initialiser la session securisee
initSecureSession();

// Configurer CORS
setCorsHeaders();

header('Content-Type: application/json; charset=utf-8');

// Gerer les requetes OPTIONS (preflight CORS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$db = Database::getInstance()->getConnection();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'login':
        login($db);
        break;
    case 'register':
        register($db);
        break;
    case 'logout':
        logout();
        break;
    case 'session':
        getSession();
        break;
    case 'forgot':
        forgotPassword($db);
        break;
    case 'password-rules':
        getPasswordRules();
        break;
    case 'csrf-token':
        getCsrfToken();
        break;
    default:
        jsonResponse(['error' => 'Action non reconnue'], 400);
}

/**
 * Connexion utilisateur avec rate limiting
 */
function login($db) {
    // Verifier le rate limiting
    $rateCheck = RateLimiter::check('login');
    if (!$rateCheck['allowed']) {
        Logger::warning('Login rate limit exceeded', ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown']);
        jsonResponse([
            'error' => $rateCheck['message'],
            'retry_after' => $rateCheck['retry_after']
        ], 429);
    }

    $data = json_decode(file_get_contents('php://input'), true);

    // Validation des champs
    if (empty($data['email']) || empty($data['password'])) {
        jsonResponse(['error' => 'Email et mot de passe requis'], 400);
    }

    $email = sanitizeString($data['email']);

    if (!validateEmail($email)) {
        jsonResponse(['error' => 'Format d\'email invalide'], 400);
    }

    // Rechercher l'utilisateur
    $stmt = $db->prepare("SELECT * FROM Utilisateur WHERE email_user = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($data['password'], $user['mot_de_passe'])) {
        Logger::info('Login failed', ['email' => $email]);
        jsonResponse(['error' => 'Email ou mot de passe incorrect'], 401);
    }

    // Connexion reussie - reinitialiser le rate limiter
    RateLimiter::reset('login');

    // Regenerer l'ID de session pour eviter la fixation de session
    session_regenerate_id(true);

    // Stocker les informations de session
    $_SESSION['user_id'] = $user['id_utilisateur'];
    $_SESSION['email'] = $user['email_user'];
    $_SESSION['nom'] = $user['nom_user'];
    $_SESSION['prenom'] = $user['prenom_user'];
    $_SESSION['role'] = $user['rôle_user'];
    $_SESSION['_login_time'] = time();
    $_SESSION['_last_activity'] = time();

    // Generer un nouveau token CSRF
    $csrfToken = CSRF::generateToken();

    // Log de l'audit
    Logger::audit('LOGIN_SUCCESS', $user['id_utilisateur'], ['email' => $email]);
    Logger::info('User logged in', ['user_id' => $user['id_utilisateur'], 'email' => $email]);

    jsonResponse([
        'success' => true,
        'user' => [
            'id' => $user['id_utilisateur'],
            'nom' => $user['nom_user'],
            'prenom' => $user['prenom_user'],
            'email' => $user['email_user'],
            'role' => $user['rôle_user']
        ],
        'csrf_token' => $csrfToken
    ]);
}

/**
 * Inscription d'un nouvel utilisateur
 */
function register($db) {
    // Rate limiting pour les inscriptions
    $rateCheck = RateLimiter::check('register', 3, 300); // 3 inscriptions par 5 minutes
    if (!$rateCheck['allowed']) {
        jsonResponse([
            'error' => $rateCheck['message'],
            'retry_after' => $rateCheck['retry_after']
        ], 429);
    }

    $data = json_decode(file_get_contents('php://input'), true);

    // Validation des champs requis
    if (empty($data['nom']) || empty($data['prenom']) || empty($data['email']) || empty($data['password'])) {
        jsonResponse(['error' => 'Tous les champs sont requis'], 400);
    }

    // Sanitization
    $nom = sanitizeString($data['nom']);
    $prenom = sanitizeString($data['prenom']);
    $email = sanitizeString($data['email']);

    // Validation du nom et prenom
    if (strlen($nom) < 2 || strlen($nom) > 50) {
        jsonResponse(['error' => 'Le nom doit contenir entre 2 et 50 caracteres'], 400);
    }

    if (strlen($prenom) < 2 || strlen($prenom) > 50) {
        jsonResponse(['error' => 'Le prenom doit contenir entre 2 et 50 caracteres'], 400);
    }

    // Validation de l'email
    if (!validateEmail($email)) {
        jsonResponse(['error' => 'Email invalide'], 400);
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

    // Hasher le mot de passe
    $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT, ['cost' => 12]);

    // Role par defaut: Employe (les admins ne peuvent etre crees que par d'autres admins)
    $role = 'Employe';

    try {
        $stmt = $db->prepare("INSERT INTO Utilisateur (nom_user, prenom_user, email_user, mot_de_passe, rôle_user, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$nom, $prenom, $email, $hashedPassword, $role]);

        $userId = $db->lastInsertId();

        // Regenerer l'ID de session
        session_regenerate_id(true);

        // Stocker les informations de session
        $_SESSION['user_id'] = $userId;
        $_SESSION['email'] = $email;
        $_SESSION['nom'] = $nom;
        $_SESSION['prenom'] = $prenom;
        $_SESSION['role'] = $role;
        $_SESSION['_login_time'] = time();
        $_SESSION['_last_activity'] = time();

        // Generer un token CSRF
        $csrfToken = CSRF::generateToken();

        // Log de l'audit
        Logger::audit('USER_REGISTERED', $userId, ['email' => $email]);
        Logger::info('New user registered', ['user_id' => $userId, 'email' => $email]);

        jsonResponse([
            'success' => true,
            'user' => [
                'id' => $userId,
                'nom' => $nom,
                'prenom' => $prenom,
                'email' => $email,
                'role' => $role
            ],
            'csrf_token' => $csrfToken
        ], 201);

    } catch (PDOException $e) {
        Logger::error('Registration failed', ['error' => $e->getMessage(), 'email' => $email]);
        jsonResponse(['error' => 'Erreur lors de l\'inscription'], 500);
    }
}

/**
 * Deconnexion utilisateur
 */
function logout() {
    $userId = $_SESSION['user_id'] ?? 'unknown';

    // Log de l'audit
    if ($userId !== 'unknown') {
        Logger::audit('LOGOUT', $userId);
    }

    // Detruire toutes les variables de session
    $_SESSION = [];

    // Detruire le cookie de session
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }

    // Detruire la session
    session_destroy();

    jsonResponse(['success' => true, 'message' => 'Deconnexion reussie']);
}

/**
 * Recuperer les informations de session
 */
function getSession() {
    if (isset($_SESSION['user_id'])) {
        // Mettre a jour le timestamp d'activite
        $_SESSION['_last_activity'] = time();

        // Verifier l'expiration
        if (isset($_SESSION['_login_time']) && (time() - $_SESSION['_login_time']) > (SESSION_LIFETIME * 60)) {
            Logger::info('Session expired during check', ['user_id' => $_SESSION['user_id']]);
            session_destroy();
            jsonResponse(['authenticated' => false, 'reason' => 'session_expired']);
        }

        jsonResponse([
            'authenticated' => true,
            'user' => [
                'id' => $_SESSION['user_id'],
                'nom' => $_SESSION['nom'],
                'prenom' => $_SESSION['prenom'],
                'email' => $_SESSION['email'],
                'role' => $_SESSION['role']
            ],
            'csrf_token' => CSRF::getToken()
        ]);
    } else {
        jsonResponse(['authenticated' => false]);
    }
}

/**
 * Mot de passe oublie
 */
function forgotPassword($db) {
    // Rate limiting strict pour cette fonctionnalite
    $rateCheck = RateLimiter::check('forgot_password', 3, 600); // 3 tentatives par 10 minutes
    if (!$rateCheck['allowed']) {
        jsonResponse([
            'error' => $rateCheck['message'],
            'retry_after' => $rateCheck['retry_after']
        ], 429);
    }

    $data = json_decode(file_get_contents('php://input'), true);

    if (empty($data['email'])) {
        jsonResponse(['error' => 'Email requis'], 400);
    }

    $email = sanitizeString($data['email']);

    // Log la demande (sans reveler si l'email existe)
    Logger::info('Password reset requested', ['email' => $email]);

    // Verifier si l'utilisateur existe (sans reveler l'information)
    $stmt = $db->prepare("SELECT id_utilisateur FROM Utilisateur WHERE email_user = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user) {
        // Ici, on enverrait normalement un email avec un token de reinitialisation
        // Pour cette demo, on log simplement l'action
        Logger::audit('PASSWORD_RESET_REQUESTED', $user['id_utilisateur'], ['email' => $email]);
    }

    // Toujours retourner le meme message pour ne pas reveler si l'email existe
    jsonResponse([
        'success' => true,
        'message' => 'Si cet email existe, un lien de reinitialisation a ete envoye'
    ]);
}

/**
 * Obtenir les regles de mot de passe
 */
function getPasswordRules() {
    jsonResponse(PasswordValidator::getRules());
}

/**
 * Obtenir un token CSRF
 */
function getCsrfToken() {
    jsonResponse(['csrf_token' => CSRF::getToken()]);
}
