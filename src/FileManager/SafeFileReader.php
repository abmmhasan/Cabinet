<?php

namespace Infocyph\Pathwise\FileManager;

use Exception;
use Generator;
use Infocyph\Pathwise\Exceptions\FileAccessException;
use NoRewindIterator;
use SplFileObject;
use SimpleXMLElement;
use Iterator;
use SeekableIterator;
use Countable;

/**
 * Memory-safe file reader with multiple read modes, locking, and interface support.
 *
 * @method SafeFileReader character() Character iterator
 * @method SafeFileReader line() Line iterator
 * @method SafeFileReader csv(string $separator = ",", string $enclosure = "\"", string $escape = "\\") CSV iterator
 * @method SafeFileReader binary(int $bytes = 1024) Binary iterator
 * @method SafeFileReader json() JSON line-by-line iterator
 * @method SafeFileReader regex(string $pattern) Regex iterator
 * @method SafeFileReader fixedWidth(array $widths) Fixed-width field iterator
 * @method SafeFileReader xml(string $element) XML iterator
 * @method SafeFileReader serialized() Serialized object iterator
 * @method SafeFileReader jsonArray() JSON array iterator
 */
final class SafeFileReader implements Iterator, SeekableIterator, Countable
{
    private SplFileObject $file;
    private int $count = 0;
    private int $position = 0;
    private int $fileSize;
    private ?Generator $currentIterator = null;
    private bool $isLocked = false;

    /**
     * Creates a new SafeFileReader instance.
     *
     * @param string $filename The name of the file to read from.
     * @param string $mode The file mode to use when opening the file.
     * @param bool $exclusiveLock Whether to apply an exclusive lock to the file.
     */
    public function __construct(
        private readonly string $filename,
        private readonly string $mode = 'r',
        private readonly bool $exclusiveLock = false,
    ) {}


    /**
     * Dynamically handles different read operations based on the specified type.
     *
     * This method uses a dynamic approach to invoke various read operations such as
     * 'character', 'line', 'csv', 'binary', 'json', 'regex', 'fixedWidth', 'xml',
     * 'serialized', and 'jsonArray'. It checks if the file is readable, initializes
     * the internal state of the SafeFileReader, acquires a lock, performs the
     * specified read operation, and finally releases the lock.
     *
     * @param string $type The type of read operation to perform.
     * @param array $params The parameters to be passed to the specific read operation.
     * @return NoRewindIterator The iterator over the elements of the file.
     * @throws Exception If the specified read type is unknown.
     */
    public function __call(string $type, array $params): NoRewindIterator
    {
        $this->initiate();
        $this->currentIterator = match ($type) {
            'character' => $this->characterIterator(),
            'line' => $this->lineIterator(),
            'csv' => $this->csvIterator(...$params),
            'binary' => $this->binaryIterator(...$params),
            'json' => $this->jsonIteratorWithHandling(),
            'regex' => $this->regexIterator(...$params),
            'fixedWidth' => $this->fixedWidthIterator(...$params),
            'xml' => $this->xmlIterator(...$params),
            'serialized' => $this->serializedIterator(),
            'jsonArray' => $this->jsonArrayIteratorWithHandling(),
            default => throw new Exception("Unknown iterator type '$type'"),
        };
        return new NoRewindIterator($this->currentIterator);
    }

    /**
     * Initializes the internal state of the SafeFileReader.
     *
     * This method checks if the file has already been initialized. If not,
     * it verifies the file's readability and creates a new SplFileObject for
     * it. It also calculates the file size, applies the necessary lock, and
     * resets the internal position and count.
     *
     * @throws FileAccessException If the file is not readable.
     */
    private function initiate(): void
    {
        if (!isset($this->file)) {
            if (!is_readable($this->filename)) {
                throw new FileAccessException("Cannot access file at path: {$this->filename}");
            }
            $this->file = new SplFileObject($this->filename, $this->mode);
            $this->fileSize = $this->file->getSize();
            $this->applyLock();
            $this->resetPosition();
        }
    }

    /**
     * Applies a lock to the file using SplFileObject::flock.
     *
     * This function takes into account the exclusive lock flag and applies
     * either a shared or exclusive lock to the file. If the lock cannot be
     * acquired, it throws a FileAccessException.
     * @throws FileAccessException
     */
    private function applyLock(): void
    {
        $lockType = $this->exclusiveLock ? LOCK_EX : LOCK_SH;
        if (!$this->file->flock($lockType)) {
            throw new FileAccessException("Unable to lock file at path: {$this->filename}");
        }
        $this->isLocked = true;
    }

    /**
     * Releases the lock on the file.
     *
     * If the object has a lock on the file, this method releases the lock.
     */
    public function releaseLock(): void
    {
        if ($this->isLocked) {
            $this->file->flock(LOCK_UN);
            $this->isLocked = false;
        }
    }

