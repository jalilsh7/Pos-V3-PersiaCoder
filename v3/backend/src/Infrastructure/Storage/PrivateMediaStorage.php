<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Infrastructure\Storage;

use RuntimeException;

final readonly class PrivateMediaStorage
{
    private string $root;

    public function __construct(string $configuredPath)
    {
        if ($configuredPath === '' || strlen($configuredPath) > 512
            || !str_starts_with($configuredPath, DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Private media storage path must be absolute.');
        }
        if (is_link($configuredPath)) {
            throw new RuntimeException('Private media storage path cannot be a symbolic link.');
        }
        if (!is_dir($configuredPath) && !@mkdir($configuredPath, 0700, true) && !is_dir($configuredPath)) {
            throw new RuntimeException('Private media storage directory could not be created.');
        }
        if (!@chmod($configuredPath, 0700)) {
            throw new RuntimeException('Private media storage permissions could not be secured.');
        }
        $root = realpath($configuredPath);
        if (!is_string($root) || $root === DIRECTORY_SEPARATOR || !is_dir($root) || !is_writable($root)) {
            throw new RuntimeException('Private media storage directory is not accessible.');
        }

        $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? null;
        if (is_string($documentRoot) && $documentRoot !== '') {
            $publicRoot = realpath($documentRoot);
            if (is_string($publicRoot)
                && ($root === $publicRoot || str_starts_with($root, rtrim($publicRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR))) {
                throw new RuntimeException('Private media storage must be outside the public document root.');
            }
        }
        $this->root = rtrim($root, DIRECTORY_SEPARATOR);
    }

    public function storeUploadedFile(string $temporaryPath, string $publicId, string $extension): string
    {
        if (!is_uploaded_file($temporaryPath)) {
            throw new RuntimeException('The uploaded file is not a valid HTTP upload.');
        }
        if (!preg_match('/^[0-9a-f-]{36}$/', $publicId) || !in_array($extension, ['jpg', 'png', 'webp'], true)) {
            throw new RuntimeException('The media storage key parameters are invalid.');
        }

        $hex = str_replace('-', '', strtolower($publicId));
        $relative = substr($hex, 0, 2) . '/' . substr($hex, 2, 2) . '/' . strtolower($publicId) . '.' . $extension;
        $directory = $this->root . DIRECTORY_SEPARATOR . dirname($relative);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('The media storage directory could not be created.');
        }
        if (!@chmod($directory, 0700)) {
            throw new RuntimeException('The media directory permissions could not be secured.');
        }
        $destination = $this->root . DIRECTORY_SEPARATOR . $relative;
        if (file_exists($destination) || is_link($destination) || !move_uploaded_file($temporaryPath, $destination)) {
            throw new RuntimeException('The uploaded media file could not be stored.');
        }
        if (!@chmod($destination, 0600)) {
            @unlink($destination);
            throw new RuntimeException('The media file permissions could not be secured.');
        }
        return str_replace(DIRECTORY_SEPARATOR, '/', $relative);
    }

    public function read(string $storageKey): string
    {
        $path = $this->resolve($storageKey);
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new RuntimeException('The media file could not be read.');
        }
        return $contents;
    }

    public function delete(string $storageKey): void
    {
        $path = $this->resolve($storageKey);
        if (is_file($path) && !@unlink($path)) {
            throw new RuntimeException('The media file could not be deleted.');
        }
    }

    private function resolve(string $storageKey): string
    {
        if (!preg_match(
            '#^[a-f0-9]{2}/[a-f0-9]{2}/[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.(jpg|png|webp)$#',
            $storageKey,
        )) {
            throw new RuntimeException('The media storage key is invalid.');
        }
        $path = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $storageKey);
        $real = realpath($path);
        if (!is_string($real) || !is_file($real)
            || !str_starts_with($real, $this->root . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('The media file is not available.');
        }
        return $real;
    }
}
