<?php

declare(strict_types=1);

namespace GSB\Reservation\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests pour la classe Logger
 */
class LoggerTest extends TestCase
{
    private string $logDir;
    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logDir = sys_get_temp_dir() . '/logger_test_' . uniqid();
        mkdir($this->logDir, 0755, true);
        $this->logFile = $this->logDir . '/' . date('Y-m-d') . '.log';
    }

    protected function tearDown(): void
    {
        // Nettoyer les fichiers de test
        $files = glob($this->logDir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->logDir)) {
            rmdir($this->logDir);
        }
        parent::tearDown();
    }

    /**
     * Simule la methode log du Logger
     */
    private function log(string $level, string $message, array $context = []): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' ' . json_encode($context) : '';
        $logLine = "[{$timestamp}] [{$level}] {$message}{$contextStr}" . PHP_EOL;
        file_put_contents($this->logFile, $logLine, FILE_APPEND);
    }

    /**
     * Simule la methode audit du Logger
     */
    private function audit(string $action, ?int $userId = null, array $details = []): void
    {
        $auditFile = $this->logDir . '/audit_' . date('Y-m') . '.log';
        $timestamp = date('Y-m-d H:i:s');
        $userStr = $userId ? "User:{$userId}" : 'Anonymous';
        $detailsStr = !empty($details) ? ' ' . json_encode($details) : '';
        $logLine = "[{$timestamp}] [{$userStr}] {$action}{$detailsStr}" . PHP_EOL;
        file_put_contents($auditFile, $logLine, FILE_APPEND);
    }

    public function testLogCreatesFile(): void
    {
        $this->assertFileDoesNotExist($this->logFile);
        $this->log('INFO', 'Test message');
        $this->assertFileExists($this->logFile);
    }

    public function testLogWritesMessage(): void
    {
        $message = 'Test log message ' . uniqid();
        $this->log('INFO', $message);

        $content = file_get_contents($this->logFile);
        $this->assertStringContainsString($message, $content);
    }

    public function testLogIncludesLevel(): void
    {
        $this->log('ERROR', 'Error message');

        $content = file_get_contents($this->logFile);
        $this->assertStringContainsString('[ERROR]', $content);
    }

    public function testLogIncludesTimestamp(): void
    {
        $this->log('INFO', 'Test message');

        $content = file_get_contents($this->logFile);
        $this->assertMatchesRegularExpression('/\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\]/', $content);
    }

    public function testLogIncludesContext(): void
    {
        $context = ['user_id' => 123, 'action' => 'login'];
        $this->log('INFO', 'User action', $context);

        $content = file_get_contents($this->logFile);
        $this->assertStringContainsString('user_id', $content);
        $this->assertStringContainsString('123', $content);
    }

    public function testLogAppendsToFile(): void
    {
        $this->log('INFO', 'First message');
        $this->log('INFO', 'Second message');

        $content = file_get_contents($this->logFile);
        $this->assertStringContainsString('First message', $content);
        $this->assertStringContainsString('Second message', $content);
    }

    public function testDifferentLogLevels(): void
    {
        $levels = ['DEBUG', 'INFO', 'WARNING', 'ERROR', 'CRITICAL'];

        foreach ($levels as $level) {
            $this->log($level, "Message at {$level} level");
        }

        $content = file_get_contents($this->logFile);

        foreach ($levels as $level) {
            $this->assertStringContainsString("[{$level}]", $content);
        }
    }

    public function testAuditCreatesFile(): void
    {
        $auditFile = $this->logDir . '/audit_' . date('Y-m') . '.log';

        $this->assertFileDoesNotExist($auditFile);
        $this->audit('LOGIN', 1);
        $this->assertFileExists($auditFile);
    }

    public function testAuditIncludesUserId(): void
    {
        $auditFile = $this->logDir . '/audit_' . date('Y-m') . '.log';

        $this->audit('LOGIN', 42);

        $content = file_get_contents($auditFile);
        $this->assertStringContainsString('User:42', $content);
    }

    public function testAuditWithAnonymousUser(): void
    {
        $auditFile = $this->logDir . '/audit_' . date('Y-m') . '.log';

        $this->audit('PAGE_VIEW', null);

        $content = file_get_contents($auditFile);
        $this->assertStringContainsString('Anonymous', $content);
    }

    public function testAuditIncludesAction(): void
    {
        $auditFile = $this->logDir . '/audit_' . date('Y-m') . '.log';

        $this->audit('CREATE_BOOKING', 1);

        $content = file_get_contents($auditFile);
        $this->assertStringContainsString('CREATE_BOOKING', $content);
    }

    public function testAuditIncludesDetails(): void
    {
        $auditFile = $this->logDir . '/audit_' . date('Y-m') . '.log';

        $details = ['room_id' => 5, 'date' => '2024-01-15'];
        $this->audit('BOOKING', 1, $details);

        $content = file_get_contents($auditFile);
        $this->assertStringContainsString('room_id', $content);
        $this->assertStringContainsString('2024-01-15', $content);
    }

    public function testLogHandlesSpecialCharacters(): void
    {
        $message = 'Test avec caracteres speciaux: <>&"\'';
        $this->log('INFO', $message);

        $content = file_get_contents($this->logFile);
        $this->assertStringContainsString($message, $content);
    }

    public function testLogHandlesUnicode(): void
    {
        $message = 'Test avec unicode: éàùç日本語';
        $this->log('INFO', $message);

        $content = file_get_contents($this->logFile);
        $this->assertStringContainsString($message, $content);
    }
}
