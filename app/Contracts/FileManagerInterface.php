<?php
namespace MuseDockPanel\Contracts;

/**
 * Interface for file manager operations.
 * All paths are relative to $basePath (the hosting account home_dir).
 * Implementations MUST validate that resolved paths stay within $basePath.
 */
interface FileManagerInterface
{
    /**
     * List directory contents.
     * @return array Array of ['name', 'type' (file|dir), 'size', 'permissions', 'modified']
     */
    public function listDirectory(string $basePath, string $relativePath, string $systemUser): array;

    /**
     * Read file contents.
     */
    public function readFile(string $basePath, string $relativePath, string $systemUser): string;

    /**
     * Write content to a file.
     */
    public function writeFile(string $basePath, string $relativePath, string $content, string $systemUser): bool;

    /**
     * Delete a file or empty directory.
     */
    public function delete(string $basePath, string $relativePath, string $systemUser): bool;

    /**
     * Handle file upload.
     */
    public function upload(string $basePath, string $relativePath, array $uploadedFile, string $systemUser): bool;

    /**
     * Create a directory.
     */
    public function createDirectory(string $basePath, string $relativePath, string $systemUser): bool;

    /**
     * Rename/move a file or directory within basePath.
     */
    public function rename(string $basePath, string $oldPath, string $newPath, string $systemUser): bool;
}
