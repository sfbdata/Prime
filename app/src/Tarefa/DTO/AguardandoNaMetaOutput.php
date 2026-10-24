<?php

declare(strict_types=1);

namespace App\Tarefa\DTO;

/**
 * Faixa "AGUARDANDO DESPACHO DE" / "AGUARDANDO REVISÃO DE" da meta (desenho 04B de 22/10, L50 e L131-132).
 *
 * Em revisão, aguarda o criador, contando desde o envio para revisão; senão, aguarda os responsáveis, contando desde
 * a criação ("com a demanda"). `atrasada` (prazo vencido fora da revisão) pinta a faixa de vermelho. O botão
 * "Lembrar" é da Fase 2b.
 */
final class AguardandoNaMetaOutput
{
    /**
     * @param list<array{iniciais: string, nome: string}> $pessoas
     */
    public function __construct(
        public readonly bool $emRevisao,
        public readonly array $pessoas,
        public readonly int $dias,
        public readonly bool $atrasada,
    ) {
    }

    /** Tom da faixa: `atrasada` (vermelho), `revisao` (âmbar) ou `despacho` (azul). */
    public function tom(): string
    {
        return match (true) {
            $this->atrasada  => 'atrasada',
            $this->emRevisao => 'revisao',
            default          => 'despacho',
        };
    }
}
