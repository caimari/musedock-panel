<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Settings;

/** Optional mail-domain provisioning for hosting creation; no DNS writes here. */
final class HostingMailSetupService
{
    public static function options(): array
    {
        $full = MailService::getCurrentMailMode() === 'full';
        $local = Settings::get('mail_local_configured', '') === '1';
        $nodes = array_values(array_filter(MailService::getMailNodes(), static fn($n) => ($n['status'] ?? '') === 'online'));
        $default = (int)Settings::get('mail_default_node_id', '0');
        $defaultAvailable = $default ? (bool)array_filter($nodes, static fn($n) => (int)$n['id'] === $default) : $local;
        return ['available' => $full && ($defaultAvailable || $nodes), 'nodes' => $nodes,
            'default_available' => $defaultAvailable, 'default_node_id' => $default,
            'reason' => !$full ? 'El correo con buzones requiere el modo Correo Completo.' : 'Configura un servidor de correo operativo en Mail > Infra.'];
    }

    public static function validate(array $input): ?array
    {
        if (($input['create_mail'] ?? '') !== '1') return null;
        if (Settings::get('cluster_role', 'standalone') === 'slave') throw new \RuntimeException('El correo se crea desde el master.');
        $options = self::options();
        if (!$options['available']) throw new \RuntimeException($options['reason']);
        $value = (string)($input['hosting_mail_node_id'] ?? '');
        if ($value !== '' && !ctype_digit($value)) throw new \RuntimeException('Nodo de correo inválido.');
        $id = (int)$value;
        if (!$id && !$options['default_available']) throw new \RuntimeException('Selecciona un nodo de correo operativo.');
        if ($id && !array_filter($options['nodes'], static fn($n) => (int)$n['id'] === $id)) throw new \RuntimeException('El nodo de correo elegido no está disponible.');
        return ['node_id' => $id ?: $options['default_node_id'] ?: null];
    }

    public static function create(string $domain, ?int $customerId, ?array $options): ?int
    {
        if ($options === null) return null;
        $existing = MailService::getDomainByName($domain);
        if ($existing) return (int)$existing['id'];
        $input = ['create_mail' => '1', 'hosting_mail_node_id' => (string)($options['node_id'] ?? '')];
        $validated = self::validate($input);
        $id = MailService::createDomain($domain, $customerId, $validated['node_id']);
        LogService::log('hosting.mail.create', $domain, 'Dominio de correo creado junto al hosting; DNS pendiente de confirmación.');
        return $id;
    }
}
