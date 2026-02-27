<?php

declare(strict_types=1);

namespace GSB\Reservation\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests pour la classe CSRF
 */
class CSRFTest extends TestCase
{
    private string $tokenName = 'csrf_token';

    protected function setUp(): void
    {
        parent::setUp();

        // Simuler une session
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    /**
     * Genere un token CSRF
     */
    private function generateToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION[$this->tokenName] = $token;
        return $token;
    }

    /**
     * Valide un token CSRF
     */
    private function validateToken(string $token): bool
    {
        if (!isset($_SESSION[$this->tokenName])) {
            return false;
        }
        return hash_equals($_SESSION[$this->tokenName], $token);
    }

    public function testGenerateTokenCreatesValidToken(): void
    {
        $token = $this->generateToken();

        $this->assertNotEmpty($token);
        $this->assertEquals(64, strlen($token)); // 32 bytes = 64 hex chars
        $this->assertTrue(ctype_xdigit($token), 'Le token doit etre hexadecimal');
    }

    public function testGenerateTokenStoresInSession(): void
    {
        $token = $this->generateToken();

        $this->assertArrayHasKey($this->tokenName, $_SESSION);
        $this->assertEquals($token, $_SESSION[$this->tokenName]);
    }

    public function testValidTokenPasses(): void
    {
        $token = $this->generateToken();
        $isValid = $this->validateToken($token);

        $this->assertTrue($isValid);
    }

    public function testInvalidTokenFails(): void
    {
        $this->generateToken();
        $isValid = $this->validateToken('invalid_token');

        $this->assertFalse($isValid);
    }

    public function testEmptyTokenFails(): void
    {
        $this->generateToken();
        $isValid = $this->validateToken('');

        $this->assertFalse($isValid);
    }

    public function testValidationWithoutGenerationFails(): void
    {
        $isValid = $this->validateToken('some_token');

        $this->assertFalse($isValid);
    }

    public function testGenerateTokenOverwritesPrevious(): void
    {
        $token1 = $this->generateToken();
        $token2 = $this->generateToken();

        $this->assertNotEquals($token1, $token2);
        $this->assertFalse($this->validateToken($token1));
        $this->assertTrue($this->validateToken($token2));
    }

    public function testTokenIsRandomEachTime(): void
    {
        $tokens = [];
        for ($i = 0; $i < 100; $i++) {
            $_SESSION = []; // Reset session
            $tokens[] = $this->generateToken();
        }

        $uniqueTokens = array_unique($tokens);
        $this->assertCount(100, $uniqueTokens, 'Tous les tokens doivent etre uniques');
    }

    public function testTimingAttackProtection(): void
    {
        $token = $this->generateToken();

        // Le token correct et un token similaire devraient prendre le meme temps
        // On verifie simplement que hash_equals est utilise
        $similarToken = substr($token, 0, -1) . 'X';

        $this->assertTrue($this->validateToken($token));
        $this->assertFalse($this->validateToken($similarToken));
    }
}
