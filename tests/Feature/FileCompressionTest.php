<?php

use Infocyph\Pathwise\FileManager\FileCompression;

beforeEach(function () {
    // Create a temporary directory for testing
    $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('test_dir_', true);
    mkdir($this->tempDir);

    // Create test files
    $this->file1 = $this->tempDir . DIRECTORY_SEPARATOR . 'file1.txt';
    $this->file2 = $this->tempDir . DIRECTORY_SEPARATOR . 'file2.txt';
    file_put_contents($this->file1, 'This is the first test file.');
    file_put_contents($this->file2, 'This is the second test file.');

    // Temporary ZIP file path
    $this->zipFilePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('test_zip_', true) . '.zip';
});

afterEach(function () {
    // Clean up files and directories after each test
    if (file_exists($this->zipFilePath)) {
        unlink($this->zipFilePath);
    }

    if (is_dir($this->tempDir)) {
        array_map('unlink', glob($this->tempDir . DIRECTORY_SEPARATOR . '*'));
        rmdir($this->tempDir);
    }
});

// Test ZIP archive creation and file compression
test('it creates a ZIP archive and compresses files', function () {
    $compressor = new FileCompression($this->zipFilePath, true);

    // Check if the ZIP file opens successfully
    $compressor->compress($this->file1);
    $compressor->compress($this->file2);
    $compressor->save();

    // Verify ZIP file creation
    if (!file_exists($this->zipFilePath)) {
        throw new RuntimeException("Failed to create ZIP file at {$this->zipFilePath}");
    }

    // Check file count inside ZIP
    expect($compressor->fileCount())->toBe(2);
})->skip(PHP_OS_FAMILY === 'Windows');


// Test setting password and compressing files
test('it sets a password and compresses files with encryption', function () {
    $compressor = new FileCompression($this->zipFilePath, true);
    $compressor->setPassword('securePassword123')->compress($this->file1);

    expect($compressor->fileCount())->toBe(1);
    expect($compressor->listFiles())->toContain('file1.txt');
});

// Test decompressing a ZIP archive
test('it decompresses a ZIP archive', function () {
    // Compress files first
    $compressor = new FileCompression($this->zipFilePath, true);
    $compressor->compress($this->tempDir)->save();

    // Decompress to a new directory
    $decompressDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('decompress_', true);
    mkdir($decompressDir);

    $compressor->decompress($decompressDir);
    expect(file_exists($decompressDir . DIRECTORY_SEPARATOR . 'file1.txt'))->toBeTrue();
    expect(file_exists($decompressDir . DIRECTORY_SEPARATOR . 'file2.txt'))->toBeTrue();

    // Cleanup decompression directory
    array_map('unlink', glob($decompressDir . DIRECTORY_SEPARATOR . '*'));
    rmdir($decompressDir);
})->skip(PHP_OS_FAMILY === 'Windows');

// Test listing files in a ZIP archive
test('it lists files in the ZIP archive', function () {
    $compressor = new FileCompression($this->zipFilePath, true);
    $compressor->compress($this->file1)->compress($this->file2);

    $files = $compressor->listFiles();
    expect($files)->toContain('file1.txt', 'file2.txt');
});

// Test batch adding files to the ZIP archive
test('it batch adds files to the ZIP archive', function () {
    $compressor = new FileCompression($this->zipFilePath, true);
    $compressor->batchAddFiles([
        $this->file1 => 'file1_in_zip.txt',
        $this->file2 => 'file2_in_zip.txt',
    ]);

    expect($compressor->fileCount())->toBe(2);
    expect($compressor->listFiles())->toContain('file1_in_zip.txt', 'file2_in_zip.txt');
});

// Test setting encryption algorithm
test('it sets encryption algorithm', function () {
    $compressor = new FileCompression($this->zipFilePath, true);
    $compressor->setEncryptionAlgorithm(ZipArchive::EM_AES_128);
    $compressor->compress($this->file1);

    expect($compressor->fileCount())->toBe(1);
});

// Test ZIP archive integrity check
test('it checks ZIP archive integrity', function () {
    $compressor = new FileCompression($this->zipFilePath, true);
    $compressor->compress($this->file1);

    expect($compressor->checkIntegrity())->toBeTrue();
});

// Test adding and decompressing with hooks
test('it registers and triggers hooks for adding files', function () {
    $compressor = new FileCompression($this->zipFilePath, true);

    $hookTriggered = false;
    $compressor->registerHook('beforeAdd', function () use (&$hookTriggered) {
        $hookTriggered = true;
    });

    $compressor->compress($this->file1);
    expect($hookTriggered)->toBeTrue();
})->skip(PHP_OS_FAMILY === 'Windows');

// Test decompressing a password-protected ZIP archive
test('it decompresses a password-protected ZIP archive', function () {
    $compressor = new FileCompression($this->zipFilePath, true);
    $compressor->setPassword('securePassword123')->compress($this->file1)->save();

    $decompressDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('decompress_protected_', true);
    mkdir($decompressDir);

    // Re-open ZIP with password for decompression
    $decompressor = new FileCompression($this->zipFilePath);
    $decompressor->setPassword('securePassword123')->decompress($decompressDir);

    expect(file_exists($decompressDir . DIRECTORY_SEPARATOR . 'file1.txt'))->toBeTrue();

    // Cleanup decompression directory
    unlink($decompressDir . DIRECTORY_SEPARATOR . 'file1.txt');
    rmdir($decompressDir);
});
