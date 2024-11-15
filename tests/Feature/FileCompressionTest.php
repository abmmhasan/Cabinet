<?php

use Infocyph\Pathwise\FileManager\FileCompression;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('test_dir_', true);
    mkdir($this->tempDir);

    $this->file1 = $this->tempDir . DIRECTORY_SEPARATOR . 'file1.txt';
    $this->file2 = $this->tempDir . DIRECTORY_SEPARATOR . 'file2.txt';
    file_put_contents($this->file1, 'This is the first test file.');
    file_put_contents($this->file2, 'This is the second test file.');

    $this->zipFilePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('test_zip_', true) . '.zip';
});

afterEach(function () {
    array_map('unlink', glob($this->tempDir . DIRECTORY_SEPARATOR . '*'));
    rmdir($this->tempDir);
    if (file_exists($this->zipFilePath)) {
        unlink($this->zipFilePath);
    }
});

// Test creating a ZIP archive and compressing files
test('it creates a ZIP archive and compresses files', function () {
    $compressor = new FileCompression($this->zipFilePath, true);
    $compressor->compress($this->tempDir)->save();

    expect(file_exists($this->zipFilePath))->toBeTrue();

    $filesInZip = $compressor->listFiles();
    expect($filesInZip)
        ->toContain('file1.txt', 'file2.txt')
        ->and($compressor->fileCount())->toBe(2);
});

// Test decompressing a ZIP archive
test('it decompresses a ZIP archive', function () {
    $compressor = new FileCompression($this->zipFilePath, true);
    $compressor->compress($this->tempDir)->save();

    $decompressDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('decompress_', true);
    mkdir($decompressDir);

    $compressor->decompress($decompressDir);

    expect(file_exists($decompressDir . DIRECTORY_SEPARATOR . 'file1.txt'))
        ->toBeTrue()
        ->and(file_exists($decompressDir . DIRECTORY_SEPARATOR . 'file2.txt'))->toBeTrue();

    array_map('unlink', glob($decompressDir . DIRECTORY_SEPARATOR . '*'));
    rmdir($decompressDir);
});

// Test compressing files with a password
test('it compresses files with a password and encrypts them', function () {
    $compressor = new FileCompression($this->zipFilePath, true);
    $compressor->setPassword('securepassword')->compress($this->tempDir)->save();

    expect($compressor->fileCount())
        ->toBe(2)
        ->and($compressor->listFiles())->toContain('file1.txt', 'file2.txt');
});

// Test decompressing a password-protected ZIP with wrong password
test('it fails to decompress with an incorrect password', function () {
    $compressor = new FileCompression($this->zipFilePath, true);
    $compressor->setPassword('securepassword')->compress($this->tempDir)->save();

    $decompressDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('decompress_', true);
    mkdir($decompressDir);

    $failedDecompressor = new FileCompression($this->zipFilePath);
    $failedDecompressor->setPassword('wrongpassword');

    expect(fn () => $failedDecompressor->decompress($decompressDir))
        ->toThrow(Exception::class, 'Failed to extract ZIP archive');

    rmdir($decompressDir);
});

// Test adding individual files
test('it adds individual files to a ZIP archive', function () {
    $compressor = new FileCompression($this->zipFilePath, true);
    $compressor->addFile($this->file1)->addFile($this->file2)->save();

    expect($compressor->fileCount())
        ->toBe(2)
        ->and($compressor->listFiles())->toContain('file1.txt', 'file2.txt');
});

// Test batch adding files
test('it handles batch adding files', function () {
    $compressor = new FileCompression($this->zipFilePath, true);
    $compressor->batchAddFiles([$this->file1 => 'file1.txt', $this->file2 => 'file2.txt'])->save();

    expect($compressor->fileCount())
        ->toBe(2)
        ->and($compressor->listFiles())->toContain('file1.txt', 'file2.txt');
});

// Test hooks
test('it triggers hooks during file operations', function () {
    $beforeAdd = false;
    $afterAdd = false;

    $compressor = new FileCompression($this->zipFilePath, true);
    $compressor->registerHook('beforeAdd', function () use (&$beforeAdd) {
        $beforeAdd = true;
    })->registerHook('afterAdd', function () use (&$afterAdd) {
        $afterAdd = true;
    });

    $compressor->addFile($this->file1)->save();

    expect($beforeAdd)
        ->toBeTrue()
        ->and($afterAdd)->toBeTrue();
});

// Test file iterator
test('it retrieves a file iterator for the ZIP archive', function () {
    $compressor = new FileCompression($this->zipFilePath, true);
    $compressor->compress($this->tempDir)->save();

    $files = iterator_to_array($compressor->getFileIterator());
    expect($files)->toContain('file1.txt', 'file2.txt');
});
