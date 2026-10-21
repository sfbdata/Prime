<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

use App\Inteligencia\Enum\Agente;
use App\Inteligencia\Enum\Comando;

/**
 * Pedido de análise da pasta. Com `comando` (drawer, F1/F2), o agente é o do comando e o que vier
 * em `agente` é ignorado; sem comando (rotas `/ia/agentes/{agente}`), vale o agente.
 *
 * `documentos`: os ids marcados na aba Documentos (F4). Só o "Índice inteligente"
 * ({@see Comando::ehDaAbaDocumentos()}) os usa; nos outros comandos e nas rotas por agente o campo é
 * ignorado ({@see documentosDoPedido()}).
 */
final readonly class SolicitarAnaliseDaPastaInput
{
    /**
     * @param list<int> $documentos
     */
    public function __construct(
        public int $pastaId,
        public Agente $agente,
        public ?Comando $comando = null,
        public array $documentos = [],
    ) {
    }

    /**
     * @param list<mixed> $documentos valores crus do POST (`documentos[]`); o que não é id inteiro positivo vira 0 e é recusado na validação
     */
    public static function doComando(int $pastaId, Comando $comando, array $documentos = []): self
    {
        $ids = array_map(static fn (mixed $v): int => \is_string($v) && ctype_digit($v) ? (int) $v : (\is_int($v) ? $v : 0), $documentos);

        return new self($pastaId, $comando->agente(), $comando, $ids);
    }

    /** O agente que responde: o do comando, quando houver. */
    public function agenteEfetivo(): Agente
    {
        return $this->comando?->agente() ?? $this->agente;
    }

    /**
     * Os ids marcados, quando o comando é o da aba Documentos; nulo nos demais (o campo é ignorado).
     *
     * @return list<int>|null
     */
    public function documentosDoPedido(): ?array
    {
        return $this->comando?->ehDaAbaDocumentos() === true ? $this->documentos : null;
    }
}
