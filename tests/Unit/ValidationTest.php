<?php

declare(strict_types=1);

namespace GSB\Reservation\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests pour les fonctions de validation
 */
class ValidationTest extends TestCase
{
    /**
     * Valide un email
     */
    private function validateEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Valide une date au format YYYY-MM-DD
     */
    private function validateDate(string $date): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }

        $parts = explode('-', $date);
        return checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0]);
    }

    /**
     * Valide une heure au format HH:MM
     */
    private function validateTime(string $time): bool
    {
        if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
            return false;
        }

        $parts = explode(':', $time);
        $hours = (int) $parts[0];
        $minutes = (int) $parts[1];

        return $hours >= 0 && $hours <= 23 && $minutes >= 0 && $minutes <= 59;
    }

    /**
     * Nettoie une chaine de caracteres
     */
    private function sanitizeString(string $input): string
    {
        return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Valide une capacite de salle
     */
    private function validateCapacity(int $capacity): bool
    {
        return $capacity >= 1 && $capacity <= 100;
    }

    // Tests Email
    public function testValidEmailPasses(): void
    {
        $this->assertTrue($this->validateEmail('test@example.com'));
        $this->assertTrue($this->validateEmail('user.name@domain.co.uk'));
        $this->assertTrue($this->validateEmail('admin@gsb.local'));
    }

    public function testInvalidEmailFails(): void
    {
        $this->assertFalse($this->validateEmail('invalid'));
        $this->assertFalse($this->validateEmail('invalid@'));
        $this->assertFalse($this->validateEmail('@domain.com'));
        $this->assertFalse($this->validateEmail(''));
    }

    // Tests Date
    public function testValidDatePasses(): void
    {
        $this->assertTrue($this->validateDate('2024-01-15'));
        $this->assertTrue($this->validateDate('2024-12-31'));
        $this->assertTrue($this->validateDate('2024-02-29')); // Annee bissextile
    }

    public function testInvalidDateFails(): void
    {
        $this->assertFalse($this->validateDate('2024-13-01')); // Mois invalide
        $this->assertFalse($this->validateDate('2024-02-30')); // Jour invalide
        $this->assertFalse($this->validateDate('2023-02-29')); // Pas bissextile
        $this->assertFalse($this->validateDate('15-01-2024')); // Mauvais format
        $this->assertFalse($this->validateDate('2024/01/15')); // Mauvais separateur
        $this->assertFalse($this->validateDate(''));
    }

    // Tests Time
    public function testValidTimePasses(): void
    {
        $this->assertTrue($this->validateTime('00:00'));
        $this->assertTrue($this->validateTime('12:30'));
        $this->assertTrue($this->validateTime('23:59'));
        $this->assertTrue($this->validateTime('09:00'));
    }

    public function testInvalidTimeFails(): void
    {
        $this->assertFalse($this->validateTime('24:00')); // Heure invalide
        $this->assertFalse($this->validateTime('12:60')); // Minutes invalides
        $this->assertFalse($this->validateTime('9:00'));  // Format invalide
        $this->assertFalse($this->validateTime('09:0'));  // Format invalide
        $this->assertFalse($this->validateTime('12:30:00')); // Avec secondes
        $this->assertFalse($this->validateTime(''));
    }

    // Tests Sanitization
    public function testSanitizeRemovesTags(): void
    {
        $input = '<script>alert("xss")</script>Hello';
        $output = $this->sanitizeString($input);
        $this->assertEquals('Hello', $output);
    }

    public function testSanitizeEscapesHtml(): void
    {
        $input = 'Hello <b>World</b>';
        $output = $this->sanitizeString($input);
        $this->assertStringNotContainsString('<b>', $output);
    }

    public function testSanitizeTrimsWhitespace(): void
    {
        $input = '  Hello World  ';
        $output = $this->sanitizeString($input);
        $this->assertEquals('Hello World', $output);
    }

    public function testSanitizeEscapesQuotes(): void
    {
        $input = "He said \"Hello\"";
        $output = $this->sanitizeString($input);
        $this->assertStringContainsString('&quot;', $output);
    }

    // Tests Capacity
    public function testValidCapacityPasses(): void
    {
        $this->assertTrue($this->validateCapacity(1));
        $this->assertTrue($this->validateCapacity(10));
        $this->assertTrue($this->validateCapacity(50));
        $this->assertTrue($this->validateCapacity(100));
    }

    public function testInvalidCapacityFails(): void
    {
        $this->assertFalse($this->validateCapacity(0));
        $this->assertFalse($this->validateCapacity(-1));
        $this->assertFalse($this->validateCapacity(101));
        $this->assertFalse($this->validateCapacity(1000));
    }

    // Tests combinaison de validations
    public function testBookingTimeRangeValid(): void
    {
        $start = '09:00';
        $end = '10:30';

        $this->assertTrue($this->validateTime($start));
        $this->assertTrue($this->validateTime($end));
        $this->assertTrue(strtotime($end) > strtotime($start));
    }

    public function testBookingTimeRangeInvalid(): void
    {
        $start = '14:00';
        $end = '13:00';

        $this->assertFalse(strtotime($end) > strtotime($start));
    }

    public function testFutureDateValidation(): void
    {
        $futureDate = date('Y-m-d', strtotime('+1 day'));
        $pastDate = date('Y-m-d', strtotime('-1 day'));

        $this->assertTrue($this->validateDate($futureDate));
        $this->assertTrue($this->validateDate($pastDate));

        // La date future devrait etre apres aujourd'hui
        $this->assertTrue(strtotime($futureDate) > strtotime(date('Y-m-d')));
    }
}
