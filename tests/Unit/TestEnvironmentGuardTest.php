<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\TestEnvironment;

class TestEnvironmentGuardTest extends TestCase
{
    public function test_it_accepts_the_safe_test_configuration(): void
    {
        TestEnvironment::assertSafe('testing', 'sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        $this->addToAssertionCount(1);
    }

    public function test_it_rejects_a_non_testing_environment(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APP_ENV must be');

        TestEnvironment::assertSafe('local', 'sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
    }

    public function test_it_rejects_a_non_sqlite_default_connection(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('default connection must be');

        TestEnvironment::assertSafe('testing', 'mysql', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
    }

    public function test_it_rejects_a_non_sqlite_driver(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('driver must be');

        TestEnvironment::assertSafe('testing', 'sqlite', [
            'driver' => 'mysql',
            'database' => ':memory:',
        ]);
    }

    public function test_it_rejects_a_real_database_name(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('database must be');

        TestEnvironment::assertSafe('testing', 'sqlite', [
            'driver' => 'sqlite',
            'database' => 'saas_pos_db',
        ]);
    }

    public function test_purge_removes_an_existing_cached_config(): void
    {
        $dir = $this->makeTemporaryDirectory();
        $path = $dir.DIRECTORY_SEPARATOR.'config.php';

        try {
            file_put_contents($path, '<?php return [];');

            TestEnvironment::purgeCachedConfig($path);

            $this->assertFileDoesNotExist($path);
        } finally {
            // Deterministic cleanup: never call unlink() on an already-deleted
            // file (which would surface an "unlink(...)" warning).
            $this->cleanupDirectory($dir);
        }
    }

    public function test_purge_is_a_no_op_when_no_cache_exists(): void
    {
        $dir = $this->makeTemporaryDirectory();
        $path = $dir.DIRECTORY_SEPARATOR.'config.php';

        try {
            TestEnvironment::purgeCachedConfig($path);

            $this->assertFileDoesNotExist($path);
        } finally {
            $this->cleanupDirectory($dir);
        }
    }

    public function test_purge_fails_closed_when_the_path_cannot_be_removed(): void
    {
        // A directory at the config path cannot be unlinked; the guard must
        // abort instead of silently continuing.
        $dir = $this->makeTemporaryDirectory();
        $path = $dir.DIRECTORY_SEPARATOR.'config.php';
        mkdir($path);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('could not be removed');

            TestEnvironment::purgeCachedConfig($path);
        } finally {
            $this->cleanupDirectory($dir);
        }
    }

    /**
     * Every test that touches the database must extend the hardened
     * Tests\TestCase, so a new test class cannot accidentally bypass the guard.
     */
    public function test_all_database_tests_extend_the_hardened_base_test_case(): void
    {
        $databaseTraits = [
            'Illuminate\\Foundation\\Testing\\RefreshDatabase',
            'Illuminate\\Foundation\\Testing\\DatabaseMigrations',
            'Illuminate\\Foundation\\Testing\\DatabaseTransactions',
            'Illuminate\\Foundation\\Testing\\DatabaseTruncation',
        ];

        $violations = [];

        foreach ([$this->suiteDirectory('Feature'), $this->suiteDirectory('Unit')] as $directory) {
            foreach (glob($directory.'/*.php') ?: [] as $file) {
                if (realpath($file) === __FILE__) {
                    continue;
                }

                $code = (string) file_get_contents($file);

                if (! $this->usesAnyTrait($code, $databaseTraits)) {
                    continue;
                }

                $importsBase = str_contains($code, 'use Tests\\TestCase;');
                $extendsBase = (bool) preg_match('/class\s+\w+\s+extends\s+TestCase\b/', $code);

                if (! $importsBase || ! $extendsBase) {
                    $violations[] = basename($file);
                }
            }
        }

        $this->assertSame([], $violations, 'Database tests must extend Tests\TestCase: '.implode(', ', $violations));
    }

    /**
     * @param  array<int, string>  $traits
     */
    private function usesAnyTrait(string $code, array $traits): bool
    {
        foreach ($traits as $trait) {
            if (str_contains($code, $trait)) {
                return true;
            }
        }

        return false;
    }

    private function makeTemporaryDirectory(): string
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'saas_pos_guard_'.bin2hex(random_bytes(6));
        mkdir($dir);

        return $dir;
    }

    /**
     * Remove a temporary directory tree without triggering PHP warnings.
     *
     * Every unlink()/rmdir() call is guarded by an existence/type check, so we
     * never operate on an already-deleted path (which is what produced the
     * "unlink(...)" warning in the fail-closed test).
     */
    private function cleanupDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $entry) {
            if (is_file($entry)) {
                unlink($entry);
            } elseif (is_dir($entry)) {
                rmdir($entry);
            }
        }

        rmdir($directory);
    }

    private function suiteDirectory(string $suffix): string
    {
        return dirname(__DIR__).DIRECTORY_SEPARATOR.$suffix;
    }
}
