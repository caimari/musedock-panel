<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\Database;
use MuseDockPanel\Flash;
use MuseDockPanel\Router;
use MuseDockPanel\Settings;
use MuseDockPanel\View;
use MuseDockPanel\Services\LogService;

class CustomerController
{
    /** GET (JSON): qué clientes de otros nodos del cluster se recuperarían aquí. No cambia nada. */
    public function mergePeersPreview(): void
    {
        header('Content-Type: application/json');
        try {
            echo json_encode(\MuseDockPanel\Services\PortalService::mergeFromPeers(false), JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /** POST: recupera esos clientes (solo añade; nunca cambia ni borra). */
    public function mergePeers(): void
    {
        $r = \MuseDockPanel\Services\PortalService::mergeFromPeers(true);
        if (empty($r['ok'])) {
            Flash::set('error', (string)($r['error'] ?? 'No se pudo'));
        } else {
            Flash::set('success', ($r['customers'] || $r['links'])
                ? 'Recuperados: ' . (implode(', ', $r['customers']) ?: 'ningún cliente nuevo') . ($r['links'] ? '. Hostings enlazados: ' . implode(', ', $r['links']) : '') . '.'
                : 'No había nada que recuperar: este servidor ya tiene todos los clientes de los demás.');
        }
        Router::redirect('/customers');
    }

    public function index(): void
    {
        $customers = Database::fetchAll(
            "SELECT c.*,
                    COUNT(h.id) as account_count,
                    COALESCE(SUM(h.disk_used_mb), 0) as total_disk_used
             FROM customers c
             LEFT JOIN hosting_accounts h ON h.customer_id = c.id
             GROUP BY c.id
             ORDER BY c.name ASC"
        );

        View::render('customers/index', [
            'layout' => 'main',
            'pageTitle' => 'Customers',
            'customers' => $customers,
        ]);
    }

    public function create(): void
    {
        if (Settings::get('cluster_role', 'standalone') === 'slave') {
            Flash::set('error', 'Este servidor es Slave. La creacion de clientes solo esta permitida en el Master.');
            Router::redirect('/customers');
            return;
        }

        View::render('customers/create', [
            'layout' => 'main',
            'pageTitle' => 'New Customer',
        ]);
    }

    public function store(): void
    {
        if (Settings::get('cluster_role', 'standalone') === 'slave') {
            Flash::set('error', 'Este servidor es Slave. La creacion de clientes solo esta permitida en el Master.');
            Router::redirect('/customers');
            return;
        }

        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $company = trim($_POST['company'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        if (empty($name) || empty($email)) {
            Flash::set('error', 'Nombre y email son obligatorios.');
            Router::redirect('/customers/create');
            return;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Flash::set('error', 'Email no válido.');
            Router::redirect('/customers/create');
            return;
        }

        $existing = Database::fetchOne("SELECT id FROM customers WHERE email = :e", ['e' => $email]);
        if ($existing) {
            Flash::set('error', 'Ya existe un cliente con ese email.');
            Router::redirect('/customers/create');
            return;
        }

        $id = Database::insert('customers', [
            'name' => $name,
            'email' => $email,
            'company' => $company ?: null,
            'phone' => $phone ?: null,
            'notes' => $notes ?: null,
        ]);

        LogService::log('customer.create', $email, "Created customer: {$name}");
        Flash::set('success', "Cliente creado: {$name}");
        Router::redirect('/customers');
    }

    public function show(array $params): void
    {
        $customer = Database::fetchOne("SELECT * FROM customers WHERE id = :id", ['id' => $params['id']]);
        if (!$customer) {
            Flash::set('error', 'Cliente no encontrado.');
            Router::redirect('/customers');
            return;
        }

        $accounts = Database::fetchAll(
            "SELECT * FROM hosting_accounts WHERE customer_id = :cid ORDER BY domain",
            ['cid' => $params['id']]
        );
        // Para vincular hostings y dominios de correo ya creados (los que aún no tienen cliente).
        $freeAccounts = Database::fetchAll("SELECT id, domain FROM hosting_accounts WHERE customer_id IS NULL ORDER BY domain");
        $mailDomains = [];
        $freeMailDomains = [];
        try {
            $mailDomains = Database::fetchAll("SELECT id, domain FROM mail_domains WHERE customer_id = :cid ORDER BY domain", ['cid' => $params['id']]);
            $freeMailDomains = Database::fetchAll("SELECT id, domain FROM mail_domains WHERE customer_id IS NULL ORDER BY domain");
        } catch (\Throwable) {
        }

        View::render('customers/show', [
            'layout' => 'main',
            'pageTitle' => $customer['name'],
            'customer' => $customer,
            'accounts' => $accounts,
            'freeAccounts' => $freeAccounts,
            'mailDomains' => $mailDomains,
            'freeMailDomains' => $freeMailDomains,
        ]);
    }

    /**
     * POST: vincular o desvincular un hosting o un dominio de correo YA creado a este cliente.
     * Solo en el que manda (las copias lo reciben). Vincular solo toma lo que no tiene cliente;
     * desvincular solo suelta lo que es de este cliente. No toca ficheros ni el hosting.
     */
    public function link(array $params): void
    {
        $cid = (int)($params['id'] ?? 0);
        if (Settings::get('cluster_role', 'standalone') === 'slave') {
            Flash::set('error', 'Este servidor es una copia: los clientes se gestionan en el servidor que manda.');
            Router::redirect("/customers/{$cid}");
            return;
        }
        $customer = Database::fetchOne("SELECT id, name FROM customers WHERE id = :id", ['id' => $cid]);
        $table = ($_POST['kind'] ?? '') === 'mail' ? 'mail_domains' : 'hosting_accounts';
        $itemId = (int)($_POST['item_id'] ?? 0);
        $item = $customer && $itemId > 0 ? Database::fetchOne("SELECT id, domain, customer_id FROM {$table} WHERE id = :id", ['id' => $itemId]) : null;
        if (!$item) {
            Flash::set('error', 'No encontrado.');
            Router::redirect("/customers/{$cid}");
            return;
        }
        if (($_POST['op'] ?? '') === 'unlink') {
            if ((int)$item['customer_id'] === $cid) {
                Database::update($table, ['customer_id' => null], 'id = :wid', ['wid' => $itemId]);
                LogService::log('customer.unlink', $item['domain'], "Desvinculado de {$customer['name']}");
                Flash::set('success', "{$item['domain']} ya no está vinculado a {$customer['name']}.");
            }
        } elseif ($item['customer_id'] === null) {
            Database::update($table, ['customer_id' => $cid], 'id = :wid', ['wid' => $itemId]);
            LogService::log('customer.link', $item['domain'], "Vinculado a {$customer['name']}");
            Flash::set('success', "{$item['domain']} vinculado a {$customer['name']}. Lo verá en su portal.");
        } else {
            Flash::set('error', "{$item['domain']} ya pertenece a otro cliente: desvincúlalo antes desde ese cliente.");
        }
        Router::redirect("/customers/{$cid}");
    }

    /**
     * POST: bloquear o permitir el acceso de un cliente al portal, sin borrar su contraseña.
     * Bloquear antepone "!" al hash (como `passwd -l`): no casa con ninguna contraseña y el
     * portal cierra su sesión abierta en su siguiente comprobación (≤1 min). Permitir lo quita
     * y vuelve a entrar con la misma contraseña. Viaja a las copias con el resto del cliente.
     */
    public function portalToggle(array $params): void
    {
        $cid = (int)($params['id'] ?? 0);
        if (Settings::get('cluster_role', 'standalone') === 'slave') {
            Flash::set('error', 'Este servidor es una copia: los clientes se gestionan en el servidor que manda.');
            Router::redirect("/customers/{$cid}");
            return;
        }
        $c = Database::fetchOne("SELECT id, name, password_hash FROM customers WHERE id = :id", ['id' => $cid]);
        $hash = (string)($c['password_hash'] ?? '');
        if (!$c || $hash === '') {
            Flash::set('error', 'Este cliente no tiene acceso al portal (no hay nada que bloquear).');
            Router::redirect("/customers/{$cid}");
            return;
        }
        $block = ($_POST['op'] ?? '') === 'block';
        $new = $block ? ('!' . ltrim($hash, '!')) : ltrim($hash, '!');
        if ($new !== $hash) {
            $upd = ['password_hash' => $new, 'updated_at' => date('Y-m-d H:i:s')];
            if ($block && Database::fetchOne("SELECT 1 FROM information_schema.columns WHERE table_name = 'customers' AND column_name = 'password_token'")) {
                // Un enlace de invitación o de cambio de contraseña pendiente lo desbloquearía
                // (las columnas solo existen si el portal está instalado).
                $upd += ['password_token' => null, 'password_token_expires' => null];
            }
            Database::update('customers', $upd, 'id = :wid', ['wid' => $cid]);
            LogService::log($block ? 'portal.customer_block' : 'portal.customer_unblock', $c['name'], $block ? 'Acceso al portal bloqueado' : 'Acceso al portal permitido');
        }
        Flash::set('success', $block
            ? "Acceso al portal bloqueado para {$c['name']}: no puede entrar y su sesión abierta se cierra en un minuto. Su contraseña se conserva."
            : "Acceso al portal permitido otra vez para {$c['name']}: entra con su contraseña de siempre.");
        Router::redirect("/customers/{$cid}");
    }

    public function edit(array $params): void
    {
        if (Settings::get('cluster_role', 'standalone') === 'slave') {
            Flash::set('error', 'Este servidor es Slave. La edicion de clientes solo esta permitida en el Master.');
            Router::redirect('/customers');
            return;
        }

        $customer = Database::fetchOne("SELECT * FROM customers WHERE id = :id", ['id' => $params['id']]);
        if (!$customer) {
            Flash::set('error', 'Cliente no encontrado.');
            Router::redirect('/customers');
            return;
        }

        View::render('customers/edit', [
            'layout' => 'main',
            'pageTitle' => 'Edit: ' . $customer['name'],
            'customer' => $customer,
        ]);
    }

    public function update(array $params): void
    {
        if (Settings::get('cluster_role', 'standalone') === 'slave') {
            Flash::set('error', 'Este servidor es Slave. La edicion de clientes solo esta permitida en el Master.');
            Router::redirect('/customers');
            return;
        }

        $customer = Database::fetchOne("SELECT * FROM customers WHERE id = :id", ['id' => $params['id']]);
        if (!$customer) {
            Flash::set('error', 'Cliente no encontrado.');
            Router::redirect('/customers');
            return;
        }

        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $company = trim($_POST['company'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $status = $_POST['status'] ?? 'active';

        if (empty($name) || empty($email)) {
            Flash::set('error', 'Nombre y email son obligatorios.');
            Router::redirect('/customers/' . $params['id'] . '/edit');
            return;
        }

        // Check email uniqueness (excluding current)
        $dup = Database::fetchOne("SELECT id FROM customers WHERE email = :e AND id != :id", ['e' => $email, 'id' => $params['id']]);
        if ($dup) {
            Flash::set('error', 'Ese email ya lo usa otro cliente.');
            Router::redirect('/customers/' . $params['id'] . '/edit');
            return;
        }

        Database::update('customers', [
            'name' => $name,
            'email' => $email,
            'company' => $company ?: null,
            'phone' => $phone ?: null,
            'notes' => $notes ?: null,
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $params['id']]);

        LogService::log('customer.update', $email, "Updated customer: {$name}");
        Flash::set('success', 'Cliente actualizado.');
        Router::redirect('/customers/' . $params['id']);
    }

    public function delete(array $params): void
    {
        if (Settings::get('cluster_role', 'standalone') === 'slave') {
            Flash::set('error', 'Este servidor es Slave. Eliminar clientes solo esta permitido en el Master.');
            Router::redirect('/customers');
            return;
        }

        $customer = Database::fetchOne("SELECT * FROM customers WHERE id = :id", ['id' => $params['id']]);
        if (!$customer) {
            Flash::set('error', 'Cliente no encontrado.');
            Router::redirect('/customers');
            return;
        }

        // Check if customer has accounts
        $accountCount = Database::fetchOne("SELECT COUNT(*) as c FROM hosting_accounts WHERE customer_id = :id", ['id' => $params['id']]);
        if ($accountCount && $accountCount['c'] > 0) {
            Flash::set('error', 'No se puede eliminar un cliente con cuentas de hosting activas. Elimina las cuentas primero.');
            Router::redirect('/customers/' . $params['id']);
            return;
        }

        Database::delete('customers', 'id = :id', ['id' => $params['id']]);
        LogService::log('customer.delete', $customer['email'], "Deleted customer: {$customer['name']}");
        Flash::set('success', "Cliente {$customer['name']} eliminado.");
        Router::redirect('/customers');
    }
}
