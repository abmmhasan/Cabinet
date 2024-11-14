<?php

namespace Infocyph\Pathwise\DirectoryManager;

use FilesystemIterator;
use Infocyph\Pathwise\Utils\PermissionsHelper;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

class DirectoryOperations
{
    /**
     * Constructor to initialize the directory path.
     *
     * @param  string  $path  The path to the directory.
     *
     * @throws InvalidArgumentException If the path is not a valid directory.
     */
    public function __construct(protected string $path)
    {
    }

    /**
     * Creates the directory.
     *
     * @param  int  $permissions  The permissions to set for the newly created directory.
     * @param  bool  $recursive  Whether to create the directory recursively.
     * @return bool True if the directory was successfully created, false otherwise.
     */
    public function create(int $permissions = 0755, bool $recursive = true): bool
    {
        return mkdir($this->path, $permissions, $recursive);
    }

    /**
     * Deletes the directory.
     *
     * If $recursive is true, the contents of the directory will be deleted
     * before the directory itself is deleted.
     *
     * @param  bool  $recursive  Whether to delete the contents of the directory first.
     * @return bool True if the directory was successfully deleted, false otherwise.
     */
    public function delete(bool $recursive = false): bool
    {
        if ($recursive) {
            $this->deleteDirectoryContents($this->path);
        }

        // Attempt to remove the main directory and return true if successful
        return @rmdir($this->path) || ! is_dir($this->path);
    }

    /**
     * Copies the contents of the directory to the specified destination.
     *
     * This method recursively copies all files and subdirectories from the
     * current directory to the given destination directory. If the destination
     * directory does not exist, it will be created with default permissions.
     *
     * @param  string  $destination  The path to the destination directory.
     * @return bool True if the copy operation was successful, false otherwise.
     */
    public function copy(string $destination): bool
    {
        if (! is_dir($destination)) {
            mkdir($destination, 0755, true);
        }

        $directoryIterator = new RecursiveDirectoryIterator($this->path, FilesystemIterator::SKIP_DOTS);
        $iterator = new RecursiveIteratorIterator($directoryIterator, RecursiveIteratorIterator::SELF_FIRST);

        foreach ($iterator as $item) {
            $subPathName = $iterator->getInnerIterator()->getSubPathName();
            $destPath = $destination.DIRECTORY_SEPARATOR.$subPathName;

            if ($item->isDir()) {
                mkdir($destPath);
            } else {
                copy($item, $destPath);
            }
        }

        return true;
    }

    /**
     * Moves the directory to the given destination.
     *
     * @param  string  $destination  The path to move the directory to.
     * @return bool True if the directory was successfully moved, false otherwise.
     */
    public function move(string $destination): bool
    {
        return rename($this->path, $destination);
    }

    /**
     * Returns an array of items in the directory.
     *
     * @param  bool  $detailed  Whether to return detailed information about each item.
     * @param  callable|null  $filter  A filter to apply to each item. If the filter
     *                                 returns false, the item is not included in the returned array.
     * @return array An array of items in the directory. If $detailed is true, each
     *               item is an associative array containing 'path', 'type', 'size', 'permissions',
     *               and 'last_modified' keys. If $detailed is false, each item is a string
     *               containing the path to the item.
     */
    public function listContents(bool $detailed = false, ?callable $filter = null): array
    {
        $contents = [];
        $iterator = $this->getIterator();

        foreach ($iterator as $item) {
            if ($filter && ! $filter($item)) {
                continue;
            }

            if ($detailed) {
                $contents[] = [
                    'path' => $item->getPathname(),
                    'type' => $item->isDir() ? 'directory' : 'file',
                    'size' => $item->getSize(),
                    'permissions' => PermissionsHelper::getHumanReadablePermissions($item->getPerms()),
                    'last_modified' => $item->getMTime(),
                ];
            } else {
                $contents[] = $item->getPathname();
            }
        }

        return $contents;
    }

    /**
     * Gets the current permissions of the directory.
     *
     * The permissions are returned as an octal number, e.g. 0755.
     *
     * @return int The current permissions of the directory.
     */
    public function getPermissions(): int
    {
        return fileperms($this->path) & 0777;
    }

    /**
     * Set the permissions of the directory to the given value.
     *
     * @param  int  $permissions  The new permissions for the directory. This should
     *                            be an octal number, e.g. 0755.
     * @return bool True if the permissions were successfully set, false otherwise.
     */
    public function setPermissions(int $permissions): bool
    {
        return chmod($this->path, $permissions);
    }

    /**
     * Lists the current permissions of the directory in a string like 'rwxr-x--'.
     *
     * The method uses an array of flags to map the current permissions to a
     * string of 'r', 'w', or 'x' representing read, write, and execute
     * permissions for the owner, group, and others.
     *
     * @return string The current permissions of the directory.
     */
    public function listPermissions(): string
    {
        return PermissionsHelper::getHumanReadablePermissions($this->getPermissions());
    }

    /**
     * Calculates the total size of all files in the directory.
     *
     * This method iterates over all files in the directory and sums their sizes.
     * Directories are skipped. An optional filter callable can be provided to
     * include only specific files in the size calculation.
     *
     * @param  callable|null  $filter  An optional callable to filter files. It receives an SplFileInfo
     *                                 object and should return true to include the file, false otherwise.
     * @return int The total size of all files that pass the filter in bytes.
     */
    public function size(?callable $filter = null): int
    {
        $size = 0;
        $iterator = $this->getIterator();

        foreach ($iterator as $file) {
            if (! $file->isDir() && (! $filter || $filter($file))) {
                $size += $file->getSize();
            }
        }

        return $size;
    }