    /**
     * Automatically releases the lock when the object is destroyed.
     */
    public function __destruct()
    {
        $this->releaseLock();
    }

    /**
     * Returns the current element.
     *
     * If the iterator is valid, this method returns the current element in
     * the iterator. Otherwise, it returns null.
     *
     * @return mixed The current element in the iterator, or null if the iterator is invalid.
     */
    public function current(): mixed
    {
        return $this->currentIterator?->current();
    }

    /**
     * Returns the current key.
     *
     * The key is the current position in the file, which is a zero-based
     * index of the current element in the iterator.
     *
     * @return int The current key.
     */
    public function key(): int
    {
        return $this->position;
    }

    /**
     * Moves the internal pointer to the next element.
     *
     * This method moves the internal pointer to the next element in the
     * current iterator. It increments the position counter by one.
     */
    public function next(): void
    {
        $this->currentIterator?->next();
        $this->position++;
    }

    /**
     * Resets the internal state of the iterator and rewinds the current iterator.
     *
     * This method resets the internal state of the iterator by calling the
     * `reset` method, and then rewinds the current iterator to its initial
     * position. This is useful when you want to start iterating over the file
     * again from the beginning.
     */
    public function rewind(): void
    {
        $this->reset();
        $this->currentIterator?->rewind();
    }

    /**
     * Checks if the current iterator position is valid.
     *
     * This method determines if the current iterator is in a valid state,
     * meaning it can yield a value. It returns true if the current iterator
     * is valid and false if it is not.
     *
     * @return bool True if the current iterator position is valid, false otherwise.
     */
    public function valid(): bool
    {
        return $this->currentIterator?->valid() ?? false;
    }

    /**
     * Moves the internal pointer to the given offset.
     *
     * Seeks to the given offset in the underlying file. If the offset is
     * negative, an exception is thrown.
     *
     * @param int $offset The offset to seek to.
     *
     * @throws Exception If the offset is negative.
     */
    public function seek(int $offset): void
    {
        if ($offset < 0) {
            throw new Exception("Invalid position ($offset)");
        }
        $this->rewind();
        while ($this->position < $offset && $this->valid()) {
            $this->next();
        }
    }

    /**
     * Returns the total number of elements read from the file.
     *
     * @return int The total number of elements read from the file.
     */
    public function count(): int
    {
        return $this->count;
    }

    /**
     * Resets the file reader to the beginning of the file.
     *
     * This method rewinds the file to the start and resets the internal position
     * and count, effectively preparing the reader for a fresh iteration over
     * the file's content.
     */
    private function reset(): void
    {
        $this->file->rewind();
        $this->resetPosition();
    }

    /**
     * Resets the internal position and count of the file reader.
     *
     * This method sets both the position and count to zero, effectively resetting
     * the state of the file reader. It is typically used when reinitializing the
     * reader or rewinding the file to the beginning.
     */
    private function resetPosition(): void
    {
        $this->count = 0;
        $this->position = 0;
    }

    /**
     * Iterator that reads a file character by character and yields each character as a string.
     *
     * The iterator will read the file from the current position until the end of the file
     * and will yield each character as a string. The position and count are incremented
     * after each character is yielded.
     *
     * @return Generator<string> The iterator over the characters in the file.
     */
    private function characterIterator(): Generator
    {
        while (!$this->file->eof()) {
            yield $this->file->fgetc();
            $this->position++;
            $this->count++;
        }
    }

    /**
     * Iterates over a file line by line and yields each line as a string.
     *
     * This method reads the file line by line and yields each line as a string.
     * Each line is yielded without any trailing newline characters.
     *
     * @return Generator<array> The iterator over the lines of the file.
     */
    private function lineIterator(): Generator
    {
        while (!$this->file->eof()) {
            yield $this->file->fgets();
            $this->position++;
            $this->count++;
        }
    }

    /**
     * Iterates over a CSV file and yields each row as an array of fields.
     *
     * This method reads the file using the specified separator, enclosure,
     * and escape characters to correctly parse CSV data. Each row is yielded
     * as an array, with each element corresponding to a field in the CSV.
     *
     * @param string $separator The character used to separate fields. Defaults to ','.
     * @param string $enclosure The character used to enclose fields. Defaults to '"'.
     * @param string $escape The character used to escape special characters. Defaults to '\\'.
     *
     * @return Generator<array<string|null>> A generator yielding each CSV row as an array.
     */
    private function csvIterator(string $separator = ",", string $enclosure = "\"", string $escape = "\\"): Generator
    {
        while (!$this->file->eof()) {
            $csvLine = $this->file->fgetcsv($separator, $enclosure, $escape);
            if ($csvLine !== false) {
                yield $csvLine;
                $this->position++;
                $this->count++;
            }
        }
    }

