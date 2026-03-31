<?php
namespace MuseDockPanel\Contracts;

/**
 * Interface that the external portal module must implement.
 * The panel discovers and loads the portal via this contract.
 */
interface PortalProviderInterface
{
    /**
     * Register portal routes via Router::group().
     */
    public function register(): void;

    /**
     * Post-registration setup (cache warming, etc.).
     */
    public function boot(): void;

    /**
     * Whether the portal is properly licensed and active.
     */
    public function isActive(): bool;

    /**
     * Return portal module version string.
     */
    public function getVersion(): string;
}
