<?php

use Infocyph\Pathwise\FileManager\DirectoryOperations;
use PHPUnit\Framework\TestCase;
use ZipArchive;

// Helper function to create a temporary directory for testing
function createTempDirectory(): string {
    $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('test_dir_', true);
    mkdir($tempDir);
    return $tempDir;
}

beforeEach(function () {
    // Set up a temporary directory for each test
    $this->tempDir = createTempDirectory();
    $this->directoryOperations = new DirectoryOperations($this->tempDir);
});

afterEach(function () {
    // Clean up the temporary directory after each test
    (new DirectoryOperations($this->tempDir))->delete(true);
});

test('can create a directory', function () {
    $newDir = $this->tempDir . DIRECTORY_SEPARATOR . 'new_dir';
    $dirOps = new DirectoryOperations($newDir);

    expect($dirOps->create())->toBeTrue();
    expect(is_dir($newDir))->toBeTrue();
});

test('can delete a directory', function () {
    $this->directoryOperations->create();
    expect($this->directoryOperations->delete())->toBeTrue();
    expect(is_dir($this->tempDir))->toBeFalse();
});

test('can copy a directory', function () {
    $destDir = createTempDirectory();
    $this->directoryOperations->create();

    // Create a test file in the directory
    file_put_contents($this->tempDir . '/test.txt', 'sample content');
    $this->directoryOperations->copy($destDir);

    expect(is_dir($destDir))->toBeTrue();
    expect(file_exists($destDir . '/test.txt'))->toBeTrue();
    expect(file_get_contents($destDir . '/test.txt'))->toBe('sample content');
});

test('can move a directory', function () {
    $destDir = createTempDirectory();
    $result = $this->directoryOperations->move($destDir . '/moved_dir');

    expect($result)->toBeTrue();
    expect(is_dir($this->tempDir))->toBeFalse();
    expect(is_dir($destDir . '/moved_dir'))->toBeTrue();
});

test('can list directory contents', function () {
    file_put_contents($this->tempDir . '/test.txt', 'sample content');
    file_put_contents($this->tempDir . '/example.txt', 'example content');

    $contents = $this->directoryOperations->listContents();

    expect($contents)->toContain($this->tempDir . '/test.txt', $this->tempDir . '/example.txt');
});

test('can list directory contents with details', function () {
    file_put_contents($this->tempDir . '/test.txt', 'sample content');

    $contents = $this->directoryOperations->listContents(true);
    $fileInfo = $contents[0];

    expect($fileInfo)->toHaveKeys(['path', 'type', 'size', 'permissions', 'last_modified']);
    expect($fileInfo['type'])->toBe('file');
});

test('can get and set directory permissions', function () {
    $this->directoryOperations->setPermissions(0777);
    expect($this->directoryOperations->getPermissions())->toBe(0777);
});

test('can calculate directory size', function () {
    file_put_contents($this->tempDir . '/file1.txt', str_repeat('A', 1024)); // 1 KB
    file_put_contents($this->tempDir . '/file2.txt', str_repeat('B', 2048)); // 2 KB

    expect($this->directoryOperations->size())->toBe(1024 + 2048);
});

test('can flatten directory contents', function () {
    mkdir($this->tempDir . '/subdir');
    file_put_contents($this->tempDir . '/subdir/file1.txt', 'content');
    file_put_contents($this->tempDir . '/file2.txt', 'more content');

    $flattened = $this->directoryOperations->flatten();

    expect($flattened)->toContain($this->tempDir . '/subdir/file1.txt', $this->tempDir . '/file2.txt');
});

test('can zip directory contents', function () {
    file_put_contents($this->tempDir . '/file.txt', 'zip test');

    $zipPath = $this->tempDir . '/archive.zip';
    $this->directoryOperations->zip($zipPath);

    $zip = new ZipArchive();
    $zip->open($zipPath);

    expect($zip->numFiles)->toBe(1);
    expect($zip->getNameIndex(0))->toBe('file.txt');
    $zip->close();
});

test('can unzip archive', function () {
    // Create a zip file with a sample file
    $zipPath = $this->tempDir . '/archive.zip';
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFromString('file.txt', 'sample content');
    $zip->close();

    // Unzip to a new directory
    $unzipDir = createTempDirectory();
    $dirOps = new DirectoryOperations($unzipDir);
    $dirOps->unzip($zipPath);

    expect(file_exists($unzipDir . '/file.txt'))->toBeTrue();
    expect(file_get_contents($unzipDir . '/file.txt'))->toBe('sample content');
});

test('finds files based on criteria', function () {
    file_put_contents($this->tempDir . '/file1.txt', 'file one');
    file_put_contents($this->tempDir . '/file2.md', 'file two');
    chmod($this->tempDir . '/file1.txt', 0644);

    $foundFiles = $this->directoryOperations->find([
        'name' => 'file',
        'extension' => 'txt',
        'permissions' => 0644,
    ]);

    expect($foundFiles)->toHaveCount(1);
    expect($foundFiles[0])->toEndWith('file1.txt');
});
