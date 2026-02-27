<?php
/**
 * Configuration principale de l'application GSB Reservation
 *
 * @package GSB_Reservation
 * @version 2.0.0
 */

// Charger les variables d'environnement
loadEnv(__DIR__ . '/../.env');

// Configuration de la base de donnees (depuis .env)
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'GSB_Reservation');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');

// Configuration de l'application
define('APP_ENV', getenv('APP_ENV') ?: 'production');
define('APP_DEBUG', filter_var(getenv('APP_DEBUG'), FILTER_VALIDATE_BOOLEAN));
define('APP_URL', getenv('APP_URL') ?: 'http://localhost');

// Configuration securite
define('SESSION_LIFETIME', (int)(getenv('SESSION_LIFETIME') ?: 120));
define('CORS_ALLOWED_ORIGINS', getenv('CORS_ALLOWED_ORIGINS') ?: 'http://localhost');

// Rate limiting
define('RATE_LIMIT_REQUESTS', (int)(getenv('RATE_LIMIT_REQUESTS') ?: 5));
define('RATE_LIMIT_WINDOW', (int)(getenv('RATE_LIMIT_WINDOW') ?: 60));

// Logging
define('LOG_LEVEL', getenv('LOG_LEVEL') ?: 'info');
define('LOG_PATH', getenv('LOG_PATH') ?: __DIR__ . '/../logs/');

/**
 * Charge les variables d'environnement depuis un fichier .env
 */
function loadEnv($path) {
    if (!file_exists($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);

        // Ignorer les commentaires
        if (strpos($line, '#') === 0) {
            continue;
        }

        // Parser la ligne
        if (strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);

            // Retirer les guillemets
            $value = trim($value, '"\'');

            if (!getenv($name)) {
                putenv("$name=$value");
            }
        }
    }
}

/**
 * Classe Database - Singleton pour la connexion PDO
 */
class Database {
    private static $instance = null;
    private $pdo;

    private function __construct() {
        try {
            $this->pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
                ]
            );
        } catch (PDOException $e) {
            Logger::error('Database connection failed', ['error' => $e->getMessage()]);
            http_response_code(500);
            die(json_encode(['error' => 'Erreur de connexion a la base de donnees']));
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->pdo;
    }

    // Empecher le clonage
    private function __clone() {}

    // Empecher la deserialisation
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}

/**
 * Classe Logger - Systeme de journalisation
 */
class Logger {
    private static $levels = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3, 'critical' => 4];

    private static function shouldLog($level) {
        $configLevel = self::$levels[LOG_LEVEL] ?? 1;
        $messageLevel = self::$levels[$level] ?? 1;
        return $messageLevel >= $configLevel;
    }

    private static function log($level, $message, $context = []) {
        if (!self::shouldLog($level)) {
            return;
        }

        // Creer le dossier logs s'il n'existe pas
        $logPath = LOG_PATH;
        if (!is_dir($logPath)) {
            mkdir($logPath, 0755, true);
        }

        $logFile = $logPath . date('Y-m-d') . '.log';
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        $logEntry = "[$timestamp] [$level] $message$contextStr" . PHP_EOL;

        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }

    public static function debug($message, $context = []) { self::log('debug', $message, $context); }
    public static function info($message, $context = []) { self::log('info', $message, $context); }
    public static function warning($message, $context = []) { self::log('warning', $message, $context); }
    public static function error($message, $context = []) { self::log('error', $message, $context); }
    public static function critical($message, $context = []) { self::log('critical', $message, $context); }

    /**
     * Log une action d'audit (actions importantes utilisateur)
     */
    public static function audit($action, $userId, $details = []) {
        $logPath = LOG_PATH;
        if (!is_dir($logPath)) {
            mkdir($logPath, 0755, true);
        }

        $auditFile = $logPath . 'audit_' . date('Y-m') . '.log';
        $timestamp = date('Y-m-d H:i:s');
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $detailsStr = !empty($details) ? ' | ' . json_encode($details, JSON_UNESCAPED_UNICODE) : '';
        $logEntry = "[$timestamp] [user:$userId] [ip:$ip] $action$detailsStr" . PHP_EOL;

        file_put_contents($auditFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
}

/**
 * Classe ActivityLogger - Journalisation des actions en base de donnees
 */
class ActivityLogger {
    public static function log($actionType, $session, $targetType, $targetId = null, $targetLabel = null) {
        try {
            $db = Database::getInstance()->getConnection();
            $actorName = ($session['prenom'] ?? '') . ' ' . ($session['nom'] ?? '');
            $stmt = $db->prepare("
                INSERT INTO ActivityLog (action_type, actor_id, actor_name, actor_role, target_type, target_id, target_label)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $actionType,
                $session['user_id'],
                trim($actorName),
                $session['role'] ?? 'Employe',
                $targetType,
                $targetId,
                $targetLabel
            ]);
        } catch (\Exception $e) {
            Logger::error('ActivityLogger failed', ['error' => $e->getMessage()]);
        }
    }
}

