<?php

namespace Infocyph\Pathwise\FileManager;

use ZipArchive;
use Exception;

class FileCompression
{
    private readonly ZipArchive $zip;
    private bool $isOpen = false;
    private ?string $password = null;
    private int $encryptionAlgorithm;
    private array $hooks = [];
    private mixed $logger = null;
    private ?string $defaultDecompressionPath = null;

    /**
     * Constructor to set the ZIP file path.
     *
     * @param string $zipFilePath Path to the ZIP file.
     * @param bool $create If true, create a new ZIP file if it doesn't exist.
     * @throws Exception
     */
    public function __construct(private readonly string $zipFilePath, bool $create = false)
    {
        $this->zip = new ZipArchive();

        // Set encryption algorithm if supported
        $this->encryptionAlgorithm = defined('ZipArchive::EM_AES_256') ? ZipArchive::EM_AES_256 : 0;

        // Open the archive with CREATE flag if specified
        $flags = $create ? ZipArchive::CREATE : 0;
        $this->openZip($flags);
    }

    /**
     * Automatically closes the ZIP archive when the object is destroyed.
     */
    public function __destruct()
    {
        $this->closeZip();
    }

    /**
     * Ensures the ZIP archive is open before any operation.
     */
    private function reopenIfNeeded(): void
    {
        if (!$this->isOpen) {
            $this->openZip();
        }
    }

    /**
     * Close the ZIP archive.
     *
     * This method is a no-op if the archive is already closed.
     *
     * @return self
     */
    public function save(): self
    {
        $this->closeZip();
        return $this;
    }

    /**
     * Set the password to use for encrypting the ZIP archive.
     */
    public function setPassword(string $password): self
    {
        $this->password = $password;
        return $this;
    }

    /**
     * Set the encryption algorithm for the ZIP archive.
     *
     * @param int $algorithm The encryption algorithm to use.
     * @throws Exception If the specified encryption algorithm is invalid.
     */
    public function setEncryptionAlgorithm(int $algorithm): self
    {
        if (!in_array($algorithm, [ZipArchive::EM_AES_256, ZipArchive::EM_AES_128], true)) {
            throw new Exception("Invalid encryption algorithm specified.");
        }

        $this->encryptionAlgorithm = $algorithm;
        return $this;
    }

    /**
     * Set the default path to decompress the ZIP archive to.
     */
    public function setDefaultDecompressionPath(string $path): self
    {
        $this->defaultDecompressionPath = $path;
        return $this;
    }

    /**
     * Set a logger function to be called with log messages.
     */
    public function setLogger(callable $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    /**
     * Register a callback to be executed when a specific event occurs.
     */
    public function registerHook(string $event, callable $callback): self
    {
        $this->hooks[$event][] = $callback;
        return $this;
    }

    /**
     * Compress a file or directory.
     */
    public function compress(string $source): self
    {
        $this->reopenIfNeeded();
        $source = realpath($source);
        $this->log("Compressing source: $source");
        $this->addFilesToZip($source, $this->zip);
        return $this;
    }

    /**
     * Compress files or directories into the current ZIP archive, optionally
     * filtering by file extensions.
     */
    public function compressWithFilter(string $source, array $extensions = []): self
    {
        $this->reopenIfNeeded();
        $source = realpath($source);
        $this->log("Compressing source with filter: $source");
        $this->addFilesToZipWithFilter($source, $this->zip, null, $extensions);
        return $this;
    }

    /**
     * Decompresses the contents of the current ZIP archive.
     */
    public function decompress(?string $destination = null): self
    {
        $this->reopenIfNeeded();
        $destination ??= $this->defaultDecompressionPath;
        if (!$destination) {
            throw new Exception("No destination path provided for decompression.");
        }

        if ($this->password) {
            $this->zip->setPassword($this->password);
        }

        if (!$this->zip->extractTo($destination)) {
            throw new Exception("Failed to extract ZIP archive.");
        }

        $this->log("Decompressed to: $destination");
        return $this;
    }

    /**
     * Add a file to the current ZIP archive.
     */
    public function addFile(string $filePath, ?string $zipPath = null): self
    {
        $this->reopenIfNeeded();
        $this->triggerHook('beforeAdd', $filePath);
        $this->log("Adding file: $filePath");
        $zipPath ??= basename($filePath);

        if ($this->password) {
            $this->zip->setPassword($this->password);
            $this->zip->addFile($filePath, $zipPath);
            $this->zip->setEncryptionName($zipPath, $this->encryptionAlgorithm);
        } else {
            $this->zip->addFile($filePath, $zipPath);
        }

        $this->triggerHook('afterAdd', $filePath);
        return $this;
    }

    /**
     * Batch add multiple files to the current ZIP archive.
     */
    public function batchAddFiles(array $files): self
    {
        $this->reopenIfNeeded();
        $this->log("Batch adding files.");
        foreach ($files as $filePath => $zipPath) {
            $this->addFile($filePath, $zipPath);
        }
        return $this;
    }

    /**
     * Batch extract files from the current ZIP archive.
     */
    public function batchExtractFiles(array $files, string $destination): self
    {
        $this->reopenIfNeeded();
        $this->log("Batch extracting files.");
        foreach ($files as $zipPath => $localPath) {
            $localPath = "$destination/$localPath";
            if (!$this->zip->extractTo($localPath, $zipPath)) {
                throw new Exception("Failed to extract file $zipPath to $localPath.");
            }
        }
        return $this;
    }

    /**
     * Checks the integrity of the current ZIP archive.
     */
    public function checkIntegrity(): bool
    {
        $this->reopenIfNeeded();
        return $this->zip->status === ZipArchive::ER_OK;
    }

    /**
     * Get the number of files in the current ZIP archive.
     */
    public function fileCount(): int
    {
        $this->reopenIfNeeded();
        return $this->zip->numFiles;
    }

    /**
     * Lists all files in the current ZIP archive.
     */
    public function listFiles(): array
    {
        $this->reopenIfNeeded();
        $files = [];
        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $files[] = $this->zip->getNameIndex($i);
        }
        return $files;
    }

