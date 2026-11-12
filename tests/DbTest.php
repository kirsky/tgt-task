<?php

declare(strict_types=1);

namespace Tests;

use App\Config;
use App\Db;
use App\Kv;
use PHPUnit\Framework\TestCase;

final class DbTest extends TestCase
{
    public function test_migrate_creates_tables(): void
    {
        $db = Db::open(':memory:');
        Db::migrate($db);

        $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);
        foreach (['agreements', 'answers', 'chat_state', 'disputes', 'kv', 'newcomers', 'processed_hire_tasks'] as $t) {
            self::assertContains($t, $tables);
        }
        self::assertSame('1', (string) $db->query('PRAGMA foreign_keys')->fetchColumn());
    }

    public function test_kv_roundtrip(): void
    {
        $kv = new Kv(Db::open(':memory:'));
        self::assertNull($kv->get('a'));
        $kv->set('a', '1');
        $kv->set('a', '2');
        self::assertSame('2', $kv->get('a'));
        $kv->delete('a');
        self::assertNull($kv->get('a'));
    }

    public function test_config_reads_env_file(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($file, "A=1\nB=https://x/rest/1/abc/\n# comment\n");
        $cfg = Config::load($file);
        self::assertSame('1', $cfg->get('A'));
        self::assertSame('https://x/rest/1/abc/', $cfg->require('B'));
        self::assertSame('d', $cfg->get('C', 'd'));
        $this->expectException(\RuntimeException::class);
        $cfg->require('C');
    }
}
