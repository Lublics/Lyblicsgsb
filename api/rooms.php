<?php
/**
 * API de gestion des salles
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
            getRoom($db, $_GET['id']);
        } else {
            getRooms($db);
        }
        break;
    case 'POST':
        createRoom($db);
        break;
    case 'PUT':
        updateRoom($db);
        break;
    case 'DELETE':
        deleteRoom($db);
        break;
    default:
        jsonResponse(['error' => 'Methode non autorisee'], 405);
}

/**
 * Recuperer toutes les salles
 */
function getRooms($db) {
    $params = [];
    $having = [];

    $sql = "SELECT s.*, b.nom_batiment, b.id_batiment,
            GROUP_CONCAT(DISTINCT e.nom_equipement ORDER BY e.nom_equipement SEPARATOR ',') as equipements
            FROM Salle s
            LEFT JOIN Batiment b ON s.id_batiment = b.id_batiment
            LEFT JOIN Salle_Equipement se ON s.id_salle = se.id_salle
            LEFT JOIN Equipement e ON se.id_equipement = e.id_equipement
            GROUP BY s.id_salle";

    // Filtre par equipement : la salle doit avoir TOUS les equipements demandes
    if (isset($_GET['equipment']) && !empty($_GET['equipment'])) {
        $equipmentNames = array_map('trim', explode(',', $_GET['equipment']));
        $equipmentNames = array_filter($equipmentNames);
        if (!empty($equipmentNames)) {
            $placeholders = implode(',', array_fill(0, count($equipmentNames), '?'));
            $sql = "SELECT s.*, b.nom_batiment, b.id_batiment,
                    GROUP_CONCAT(DISTINCT e.nom_equipement ORDER BY e.nom_equipement SEPARATOR ',') as equipements
                    FROM Salle s
                    LEFT JOIN Batiment b ON s.id_batiment = b.id_batiment
                    LEFT JOIN Salle_Equipement se ON s.id_salle = se.id_salle
                    LEFT JOIN Equipement e ON se.id_equipement = e.id_equipement
                    WHERE s.id_salle IN (
                        SELECT se2.id_salle FROM Salle_Equipement se2
                        JOIN Equipement e2 ON se2.id_equipement = e2.id_equipement
                        WHERE e2.nom_equipement IN ($placeholders)
                        GROUP BY se2.id_salle
                        HAVING COUNT(DISTINCT e2.id_equipement) = ?
                    )
                    GROUP BY s.id_salle";
            $params = array_merge($equipmentNames, [count($equipmentNames)]);
        }
    }

    $sql .= " ORDER BY s.nom_salle";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rooms = $stmt->fetchAll();

    $formattedRooms = array_map(function($room) {
        $equipment = [];
        if (!empty($room['equipements'])) {
            $equipment = explode(',', $room['equipements']);
        }

        return [
            'id' => (int)$room['id_salle'],
            'name' => $room['nom_salle'],
            'description' => $room['description_salle'],
            'capacity' => (int)$room['capacite_salle'],
            'status' => StatusMapper::roomStatus($room['etat_salle']),
            'floor' => extractFloor($room['description_salle']),
            'building' => $room['nom_batiment'] ?? null,
            'buildingId' => $room['id_batiment'] ? (int)$room['id_batiment'] : null,
            'equipment' => $equipment,
            'created_at' => $room['created_at'] ?? null,
            'updated_at' => $room['updated_at'] ?? null
        ];
    }, $rooms);

    jsonResponse($formattedRooms);
}

/**
 * Recuperer une salle par son ID
 */
function getRoom($db, $id) {
    $id = (int)$id;

    $sql = "SELECT s.*, b.nom_batiment, b.id_batiment,
            GROUP_CONCAT(DISTINCT e.nom_equipement ORDER BY e.nom_equipement SEPARATOR ',') as equipements
            FROM Salle s
            LEFT JOIN Batiment b ON s.id_batiment = b.id_batiment
            LEFT JOIN Salle_Equipement se ON s.id_salle = se.id_salle
            LEFT JOIN Equipement e ON se.id_equipement = e.id_equipement
            WHERE s.id_salle = ?
            GROUP BY s.id_salle";

    $stmt = $db->prepare($sql);
    $stmt->execute([$id]);
    $room = $stmt->fetch();

    if (!$room) {
        jsonResponse(['error' => 'Salle non trouvee'], 404);
    }

    $equipment = [];
    if (!empty($room['equipements'])) {
        $equipment = explode(',', $room['equipements']);
    }

    jsonResponse([
        'id' => (int)$room['id_salle'],
        'name' => $room['nom_salle'],
        'description' => $room['description_salle'],
        'capacity' => (int)$room['capacite_salle'],
        'status' => StatusMapper::roomStatus($room['etat_salle']),
        'floor' => extractFloor($room['description_salle']),
        'building' => $room['nom_batiment'] ?? null,
        'buildingId' => $room['id_batiment'] ? (int)$room['id_batiment'] : null,
        'equipment' => $equipment,
        'created_at' => $room['created_at'] ?? null,
        'updated_at' => $room['updated_at'] ?? null
    ]);
}

