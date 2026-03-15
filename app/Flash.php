<?php
namespace MuseDockPanel;

class Flash
{
    public static function set(string $type, string $message): void
    {
        $_SESSION['flash'][$type] = $message;
    }

    public static function get(string $type): ?string
    {
        $msg = $_SESSION['flash'][$type] ?? null;
        unset($_SESSION['flash'][$type]);
        return $msg;
    }

    public static function has(string $type): bool
    {
        return isset($_SESSION['flash'][$type]);
    }

    public static function all(): array
    {
        $messages = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return $messages;
    }
}
