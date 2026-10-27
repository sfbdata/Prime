<?php

declare(strict_types=1);

namespace App\Auth\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Impressão do acesso (spec `login-aviso-novo.md`): HMAC-SHA256, com o segredo da aplicação, de navegador (só a família,
 * sem versão: atualizar o navegador não gera aviso) + sistema + faixa do IP (IPv4 /24, IPv6 /48). O IP cru nunca entra no resultado nem sai daqui.
 * Mesmo navegador na mesma faixa dá a mesma impressão; trocar de rede ou de aparelho dá outra.
 */
final class ImpressaoDoAcesso
{
    private const DESCONHECIDO = 'desconhecido';

    public function __construct(
        #[Autowire('%kernel.secret%')]
        private readonly string $segredo,
    ) {
    }

    public function calcular(NavegadorDoAcesso $navegador, ?string $ip): string
    {
        $material = implode('|', [
            $navegador->familia ?? self::DESCONHECIDO,
            $navegador->sistema ?? self::DESCONHECIDO,
            self::faixaDoIp($ip),
        ]);

        return hash_hmac('sha256', $material, $this->segredo);
    }

    private static function faixaDoIp(?string $ip): string
    {
        $binario = $ip === null || $ip === '' ? false : @inet_pton($ip);
        if ($binario === false) {
            return self::DESCONHECIDO;
        }

        // IPv6 que carrega um IPv4 (::ffff:a.b.c.d) vale como o IPv4.
        if (strlen($binario) === 16 && str_starts_with($binario, str_repeat("\0", 10) . "\xff\xff")) {
            $binario = substr($binario, 12);
        }

        if (strlen($binario) === 4) {
            return bin2hex(substr($binario, 0, 3)) . '/24';
        }

        return bin2hex(substr($binario, 0, 6)) . '/48';
    }
}