/**
 * Creer une nouvelle salle
 */
function createRoom($db) {
    $session = checkAdmin();
    verifyCsrf();

    $data = getRequestBody();

    // Validation
    if (empty($data['name']) || empty($data['capacity'])) {
        jsonResponse(['error' => 'Nom et capacite requis'], 400);
    }

    $name = sanitizeString($data['name']);
    $capacity = (int)$data['capacity'];
    $floor = isset($data['floor']) ? (int)$data['floor'] : 0;
    $description = sanitizeString($data['description'] ?? '');

    // Validation des valeurs
    if (strlen($name) < 2 || strlen($name) > 50) {
        jsonResponse(['error' => 'Le nom doit contenir entre 2 et 50 caracteres'], 400);
    }

    if ($capacity < 1 || $capacity > 500) {
        jsonResponse(['error' => 'La capacite doit etre entre 1 et 500'], 400);
    }

    if ($floor < 0 || $floor > 100) {
        jsonResponse(['error' => 'L\'etage doit etre entre 0 et 100'], 400);
    }

    // Construire la description avec l'etage
    if ($floor > 0) {
        $description = "Etage $floor - " . $description;
    } elseif ($floor === 0) {
        $description = "RDC - " . $description;
    }

    $status = StatusMapper::roomStatusToDb($data['status'] ?? 'available');
    $buildingId = isset($data['buildingId']) ? (int)$data['buildingId'] : null;

    try {
        $db->beginTransaction();

        $stmt = $db->prepare("INSERT INTO Salle (nom_salle, description_salle, capacite_salle, etat_salle, id_batiment, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())");
        $stmt->execute([$name, $description, $capacity, $status, $buildingId]);

        $roomId = $db->lastInsertId();

        // Ajouter les equipements si fournis
        if (!empty($data['equipment']) && is_array($data['equipment'])) {
            addEquipmentToRoom($db, $roomId, $data['equipment']);
        }

        $db->commit();

        Logger::audit('ROOM_CREATED', $session['user_id'], ['room_id' => $roomId, 'name' => $name]);
        Logger::info('Room created', ['room_id' => $roomId, 'name' => $name, 'user_id' => $session['user_id']]);
        ActivityLogger::log('ROOM_CREATED', $session, 'salle', $roomId, $name);

        jsonResponse([
            'success' => true,
            'id' => $roomId,
            'message' => 'Salle creee avec succes'
        ], 201);

    } catch (PDOException $e) {
        $db->rollBack();
        Logger::error('Room creation failed', ['error' => $e->getMessage()]);
        jsonResponse(['error' => 'Erreur lors de la creation de la salle'], 500);
    }
}

/**
 * Mettre a jour une salle
 */
function updateRoom($db) {
    $session = checkAdmin();
    verifyCsrf();

    $data = getRequestBody();

    if (empty($data['id'])) {
        jsonResponse(['error' => 'ID de salle requis'], 400);
    }

    $roomId = (int)$data['id'];

    // Verifier que la salle existe
    $stmt = $db->prepare("SELECT * FROM Salle WHERE id_salle = ?");
    $stmt->execute([$roomId]);
    if (!$stmt->fetch()) {
        jsonResponse(['error' => 'Salle non trouvee'], 404);
    }

    $updates = [];
    $params = [];

    if (isset($data['name'])) {
        $name = sanitizeString($data['name']);
        if (strlen($name) < 2 || strlen($name) > 50) {
            jsonResponse(['error' => 'Le nom doit contenir entre 2 et 50 caracteres'], 400);
        }
        $updates[] = "nom_salle = ?";
        $params[] = $name;
    }

    if (isset($data['description'])) {
        $updates[] = "description_salle = ?";
        $params[] = sanitizeString($data['description']);
    }

    if (isset($data['capacity'])) {
        $capacity = (int)$data['capacity'];
        if ($capacity < 1 || $capacity > 500) {
            jsonResponse(['error' => 'La capacite doit etre entre 1 et 500'], 400);
        }
        $updates[] = "capacite_salle = ?";
        $params[] = $capacity;
    }

    if (isset($data['status'])) {
        $updates[] = "etat_salle = ?";
        $params[] = StatusMapper::roomStatusToDb($data['status']);
    }

    if (array_key_exists('buildingId', $data)) {
        $updates[] = "id_batiment = ?";
        $params[] = $data['buildingId'] ? (int)$data['buildingId'] : null;
    }

    if (empty($updates)) {
        jsonResponse(['error' => 'Aucune donnee a mettre a jour'], 400);
    }

    $updates[] = "updated_at = NOW()";
    $params[] = $roomId;

    try {
        $db->beginTransaction();

        $sql = "UPDATE Salle SET " . implode(', ', $updates) . " WHERE id_salle = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        // Mettre a jour les equipements si fournis
        if (isset($data['equipment']) && is_array($data['equipment'])) {
            // Supprimer les anciens equipements
            $stmt = $db->prepare("DELETE FROM Salle_Equipement WHERE id_salle = ?");
            $stmt->execute([$roomId]);

            // Ajouter les nouveaux
            addEquipmentToRoom($db, $roomId, $data['equipment']);
        }

        $db->commit();

        Logger::audit('ROOM_UPDATED', $session['user_id'], ['room_id' => $roomId]);
        Logger::info('Room updated', ['room_id' => $roomId, 'user_id' => $session['user_id']]);
        ActivityLogger::log('ROOM_UPDATED', $session, 'salle', $roomId, $data['name'] ?? '');

        jsonResponse(['success' => true, 'message' => 'Salle mise a jour']);

    } catch (PDOException $e) {
        $db->rollBack();
        Logger::error('Room update failed', ['error' => $e->getMessage(), 'room_id' => $roomId]);
        jsonResponse(['error' => 'Erreur lors de la mise a jour'], 500);
    }
}