/**
 * Classe RateLimiter - Protection contre les attaques par force brute
 */
class RateLimiter {
    private static $storageDir;

    private static function getStorageDir() {
        if (self::$storageDir === null) {
            self::$storageDir = sys_get_temp_dir() . '/gsb_rate_limit/';
            if (!is_dir(self::$storageDir)) {
                mkdir(self::$storageDir, 0755, true);
            }
        }
        return self::$storageDir;
    }

    /**
     * Verifie si l'IP a depasse la limite de requetes
     */
    public static function check($key, $maxRequests = null, $windowSeconds = null) {
        $maxRequests = $maxRequests ?? RATE_LIMIT_REQUESTS;
        $windowSeconds = $windowSeconds ?? RATE_LIMIT_WINDOW;

        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $identifier = md5($key . '_' . $ip);
        $file = self::getStorageDir() . $identifier . '.json';

        $now = time();
        $data = ['attempts' => [], 'blocked_until' => 0];

        if (file_exists($file)) {
            $data = json_decode(file_get_contents($file), true) ?? $data;
        }

        // Verifier si bloque
        if ($data['blocked_until'] > $now) {
            $remainingSeconds = $data['blocked_until'] - $now;
            return [
                'allowed' => false,
                'remaining' => 0,
                'retry_after' => $remainingSeconds,
                'message' => "Trop de tentatives. Reessayez dans $remainingSeconds secondes."
            ];
        }

        // Nettoyer les anciennes tentatives
        $data['attempts'] = array_filter($data['attempts'], function($timestamp) use ($now, $windowSeconds) {
            return $timestamp > ($now - $windowSeconds);
        });

        // Ajouter la tentative actuelle
        $data['attempts'][] = $now;
        $attemptCount = count($data['attempts']);

        // Verifier la limite
        if ($attemptCount > $maxRequests) {
            $data['blocked_until'] = $now + ($windowSeconds * 2); // Double la fenetre pour le blocage
            file_put_contents($file, json_encode($data));

            Logger::warning('Rate limit exceeded', ['key' => $key, 'ip' => $ip]);

            return [
                'allowed' => false,
                'remaining' => 0,
                'retry_after' => $windowSeconds * 2,
                'message' => 'Trop de tentatives. Veuillez patienter.'
            ];
        }

        file_put_contents($file, json_encode($data));

        return [
            'allowed' => true,
            'remaining' => $maxRequests - $attemptCount,
            'retry_after' => 0
        ];
    }

    /**
     * Reinitialise le compteur pour une cle donnee (apres succes)
     */
    public static function reset($key) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $identifier = md5($key . '_' . $ip);
        $file = self::getStorageDir() . $identifier . '.json';

        if (file_exists($file)) {
            unlink($file);
        }
    }
}

/**
 * Classe CSRF - Protection contre les attaques CSRF
 */
class CSRF {
    private static $tokenName = 'csrf_token';

    /**
     * Genere un nouveau token CSRF
     */
    public static function generateToken() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $token = bin2hex(random_bytes(32));
        $_SESSION[self::$tokenName] = $token;
        $_SESSION[self::$tokenName . '_time'] = time();

