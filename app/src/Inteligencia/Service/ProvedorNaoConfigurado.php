<?php

declare(strict_types=1);

namespace App\Inteligencia\Service;

use App\Inteligencia\DTO\PedidoDeLinguagem;
use App\Inteligencia\DTO\RespostaDeLinguagem;
use App\Inteligencia\Exception\ProvedorIndisponivelException;

/**
 * Provedor dormente para instalação SEM IA. Desde a D1 (14/10/2026) o alias aponta para o
 * {@see ProvedorAnthropic}, que se comporta assim enquanto não tem chave e modelo — e usa o {@see NOME}
 * daqui no `nome()`, para a coluna `provedor` não dizer que algo saiu. Nunca inventa resposta.
 */
final class ProvedorNaoConfigurado implements ProvedorDeLinguagem
{
    public const NOME = 'nao_configurado';

    public function nome(): string
    {
        return self::NOME;
    }

    public function estaConfigurado(): bool
    {
        return false;
    }

    public function completar(PedidoDeLinguagem $pedido): RespostaDeLinguagem
    {
        throw new ProvedorIndisponivelException('Provedor de IA não configurado nesta instalação.');
    }
}
