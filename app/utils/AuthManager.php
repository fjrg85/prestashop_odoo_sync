<?php
namespace App\Utils;

class AuthManager
{
    /**
     * Headers para PrestaShop
     */
    public static function getPrestaHeaders(): array
    {
        $scheme = strtolower((string) Config::get('PRESTA_AUTH_SCHEME', 'bearer'));
        $key    = Config::get('PRESTA_KEY');

        if ($scheme === 'bearer') {
            return ["Authorization: Bearer $key"];
        }

        if ($scheme === 'basic') {
            return ["Authorization: Basic " . base64_encode($key . ':')];
        }

        // ws_key no usa headers, se añade en la URL
        return [];
    }

    /**
     * Firmar URL con ws_key para PrestaShop
     */
    public static function signUrlForKey(string $url, string $system = 'presta'): string
    {
        $key = $system === 'presta'
            ? Config::get('PRESTA_KEY')
            : Config::get('ODOO_API_KEY');

        if (!$key) {
            return $url;
        }

        $sep = (str_contains($url, '?')) ? '&' : '?';
        return $url . $sep . 'ws_key=' . urlencode($key);
    }

    /**
     * Headers para Odoo
     */
    public static function getOdooHeaders(): array
    {
        $scheme = strtolower((string) Config::get('ODOO_AUTH_SCHEME', 'header'));

        // API Key con Bearer
        if ($scheme === 'bearer') {
            $key = Config::get('ODOO_API_KEY');
            return $key ? ["Authorization: Bearer $key"] : [];
        }

        // User/Pass en Basic Auth
        if ($scheme === 'basic') {
            $user = Config::get('ODOO_USER');
            $pass = Config::get('ODOO_PASS');
            return ["Authorization: Basic " . base64_encode("$user:$pass")];
        }

        // Header personalizado (ejemplo: X-Openerp-Session-Id)
        if ($scheme === 'header') {
            $user = Config::get('ODOO_USER');
            $pass = Config::get('ODOO_PASS');
            return [
                "X-Odoo-User: $user",
                "X-Odoo-Pass: $pass"
            ];
        }

        return [];
    }

    /**
     * Manejo de errores de autenticación
     */
    public static function handleAuthError(int $code, string $system): bool
    {
        Logger::warning("Auth error $code en $system", ['flow'=>$system]);
        // En API Key no hay refresh posible, devolvemos false
        return false;
    }
}