        return $token;
    }

    /**
     * Obtient le token actuel ou en genere un nouveau
     */
    public static function getToken() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!isset($_SESSION[self::$tokenName])) {
            return self::generateToken();
        }

        // Regenerer si le token a plus de 30 minutes
        $tokenAge = time() - ($_SESSION[self::$tokenName . '_time'] ?? 0);
        if ($tokenAge > 1800) {
            return self::generateToken();
        }

        return $_SESSION[self::$tokenName];
    }

    /**
     * Verifie la validite du token
     */
    public static function verifyToken($token) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (empty($token) || empty($_SESSION[self::$tokenName])) {
            return false;
        }

        return hash_equals($_SESSION[self::$tokenName], $token);
    }
}

/**
 * Classe de validation des mots de passe
 */
class PasswordValidator {
    const MIN_LENGTH = 12;
    const REQUIRE_UPPERCASE = true;
    const REQUIRE_LOWERCASE = true;
    const REQUIRE_NUMBER = true;
    const REQUIRE_SPECIAL = true;

    /**
     * Valide un mot de passe selon les regles de securite
     */
    public static function validate($password) {
        $errors = [];

        if (strlen($password) < self::MIN_LENGTH) {
            $errors[] = 'Le mot de passe doit contenir au moins ' . self::MIN_LENGTH . ' caracteres';
        }

        if (self::REQUIRE_UPPERCASE && !preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Le mot de passe doit contenir au moins une majuscule';
        }

        if (self::REQUIRE_LOWERCASE && !preg_match('/[a-z]/', $password)) {
            $errors[] = 'Le mot de passe doit contenir au moins une minuscule';
        }

        if (self::REQUIRE_NUMBER && !preg_match('/[0-9]/', $password)) {
            $errors[] = 'Le mot de passe doit contenir au moins un chiffre';
        }

        if (self::REQUIRE_SPECIAL && !preg_match('/[!@#$%^&*(),.?":{}|<>_\-+=\[\]\\\\\/]/', $password)) {
            $errors[] = 'Le mot de passe doit contenir au moins un caractere special (!@#$%^&*...)';
        }

        // Verifier les mots de passe courants
        $commonPasswords = ['password', '123456', 'password123', 'admin', 'letmein', 'welcome'];
        if (in_array(strtolower($password), $commonPasswords)) {
            $errors[] = 'Ce mot de passe est trop courant';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Retourne les regles de mot de passe pour l'affichage
     */
    public static function getRules() {
        return [
            'minLength' => self::MIN_LENGTH,
            'requireUppercase' => self::REQUIRE_UPPERCASE,
            'requireLowercase' => self::REQUIRE_LOWERCASE,
            'requireNumber' => self::REQUIRE_NUMBER,
            'requireSpecial' => self::REQUIRE_SPECIAL
        ];
    }
}

/**
 * Initialise une session securisee
 */
function initSecureSession() {
    if (session_status() === PHP_SESSION_NONE) {
        // Configuration securisee des sessions
        ini_set('session.cookie_httponly', 1);
        ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) ? 1 : 0);
        ini_set('session.cookie_samesite', 'Strict');
        ini_set('session.use_strict_mode', 1);
        ini_set('session.gc_maxlifetime', SESSION_LIFETIME * 60);

        session_start();

        // Regenerer l'ID de session periodiquement
        if (!isset($_SESSION['_last_regeneration'])) {
            $_SESSION['_last_regeneration'] = time();
        } elseif (time() - $_SESSION['_last_regeneration'] > 300) {
            session_regenerate_id(true);
            $_SESSION['_last_regeneration'] = time();
        }
    }
}

/**
 * Configure les en-tetes CORS de maniere securisee
 */
function setCorsHeaders() {
    $allowedOrigins = array_map('trim', explode(',', CORS_ALLOWED_ORIGINS));
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

    if (in_array($origin, $allowedOrigins) || APP_ENV === 'development') {
        header('Access-Control-Allow-Origin: ' . ($origin ?: '*'));
    }

    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Max-Age: 86400');
}

