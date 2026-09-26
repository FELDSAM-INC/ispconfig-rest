<?php

namespace Tests\Unit;

use App\Support\WebRuntimeDirectory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WebRuntimeDirectoryTest extends TestCase
{
    public function test_existing_child_folders_pass_but_missing_paths_and_symlinks_fail(): void
    {
        $root = sys_get_temp_dir().'/runtime-'.bin2hex(random_bytes(8));
        mkdir($root.'/web/app/public', 0700, true);
        mkdir($root.'/private');
        symlink($root.'/private', $root.'/web/link');
        symlink($root.'/web/app/public', $root.'/web/inside');
        try {
            $site = ['type' => 'vhost', 'document_root' => $root];
            WebRuntimeDirectory::check($site, '');
            WebRuntimeDirectory::check($site, 'app/public');
            foreach (['../private', '/etc', 'missing', 'link', 'inside', 'app/../..'] as $path) {
                try {
                    WebRuntimeDirectory::check($site, $path);
                    self::fail('Unsafe or missing path accepted: '.$path);
                } catch (RuntimeException $error) {
                    self::assertStringStartsWith('runtime_directory_', $error->getMessage());
                }
            }
            $child = ['type' => 'vhostalias', 'document_root' => $root, 'web_folder' => 'private'];
            WebRuntimeDirectory::check($child, '');
        } finally {
            unlink($root.'/web/link');
            unlink($root.'/web/inside');
            rmdir($root.'/web/app/public');
            rmdir($root.'/web/app');
            rmdir($root.'/web');
            rmdir($root.'/private');
            rmdir($root);
        }
    }
}
