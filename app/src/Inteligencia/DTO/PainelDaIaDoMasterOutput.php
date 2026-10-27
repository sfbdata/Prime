<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

use App\Inteligencia\Enum\ModeloDeIa;

/**
 * A aba "Configurações da I.A." do Painel do Master (spec ia-anthropic-ligar §10). A chave da API nunca está aqui:
 * só se ela está gravada no servidor.
 */
final class PainelDaIaDoMasterOutput
{
    /** @param list<EscritorioNaIaDoMasterOutput> $escritorios */
    public function __construct(
        public readonly bool $chaveDaApiGravada,
        public readonly bool $travaDoServidorLigada,
        public readonly bool $ligadaParaTodos,
        public readonly ?ModeloDeIa $modelo,
        public readonly int $mesesDeRetencao,
        public readonly array $escritorios,
    ) {
    }
}