/**
 * Fonction helper pour les reponses JSON
 */
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Verifie l'authentification de la session
 */
function checkAuth() {
    initSecureSession();

    if (!isset($_SESSION['user_id'])) {
        Logger::debug('Authentication check failed - no session');
        jsonResponse(['error' => 'Non authentifie'], 401);
    }

    // Verifier l'expiration de la session
    if (isset($_SESSION['_login_time']) && (time() - $_SESSION['_login_time']) > (SESSION_LIFETIME * 60)) {
        Logger::info('Session expired', ['user_id' => $_SESSION['user_id']]);
        session_destroy();
        jsonResponse(['error' => 'Session expiree'], 401);
    }

    return $_SESSION;
}

/**
 * Verifie le role administrateur
 */
function checkAdmin() {
    $session = checkAuth();

    if ($session['role'] !== 'Admin') {
        Logger::warning('Admin access denied', ['user_id' => $session['user_id'], 'role' => $session['role']]);
        jsonResponse(['error' => 'Acces refuse'], 403);
    }

    return $session;
}

/**
 * Verifie le token CSRF pour les requetes de mutation (POST/PUT/DELETE)
 */
function verifyCsrf() {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($token) || !CSRF::verifyToken($token)) {
        jsonResponse(['error' => 'Token CSRF invalide'], 403);
    }
}

/**
 * Detecte la methode HTTP reelle (support _method pour PUT/DELETE via POST)
 */
function getRequestMethod() {
    global $_REQUEST_BODY;
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'POST') {
        $raw = file_get_contents('php://input');
        $_REQUEST_BODY = $raw;
        $data = json_decode($raw, true);
        if (isset($data['_method']) && in_array($data['_method'], ['PUT', 'DELETE'])) {
            $method = $data['_method'];
        }
    }
    return $method;
}

/**
 * Recupere le body JSON de la requete (supporte la relecture apres getRequestMethod)
 */
function getRequestBody() {
    global $_REQUEST_BODY;
    if (isset($_REQUEST_BODY)) {
        $data = json_decode($_REQUEST_BODY, true);
    } else {
        $data = json_decode(file_get_contents('php://input'), true);
    }
    if (isset($data['_method'])) {
        unset($data['_method']);
    }
    return $data;
}

/**
 * Sanitize une chaine de caracteres
 */
function sanitizeString($input) {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

/**
 * Valide un email
 */
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Valide une date au format Y-m-d
 */
function validateDate($date, $format = 'Y-m-d') {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

/**
 * Valide une heure au format H:i
 */
function validateTime($time) {
    return preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $time);
}

/**
 * Classe utilitaire pour mapper les statuts entre la base et l'API
 */
class StatusMapper {
    private static $roomStatusMap = [
        'Disponible' => 'available',
        'Occupee' => 'occupied',
        'Maintenance' => 'maintenance',
        'Reservee' => 'occupied'
    ];

    private static $roomStatusMapReverse = [
        'available' => 'Disponible',
        'occupied' => 'Occupee',
        'maintenance' => 'Maintenance'
    ];

    private static $bookingStatusMap = [
        'Confirmee' => 'confirmed',
        'En attente' => 'pending',
        'Annulee' => 'cancelled'
    ];

    private static $bookingStatusMapReverse = [
        'confirmed' => 'Confirmee',
        'pending' => 'En attente',
        'cancelled' => 'Annulee'
    ];

    public static function roomStatus($dbStatus) {
        return self::$roomStatusMap[$dbStatus] ?? 'available';
    }

    public static function roomStatusToDb($status) {
        return self::$roomStatusMapReverse[$status] ?? 'Disponible';
    }

    public static function bookingStatus($dbStatus) {
        return self::$bookingStatusMap[$dbStatus] ?? 'pending';
    }

    public static function bookingStatusToDb($status) {
        return self::$bookingStatusMapReverse[$status] ?? 'En attente';
    }
}
