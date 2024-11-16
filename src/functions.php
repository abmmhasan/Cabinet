<?php

if (!function_exists('getHumanReadableFileSize')) {
    /**
     * Format the given size (in bytes) into a human-readable string.
     *
     * This method takes an integer representing a file size in bytes and
     * returns a string representation of that size in a human-readable format,
     * such as '1.23 KB' or '4.56 GB'. It will use the appropriate unit of
     * measurement (B, KB, MB, GB, or TB) to represent the size.
     *
     * @param int $sizeInBytes The size of the file in bytes.
     * @return string The human-readable representation of the given size.
     */
    function getHumanReadableFileSize(int $sizeInBytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $sizeInBytes > 0 ? floor(log($sizeInBytes, 1024)) : 0;
        return number_format($sizeInBytes / (1024 ** $power), 2) . ' ' . $units[$power];
    }
}

if (!function_exists('isDirectoryEmpty')) {
    /**
     * Check if a directory is empty.
     *
     * @param string $directoryPath The directory path.
     * @return bool True if empty, false otherwise.
     */
    function isDirectoryEmpty(string $directoryPath): bool
    {
        if (!is_dir($directoryPath)) {
            throw new InvalidArgumentException("The provided path is not a directory.");
        }
        return count(scandir($directoryPath)) === 2; // '.' and '..'
    }
}

if (!function_exists('deleteDirectory')) {
    /**
     * Delete a directory and its contents recursively.
     *
     * @param string $directoryPath The directory path.
     * @return bool True if successful, false otherwise.
     */
    function deleteDirectory(string $directoryPath): bool
    {
        if (!is_dir($directoryPath)) {
            return false;
        }
        $items = array_diff(scandir($directoryPath), ['.', '..']);
        foreach ($items as $item) {
            $itemPath = $directoryPath . DIRECTORY_SEPARATOR . $item;
            is_dir($itemPath) ? deleteDirectory($itemPath) : unlink($itemPath);
        }
        return rmdir($directoryPath);
    }
}

if (!function_exists('getDirectorySize')) {
    /**
     * Get the size of a directory recursively.
     *
     * @param string $directoryPath The directory path.
     * @return int The total size of the directory in bytes.
     */
    function getDirectorySize(string $directoryPath): int
    {
        if (!is_dir($directoryPath)) {
            throw new InvalidArgumentException("The provided path is not a directory.");
        }
        $size = 0;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directoryPath, FilesystemIterator::SKIP_DOTS)) as $file) {
            $size += $file->getSize();
        }
        return $size;
    }
}

if (!function_exists('createDirectory')) {
    /**
     * Create a directory if it doesn't exist.
     *
     * @param string $directoryPath The directory path.
     * @param int $permissions Permissions for the directory (default 0755).
     * @return bool True if successful, false otherwise.
     */
    function createDirectory(string $directoryPath, int $permissions = 0755): bool
    {
        return is_dir($directoryPath) || mkdir($directoryPath, $permissions, true);
    }
}

if (!function_exists('listFiles')) {
    /**
     * List all files in a directory.
     *
     * @param string $directoryPath The directory path.
     * @return array List of files (excluding directories).
     */
    function listFiles(string $directoryPath): array
    {
        if (!is_dir($directoryPath)) {
            throw new InvalidArgumentException("The provided path is not a directory.");
        }
        $files = array_filter(scandir($directoryPath), fn ($item) => is_file($directoryPath . DIRECTORY_SEPARATOR . $item));
        return array_values($files);
    }
}

if (!function_exists('copyDirectory')) {
    /**
     * Copy a directory and its contents recursively.
     *
     * @param string $source The source directory.
     * @param string $destination The destination directory.
     * @return bool True if successful, false otherwise.
     */
    function copyDirectory(string $source, string $destination): bool
    {
        if (!is_dir($source)) {
            return false;
        }
        if (!is_dir($destination)) {
            mkdir($destination, 0755, true);
        }
        foreach (scandir($source) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $src = $source . DIRECTORY_SEPARATOR . $item;
            $dest = $destination . DIRECTORY_SEPARATOR . $item;
            is_dir($src) ? copyDirectory($src, $dest) : copy($src, $dest);
        }
        return true;
    }
}
