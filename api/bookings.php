<?php
/**
 * API de gestion des reservations
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
            getBooking($db, $_GET['id']);
        } else {
            getBookings($db);
        }
        break;
    case 'POST':
        createBooking($db);
        break;
    case 'PUT':
        updateBooking($db);
        break;
    case 'DELETE':
        deleteBooking($db);
        break;
    default:
        jsonResponse(['error' => 'Methode non autorisee'], 405);
}

/**
 * Recuperer les reservations
 */
function getBookings($db) {
    $session = checkAuth();

    $params = [];
    $where = [];

    // Filtrer par utilisateur si pas admin
    if ($session['role'] !== 'Admin' && !isset($_GET['all'])) {
        if (isset($_GET['mine']) && $_GET['mine'] === 'true') {
            $where[] = "r.id_utilisateur = ?";
            $params[] = $session['user_id'];
        }
    }

    // Filtrer par salle
    if (isset($_GET['room_id'])) {
        $where[] = "r.id_salle = ?";
        $params[] = (int)$_GET['room_id'];
    }

    // Filtrer par date
    if (isset($_GET['date'])) {
        if (!validateDate($_GET['date'])) {
            jsonResponse(['error' => 'Format de date invalide'], 400);
        }
        $where[] = "DATE(r.date_debut) = ?";
        $params[] = $_GET['date'];
    }

    // Filtrer par periode
    if (isset($_GET['start_date']) && isset($_GET['end_date'])) {
        if (!validateDate($_GET['start_date']) || !validateDate($_GET['end_date'])) {
            jsonResponse(['error' => 'Format de date invalide'], 400);
        }
        $where[] = "DATE(r.date_debut) >= ? AND DATE(r.date_debut) <= ?";
        $params[] = $_GET['start_date'];
        $params[] = $_GET['end_date'];
    }

    // Filtrer par statut
    if (isset($_GET['status'])) {
        $status = StatusMapper::bookingStatusToDb($_GET['status']);
        $where[] = "r.statut_reservation = ?";
        $params[] = $status;
    }

    $sql = "SELECT r.*, s.nom_salle, u.nom_user, u.prenom_user, u.email_user
            FROM Reservation r
            JOIN Salle s ON r.id_salle = s.id_salle
            JOIN Utilisateur u ON r.id_utilisateur = u.id_utilisateur";

    if (!empty($where)) {
        $sql .= " WHERE " . implode(' AND ', $where);
    }

    $sql .= " ORDER BY r.date_debut DESC";

    // Pagination
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 50;
    $offset = ($page - 1) * $limit;

    $sql .= " LIMIT $limit OFFSET $offset";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $bookings = $stmt->fetchAll();

    // Batch : recuperer la derniere action pour toutes les reservations en une seule requete
    $lastActions = [];
    if (!empty($bookings)) {
        try {
            $bookingIds = array_column($bookings, 'id_reservation');
            $placeholders = implode(',', array_fill(0, count($bookingIds), '?'));
            $logSql = "SELECT a.target_id, a.action_type, a.actor_name, a.actor_role
                       FROM ActivityLog a
                       INNER JOIN (
                           SELECT target_id, MAX(created_at) as max_date
                           FROM ActivityLog
                           WHERE target_type = 'reservation' AND target_id IN ($placeholders)
                           GROUP BY target_id
                       ) latest ON a.target_id = latest.target_id AND a.created_at = latest.max_date AND a.target_type = 'reservation'";
            $logStmt = $db->prepare($logSql);
            $logStmt->execute($bookingIds);
            foreach ($logStmt->fetchAll() as $log) {
                $lastActions[$log['target_id']] = $log;
            }
        } catch (PDOException $e) {
            // Table ActivityLog pas encore creee - on continue sans
        }
    }

    $actionMap = [
        'BOOKING_CREATED' => 'Creee',
        'BOOKING_UPDATED' => 'Modifiee',
        'BOOKING_CANCELLED' => 'Annulee'
    ];

    $formattedBookings = array_map(function($booking) use ($db, $lastActions, $actionMap) {
        $options = getBookingOptions($db, $booking['id_reservation']);
        $ref = 'RES-' . str_pad($booking['id_reservation'], 3, '0', STR_PAD_LEFT);

        $actionText = 'Creee';
        $lastAction = $lastActions[$booking['id_reservation']] ?? null;
        if ($lastAction) {
            $label = $actionMap[$lastAction['action_type']] ?? $lastAction['action_type'];
            $actionText = $label . ' par ' . $lastAction['actor_name'] . ' (' . $lastAction['actor_role'] . ')';
        }

        return [
            'id' => (int)$booking['id_reservation'],
            'ref' => $ref,
            'roomId' => (int)$booking['id_salle'],
            'roomName' => $booking['nom_salle'],
            'date' => date('Y-m-d', strtotime($booking['date_debut'])),
            'start' => date('H:i', strtotime($booking['date_debut'])),
            'end' => date('H:i', strtotime($booking['date_fin'])),
            'subject' => $booking['objet_reservation'],
            'status' => StatusMapper::bookingStatus($booking['statut_reservation']),
            'user' => $booking['prenom_user'] . ' ' . $booking['nom_user'],
            'userEmail' => $booking['email_user'],
            'userId' => (int)$booking['id_utilisateur'],
            'lastAction' => $actionText,
            'options' => $options,
            'created_at' => $booking['created_at'] ?? null,
            'updated_at' => $booking['updated_at'] ?? null
        ];
    }, $bookings);

    jsonResponse($formattedBookings);
}

