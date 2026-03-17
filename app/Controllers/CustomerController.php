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

        View::render('customers/show', [
            'layout' => 'main',
            'pageTitle' => $customer['name'],
            'customer' => $customer,
            'accounts' => $accounts,
        ]);
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
