<?php

declare(strict_types=1);

namespace App\Voto\DTO;

/**
 * A ata imprimível (relatório consolidado do desenho, em HTML): a assembleia lida, quem emitiu e, por pauta, a
 * relação de votantes (quem e quando, nunca em quê) e a participação por bloco ou torre.
 */
final readonly class AtaDaAssembleia
{
    /**
     * @param array<int, list<array{bloco: string, identificacao: string, responsavel: ?string, em: \DateTimeImmutable}>> $votantes por id da pauta
     * @param array<int, list<array{bloco: string, unidades: int, peso: string}>>                                       $blocos   por id da pauta
     */
    public function __construct(
        public AssembleiaNoPainel $assembleia,
        public \DateTimeImmutable $emitidaEm,
        public string $emitidaPor,
        public array $votantes,
        public array $blocos,
    ) {
    }
}