    /**
     * Gets an iterator that traverses the directory tree.
     *
     * This method returns a RecursiveIteratorIterator that traverses the
     * directory tree starting from the current directory path. The iterator
     * will yield SplFileInfo objects for all files and directories found.
     *
     * @return RecursiveIteratorIterator An iterator that traverses the directory tree.
     */
    public function getIterator(): RecursiveIteratorIterator
    {
        return new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->path, FilesystemIterator::SKIP_DOTS),
        );
    }

    /**
     * Get the maximum depth of the directory.
     *
     * @return int The maximum depth of the directory.
     */
    public function getDepth(): int
    {
        $maxDepth = 0;
        $iterator = $this->getIterator();

        foreach ($iterator as $file) {
            $depth = $iterator->getDepth();
            $maxDepth = max($maxDepth, $depth);
        }

        return $maxDepth;
    }

    /**
     * Flatten the directory structure and return an array of file paths.
     *
     * This method returns an array of file paths without any directory structure.
     * If a filter is provided, it will be used to filter the files.
     *
     * @param  callable|null  $filter  A filter to apply to the files. If the filter
     *                                 returns true, the file will be included in the flattened array.
     * @return array An array of file paths.
     */
    public function flatten(?callable $filter = null): array
    {
        $flattened = [];
        $iterator = $this->getIterator();

        foreach ($iterator as $file) {
            if (! $file->isDir() && (! $filter || $filter($file))) {
                $flattened[] = $file->getPathname();
            }
        }

        return $flattened;
    }

    /**
     * Creates a temporary directory with a unique name in the system's temporary directory.
     *
     * @return string The path to the temporary directory.
     */
    public function createTempDir(): string
    {
        $tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('temp_', true);
        mkdir($tempDir);

        return $tempDir;
    }

    /**
     * Returns a sorted array of the directory's contents.
     *
     * @param  string  $sortOrder  The sort order of the contents. Defaults to 'asc'.
     * @return array An array of the directory's contents, sorted by the given order.
     *
     * @see scandir()
     */
    public function listSortedContents(string $sortOrder = 'asc'): array
    {
        $contents = scandir($this->path, $sortOrder === 'asc' ? SCANDIR_SORT_ASCENDING : SCANDIR_SORT_DESCENDING);

        return array_values(array_diff($contents, ['.', '..']));
    }

    /**
     * Zip the contents of the directory to a file.
     *
     * @param  string  $destination  The path to the zip file.
     * @return bool True if the zip was created successfully, false otherwise.
     */
    public function zip(string $destination): bool
    {
        $zip = new ZipArchive();
        if ($zip->open($destination, ZipArchive::CREATE) === true) {
            $directoryIterator = new RecursiveDirectoryIterator($this->path, FilesystemIterator::SKIP_DOTS);
            $iterator = new RecursiveIteratorIterator($directoryIterator, RecursiveIteratorIterator::SELF_FIRST);

            foreach ($iterator as $file) {
                $subPathName = $iterator->getInnerIterator()->getSubPathName();
                $zip->addFile($file->getPathname(), $subPathName);
            }

            $zip->close();

            return true;
        }

        return false;
    }

    /**
     * Extracts the contents of a zip file to the directory represented by this object.
     *
     * @param  string  $source  The path to the zip file.
     * @return bool True if the extraction was successful, false otherwise.
     */
    public function unzip(string $source): bool
    {
        $zip = new ZipArchive();
        if ($zip->open($source) === true) {
            $zip->extractTo($this->path);
            $zip->close();

            return true;
        }

        return false;
    }

    /**
     * Deletes all files and directories in the given directory.
     *
     * @param  string  $directory  The directory to delete contents of.
     * @return bool True if the directory contents were successfully deleted, false otherwise.
     */
    protected function deleteDirectoryContents(string $directory): bool
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());

                continue;
            }
            unlink($file->getRealPath());
        }

        return true;
    }

    /**
     * Finds files in the current directory based on the given criteria.
     *
     * Criteria that can be specified are:
     * - name: string to search for in the filename
     * - extension: string to match the file extension to
     * - permissions: integer to match the file permissions to
     * - minSize: minimum size of the file
     * - maxSize: maximum size of the file
     *
     * @param  array  $criteria  The criteria to match against
     * @return array A list of file paths that match the criteria
     */
    public function find(array $criteria = []): array
    {
        $results = [];
        $iterator = $this->getIterator();
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

        foreach ($iterator as $file) {
            $conditions = [
                empty($criteria['name']) || str_contains((string) $file->getFilename(), (string) $criteria['name']),
                empty($criteria['extension']) || $file->getExtension() === $criteria['extension'],
                $isWindows || empty($criteria['permissions']) || ($file->getPerms() & 0777) === $criteria['permissions'],
                empty($criteria['minSize']) || $file->getSize() >= $criteria['minSize'],
                empty($criteria['maxSize']) || $file->getSize() <= $criteria['maxSize'],
            ];

            if (in_array(false, $conditions, true)) {
                continue;
            }

            $results[] = $file->getPathname();
        }

        return $results;
    }
}
