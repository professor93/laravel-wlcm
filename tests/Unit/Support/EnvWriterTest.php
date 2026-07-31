<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Unit\Support;

use Uzbek\Wlcm\Support\EnvWriter;
use Uzbek\Wlcm\Tests\TestCase;

final class EnvWriterTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'/wlcm-env-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    private function write(string $contents): EnvWriter
    {
        file_put_contents($this->path, $contents);

        return new EnvWriter($this->path);
    }

    public function test_it_sees_a_plain_key(): void
    {
        $w = $this->write("APP_NAME=Acme\nWLCM_API_KEY=existing\n");

        $this->assertTrue($w->has('WLCM_API_KEY'));
        $this->assertFalse($w->has('WLCM_API_SECRET'));
    }

    public function test_it_sees_a_key_with_an_empty_value(): void
    {
        // A scaffolded .env ships keys with no value; appending a second one would give
        // the file two definitions of the same key.
        $w = $this->write("WLCM_API_KEY=\n");

        $this->assertTrue($w->has('WLCM_API_KEY'));
    }

    public function test_it_sees_an_exported_key(): void
    {
        $w = $this->write("export WLCM_API_KEY=existing\n");

        $this->assertTrue($w->has('WLCM_API_KEY'));
    }

    public function test_it_sees_a_key_with_surrounding_whitespace(): void
    {
        $w = $this->write("   WLCM_API_KEY = existing  \n");

        $this->assertTrue($w->has('WLCM_API_KEY'));
    }

    public function test_a_commented_key_does_not_count_as_present(): void
    {
        // Our own commented duplicates are comments; they must not block a later real
        // append, or a merchant who declined once can never add the key.
        $w = $this->write("# WLCM_API_KEY=old-commented-value\n");

        $this->assertFalse($w->has('WLCM_API_KEY'));
    }

    public function test_a_key_that_is_a_prefix_of_another_is_not_a_match(): void
    {
        $w = $this->write("WLCM_API_KEY_EXTRA=x\n");

        $this->assertFalse($w->has('WLCM_API_KEY'));
    }

    public function test_it_appends_a_missing_key(): void
    {
        $w = $this->write("APP_NAME=Acme\n");

        $w->append('WLCM_API_KEY', 'wlcm_abc');

        $this->assertStringContainsString("\nWLCM_API_KEY=wlcm_abc\n", (string) file_get_contents($this->path));
        $this->assertTrue($w->has('WLCM_API_KEY'));
    }

    public function test_it_appends_a_commented_duplicate_when_asked(): void
    {
        $w = $this->write("WLCM_API_KEY=existing\n");

        $w->append('WLCM_API_KEY', 'wlcm_new', commented: true);

        $contents = (string) file_get_contents($this->path);
        // The original survives untouched — never overwrite is the binding rule.
        $this->assertStringContainsString('WLCM_API_KEY=existing', $contents);
        $this->assertStringContainsString('# WLCM_API_KEY=wlcm_new', $contents);
    }

    public function test_it_creates_the_file_when_none_exists(): void
    {
        $w = new EnvWriter($this->path);

        $this->assertFalse($w->has('WLCM_API_KEY'));

        $w->append('WLCM_API_KEY', 'wlcm_abc');

        $this->assertStringContainsString('WLCM_API_KEY=wlcm_abc', (string) file_get_contents($this->path));
    }

    public function test_it_does_not_glue_onto_a_file_with_no_trailing_newline(): void
    {
        $w = $this->write('APP_NAME=Acme');

        $w->append('WLCM_API_KEY', 'wlcm_abc');

        $this->assertStringNotContainsString('AcmeWLCM_API_KEY', (string) file_get_contents($this->path));
        $this->assertTrue($w->has('WLCM_API_KEY'));
    }

    public function test_it_quotes_a_value_that_needs_it(): void
    {
        $w = $this->write("APP_NAME=Acme\n");

        $w->append('WLCM_NAME', 'Acme Trading LLC');

        $this->assertStringContainsString('WLCM_NAME="Acme Trading LLC"', (string) file_get_contents($this->path));
    }

    public function test_it_reads_the_existing_value_for_display(): void
    {
        $w = $this->write("WLCM_API_KEY=\"quoted value\"\n");

        $this->assertSame('quoted value', $w->value('WLCM_API_KEY'));
    }

    public function test_it_finds_a_key_in_a_crlf_file(): void
    {
        // A .env edited on Windows; the package has already been bitten once by CRLF.
        $w = $this->write("APP_NAME=Acme\r\nWLCM_API_KEY=existing\r\n");

        $this->assertTrue($w->has('WLCM_API_KEY'));
    }
}
