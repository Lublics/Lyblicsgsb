<?php
/**
 * API de consultation des logs d'activite
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
        getLogs($db);
        break;
    default:
        jsonResponse(['error' => 'Methode non autorisee'], 405);
}

/**
 * Recuperer les logs d'activite (admin seulement)
 */
function getLogs($db) {
    checkAdmin();

    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 50;
    $offset = ($page - 1) * $limit;

    $where = [];
    $params = [];

    if (isset($_GET['type']) && $_GET['type'] !== '') {
        $where[] = "action_type LIKE ?";
        $escapedType = str_replace(['%', '_'], ['\\%', '\\_'], $_GET['type']);
        $params[] = '%' . $escapedType . '%';
    }

    $sql = "SELECT * FROM ActivityLog";
    if (!empty($where)) {
        $sql .= " WHERE " . implode(' AND ', $where);
    }
    $sql .= " ORDER BY created_at DESC LIMIT $limit OFFSET $offset";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll();

    // Total count
    $countSql = "SELECT COUNT(*) FROM ActivityLog";
    if (!empty($where)) {
        $countSql .= " WHERE " . implode(' AND ', $where);
    }
    $countStmt = $db->prepare($countSql);
    $countStmt->execute($params);
    $total = $countStmt->fetchColumn();

    $formatted = array_map(function($log) {
        return [
            'id' => (int)$log['id_log'],
            'action' => $log['action_type'],
            'actorId' => (int)$log['actor_id'],
            'actorName' => $log['actor_name'],
            'actorRole' => $log['actor_role'],
            'targetType' => $log['target_type'],
            'targetId' => $log['target_id'] ? (int)$log['target_id'] : null,
            'targetLabel' => $log['target_label'],
            'date' => $log['created_at']
        ];
    }, $logs);

    jsonResponse([
        'logs' => $formatted,
        'total' => (int)$total,
        'page' => $page,
        'limit' => $limit
    ]);
}
