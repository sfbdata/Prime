<?php

declare(strict_types=1);

namespace App\Inteligencia\Service;

use App\Inteligencia\DTO\PedidoDeLinguagem;
use App\Inteligencia\DTO\RespostaDeLinguagem;
use App\Inteligencia\Exception\ProvedorIndisponivelException;

/**
 * Provedor dormente — o estado de dev/prod enquanto o dono não escolhe o fornecedor (decisão D1).
 * Mesmo padrão do `ClienteOabIndisponivel`: sempre sinaliza indisponibilidade, nunca inventa
 * resposta. Quando houver provedor real: implementar {@see ProvedorDeLinguagem} com
 * `HttpClientInterface` e trocar o alias em `services.yaml`.
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
