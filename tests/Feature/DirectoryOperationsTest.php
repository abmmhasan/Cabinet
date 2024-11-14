<?php

use Infocyph\Pathwise\DirectoryManager\DirectoryOperations;

// Helper function to create a temporary directory for testing
function createTempDirectory(): string
{
    $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('test_dir_', true) . random_int(0, 99);
    mkdir($tempDir);

    return $tempDir;
}

beforeEach(function () {
    $this->tempDir = createTempDirectory();
    $this->directoryOperations = new DirectoryOperations($this->tempDir);
});

afterEach(function () {
    if (is_dir($this->tempDir)) {
        (new DirectoryOperations($this->tempDir))->delete(true);
    }
});

test('can create a directory', function () {
    $newDir = $this->tempDir . DIRECTORY_SEPARATOR . uniqid('new_dir_', true);
    $dirOps = new DirectoryOperations($newDir);
    expect($dirOps->create())
        ->toBeTrue()
        ->and(is_dir($newDir))->toBeTrue();
});

test('can delete a directory', function () {
//    $this->directoryOperations->create();
    expect($this->directoryOperations->delete())
        ->toBeTrue()
        ->and(is_dir($this->tempDir))->toBeFalse();
});

test('can copy a directory', function () {
    $destDir = createTempDirectory();
//    $this->directoryOperations->create();
    $fileName = uniqid('test_', true) . '.txt';
    file_put_contents($this->tempDir . '/' . $fileName, 'sample content');
    $this->directoryOperations->copy($destDir);

    expect(is_dir($destDir))
        ->toBeTrue()
        ->and(file_exists($destDir . '/' . $fileName))->toBeTrue()
        ->and(file_get_contents($destDir . '/' . $fileName))->toBe('sample content');
});

test('can move a directory', function () {
    $destDir = createTempDirectory();
    $newLocation = $destDir . DIRECTORY_SEPARATOR . uniqid('moved_dir_', true);
    $result = $this->directoryOperations->move($newLocation);

    expect($result)
        ->toBeTrue()
        ->and(is_dir($this->tempDir))->toBeFalse()
        ->and(is_dir($newLocation))->toBeTrue();
});

test('can list directory contents', function () {
    $file1 = $this->tempDir . '/' . uniqid('test_', true) . '.txt';
    $file2 = $this->tempDir . '/' . uniqid('example_', true) . '.txt';
    file_put_contents($file1, 'sample content');
    file_put_contents($file2, 'example content');

    $contents = $this->directoryOperations->listContents();
    $contents = array_map(fn($path) => realpath($path), $contents);

    expect($contents)->toContain(realpath($file1), realpath($file2));
});

test('can list directory contents with details', function () {
    $file = $this->tempDir . '/' . uniqid('test_', true) . '.txt';
    file_put_contents($file, 'sample content');

    $contents = $this->directoryOperations->listContents(true);
    $fileInfo = $contents[0];

    expect($fileInfo)
        ->toHaveKeys(['path', 'type', 'size', 'permissions', 'last_modified'])
        ->and($fileInfo['type'])->toBe('file');
});

test('can get and set directory permissions', function () {
    $this->directoryOperations->setPermissions(0777);
    expect($this->directoryOperations->getPermissions())->toBe(0777);
});

test('can calculate directory size', function () {
    $file1 = $this->tempDir . '/' . uniqid('file1_', true) . '.txt';
    $file2 = $this->tempDir . '/' . uniqid('file2_', true) . '.txt';
    file_put_contents($file1, str_repeat('A', 1024)); // 1 KB
    file_put_contents($file2, str_repeat('B', 2048)); // 2 KB

    expect($this->directoryOperations->size())->toBe(1024 + 2048);
});

test('can flatten directory contents', function () {
    $subdir = $this->tempDir . '/' . uniqid('subdir_', true);
    mkdir($subdir);
    $file1 = $subdir . '/' . uniqid('file1_', true) . '.txt';
    $file2 = $this->tempDir . '/' . uniqid('file2_', true) . '.txt';
    file_put_contents($file1, 'content');
    file_put_contents($file2, 'more content');

    $flattened = $this->directoryOperations->flatten();
    $flattened = array_map(fn($path) => realpath($path), $flattened);

    expect($flattened)->toContain(realpath($file1), realpath($file2));
});

test('can zip directory contents', function () {
    $file = $this->tempDir . '/' . uniqid('file_', true) . '.txt';
    file_put_contents($file, 'zip test');

    $zipPath = $this->tempDir . '/' . uniqid('archive_', true) . '.zip';
    $this->directoryOperations->zip($zipPath);

    $zip = new ZipArchive;
    $zip->open($zipPath);

    expect($zip->numFiles)
        ->toBe(1)
        ->and($zip->getNameIndex(0))->toBe(basename($file));
    $zip->close();
});

test('can unzip archive', function () {
    // Create a zip file with a sample file
    $zipPath = $this->tempDir . '/' . uniqid('archive_', true) . '.zip';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE);
    $fileName = uniqid('file_', true) . '.txt';
    $zip->addFromString($fileName, 'sample content');
    $zip->close();

    // Unzip to a new directory
    $unzipDir = createTempDirectory();
    $dirOps = new DirectoryOperations($unzipDir);
    $dirOps->unzip($zipPath);

    expect(file_exists($unzipDir . '/' . $fileName))
        ->toBeTrue()
        ->and(file_get_contents($unzipDir . '/' . $fileName))->toBe('sample content');
});

test('finds files based on criteria', function () {
    $file1 = $this->tempDir . '/' . uniqid('file1_', true) . '.txt';
    $file2 = $this->tempDir . '/' . uniqid('file2_', true) . '.md';
    file_put_contents($file1, 'file one');
    file_put_contents($file2, 'file two');

    // Set permissions compatible across platforms
    if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
        chmod($file1, 0644);
    }

    $foundFiles = $this->directoryOperations->find([
        'name' => basename($file1),
        'extension' => 'txt',
        'permissions' => 0644,
    ]);

    $foundFiles = array_map(fn($path) => realpath($path), $foundFiles);

    expect($foundFiles)
        ->toHaveCount(1)
        ->and($foundFiles[0])->toEndWith(basename($file1));
});
