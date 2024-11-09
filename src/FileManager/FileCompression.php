<?php

namespace Infocyph\Pathwise\FileManager;

use Generator;
use ZipArchive;
use Exception;

class FileCompression
{
    private ZipArchive $zip;
    private string $zipFilePath;
    private bool $isOpen = false;
    private ?string $password = null;
    private int $encryptionAlgorithm = ZipArchive::EM_AES_256;
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
    public function __construct(string $zipFilePath, bool $create = false)
    {
        $this->zip = new ZipArchive();
        $this->zipFilePath = $zipFilePath;

        // Open archive on instantiation, create if specified
        $flags = $create ? ZipArchive::CREATE : 0;
        $this->openZip($flags);
    }


    /**
     * Automatically closes the ZIP archive when the object is destroyed.
     *
     * This is a convenience method to ensure the archive is closed even if
     * the user forgets to call {@see closeZip()}.
     */
    public function __destruct()
    {
        $this->closeZip();
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
     *
     * This should be used in conjunction with `setEncryptionAlgorithm()` to
     * specify the encryption settings for the archive.
     *
     * @param string $password The password to use for encryption.
     *
     * @return self
     */
    public function setPassword(string $password): self
    {
        $this->password = $password;
        return $this;
    }


    /**
     * Set the encryption algorithm for the ZIP archive.
     *
     * @param int $algorithm The encryption algorithm to use. Must be one of the following:
     *                        - ZipArchive::EM_AES_256
     *                        - ZipArchive::EM_AES_128
     *                        - ZipArchive::EM_PKWARE
     *
     * @return self
     *
     * @throws Exception If the specified encryption algorithm is invalid.
     */
    public function setEncryptionAlgorithm(int $algorithm): self
    {
        if (!in_array($algorithm, [ZipArchive::EM_AES_256, ZipArchive::EM_AES_128, ZipArchive::EM_PKWARE], true)) {
            throw new Exception("Invalid encryption algorithm specified.");
        }

        $this->encryptionAlgorithm = $algorithm;
        return $this;
    }


    /**
     * Set the default path to decompress the ZIP archive to.
     *
     * If no destination path is provided to the `decompress` method, the
     * ZIP archive will be decompressed to the specified default path.
     *
     * @param string $path The default path to decompress the ZIP archive to.
     * @return $this The FileCompression instance.
     */
    public function setDefaultDecompressionPath(string $path): self
    {
        $this->defaultDecompressionPath = $path;
        return $this;
    }


    /**
     * Set a logger function to be called with log messages.
     *
     * The logger function should take a single string argument, which is the
     * log message. If no logger is set, log messages will be ignored.
     *
     * @param callable $logger The logger function to be called.
     * @return $this The FileCompression instance.
     */
    public function setLogger(callable $logger): self
    {
        $this->logger = $logger;
        return $this;
    }


    /**
     * Register a callback to be executed when a specific event occurs.
     *
     * Events:
     * - `beforeAdd`: Called before adding a file to the ZIP archive.
     * - `afterAdd`: Called after adding a file to the ZIP archive.
     * - `beforeCompress`: Called before compressing the ZIP archive.
     * - `afterCompress`: Called after compressing the ZIP archive.
     * - `beforeDecompress`: Called before decompressing the ZIP archive.
     * - `afterDecompress`: Called after decompressing the ZIP archive.
     *
     * @param string $event The event name.
     * @param callable $callback The callback to be executed.
     * @return $this The FileCompression instance.
     */
    public function registerHook(string $event, callable $callback): self
    {
        $this->hooks[$event][] = $callback;
        return $this;
    }


    /**
     * Compress a file or directory.
     *
     * This method compresses the specified file or directory, including all
     * subdirectories and their contents. It triggers the `beforeCompress` and
     * `afterCompress` hooks.
     *
     * @param string $source The file or directory to compress.
     * @return self
     */
    public function compress(string $source): self
    {
        $source = realpath($source);
        $this->log("Compressing source: $source");
        $this->addFilesToZip($source, $this->zip);
        return $this;
    }


    /**
     * Compress files or directories into the current ZIP archive, optionally
     * filtering by file extensions.
     *
     * This method recursively traverses the specified directory and adds all
     * files to the ZIP archive, optionally filtering by file extensions. If a
     * password has been set, it will be used to encrypt all added files.
     *
     * @param string $source The file or directory to compress.
     * @param array $extensions An array of file extensions to filter by.
     *
     * @return self
     */
    public function compressWithFilter(string $source, array $extensions = []): self
    {
        $source = realpath($source);
        $this->log("Compressing source with filter: $source");
        $this->addFilesToZipWithFilter($source, $this->zip, null, $extensions);
        return $this;
    }


    /**
     * Decompresses the contents of the current ZIP archive.
     *
     * This method extracts all files from the ZIP archive to the specified
     * destination directory. If no destination is provided, the default
     * decompression path is used. If a password is set, it is applied to
     * the ZIP archive before extraction.
     *
     * @param string|null $destination The destination directory for extraction.
     *                                 If null, the default decompression path is used.
     * @return self This instance.
     * @throws Exception If no destination path is provided or extraction fails.
     */
    public function decompress(?string $destination = null): self
    {
        $destination = $destination ?? $this->defaultDecompressionPath;
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
     *
     * Triggers `beforeAdd` and `afterAdd` hooks around the file addition process.
     * If a password is set, the file will be encrypted using the specified
     * encryption algorithm.
     *
     * @param string $filePath The file path to add to the ZIP archive.
     * @param string|null $zipPath The path within the ZIP archive. Defaults to the basename of the file path.
     * @return self
     */
    public function addFile(string $filePath, ?string $zipPath = null): self
    {
        $this->triggerHook('beforeAdd', $filePath);
        $this->log("Adding file: $filePath");
        $zipPath = $zipPath ?? basename($filePath);

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
     *
     * @param array $files An associative array of file paths as keys and their
     *                     corresponding paths within the ZIP archive as values.
     * @return self
     */
    public function batchAddFiles(array $files): self
    {
        $this->log("Batch adding files.");
        foreach ($files as $filePath => $zipPath) {
            $this->addFile($filePath, $zipPath);
        }
        return $this;
    }


    /**
     * Batch extract files from the current ZIP archive.
     *
     * Extracts multiple files from the current ZIP archive to the specified
     * destination directory. The files are specified as an associative array,
     * where the key is the path of the file within the ZIP archive, and the
     * value is the path where the file should be extracted to.
     *
     * @param array $files An associative array of files to extract, where the
     *                     key is the path of the file within the ZIP archive,
     *                     and the value is the path where the file should be
     *                     extracted to.
     * @param string $destination The destination directory where the files
     *                            should be extracted to.
     * @return self This instance.
     * @throws Exception If any of the files fail to be extracted.
     */
    public function batchExtractFiles(array $files, string $destination): self
    {
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
     *
     * Returns true if the ZIP archive is valid and has no errors, false otherwise.
     *
     * @return bool True if the archive is valid, false otherwise.
     */
    public function checkIntegrity(): bool
    {
        return $this->zip->status === ZipArchive::ER_OK;
    }


    /**
     * Get the number of files in the current ZIP archive.
     *
     * Returns the number of files within the ZIP archive.
     *
     * @return int The number of files in the archive.
     */
    public function fileCount(): int
    {
        return $this->zip->numFiles;
    }


    /**
     * Lists all files in the current ZIP archive.
     *
     * Iterates through the ZIP archive and collects the names of all files,
     * returning them as an array of strings.
     *
     * @return array An array containing the names of all files in the ZIP archive.
     */
    public function listFiles(): array
    {
        $files = [];
        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $files[] = $this->zip->getNameIndex($i);
        }
        return $files;
    }


    /**
     * Returns an iterator that yields the names of all files in the current ZIP archive.
     *
     * The iterator yields the names of all files in the archive as strings.
     *
     * @return Generator<string> An iterator that yields the names of all files in the archive.
     */
    public function getFileIterator(): Generator
    {
        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            yield $this->zip->getNameIndex($i);
        }
    }


    /**
     * Opens the ZIP archive at the specified path with the given flags.
     *
     * Throws an exception if the archive cannot be opened.
     *
     * @param int $flags The flags to use when opening the archive. Defaults to 0.
     * @throws Exception
     * @see ZipArchive::open()
     */
    private function openZip(int $flags = 0): void
    {
        if (!$this->isOpen && $this->zip->open($this->zipFilePath, $flags) !== true) {
            throw new Exception("Failed to open ZIP archive at {$this->zipFilePath}.");
        }
        $this->isOpen = true;
    }


    /**
     * Closes the ZIP archive if it is open.
     *
     * This method checks if the ZIP archive is currently open. If it is,
     * the archive is closed, and the open state is set to false.
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
     *
     * @param string $event The name of the event to trigger.
     * @param mixed ...$args The arguments to pass to the event callbacks.
     */
    private function triggerHook(string $event, ...$args): void
    {
        foreach ($this->hooks[$event] ?? [] as $callback) {
            $callback(...$args);
        }
    }


    /**
     * Log a message if the logger is callable.
     *
     * @param string $message The message to log.
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
     *
     * If a password has been set, it will be used to encrypt all added files.
     *
     * @param string $path The path to add files from.
     * @param ZipArchive $zip The ZIP archive to add the files to.
     * @param string|null $relativePath The relative path to add files under.
     */
    private function addFilesToZip(string $path, ZipArchive $zip, string $relativePath = null): void
    {
        $relativePath = $relativePath ?? basename($path);

        if (is_dir($path)) {
            $zip->addEmptyDir($relativePath);
            foreach (scandir($path) as $file) {
                if ($file !== '.' && $file !== '..') {
                    $this->addFilesToZip("$path/$file", $zip, "$relativePath/$file");
                }
            }
        } else {
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
     *
     * Recursively traverses the specified directory and adds all files to the ZIP
     * archive, optionally filtering by file extensions. If a password has been set,
     * it will be used to encrypt all added files.
     *
     * @param string $path The path to add files from.
     * @param ZipArchive $zip The ZIP archive to add the files to.
     * @param string|null $relativePath The relative path to add files under.
     * @param array $extensions An array of file extensions to filter by.
     */
    private function addFilesToZipWithFilter(
        string $path,
        ZipArchive $zip,
        ?string $relativePath,
        array $extensions,
    ): void {
        $relativePath = $relativePath ?? basename($path);

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
