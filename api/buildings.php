<?php
/**
 * API de gestion des batiments
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
            getBuilding($db, $_GET['id']);
        } else {
            getBuildings($db);
        }
        break;
    case 'POST':
        createBuilding($db);
        break;
    case 'PUT':
        updateBuilding($db);
        break;
    case 'DELETE':
        deleteBuilding($db);
        break;
    default:
        jsonResponse(['error' => 'Methode non autorisee'], 405);
}

/**
 * Recuperer tous les batiments
 */
function getBuildings($db) {
    checkAuth();

    $sql = "SELECT b.*, COUNT(s.id_salle) as nb_salles
            FROM Batiment b
            LEFT JOIN Salle s ON b.id_batiment = s.id_batiment
            GROUP BY b.id_batiment
            ORDER BY b.nom_batiment";

    $stmt = $db->query($sql);
    $buildings = $stmt->fetchAll();

    $formatted = array_map(function($b) {
        return [
            'id' => (int)$b['id_batiment'],
            'name' => $b['nom_batiment'],
            'address' => $b['adresse_batiment'],
            'roomCount' => (int)$b['nb_salles'],
            'created_at' => $b['created_at'] ?? null
        ];
    }, $buildings);

    jsonResponse($formatted);
}

/**
 * Recuperer un batiment par son ID
 */
function getBuilding($db, $id) {
    checkAuth();
    $id = (int)$id;

    $sql = "SELECT b.*, COUNT(s.id_salle) as nb_salles
            FROM Batiment b
            LEFT JOIN Salle s ON b.id_batiment = s.id_batiment
            WHERE b.id_batiment = ?
            GROUP BY b.id_batiment";

    $stmt = $db->prepare($sql);
    $stmt->execute([$id]);
    $b = $stmt->fetch();

    if (!$b) {
        jsonResponse(['error' => 'Batiment non trouve'], 404);
    }

    jsonResponse([
        'id' => (int)$b['id_batiment'],
        'name' => $b['nom_batiment'],
        'address' => $b['adresse_batiment'],
        'roomCount' => (int)$b['nb_salles'],
        'created_at' => $b['created_at'] ?? null
    ]);
}

/**
 * Creer un nouveau batiment
 */
function createBuilding($db) {
    $session = checkAdmin();
    verifyCsrf();

    $data = getRequestBody();

    if (empty($data['name'])) {
        jsonResponse(['error' => 'Nom du batiment requis'], 400);
    }

    $name = sanitizeString($data['name']);
    $address = sanitizeString($data['address'] ?? '');

    if (strlen($name) < 2 || strlen($name) > 100) {
        jsonResponse(['error' => 'Le nom doit contenir entre 2 et 100 caracteres'], 400);
    }

    try {
        $stmt = $db->prepare("INSERT INTO Batiment (nom_batiment, adresse_batiment, created_at) VALUES (?, ?, NOW())");
        $stmt->execute([$name, $address]);

        $buildingId = $db->lastInsertId();

        Logger::audit('BUILDING_CREATED', $session['user_id'], ['building_id' => $buildingId, 'name' => $name]);
        ActivityLogger::log('BUILDING_CREATED', $session, 'batiment', $buildingId, $name);

        jsonResponse([
            'success' => true,
            'id' => $buildingId,
            'message' => 'Batiment cree avec succes'
        ], 201);

    } catch (PDOException $e) {
        Logger::error('Building creation failed', ['error' => $e->getMessage()]);
        jsonResponse(['error' => 'Erreur lors de la creation du batiment'], 500);
    }
}

/**
 * Mettre a jour un batiment
 */
function updateBuilding($db) {
    $session = checkAdmin();
    verifyCsrf();

    $data = getRequestBody();

    if (empty($data['id'])) {
        jsonResponse(['error' => 'ID du batiment requis'], 400);
    }

    $buildingId = (int)$data['id'];

    $stmt = $db->prepare("SELECT * FROM Batiment WHERE id_batiment = ?");
    $stmt->execute([$buildingId]);
    if (!$stmt->fetch()) {
        jsonResponse(['error' => 'Batiment non trouve'], 404);
    }

    $updates = [];
    $params = [];

    if (isset($data['name'])) {
        $name = sanitizeString($data['name']);
        if (strlen($name) < 2 || strlen($name) > 100) {
            jsonResponse(['error' => 'Le nom doit contenir entre 2 et 100 caracteres'], 400);
        }
        $updates[] = "nom_batiment = ?";
        $params[] = $name;
    }

    if (isset($data['address'])) {
        $updates[] = "adresse_batiment = ?";
        $params[] = sanitizeString($data['address']);
    }

    if (empty($updates)) {
        jsonResponse(['error' => 'Aucune donnee a mettre a jour'], 400);
    }

    $params[] = $buildingId;

    try {
        $sql = "UPDATE Batiment SET " . implode(', ', $updates) . " WHERE id_batiment = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        Logger::audit('BUILDING_UPDATED', $session['user_id'], ['building_id' => $buildingId]);
        ActivityLogger::log('BUILDING_UPDATED', $session, 'batiment', $buildingId, $data['name'] ?? '');

        jsonResponse(['success' => true, 'message' => 'Batiment mis a jour']);

    } catch (PDOException $e) {
        Logger::error('Building update failed', ['error' => $e->getMessage()]);
        jsonResponse(['error' => 'Erreur lors de la mise a jour'], 500);
    }
}

/**
 * Supprimer un batiment
 */
function deleteBuilding($db) {
    $session = checkAdmin();
    verifyCsrf();

    $data = getRequestBody();

    if (empty($data['id'])) {
        jsonResponse(['error' => 'ID du batiment requis'], 400);
    }

    $buildingId = (int)$data['id'];

    // Verifier s'il y a des salles associees
    $stmt = $db->prepare("SELECT COUNT(*) FROM Salle WHERE id_batiment = ?");
    $stmt->execute([$buildingId]);
    $count = $stmt->fetchColumn();

    if ($count > 0) {
        jsonResponse(['error' => 'Impossible de supprimer : des salles sont associees a ce batiment'], 400);
    }

    try {
        $stmt = $db->prepare("DELETE FROM Batiment WHERE id_batiment = ?");
        $stmt->execute([$buildingId]);

        if ($stmt->rowCount() === 0) {
            jsonResponse(['error' => 'Batiment non trouve'], 404);
        }

        Logger::audit('BUILDING_DELETED', $session['user_id'], ['building_id' => $buildingId]);
        ActivityLogger::log('BUILDING_DELETED', $session, 'batiment', $buildingId, null);

        jsonResponse(['success' => true, 'message' => 'Batiment supprime']);

    } catch (PDOException $e) {
        Logger::error('Building deletion failed', ['error' => $e->getMessage()]);
        jsonResponse(['error' => 'Erreur lors de la suppression'], 500);
    }
}
