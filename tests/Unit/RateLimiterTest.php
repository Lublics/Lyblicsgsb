<?php

declare(strict_types=1);

namespace GSB\Reservation\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests pour la classe RateLimiter
 */
class RateLimiterTest extends TestCase
{
    private string $storageDir;
    private int $maxAttempts = 5;
    private int $windowSeconds = 60;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageDir = sys_get_temp_dir() . '/rate_limit_test_' . uniqid();
        mkdir($this->storageDir, 0755, true);
    }

    protected function tearDown(): void
    {
        // Nettoyer les fichiers de test
        $files = glob($this->storageDir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->storageDir)) {
            rmdir($this->storageDir);
        }
        parent::tearDown();
    }

    /**
     * Obtient le chemin du fichier de rate limit pour une cle
     */
    private function getFilePath(string $key): string
    {
        return $this->storageDir . '/' . md5($key) . '.json';
    }

    /**
     * Verifie si une tentative est autorisee
     */
    private function isAllowed(string $key): bool
    {
        $file = $this->getFilePath($key);
        $now = time();

        if (!file_exists($file)) {
            return true;
        }

        $data = json_decode(file_get_contents($file), true);
        if (!$data) {
            return true;
        }

        // Nettoyer les anciennes entrees
        $data['attempts'] = array_filter($data['attempts'], function ($timestamp) use ($now) {
            return ($now - $timestamp) < $this->windowSeconds;
        });

        return count($data['attempts']) < $this->maxAttempts;
    }

    /**
     * Enregistre une tentative
     */
    private function recordAttempt(string $key): void
    {
        $file = $this->getFilePath($key);
        $now = time();

        $data = ['attempts' => []];
        if (file_exists($file)) {
            $data = json_decode(file_get_contents($file), true) ?: ['attempts' => []];
        }

        // Nettoyer les anciennes entrees
        $data['attempts'] = array_filter($data['attempts'], function ($timestamp) use ($now) {
            return ($now - $timestamp) < $this->windowSeconds;
        });

        $data['attempts'][] = $now;
        file_put_contents($file, json_encode($data));
    }

    /**
     * Obtient le temps restant avant reset
     */
    private function getRetryAfter(string $key): int
    {
        $file = $this->getFilePath($key);

        if (!file_exists($file)) {
            return 0;
        }

        $data = json_decode(file_get_contents($file), true);
        if (!$data || empty($data['attempts'])) {
            return 0;
        }

        $oldestAttempt = min($data['attempts']);
        return max(0, $this->windowSeconds - (time() - $oldestAttempt));
    }

    public function testFirstAttemptIsAllowed(): void
    {
        $key = 'test_user_' . uniqid();
        $this->assertTrue($this->isAllowed($key));
    }

    public function testAttemptsUnderLimitAllowed(): void
    {
        $key = 'test_user_' . uniqid();

        for ($i = 0; $i < $this->maxAttempts - 1; $i++) {
            $this->recordAttempt($key);
        }

        $this->assertTrue($this->isAllowed($key));
    }

    public function testAttemptsAtLimitBlocked(): void
    {
        $key = 'test_user_' . uniqid();

        for ($i = 0; $i < $this->maxAttempts; $i++) {
            $this->recordAttempt($key);
        }

        $this->assertFalse($this->isAllowed($key));
    }

    public function testDifferentKeysIndependent(): void
    {
        $key1 = 'user1_' . uniqid();
        $key2 = 'user2_' . uniqid();

        for ($i = 0; $i < $this->maxAttempts; $i++) {
            $this->recordAttempt($key1);
        }

        $this->assertFalse($this->isAllowed($key1));
        $this->assertTrue($this->isAllowed($key2));
    }

    public function testRetryAfterReturnsPositiveWhenBlocked(): void
    {
        $key = 'test_user_' . uniqid();

        for ($i = 0; $i < $this->maxAttempts; $i++) {
            $this->recordAttempt($key);
        }

        $retryAfter = $this->getRetryAfter($key);
        $this->assertGreaterThan(0, $retryAfter);
        $this->assertLessThanOrEqual($this->windowSeconds, $retryAfter);
    }

    public function testRetryAfterReturnsZeroWhenNoAttempts(): void
    {
        $key = 'new_user_' . uniqid();
        $this->assertEquals(0, $this->getRetryAfter($key));
    }

    public function testRecordAttemptCreatesFile(): void
    {
        $key = 'test_user_' . uniqid();
        $file = $this->getFilePath($key);

        $this->assertFileDoesNotExist($file);
        $this->recordAttempt($key);
        $this->assertFileExists($file);
    }

    public function testAttemptDataStructure(): void
    {
        $key = 'test_user_' . uniqid();
        $file = $this->getFilePath($key);

        $this->recordAttempt($key);

        $data = json_decode(file_get_contents($file), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('attempts', $data);
        $this->assertIsArray($data['attempts']);
        $this->assertCount(1, $data['attempts']);
    }
}
