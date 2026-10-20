<?php

declare(strict_types=1);

namespace App\Comercial\Campanha;

/**
 * A chave da instalação `COMERCIAL_CAMPANHAS_LIGADAS` (spec §8.1.2, padrão desligada). Desligada, o "Enviar campanha"
 * é recusado e o expansor cancela toda campanha ativa na volta seguinte (parada de emergência). É variável de ambiente:
 * trocar exige reiniciar o worker (em produção, deploy). Não é o freio do dia a dia (esse é o comando
 * `app:comercial:encerrar-campanha`).
 */
final class ChaveDasCampanhas
{
    public const DESLIGADA = 'O envio de campanhas está desligado nesta instalação.';
    public const MOTIVO_DO_CANCELAMENTO = 'Envio de campanhas desligado na instalação';

    public function __construct(private readonly bool $campanhasLigadas)
    {
    }

    public function ligada(): bool
    {
        return $this->campanhasLigadas;
    }
}