/**
 * Supprimer une salle
 */
function deleteRoom($db) {
    $session = checkAdmin();
    verifyCsrf();

    $data = getRequestBody();

    if (empty($data['id'])) {
        jsonResponse(['error' => 'ID de salle requis'], 400);
    }

    $roomId = (int)$data['id'];

    // Verifier s'il y a des reservations en cours
    $stmt = $db->prepare("SELECT COUNT(*) FROM Reservation WHERE id_salle = ? AND date_fin > NOW() AND statut_reservation != 'Annulee'");
    $stmt->execute([$roomId]);
    $count = $stmt->fetchColumn();

    if ($count > 0) {
        jsonResponse(['error' => 'Impossible de supprimer : des reservations sont en cours'], 400);
    }

    try {
        $db->beginTransaction();

        // Supprimer les equipements associes
        $stmt = $db->prepare("DELETE FROM Salle_Equipement WHERE id_salle = ?");
        $stmt->execute([$roomId]);

        // Supprimer la salle
        $stmt = $db->prepare("DELETE FROM Salle WHERE id_salle = ?");
        $stmt->execute([$roomId]);

        if ($stmt->rowCount() === 0) {
            $db->rollBack();
            jsonResponse(['error' => 'Salle non trouvee'], 404);
        }

        $db->commit();

        Logger::audit('ROOM_DELETED', $session['user_id'], ['room_id' => $roomId]);
        Logger::info('Room deleted', ['room_id' => $roomId, 'user_id' => $session['user_id']]);
        ActivityLogger::log('ROOM_DELETED', $session, 'salle', $roomId, null);

        jsonResponse(['success' => true, 'message' => 'Salle supprimee']);

    } catch (PDOException $e) {
        $db->rollBack();
        Logger::error('Room deletion failed', ['error' => $e->getMessage(), 'room_id' => $roomId]);
        jsonResponse(['error' => 'Erreur lors de la suppression'], 500);
    }
}

/**
 * Ajouter des equipements a une salle
 */
function addEquipmentToRoom($db, $roomId, $equipmentNames) {
    foreach ($equipmentNames as $equipmentName) {
        $equipmentName = sanitizeString($equipmentName);
        if (empty($equipmentName)) continue;

        // Trouver ou creer l'equipement
        $stmt = $db->prepare("SELECT id_equipement FROM Equipement WHERE nom_equipement = ?");
        $stmt->execute([$equipmentName]);
        $equipment = $stmt->fetch();

        if (!$equipment) {
            $stmt = $db->prepare("INSERT INTO Equipement (nom_equipement) VALUES (?)");
            $stmt->execute([$equipmentName]);
            $equipmentId = $db->lastInsertId();
        } else {
            $equipmentId = $equipment['id_equipement'];
        }

        // Lier l'equipement a la salle
        $stmt = $db->prepare("INSERT IGNORE INTO Salle_Equipement (id_salle, id_equipement) VALUES (?, ?)");
        $stmt->execute([$roomId, $equipmentId]);
    }
}

/**
 * Extraire l'etage depuis la description
 */
function extractFloor($description) {
    if (preg_match('/[Ee]tage\s*(\d+)/i', $description, $matches)) {
        return (int)$matches[1];
    }
    if (stripos($description, 'RDC') !== false) {
        return 0;
    }
    return 1;
}

// StatusMapper est defini dans config/database.php
