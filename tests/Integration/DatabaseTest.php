<?php

declare(strict_types=1);

namespace GSB\Reservation\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests d'integration pour la base de donnees
 */
class DatabaseTest extends TestCase
{
    private static ?PDO $pdo = null;

    public static function setUpBeforeClass(): void
    {
        try {
            self::$pdo = createTestDatabase();
            cleanTestDatabase(self::$pdo);
            setupTestTables(self::$pdo);
        } catch (\Exception $e) {
            self::markTestSkipped('Base de donnees de test non disponible: ' . $e->getMessage());
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$pdo !== null) {
            cleanTestDatabase(self::$pdo);
        }
        self::$pdo = null;
    }

    protected function setUp(): void
    {
        if (self::$pdo === null) {
            $this->markTestSkipped('Base de donnees de test non disponible');
        }
    }

    public function testTablesExist(): void
    {
        $tables = ['Utilisateur', 'Salle', 'Reservation', 'Options', 'Equipement'];

        foreach ($tables as $table) {
            $result = self::$pdo->query("SHOW TABLES LIKE '{$table}'")->fetch();
            $this->assertNotFalse($result, "La table {$table} devrait exister");
        }
    }

    public function testUtilisateurTableStructure(): void
    {
        $stmt = self::$pdo->query("DESCRIBE Utilisateur");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $requiredColumns = ['ID', 'Nom', 'Prenom', 'Email', 'MotDePasse', 'Role', 'created_at', 'updated_at'];

        foreach ($requiredColumns as $column) {
            $this->assertContains($column, $columns, "La colonne {$column} devrait exister dans Utilisateur");
        }
    }

    public function testSalleTableStructure(): void
    {
        $stmt = self::$pdo->query("DESCRIBE Salle");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $requiredColumns = ['ID', 'Nom', 'Description', 'Capacite', 'Statut', 'Etage', 'created_at', 'updated_at'];

        foreach ($requiredColumns as $column) {
            $this->assertContains($column, $columns, "La colonne {$column} devrait exister dans Salle");
        }
    }

    public function testReservationTableStructure(): void
    {
        $stmt = self::$pdo->query("DESCRIBE Reservation");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $requiredColumns = ['ID', 'Reference', 'Utilisateur_ID', 'Salle_ID', 'DateReservation', 'HeureDebut', 'HeureFin', 'Objet', 'Statut'];

        foreach ($requiredColumns as $column) {
            $this->assertContains($column, $columns, "La colonne {$column} devrait exister dans Reservation");
        }
    }

