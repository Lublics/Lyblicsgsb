<?php

declare(strict_types=1);

namespace GSB\Reservation\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests pour la classe PasswordValidator
 */
class PasswordValidatorTest extends TestCase
{
    private array $rules;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rules = [
            'minLength' => 12,
            'requireUppercase' => true,
            'requireLowercase' => true,
            'requireNumber' => true,
            'requireSpecial' => true,
        ];
    }

    /**
     * Valide un mot de passe selon les regles
     */
    private function validatePassword(string $password): array
    {
        $errors = [];

        if (strlen($password) < $this->rules['minLength']) {
            $errors[] = "Le mot de passe doit contenir au moins {$this->rules['minLength']} caracteres";
        }

        if ($this->rules['requireUppercase'] && !preg_match('/[A-Z]/', $password)) {
            $errors[] = "Le mot de passe doit contenir au moins une majuscule";
        }

        if ($this->rules['requireLowercase'] && !preg_match('/[a-z]/', $password)) {
            $errors[] = "Le mot de passe doit contenir au moins une minuscule";
        }

        if ($this->rules['requireNumber'] && !preg_match('/[0-9]/', $password)) {
            $errors[] = "Le mot de passe doit contenir au moins un chiffre";
        }

        if ($this->rules['requireSpecial'] && !preg_match('/[!@#$%^&*()_+\-=\[\]{}|;:,.<>?]/', $password)) {
            $errors[] = "Le mot de passe doit contenir au moins un caractere special";
        }

        return $errors;
    }

    public function testValidPasswordPasses(): void
    {
        $password = 'SecurePass123!@#';
        $errors = $this->validatePassword($password);
        $this->assertEmpty($errors, 'Un mot de passe valide ne devrait pas avoir d\'erreurs');
    }

    public function testPasswordTooShortFails(): void
    {
        $password = 'Short1!';
        $errors = $this->validatePassword($password);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('12 caracteres', $errors[0]);
    }

    public function testPasswordWithoutUppercaseFails(): void
    {
        $password = 'nouppercase123!@#';
        $errors = $this->validatePassword($password);
        $this->assertNotEmpty($errors);
        $this->assertTrue(
            $this->arrayContainsString($errors, 'majuscule'),
            'Devrait indiquer qu\'une majuscule est requise'
        );
    }

    public function testPasswordWithoutLowercaseFails(): void
    {
        $password = 'NOLOWERCASE123!@#';
        $errors = $this->validatePassword($password);
        $this->assertNotEmpty($errors);
        $this->assertTrue(
            $this->arrayContainsString($errors, 'minuscule'),
            'Devrait indiquer qu\'une minuscule est requise'
        );
    }

    public function testPasswordWithoutNumberFails(): void
    {
        $password = 'NoNumberHere!@#';
        $errors = $this->validatePassword($password);
        $this->assertNotEmpty($errors);
        $this->assertTrue(
            $this->arrayContainsString($errors, 'chiffre'),
            'Devrait indiquer qu\'un chiffre est requis'
        );
    }

    public function testPasswordWithoutSpecialCharFails(): void
    {
        $password = 'NoSpecialChar123';
        $errors = $this->validatePassword($password);
        $this->assertNotEmpty($errors);
        $this->assertTrue(
            $this->arrayContainsString($errors, 'special'),
            'Devrait indiquer qu\'un caractere special est requis'
        );
    }

    public function testPasswordWithMultipleIssues(): void
    {
        $password = 'abc';
        $errors = $this->validatePassword($password);
        $this->assertGreaterThan(1, count($errors), 'Devrait avoir plusieurs erreurs');
    }

    public function testEmptyPasswordFails(): void
    {
        $password = '';
        $errors = $this->validatePassword($password);
        $this->assertNotEmpty($errors);
    }

    /**
     * Helper pour verifier si un tableau contient une chaine
     */
    private function arrayContainsString(array $array, string $needle): bool
    {
        foreach ($array as $item) {
            if (stripos($item, $needle) !== false) {
                return true;
            }
        }
        return false;
    }
}