    /**
     * Iterator that reads a file in binary mode and yields each block of data as a string.
     *
     * This method reads the file in binary mode, and yields each block of data of the
     * specified size. The default block size is 1024 bytes (1KB).
     *
     * @param int $bytes The size of each block to read. Defaults to 1024 (1KB).
     *
     * @return Generator A generator that yields each block of data as a string.
     */
    private function binaryIterator(int $bytes = 1024): Generator
    {
        while (!$this->file->eof()) {
            yield $this->file->fread($bytes);
            $this->position++;
            $this->count++;
        }
    }

    /**
     * Iterator that reads a file line by line and yields each line as a decoded JSON object.
     *
     * The iterator will skip any empty lines and will throw an exception if it encounters a line
     * that cannot be decoded as JSON.
     *
     * @return Generator<array|object|scalar|null> The iterator over the JSON objects.
     *
     * @throws Exception If a line cannot be decoded as JSON.
     */
    private function jsonIteratorWithHandling(): Generator
    {
        while (!$this->file->eof()) {
            $line = trim($this->file->fgets());
            if ($line) {
                $decoded = json_decode($line, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    yield $decoded;
                    $this->position++;
                    $this->count++;
                } else {
                    throw new Exception("JSON decoding error: " . json_last_error_msg());
                }
            }
        }
    }

    /**
     * Reads a file line by line and yields each matching line as an array of matches.
     *
     * The pattern is specified by the $pattern parameter, which is a string
     * containing a regular expression. The yield value is an array of strings,
     * where each string is a match of the regular expression against the line.
     *
     * @param string $pattern The regular expression pattern to match against.
     *
     * @return Generator<array<string>>
     */
    private function regexIterator(string $pattern): Generator
    {
        while (!$this->file->eof()) {
            $line = $this->file->fgets();
            if (preg_match($pattern, $line, $matches)) {
                yield $matches;
                $this->position++;
                $this->count++;
            }
        }
    }

    /**
     * Reads a file line by line and yields each fixed-width line as an array of fields.
     *
     * The widths of the fields are specified by the $widths parameter, which is an
     * array of positive integers. The yield value is an array of strings, where
     * each string is a field read from the line.
     *
     * @param array<int> $widths The widths of the fields to read.
     *
     * @return Generator<array<string>>
     */
    private function fixedWidthIterator(array $widths): Generator
    {
        while (!$this->file->eof()) {
            $line = $this->file->fgets();
            $fields = [];
            $offset = 0;
            foreach ($widths as $width) {
                $fields[] = substr($line, $offset, $width);
                $offset += $width;
            }
            yield $fields;
            $this->position++;
            $this->count++;
        }
    }

    /**
     * Reads a file line by line and yields each XML element of the specified type.
     *
     * @param string $element The name of the XML element to yield.
     *
     * @return Generator<SimpleXMLElement>
     *
     * @throws Exception If the XML cannot be parsed.
     */
    private function xmlIterator(string $element): Generator
    {
        $currentElement = '';
        while (!$this->file->eof()) {
            $line = $this->file->fgets();
            $currentElement .= $line;
            if (stripos($line, "</$element>") !== false) {
                try {
                    yield new SimpleXMLElement($currentElement);
                } catch (Exception $e) {
                    throw new Exception("XML parsing error: {$e->getMessage()}");
                }
                $currentElement = '';
                $this->position++;
                $this->count++;
            }
        }
    }

    /**
     * Iterator that reads a file line by line and unserializes the data.
     *
     * @return Generator<array|object|scalar|null>
     *
     * @throws Exception If the data cannot be unserialized.
     */
    private function serializedIterator(): Generator
    {
        while (!$this->file->eof()) {
            $serializedLine = $this->file->fgets();
            if ($serializedLine) {
                $result = unserialize($serializedLine);
                if ($result !== false || $serializedLine === 'b:0;') {
                    yield $result;
                    $this->position++;
                    $this->count++;
                } else {
                    throw new Exception("Failed to unserialize data.");
                }
            }
        }
    }

    /**
     * Generates an iterator over the elements of a JSON array read from the file.
     *
     * This method reads the entire JSON array from the file and decodes it,
     * and then iterates over the elements of the resulting array.
     *
     * @return Generator An iterator over the elements of the JSON array.
     *
     * @throws Exception If the JSON array decoding fails.
     */
    private function jsonArrayIteratorWithHandling(): Generator
    {
        $jsonArray = json_decode($this->file->fread($this->fileSize), true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($jsonArray)) {
            foreach ($jsonArray as $element) {
                yield $element;
                $this->position++;
                $this->count++;
            }
        } else {
            throw new Exception("JSON array decoding error: " . json_last_error_msg());
        }
    }
}
