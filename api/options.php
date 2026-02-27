<?php
/**
 * API de gestion des options/services
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
            getOption($db, $_GET['id']);
        } else {
            getOptions($db);
        }
        break;
    case 'POST':
        createOption($db);
        break;
    case 'PUT':
        updateOption($db);
        break;
    case 'DELETE':
        deleteOption($db);
        break;
    default:
        jsonResponse(['error' => 'Methode non autorisee'], 405);
}

/**
 * Recuperer toutes les options
 */
function getOptions($db) {
    $stmt = $db->query("SELECT * FROM Option_Service ORDER BY libelle_option");
    $options = $stmt->fetchAll();

    $formattedOptions = array_map(function($option) {
        return [
            'id' => (int)$option['id_option'],
            'name' => $option['libelle_option']
        ];
    }, $options);

    jsonResponse($formattedOptions);
}

/**
 * Recuperer une option par son ID
 */
function getOption($db, $id) {
    $id = (int)$id;

    $stmt = $db->prepare("SELECT * FROM Option_Service WHERE id_option = ?");
    $stmt->execute([$id]);
    $option = $stmt->fetch();

    if (!$option) {
        jsonResponse(['error' => 'Option non trouvee'], 404);
    }

    jsonResponse([
        'id' => (int)$option['id_option'],
        'name' => $option['libelle_option']
    ]);
}

/**
 * Creer une nouvelle option (admin seulement)
 */
function createOption($db) {
    $session = checkAdmin();
    verifyCsrf();

    $data = getRequestBody();

    if (empty($data['name'])) {
        jsonResponse(['error' => 'Nom de l\'option requis'], 400);
    }

    $name = sanitizeString($data['name']);

    if (strlen($name) < 2 || strlen($name) > 50) {
        jsonResponse(['error' => 'Le nom doit contenir entre 2 et 50 caracteres'], 400);
    }

    // Verifier si l'option existe deja
    $stmt = $db->prepare("SELECT id_option FROM Option_Service WHERE libelle_option = ?");
    $stmt->execute([$name]);
    if ($stmt->fetch()) {
        jsonResponse(['error' => 'Cette option existe deja'], 400);
    }

    try {
        $stmt = $db->prepare("INSERT INTO Option_Service (libelle_option) VALUES (?)");
        $stmt->execute([$name]);

        $optionId = $db->lastInsertId();

        Logger::audit('OPTION_CREATED', $session['user_id'], ['option_id' => $optionId, 'name' => $name]);
        Logger::info('Option created', ['option_id' => $optionId, 'name' => $name]);

        jsonResponse([
            'success' => true,
            'id' => $optionId,
            'message' => 'Option creee avec succes'
        ], 201);

    } catch (PDOException $e) {
        Logger::error('Option creation failed', ['error' => $e->getMessage()]);
        jsonResponse(['error' => 'Erreur lors de la creation'], 500);
    }
}

/**
 * Mettre a jour une option (admin seulement)
 */
function updateOption($db) {
    $session = checkAdmin();
    verifyCsrf();

    $data = getRequestBody();

    if (empty($data['id'])) {
        jsonResponse(['error' => 'ID de l\'option requis'], 400);
    }

    $optionId = (int)$data['id'];

    if (empty($data['name'])) {
        jsonResponse(['error' => 'Nom de l\'option requis'], 400);
    }

    $name = sanitizeString($data['name']);

    if (strlen($name) < 2 || strlen($name) > 50) {
        jsonResponse(['error' => 'Le nom doit contenir entre 2 et 50 caracteres'], 400);
    }

    // Verifier si l'option existe
    $stmt = $db->prepare("SELECT * FROM Option_Service WHERE id_option = ?");
    $stmt->execute([$optionId]);
    if (!$stmt->fetch()) {
        jsonResponse(['error' => 'Option non trouvee'], 404);
    }

    // Verifier si le nouveau nom n'est pas deja pris
    $stmt = $db->prepare("SELECT id_option FROM Option_Service WHERE libelle_option = ? AND id_option != ?");
    $stmt->execute([$name, $optionId]);
    if ($stmt->fetch()) {
        jsonResponse(['error' => 'Ce nom d\'option existe deja'], 400);
    }

    try {
        $stmt = $db->prepare("UPDATE Option_Service SET libelle_option = ? WHERE id_option = ?");
        $stmt->execute([$name, $optionId]);

        Logger::audit('OPTION_UPDATED', $session['user_id'], ['option_id' => $optionId, 'new_name' => $name]);
        Logger::info('Option updated', ['option_id' => $optionId, 'user_id' => $session['user_id']]);

        jsonResponse(['success' => true, 'message' => 'Option mise a jour']);

    } catch (PDOException $e) {
        Logger::error('Option update failed', ['error' => $e->getMessage(), 'option_id' => $optionId]);
        jsonResponse(['error' => 'Erreur lors de la mise a jour'], 500);
    }
}

/**
 * Supprimer une option (admin seulement)
 */
function deleteOption($db) {
    $session = checkAdmin();
    verifyCsrf();

    $data = getRequestBody();

    if (empty($data['id'])) {
        jsonResponse(['error' => 'ID de l\'option requis'], 400);
    }

    $optionId = (int)$data['id'];

    // Verifier si l'option est utilisee dans des reservations
    $stmt = $db->prepare("SELECT COUNT(*) FROM Reservation_Option WHERE id_option = ?");
    $stmt->execute([$optionId]);
    $usageCount = $stmt->fetchColumn();

    if ($usageCount > 0) {
        jsonResponse(['error' => 'Impossible de supprimer : cette option est utilisee dans ' . $usageCount . ' reservation(s)'], 400);
    }

    try {
        $stmt = $db->prepare("DELETE FROM Option_Service WHERE id_option = ?");
        $stmt->execute([$optionId]);

        if ($stmt->rowCount() === 0) {
            jsonResponse(['error' => 'Option non trouvee'], 404);
        }

        Logger::audit('OPTION_DELETED', $session['user_id'], ['option_id' => $optionId]);
        Logger::info('Option deleted', ['option_id' => $optionId, 'user_id' => $session['user_id']]);

        jsonResponse(['success' => true, 'message' => 'Option supprimee']);

    } catch (PDOException $e) {
        Logger::error('Option deletion failed', ['error' => $e->getMessage(), 'option_id' => $optionId]);
        jsonResponse(['error' => 'Erreur lors de la suppression'], 500);
    }
}