    public function testCanInsertUser(): void
    {
        $email = 'insert_test_' . uniqid() . '@gsb.local';
        $userId = createTestUser(self::$pdo, $email);

        $this->assertGreaterThan(0, $userId);

        $stmt = self::$pdo->prepare("SELECT * FROM Utilisateur WHERE ID = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotFalse($user);
        $this->assertEquals($email, $user['Email']);
        $this->assertEquals('Employe', $user['Role']);
    }

    public function testCanInsertRoom(): void
    {
        $name = 'Salle Test ' . uniqid();
        $roomId = createTestRoom(self::$pdo, $name);

        $this->assertGreaterThan(0, $roomId);

        $stmt = self::$pdo->prepare("SELECT * FROM Salle WHERE ID = ?");
        $stmt->execute([$roomId]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotFalse($room);
        $this->assertEquals($name, $room['Nom']);
    }

    public function testCanInsertReservation(): void
    {
        $email = 'reservation_test_' . uniqid() . '@gsb.local';
        $userId = createTestUser(self::$pdo, $email);
        $roomId = createTestRoom(self::$pdo, 'Salle Reservation ' . uniqid());

        $reference = 'TEST-' . uniqid();
        $stmt = self::$pdo->prepare("
            INSERT INTO Reservation (Reference, Utilisateur_ID, Salle_ID, DateReservation, HeureDebut, HeureFin, Objet, Statut)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$reference, $userId, $roomId, '2024-12-25', '09:00:00', '10:00:00', 'Test', 'pending']);

        $reservationId = (int) self::$pdo->lastInsertId();
        $this->assertGreaterThan(0, $reservationId);

        $stmt = self::$pdo->prepare("SELECT * FROM Reservation WHERE ID = ?");
        $stmt->execute([$reservationId]);
        $reservation = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotFalse($reservation);
        $this->assertEquals($reference, $reservation['Reference']);
        $this->assertEquals('pending', $reservation['Statut']);
    }

    public function testEmailUniqueConstraint(): void
    {
        $this->expectException(\PDOException::class);

        $email = 'unique_test_' . uniqid() . '@gsb.local';
        createTestUser(self::$pdo, $email);
        createTestUser(self::$pdo, $email); // Devrait echouer
    }

    public function testForeignKeyConstraintReservation(): void
    {
        $this->expectException(\PDOException::class);

        // Tenter de creer une reservation avec un utilisateur inexistant
        $stmt = self::$pdo->prepare("
            INSERT INTO Reservation (Reference, Utilisateur_ID, Salle_ID, DateReservation, HeureDebut, HeureFin, Objet)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute(['FK-TEST-' . uniqid(), 99999, 99999, '2024-12-25', '09:00:00', '10:00:00', 'Test FK']);
    }

    public function testRoleEnumConstraint(): void
    {
        $validRoles = ['Admin', 'Delegue', 'Employe'];

        foreach ($validRoles as $role) {
            $email = "role_test_{$role}_" . uniqid() . '@gsb.local';
            $userId = createTestUser(self::$pdo, $email, $role);

            $stmt = self::$pdo->prepare("SELECT Role FROM Utilisateur WHERE ID = ?");
            $stmt->execute([$userId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            $this->assertEquals($role, $result['Role']);
        }
    }

    public function testStatutEnumConstraint(): void
    {
        $validStatuts = ['available', 'occupied', 'maintenance'];

        foreach ($validStatuts as $statut) {
            $name = "Salle Statut {$statut} " . uniqid();
            $stmt = self::$pdo->prepare("INSERT INTO Salle (Nom, Capacite, Statut) VALUES (?, ?, ?)");
            $stmt->execute([$name, 10, $statut]);

            $roomId = (int) self::$pdo->lastInsertId();

            $stmt = self::$pdo->prepare("SELECT Statut FROM Salle WHERE ID = ?");
            $stmt->execute([$roomId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            $this->assertEquals($statut, $result['Statut']);
        }
    }

    public function testTimestampsAutoPopulate(): void
    {
        $email = 'timestamp_test_' . uniqid() . '@gsb.local';
        $userId = createTestUser(self::$pdo, $email);

        $stmt = self::$pdo->prepare("SELECT created_at, updated_at FROM Utilisateur WHERE ID = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotNull($user['created_at']);
        $this->assertNotNull($user['updated_at']);
    }

    public function testCascadeDeleteReservation(): void
    {
        $email = 'cascade_test_' . uniqid() . '@gsb.local';
        $userId = createTestUser(self::$pdo, $email);
        $roomId = createTestRoom(self::$pdo, 'Salle Cascade ' . uniqid());

        $reference = 'CASCADE-' . uniqid();
        $stmt = self::$pdo->prepare("
            INSERT INTO Reservation (Reference, Utilisateur_ID, Salle_ID, DateReservation, HeureDebut, HeureFin, Objet)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$reference, $userId, $roomId, '2024-12-25', '09:00:00', '10:00:00', 'Test Cascade']);
        $reservationId = (int) self::$pdo->lastInsertId();

        // Supprimer l'utilisateur
        $stmt = self::$pdo->prepare("DELETE FROM Utilisateur WHERE ID = ?");
        $stmt->execute([$userId]);

        // La reservation devrait aussi etre supprimee
        $stmt = self::$pdo->prepare("SELECT * FROM Reservation WHERE ID = ?");
        $stmt->execute([$reservationId]);
        $result = $stmt->fetch();

        $this->assertFalse($result, 'La reservation devrait etre supprimee en cascade');
    }
}
