<?php

namespace Tests\Unit\Services;

use Tests\TestCase;

/**
 * Папки вложений создаёт и очередь (root), и веб-сервер (www-data). Папка root с
 * правами 0755 закрывает запись веб-серверу, и файлы чатов молча теряются.
 */
class StorageWritePermissionsTest extends TestCase
{
    public function test_public_disk_creates_group_writable_directories_and_files(): void
    {
        $permissions = config('filesystems.disks.public.permissions');

        $this->assertSame(0775, $permissions['dir']['public']);
        $this->assertSame(0664, $permissions['file']['public']);
        // Группа должна писать: 0775 = rwxrwxr-x, 0664 = rw-rw-r--.
        $this->assertNotSame(0, $permissions['dir']['public'] & 0020);
        $this->assertNotSame(0, $permissions['file']['public'] & 0020);
    }

    public function test_console_processes_keep_group_write_bit(): void
    {
        // Очередь (root, umask 0022) иначе создаёт папки 0755 и закрывает запись www-data.
        $this->assertSame(0002, umask());
    }

    public function test_directory_created_by_a_console_process_is_writable_by_the_group(): void
    {
        $dir = sys_get_temp_dir().'/umask-check-'.uniqid();
        mkdir($dir, config('filesystems.disks.public.permissions.dir.public'), true);

        $mode = fileperms($dir) & 0777;
        rmdir($dir);

        $this->assertSame(0775, $mode);
    }
}
