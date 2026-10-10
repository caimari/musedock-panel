<?php
/** Isolated fixtures: no real DNS, API, database, hosting or Caddy mutations. */
namespace MuseDockPanel {
    class Settings {
        public static array $values = ['server_public_ip' => '81.82.83.84', 'dns_default_target' => 'origin.example.net', 'mail_local_hostname' => 'mail.example.com', 'mail_local_configured' => '1'];
        public static function get($key, $default = '') { return self::$values[$key] ?? $default; }
    }
    class Database {
        public static function fetchOne($sql, $params) { return $params['d'] === 'example.com' ? ['id' => 7, 'domain' => 'example.com'] : null; }
    }
}
namespace MuseDockPanel\Services {
    function dns_get_record($name, $type) {
        if ($type === DNS_NS) {
            if ($name === 'example.com') return [['target' => CloudflareService::$delegation]];
            if ($name === 'example.net') return [['target' => 'ns.external.test']];
            return [];
        }
        if ($type === DNS_A && in_array($name, ['origin.example.net', 'mail.example.com'], true)) return [['type' => 'A', 'host' => $name, 'ip' => '81.82.83.84']];
        return [];
    }
    class SystemService {
        public static function hostsWithWww($domain) { return [$domain, 'www.' . $domain]; }
        public static function ensureTlsCatchAllPolicy($api) {}
    }
    class LogService { public static function log(...$args) {} }
    class MailService {
        public static bool $enabled = false;
        public static string $mode = 'full';
        public static array $nodes = [];
        public static array $created = [];
        public static function getCurrentMailMode() { return self::$mode; }
        public static function getMailNodes() { return self::$nodes; }
        public static function createDomain($domain, $customer, $node) { self::$created[] = [$domain, $customer, $node]; return 9; }
        public static function getDomainByName($name) {
            return self::$enabled && $name === 'example.com' ? ['id' => 8, 'domain' => $name, 'mail_node_id' => null,
                'dkim_public_key' => 'TESTPUBLICKEY', 'dkim_selector' => 'default', 'spf_record' => '', 'dmarc_policy' => 'quarantine'] : null;
        }
        public static function ensureMailCertRoute($host) { return ['ok' => true]; }
    }
    class CloudflareService {
        public static array $records = [];
        public static array $calls = [];
        public static string $delegation = 'ns.cloudflare.com';
        public static bool $failCreate = false;
        public static bool $listFails = false;
        public static function findZoneForDomain($name) {
            return $name === 'example.com' || str_ends_with($name, '.example.com') ? ['token' => 'SECRET_FIXTURE', 'zone_id' => 'zone1', 'zone' => 'example.com', 'account' => 'Fixture account'] : null;
        }
        public static function refreshZones() { return true; }
        public static function getZone(...$args) { return ['ok' => true, 'result' => ['status' => 'active', 'name_servers' => ['ns.cloudflare.com']]]; }
        public static function listRecordsAll(...$args) { return ['ok' => !self::$listFails, 'result' => self::$records]; }
        public static function createRecord($token, $zone, $record) {
            self::$calls[] = 'create';
            if (self::$failCreate && $record['type'] === 'CNAME') return ['ok' => false, 'error' => 'Fixture create failure'];
            $record['id'] = 'new' . count(self::$calls); self::$records[] = $record;
            return ['ok' => true, 'result' => $record];
        }
        public static function updateRecord($token, $zone, $id, $record) {
            self::$calls[] = 'update';
            foreach (self::$records as &$r) if ($r['id'] === $id) $r = $record + ['id' => $id];
            return ['ok' => true];
        }
        public static function deleteRecord($token, $zone, $id) {
            self::$calls[] = 'delete'; self::$records = array_values(array_filter(self::$records, fn($r) => $r['id'] !== $id)); return ['ok' => true];
        }
    }
}
namespace {
    use MuseDockPanel\Services\DomainDnsSyncService as Sync;
    use MuseDockPanel\Services\CloudflareService as CF;
    use MuseDockPanel\Services\MailService;
    use MuseDockPanel\Settings;
    $root = dirname(__DIR__);
    $temp = sys_get_temp_dir() . '/musedock-dns-test-' . bin2hex(random_bytes(6));
    mkdir($temp . '/config', 0700, true);
    file_put_contents($temp . '/config/panel.php', '<?php return ["caddy" => ["api_url" => "fixture"]];');
    define('PANEL_ROOT', $temp);
    spl_autoload_register(function ($class) use ($root) {
        $file = $root . '/app/' . str_replace('\\', '/', substr($class, strlen('MuseDockPanel\\'))) . '.php';
        if (is_file($file)) require $file;
    });
    ob_start();
    $count = 0;
    function check($condition, $label) {
        global $count;
        if (!$condition) throw new RuntimeException('FAIL: ' . $label);
        $count++; echo "OK: {$label}\n";
    }
    function rejects($fn, $needle) {
        try { $fn(); return false; } catch (Throwable $e) { return str_contains($e->getMessage(), $needle); }
    }
    try {
        $want = ['type' => 'TXT', 'name' => 'example.com', 'content' => 'v=spf1 mx ~all', 'ttl' => 300, 'proxied' => false];
        $txt = [['id' => 'verification', 'type' => 'TXT', 'name' => 'example.com', 'content' => 'google-site-verification=keep'],
            ['id' => 'spf', 'type' => 'TXT', 'name' => 'example.com', 'content' => 'v=spf1 include:external.test ~all'],
            ['id' => 'web', 'type' => 'CNAME', 'name' => 'example.com', 'content' => 'old.example.net']];
        $diff = Sync::diff($want, $txt);
        check(count($diff['current']) === 1 && $diff['current'][0]['id'] === 'spf', 'SPF preserves unrelated TXT and website CNAME');
        check(Sync::diff($want, [['id' => 'spf', 'type' => 'TXT', 'name' => 'example.com', 'content' => $want['content']]])['status'] === 'ok', 'idempotent TXT');
        check(Sync::diff(['type' => 'MX', 'name' => 'example.com', 'content' => 'mail.example.com', 'priority' => 10],
            [['id' => 'mx', 'type' => 'MX', 'name' => 'example.com', 'content' => 'MAIL.EXAMPLE.COM.', 'priority' => 10]])['status'] === 'ok', 'MX hostname normalization');
        check(Sync::providerFromNameservers(['ns1.digitalocean.com']) === 'digitalocean', 'future provider detection');
        CF::$records = $txt;
        $plan = Sync::plan('example.com', 'hosting');
        check(count($plan['entries']) === 2 && $plan['entries'][0]['expected']['type'] === 'CNAME', 'hosting proposes configured CNAME');
        check(!str_contains(json_encode($plan), 'SECRET_FIXTURE'), 'credentials never enter client plan');
        CF::$records[] = ['id' => 'concurrent', 'type' => 'A', 'name' => 'www.example.com', 'content' => '1.2.3.4'];
        check(rejects(fn() => Sync::apply($plan), 'han cambiado') && !CF::$calls, 'concurrent DNS change refuses all writes');
        CF::$records = []; $plan = Sync::plan('example.com', 'hosting');
        $result = Sync::apply($plan);
        check($result['ok'] && count(CF::$records) === 2, 'confirmed hosting plan applies both records');
        check(array_filter(CF::$records, fn($r) => $r['proxied']) === [], 'new address records are DNS-only');
        CF::$calls = []; $again = Sync::plan('example.com', 'hosting'); Sync::apply($again);
        check(!CF::$calls, 'second apply is idempotent');
        CF::$delegation = 'ns.external.test'; CF::$records = [];
        $external = Sync::plan('example.com', 'hosting');
        check(!$external['entries'][0]['managed'], 'stale Cloudflare zone with external delegation is manual');
        Sync::apply($external); check(!CF::$calls, 'unmanaged nameservers are never written');
        CF::$delegation = 'ns.cloudflare.com'; CF::$listFails = true;
        check(rejects(fn() => Sync::plan('example.com', 'hosting'), 'consultar'), 'API read failure never becomes an empty-zone plan');
        CF::$listFails = false;
        CF::$records = [['id' => 'oldA', 'type' => 'A', 'name' => 'example.com', 'content' => '11.22.33.44', 'ttl' => 120, 'proxied' => false]];
        CF::$calls = []; CF::$failCreate = true;
        $failure = Sync::apply(Sync::plan('example.com', 'hosting'));
        check(!$failure['ok'] && count(CF::$records) === 1 && CF::$records[0]['content'] === '11.22.33.44', 'failed A-to-CNAME replacement restores removed A');
        check(count($failure['done']) === 0, 'failure stops before later records');
        CF::$failCreate = false; CF::$records = $txt; CF::$calls = []; MailService::$enabled = true;
        $mail = Sync::plan('example.com', 'mail');
        check(count($mail['entries']) === 5 && !array_filter($mail['entries'], fn($e) => in_array($e['expected']['type'], ['A', 'CNAME'], true) && $e['expected']['name'] === 'example.com'), 'mail-only plan never changes website root');
        check(count(array_filter($mail['entries'], fn($e) => $e['expected']['name'] === 'default._domainkey.example.com')) === 1, 'mail plan uses generated DKIM');
        Sync::apply($mail);
        check(count(array_filter(CF::$records, fn($r) => $r['id'] === 'web' || $r['id'] === 'verification')) === 2, 'mail publication preserves website and verification TXT');
        check(count(Sync::plan('example.com', 'hosting')['entries']) === 7, 'hosting with existing mail includes mail DNS');
        Settings::$values['cluster_role'] = 'slave'; CF::$calls = [];
        check(rejects(fn() => Sync::apply($mail), 'master') && !CF::$calls, 'slave cannot write DNS');
        Settings::$values['cluster_role'] = 'standalone'; Settings::$values['dns_default_target'] = 'wrong.example.net';
        check(rejects(fn() => Sync::plan('example.com', 'hosting'), 'no resuelve'), 'wrong CNAME destination is blocked');
        Settings::$values['dns_default_target'] = 'origin.example.net'; MailService::$enabled = false; CF::$records = []; CF::$calls = [];
        $controller = new \MuseDockPanel\Controllers\DomainDnsSyncController();
        $jsonCall = static function ($method, $post) use ($controller) {
            $_POST = $post; ob_start(); $controller->$method(); return json_decode(ob_get_clean(), true);
        };
        $review = $jsonCall('plan', ['domain' => 'example.com', 'scope' => 'hosting']);
        check($review['ok'] && isset($_SESSION['domain_dns_review']), 'controller stores session-bound review');
        $denied = $jsonCall('apply', ['token' => $review['token']]);
        check(!$denied['ok'] && !CF::$calls, 'missing modal acknowledgement cannot write');
        $_SESSION['domain_dns_review']['expires'] = time() - 1;
        $denied = $jsonCall('apply', ['token' => $review['token'], 'confirmed' => '1']);
        check(!$denied['ok'] && !CF::$calls, 'expired review cannot write');
        $old = $jsonCall('plan', ['domain' => 'example.com', 'scope' => 'hosting']);
        $new = $jsonCall('plan', ['domain' => 'example.com', 'scope' => 'hosting']);
        $denied = $jsonCall('apply', ['token' => $old['token'], 'confirmed' => '1']);
        check(!$denied['ok'] && !CF::$calls, 'new review invalidates old confirmation');
        $published = $jsonCall('apply', ['token' => $new['token'], 'confirmed' => '1']);
        check($published['ok'] && !isset($_SESSION['domain_dns_review']), 'confirmed review publishes and consumes token');
        CF::$calls = [];
        $denied = $jsonCall('apply', ['token' => $new['token'], 'confirmed' => '1']);
        check(!$denied['ok'] && !CF::$calls, 'confirmation token cannot be replayed');
        $setup = \MuseDockPanel\Services\HostingMailSetupService::class;
        check($setup::validate([]) === null && $setup::create('example.com', 2, null) === null, 'unchecked optional mail creates nothing');
        MailService::$mode = 'relay';
        check(rejects(fn() => $setup::validate(['create_mail' => '1']), 'Completo'), 'mail opt-in requires full mode');
        MailService::$mode = 'full'; Settings::$values['mail_local_configured'] = '0';
        check(!$setup::options()['available'], 'mail opt-in disabled without backend');
        MailService::$nodes = [['id' => 12, 'name' => 'Mail fixture', 'status' => 'online'], ['id' => 13, 'status' => 'offline']];
        check($setup::validate(['create_mail' => '1', 'hosting_mail_node_id' => '12'])['node_id'] === 12, 'online mail node selection');
        check(rejects(fn() => $setup::validate(['create_mail' => '1', 'hosting_mail_node_id' => '13']), 'disponible'), 'offline node rejected before hosting creation');
        check(rejects(fn() => $setup::validate(['create_mail' => '1', 'hosting_mail_node_id' => 'bad']), 'inválido'), 'invalid node input rejected');
        check($setup::create('example.com', 2, ['node_id' => 12]) === 9 && MailService::$created[0] === ['example.com', 2, 12], 'opt-in preserves customer and selected node');
        MailService::$enabled = true;
        check($setup::create('example.com', 2, ['node_id' => 12]) === 8 && count(MailService::$created) === 1, 'existing mail domain is reused');
        $parser = \MuseDockPanel\Services\DnsPropagationService::class;
        $parsed = $parser::parseAnswers([['type' => 16, 'name' => 'default._domainkey.example.com.', 'data' => '"v=DKIM1; p=" "abc"']], 'TXT');
        check($parsed[0]['content'] === 'v=DKIM1; p=abc', 'public DNS joins split TXT strings');
        $parsed = $parser::parseAnswers([['type' => 15, 'name' => 'example.com.', 'data' => '10 mail.example.com.']], 'MX');
        check($parsed[0]['priority'] === 10 && $parsed[0]['name'] === 'example.com', 'public DNS MX priority and owner normalization');
        echo "{$count} checks passed. No external services touched.\n";
    } finally {
        ob_end_flush();
        unlink($temp . '/config/panel.php'); rmdir($temp . '/config'); rmdir($temp);
    }
}
