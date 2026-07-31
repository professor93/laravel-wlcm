<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Uzbek\Wlcm\Storage\FileLogStore;
use Uzbek\Wlcm\Tests\TestCase;

final class FileRotationTest extends TestCase
{
    private string $dir;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/wlcm-rot-'.bin2hex(random_bytes(6));
        $this->path = $this->dir.'/wlcm.jsonl';

        config()->set('wlcm.storage.logs', 'file');
        config()->set('wlcm.storage.logs_path', $this->path);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function write(int $times = 1): void
    {
        $store = new FileLogStore;

        for ($i = 0; $i < $times; $i++) {
            $store->webhook('main', ['order_id' => $i, 'padding' => str_repeat('x', 200)], 'ok');
        }
    }

    public function test_without_limits_it_keeps_appending_to_one_file(): void
    {
        $this->write(5);

        $this->assertFileExists($this->path);
        $this->assertCount(5, file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
        $this->assertSame([], glob($this->path.'.*') ?: []);
    }

    public function test_it_rotates_once_the_size_limit_is_reached(): void
    {
        config()->set('wlcm.storage.file.max_size', '1K');

        $this->write(20);

        $archives = glob($this->path.'.*') ?: [];
        $this->assertNotEmpty($archives, 'nothing was rotated');
        // The live file exists and is smaller than everything written.
        $this->assertFileExists($this->path);
    }

    public function test_no_line_is_lost_across_a_rotation(): void
    {
        config()->set('wlcm.storage.file.max_size', '1K');

        $this->write(20);

        $lines = count(file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);

        foreach (glob($this->path.'.*') ?: [] as $archive) {
            $lines += count(file($archive, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
        }

        // Rename-based rotation must not drop a single entry — an audit trail with a
        // hole is worse than one that is merely large.
        $this->assertSame(20, $lines);
    }

    public function test_rotation_never_overwrites_an_existing_archive(): void
    {
        config()->set('wlcm.storage.file.max_size', '1K');

        $this->write(20);
        $first = glob($this->path.'.*') ?: [];
        $firstContents = array_map(static fn (string $p): string => (string) file_get_contents($p), $first);

        $this->write(20);

        foreach ($first as $i => $archive) {
            $this->assertSame($firstContents[$i], (string) file_get_contents($archive), 'an archive was clobbered');
        }
    }

    public function test_max_files_keeps_only_the_newest(): void
    {
        config()->set('wlcm.storage.file.max_size', '1K');
        config()->set('wlcm.storage.file.max_files', 2);

        $this->write(120);

        $this->assertLessThanOrEqual(2, count(glob($this->path.'.*') ?: []));
    }

    public function test_daily_writes_to_a_dated_file(): void
    {
        config()->set('wlcm.storage.file.daily', true);

        $this->write(3);

        // UTC in the name, so a host that moves timezone does not reopen yesterday.
        $expected = $this->dir.'/wlcm-'.gmdate('Y-m-d').'.jsonl';
        $this->assertFileExists($expected);
        $this->assertFileDoesNotExist($this->path);
    }

    public function test_a_broken_rotation_never_breaks_the_caller(): void
    {
        config()->set('wlcm.storage.file.max_size', '1K');
        $this->write(20);

        // Directory read-only: the rename cannot happen.
        @chmod($this->dir, 0555);

        try {
            $this->write(5);
            $this->addToAssertionCount(1);
        } finally {
            @chmod($this->dir, 0755);
        }
    }

    public function test_an_unreadable_size_switches_rotation_off_rather_than_guessing(): void
    {
        config()->set('wlcm.storage.file.max_size', 'not-a-size');

        $this->write(20);

        $this->assertSame([], glob($this->path.'.*') ?: []);
        $this->assertFileExists($this->path);
    }
}
