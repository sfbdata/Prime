<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

use App\Inteligencia\Enum\Agente;
use App\Inteligencia\Enum\Comando;

/**
 * Pedido de análise da pasta. Com `comando` (drawer, F1/F2), o agente é o do comando e o que vier
 * em `agente` é ignorado; sem comando (rotas `/ia/agentes/{agente}`), vale o agente.
 */
final readonly class SolicitarAnaliseDaPastaInput
{
    public function __construct(
        public int $pastaId,
        public Agente $agente,
        public ?Comando $comando = null,
    ) {
    }

    public static function doComando(int $pastaId, Comando $comando): self
    {
        return new self($pastaId, $comando->agente(), $comando);
    }

    /** O agente que responde: o do comando, quando houver. */
    public function agenteEfetivo(): Agente
    {
        return $this->comando?->agente() ?? $this->agente;
    }
}