    /**
     * Returns an iterator that yields the names of all files in the current ZIP archive.
     */
    public function getFileIterator(): \Generator
    {
        $this->reopenIfNeeded();
        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            yield $this->zip->getNameIndex($i);
        }
    }

    /**
     * Opens the ZIP archive at the specified path with the given flags.
     *
     * Throws an exception if the archive cannot be opened.
     */
    private function openZip(int $flags = 0): void
    {
        $result = $this->zip->open($this->zipFilePath, $flags);
        if ($result !== true) {
            throw new Exception("Failed to open ZIP archive at {$this->zipFilePath}. Error: $result");
        }
        $this->isOpen = true;
    }

    /**
     * Closes the ZIP archive if it is open.
     */
    private function closeZip(): void
    {
        if ($this->isOpen) {
            $this->zip->close();
            $this->isOpen = false;
        }
    }

    /**
     * Triggers all callbacks registered for the specified event.
     */
    private function triggerHook(string $event, ...$args): void
    {
        foreach ($this->hooks[$event] ?? [] as $callback) {
            $callback(...$args);
        }
    }

    /**
     * Log a message if the logger is callable.
     */
    private function log(string $message): void
    {
        if (is_callable($this->logger)) {
            ($this->logger)($message);
        }
    }

    /**
     * Recursively traverse the specified directory and add all files to the ZIP archive,
     * optionally under a relative path.
     */
    private function addFilesToZip(string $path, ZipArchive $zip, string $baseDir = null): void
    {
        $baseDir ??= $path;

        if (is_dir($path)) {
            $relativePath = trim(str_replace($baseDir, '', $path), DIRECTORY_SEPARATOR);
            if (!empty($relativePath)) {
                $zip->addEmptyDir($relativePath);
            }

            foreach (scandir($path) as $file) {
                if ($file !== '.' && $file !== '..') {
                    $this->addFilesToZip("$path/$file", $zip, $baseDir);
                }
            }
        } else {
            $relativePath = trim(str_replace($baseDir, '', $path), DIRECTORY_SEPARATOR);
            if ($this->password) {
                $zip->setPassword($this->password);
                $zip->addFile($path, $relativePath);
                $zip->setEncryptionName($relativePath, $this->encryptionAlgorithm);
            } else {
                $zip->addFile($path, $relativePath);
            }
        }
    }

    /**
     * Adds files to the ZIP archive, optionally filtering by file extensions.
     */
    private function addFilesToZipWithFilter(string $path, ZipArchive $zip, ?string $relativePath, array $extensions): void
    {
        $relativePath ??= basename($path);

        if (is_dir($path)) {
            $zip->addEmptyDir($relativePath);
            foreach (scandir($path) as $file) {
                if ($file !== '.' && $file !== '..') {
                    $this->addFilesToZipWithFilter("$path/$file", $zip, "$relativePath/$file", $extensions);
                }
            }
        } elseif (empty($extensions) || in_array(pathinfo($path, PATHINFO_EXTENSION), $extensions)) {
            if ($this->password) {
                $zip->setPassword($this->password);
                $zip->addFile($path, $relativePath);
                $zip->setEncryptionName($relativePath, $this->encryptionAlgorithm);
            } else {
                $zip->addFile($path, $relativePath);
            }
        }
    }
}
