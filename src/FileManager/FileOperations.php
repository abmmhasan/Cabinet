<?php

namespace Infocyph\Pathwise\FileManager;

use Infocyph\Pathwise\Exceptions\FileNotFoundException;
use RuntimeException;
use SplFileObject;
use SplFileInfo;

class FileOperations
{
    protected ?SplFileObject $file = null;

    /**
     * Constructor to initialize the file path.
     *
     * @param string $filePath
     */
    public function __construct(protected string $filePath)
    {
    }

    /**
     * Check if a file exists at the given path.
     *
     * @return bool
     */
    public function exists(): bool
    {
        return file_exists($this->filePath);
    }

    /**
     * Check if a file is readable.
     *
     * @return bool
     * @throws FileNotFoundException
     */
    public function isReadable(): bool
    {
        if (!$this->exists()) {
            throw new FileNotFoundException("File not found at $this->filePath.");
        }
        return is_readable($this->filePath);
    }

    /**
     * Initialize the SplFileObject.
     *
     * @param string $mode
     * @return self
     */
    protected function initFile(string $mode = 'r'): self
    {
        $this->file = new SplFileObject($this->filePath, $mode);
        return $this;
    }

    /**
     * Create or overwrite the file with optional content.
     *
     * @param string|null $content
     * @return self
     */
    public function create(?string $content = ''): self
    {
        $this->initFile('w');
        if ($content) {
            $this->file->fwrite($content);
        }
        return $this;
    }

    /**
     * Read content from the file.
     *
     * @return string
     * @throws FileNotFoundException
     */
    public function read(): string
    {
        $this->isReadable();
        $this->initFile();
        return $this->file->fread($this->file->getSize());
    }

    /**
     * Overwrite the file with new content.
     *
     * @param string $content
     * @return self
     */
    public function update(string $content): self
    {
        $this->initFile('w');
        $this->file->fwrite($content);
        return $this;
    }

    /**
     * Append content to the file.
     *
     * @param string $content
     * @return self
     */
    public function append(string $content): self
    {
        $this->initFile('a');
        $this->file->fwrite($content);
        return $this;
    }

    /**
     * Delete the file.
     *
     * @return self
     */
    public function delete(): self
    {
        if (!$this->exists()) {
            throw new RuntimeException("File does not exist at $this->filePath.");
        }
        if (!unlink($this->filePath)) {
            throw new RuntimeException("Unable to delete file at $this->filePath.");
        }
        return $this;
    }

    /**
     * Get all metadata for the file.
     *
     * @return array
     */
    public function getMetadata(): array
    {
        $info = new SplFileInfo($this->filePath);

        return [
            'permissions' => substr(sprintf('%o', $info->getPerms()), -4),
            'size' => $info->getSize(),
            'last_modified' => $info->getMTime(),
            'owner' => $info->getOwner(),
            'group' => $info->getGroup(),
            'type' => $info->getType(),
            'mime_type' => mime_content_type($this->filePath),
            'extension' => $info->getExtension(),
        ];
    }

    /**
     * Get the line count of the file using SplFileObject.
     *
     * @return int
     */
    public function getLineCount(): int
    {
        $this->initFile();
        $this->file->seek(PHP_INT_MAX);
        return $this->file->key() + 1;
    }

    /**
     * Search for a term in the file using OS-native commands and return matching lines.
     *
     * @param string $searchTerm
     * @return array
     */
    public function searchContent(string $searchTerm): array
    {
        $command = escapeshellarg($this->filePath);
        $escapedTerm = escapeshellarg($searchTerm);

        $output = [];
        $returnVar = 0;

        if (PHP_OS_FAMILY === 'Windows') {
            exec("findstr /I $escapedTerm $command", $output, $returnVar);
        } else {
            exec("grep -i $escapedTerm $command", $output, $returnVar);
        }

        if ($returnVar !== 0 && empty($output)) {
            return [];
        }

        return $output;
    }

    /**
     * Rename or move the file to a new location.
     *
     * @param string $newPath
     * @return self
     */
    public function rename(string $newPath): self
    {
        if (!rename($this->filePath, $newPath)) {
            throw new RuntimeException("Unable to rename or move file to $newPath.");
        }
        $this->filePath = $newPath;
        $this->initFile(); // Reinitialize file object with new path
        return $this;
    }

    /**
     * Copy the file to a new location.
     *
     * @param string $destination
     * @return self
     */
    public function copy(string $destination): self
    {
        if (!copy($this->filePath, $destination)) {
            throw new RuntimeException("Unable to copy file to $destination.");
        }
        return $this;
    }

    /**
     * Set file permissions.
     *
     * @param int $permissions
     * @return self
     */
    public function setPermissions(int $permissions): self
    {
        if (!$this->exists()) {
            throw new RuntimeException("File does not exist at $this->filePath.");
        }
        if (!chmod($this->filePath, $permissions)) {
            throw new RuntimeException("Unable to set permissions for file: {$this->filePath}.");
        }
        return $this;
    }

    /**
     * Set file owner.
     *
     * @param int $ownerId
     * @return self
     */
    public function setOwner(int $ownerId): self
    {
        if (!chown($this->filePath, $ownerId)) {
            throw new RuntimeException("Unable to change owner for file: {$this->filePath}.");
        }
        return $this;
    }

    /**
     * Set file group.
     *
     * @param int $groupId
     * @return self
     */
    public function setGroup(int $groupId): self
    {
        if (!chgrp($this->filePath, $groupId)) {
            throw new RuntimeException("Unable to change group for file: {$this->filePath}.");
        }
        return $this;
    }

    /**
     * Open the file with a lock, optionally with a timeout.
     *
     * @param bool $exclusive
     * @param int $timeout
     * @return self
     * @throws RuntimeException
     */
    public function openWithLock(bool $exclusive = true, int $timeout = 0): self
    {
        $this->initFile('r+');
        $lockType = $exclusive ? LOCK_EX : LOCK_SH;
        $lockType |= LOCK_NB;

        $startTime = time();

        while (!$this->file->flock($lockType)) {
            if ($timeout > 0 && (time() - $startTime) >= $timeout) {
                throw new RuntimeException("Timeout reached while trying to acquire lock on file: {$this->filePath}.");
            }
            usleep(100000); // Wait 100 ms before retrying
        }

        return $this;
    }

    /**
     * Unlock the file.
     *
     * @return self
     */
    public function unlock(): self
    {
        $this->file->flock(LOCK_UN);
        return $this;
    }
}