/**
 * Recuperer une reservation par son ID
 */
function getBooking($db, $id) {
    checkAuth();

    $id = (int)$id;

    $stmt = $db->prepare("SELECT r.*, s.nom_salle, u.nom_user, u.prenom_user, u.email_user
                          FROM Reservation r
                          JOIN Salle s ON r.id_salle = s.id_salle
                          JOIN Utilisateur u ON r.id_utilisateur = u.id_utilisateur
                          WHERE r.id_reservation = ?");
    $stmt->execute([$id]);
    $booking = $stmt->fetch();

    if (!$booking) {
        jsonResponse(['error' => 'Reservation non trouvee'], 404);
    }

    $options = getBookingOptions($db, $booking['id_reservation']);

    jsonResponse([
        'id' => (int)$booking['id_reservation'],
        'ref' => 'RES-' . str_pad($booking['id_reservation'], 3, '0', STR_PAD_LEFT),
        'roomId' => (int)$booking['id_salle'],
        'roomName' => $booking['nom_salle'],
        'date' => date('Y-m-d', strtotime($booking['date_debut'])),
        'start' => date('H:i', strtotime($booking['date_debut'])),
        'end' => date('H:i', strtotime($booking['date_fin'])),
        'subject' => $booking['objet_reservation'],
        'status' => StatusMapper::bookingStatus($booking['statut_reservation']),
        'user' => $booking['prenom_user'] . ' ' . $booking['nom_user'],
        'userEmail' => $booking['email_user'],
        'userId' => (int)$booking['id_utilisateur'],
        'options' => $options,
        'created_at' => $booking['created_at'] ?? null,
        'updated_at' => $booking['updated_at'] ?? null
    ]);
}

/**
 * Creer une nouvelle reservation
 */
function createBooking($db) {
    $session = checkAuth();
    verifyCsrf();

    $data = getRequestBody();

    // Validation des champs requis
    if (empty($data['roomId']) || empty($data['date']) || empty($data['start']) || empty($data['end']) || empty($data['subject'])) {
        jsonResponse(['error' => 'Tous les champs sont requis (salle, date, debut, fin, objet)'], 400);
    }

    $roomId = (int)$data['roomId'];
    $date = $data['date'];
    $start = $data['start'];
    $end = $data['end'];
    $subject = sanitizeString($data['subject']);

    // Validation des formats
    if (!validateDate($date)) {
        jsonResponse(['error' => 'Format de date invalide (YYYY-MM-DD attendu)'], 400);
    }

    if (!validateTime($start) || !validateTime($end)) {
        jsonResponse(['error' => 'Format d\'heure invalide (HH:MM attendu)'], 400);
    }

    if (strlen($subject) < 3 || strlen($subject) > 100) {
        jsonResponse(['error' => 'L\'objet doit contenir entre 3 et 100 caracteres'], 400);
    }

    // Verifier que la salle existe
    $stmt = $db->prepare("SELECT id_salle, etat_salle FROM Salle WHERE id_salle = ?");
    $stmt->execute([$roomId]);
    $room = $stmt->fetch();

    if (!$room) {
        jsonResponse(['error' => 'Salle non trouvee'], 404);
    }

    if ($room['etat_salle'] === 'Maintenance') {
        jsonResponse(['error' => 'Cette salle est en maintenance'], 400);
    }

    $dateDebut = $date . ' ' . $start . ':00';
    $dateFin = $date . ' ' . $end . ':00';

    // Verifier que la date n'est pas dans le passe
    if (strtotime($dateDebut) < time()) {
        jsonResponse(['error' => 'Impossible de reserver dans le passe'], 400);
    }

    // Verifier que la date de fin est apres la date de debut
    if (strtotime($dateFin) <= strtotime($dateDebut)) {
        jsonResponse(['error' => 'L\'heure de fin doit etre apres l\'heure de debut'], 400);
    }

    // Verifier les conflits de reservation
    $stmt = $db->prepare("SELECT id_reservation FROM Reservation
                          WHERE id_salle = ?
                          AND statut_reservation != 'Annulee'
                          AND ((date_debut < ? AND date_fin > ?)
                               OR (date_debut < ? AND date_fin > ?)
                               OR (date_debut >= ? AND date_fin <= ?))");
    $stmt->execute([
        $roomId,
        $dateFin, $dateDebut,
        $dateFin, $dateDebut,
        $dateDebut, $dateFin
    ]);

    if ($stmt->fetch()) {
        jsonResponse(['error' => 'Cette salle est deja reservee pour ce creneau'], 400);
    }

    // Creer la reservation
    $status = ($session['role'] === 'Admin') ? 'Confirmee' : 'En attente';

    try {
        $db->beginTransaction();

        $stmt = $db->prepare("INSERT INTO Reservation (date_debut, date_fin, objet_reservation, statut_reservation, id_utilisateur, id_salle, created_at, updated_at)
                              VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())");
        $stmt->execute([
            $dateDebut,
            $dateFin,
            $subject,
            $status,
            $session['user_id'],
            $roomId
        ]);

        $bookingId = $db->lastInsertId();

        // Ajouter les options si presentes
        if (!empty($data['options']) && is_array($data['options'])) {
            foreach ($data['options'] as $optionName) {
                $optionName = sanitizeString($optionName);
                if (empty($optionName)) continue;

                // Recuperer l'option (ne pas creer dynamiquement)
                $stmt = $db->prepare("SELECT id_option FROM Option_Service WHERE libelle_option = ?");
                $stmt->execute([$optionName]);
                $option = $stmt->fetch();

                if (!$option) {
                    continue; // Ignorer les options inconnues
                }
                $optionId = $option['id_option'];

                // Lier l'option a la reservation
                $stmt = $db->prepare("INSERT INTO Reservation_Option (id_reservation, id_option) VALUES (?, ?)");
                $stmt->execute([$bookingId, $optionId]);
            }
        }

        // Creer une notification
        $stmt = $db->prepare("INSERT INTO Notification (id_reservation, type_notification, date_envoi) VALUES (?, 'Creation', NOW())");
        $stmt->execute([$bookingId]);

        $db->commit();

        Logger::audit('BOOKING_CREATED', $session['user_id'], [
            'booking_id' => $bookingId,
            'room_id' => $roomId,
            'date' => $date,
            'start' => $start,
            'end' => $end
        ]);
        Logger::info('Booking created', ['booking_id' => $bookingId, 'user_id' => $session['user_id']]);
        ActivityLogger::log('BOOKING_CREATED', $session, 'reservation', $bookingId, "RES-" . str_pad($bookingId, 3, '0', STR_PAD_LEFT));

        jsonResponse([
            'success' => true,
            'id' => $bookingId,
            'ref' => 'RES-' . str_pad($bookingId, 3, '0', STR_PAD_LEFT),
            'status' => StatusMapper::bookingStatus($status),
            'message' => 'Reservation creee avec succes'
        ], 201);

    } catch (PDOException $e) {
        $db->rollBack();
        Logger::error('Booking creation failed', ['error' => $e->getMessage()]);
        jsonResponse(['error' => 'Erreur lors de la creation de la reservation'], 500);
    }
}

/**
 * Mettre a jour une reservation
 */
function updateBooking($db) {
    $session = checkAuth();
    verifyCsrf();

    $data = getRequestBody();

    if (empty($data['id'])) {
        jsonResponse(['error' => 'ID de reservation requis'], 400);
    }

    $bookingId = (int)$data['id'];

    // Verifier que la reservation existe et appartient a l'utilisateur (ou admin)
    $stmt = $db->prepare("SELECT * FROM Reservation WHERE id_reservation = ?");
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch();

    if (!$booking) {
        jsonResponse(['error' => 'Reservation non trouvee'], 404);
    }

    if ($session['role'] !== 'Admin' && $booking['id_utilisateur'] != $session['user_id']) {
        jsonResponse(['error' => 'Non autorise'], 403);
    }

    $updates = [];
    $params = [];

    if (isset($data['status'])) {
        $status = StatusMapper::bookingStatusToDb($data['status']);
        $updates[] = "statut_reservation = ?";
        $params[] = $status;
    }

    if (isset($data['subject'])) {
        $subject = sanitizeString($data['subject']);
        if (strlen($subject) < 3 || strlen($subject) > 100) {
            jsonResponse(['error' => 'L\'objet doit contenir entre 3 et 100 caracteres'], 400);
        }
        $updates[] = "objet_reservation = ?";
        $params[] = $subject;
    }

    if (empty($updates)) {
        jsonResponse(['error' => 'Aucune donnee a mettre a jour'], 400);
    }

    $updates[] = "updated_at = NOW()";
    $params[] = $bookingId;

    try {
        $db->beginTransaction();

        $sql = "UPDATE Reservation SET " . implode(', ', $updates) . " WHERE id_reservation = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        // Creer une notification de modification
        $stmt = $db->prepare("INSERT INTO Notification (id_reservation, type_notification, date_envoi) VALUES (?, 'Modification', NOW())");
        $stmt->execute([$bookingId]);

        $db->commit();

        Logger::audit('BOOKING_UPDATED', $session['user_id'], ['booking_id' => $bookingId]);
        Logger::info('Booking updated', ['booking_id' => $bookingId, 'user_id' => $session['user_id']]);
        ActivityLogger::log('BOOKING_UPDATED', $session, 'reservation', $bookingId, "RES-" . str_pad($bookingId, 3, '0', STR_PAD_LEFT));

        jsonResponse(['success' => true, 'message' => 'Reservation mise a jour']);

    } catch (PDOException $e) {
        $db->rollBack();
        Logger::error('Booking update failed', ['error' => $e->getMessage(), 'booking_id' => $bookingId]);
        jsonResponse(['error' => 'Erreur lors de la mise a jour'], 500);
    }
}

/**
 * Annuler une reservation (soft delete)
 */
function deleteBooking($db) {
    $session = checkAuth();
    verifyCsrf();

    $data = getRequestBody();

    if (empty($data['id'])) {
        jsonResponse(['error' => 'ID de reservation requis'], 400);
    }

    $bookingId = (int)$data['id'];

    // Verifier que la reservation existe et appartient a l'utilisateur (ou admin)
    $stmt = $db->prepare("SELECT * FROM Reservation WHERE id_reservation = ?");
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch();

    if (!$booking) {
        jsonResponse(['error' => 'Reservation non trouvee'], 404);
    }

    if ($session['role'] !== 'Admin' && $booking['id_utilisateur'] != $session['user_id']) {
        jsonResponse(['error' => 'Non autorise'], 403);
    }

    // Verifier que la reservation n'est pas deja annulee
    if ($booking['statut_reservation'] === 'Annulee') {
        jsonResponse(['error' => 'Cette reservation est deja annulee'], 400);
    }

    try {
        $db->beginTransaction();

        // Annuler plutot que supprimer pour garder l'historique
        $stmt = $db->prepare("UPDATE Reservation SET statut_reservation = 'Annulee', updated_at = NOW() WHERE id_reservation = ?");
        $stmt->execute([$bookingId]);

        // Creer une notification d'annulation
        $stmt = $db->prepare("INSERT INTO Notification (id_reservation, type_notification, date_envoi) VALUES (?, 'Annulation', NOW())");
        $stmt->execute([$bookingId]);

        $db->commit();

        Logger::audit('BOOKING_CANCELLED', $session['user_id'], ['booking_id' => $bookingId]);
        Logger::info('Booking cancelled', ['booking_id' => $bookingId, 'user_id' => $session['user_id']]);
        ActivityLogger::log('BOOKING_CANCELLED', $session, 'reservation', $bookingId, "RES-" . str_pad($bookingId, 3, '0', STR_PAD_LEFT));

        jsonResponse(['success' => true, 'message' => 'Reservation annulee']);

    } catch (PDOException $e) {
        $db->rollBack();
        Logger::error('Booking cancellation failed', ['error' => $e->getMessage(), 'booking_id' => $bookingId]);
        jsonResponse(['error' => 'Erreur lors de l\'annulation'], 500);
    }
}

/**
 * Recuperer les options d'une reservation
 */
function getBookingOptions($db, $bookingId) {
    $stmt = $db->prepare("SELECT o.libelle_option
                          FROM Reservation_Option ro
                          JOIN Option_Service o ON ro.id_option = o.id_option
                          WHERE ro.id_reservation = ?");
    $stmt->execute([$bookingId]);
    return array_column($stmt->fetchAll(), 'libelle_option');
}
