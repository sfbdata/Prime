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

    /** @return list<PautaNoPainel> as pautas que seguem na ordem do dia (sem as retiradas), na ordem em que foram cadastradas */
    public function naOrdemDoDia(): array
    {
        return array_values(array_filter($this->assembleia->pautas, static fn (PautaNoPainel $p): bool => !$p->retirada()));
    }

    /** @return list<PautaNoPainel> as da ordem do dia na ordem de abertura; as ainda não abertas ao fim, na ordem original */
    public function emOrdemDeAbertura(): array
    {
        $naOrdem = $this->naOrdemDoDia();
        $abertas = array_values(array_filter($naOrdem, static fn (PautaNoPainel $p): bool => null !== $p->abertaEm));
        usort($abertas, static fn (PautaNoPainel $x, PautaNoPainel $y): int => $x->abertaEm?->getTimestamp() <=> $y->abertaEm?->getTimestamp());

        return array_merge($abertas, array_values(array_filter($naOrdem, static fn (PautaNoPainel $p): bool => null === $p->abertaEm)));
    }

    /** A ata só com a pauta pedida (mais as retiradas da ordem do dia, que o desenho mantém na minuta do evento). */
    public function soComAPauta(int $pautaId): self
    {
        $pautas = array_values(array_filter($this->assembleia->pautas, static fn (PautaNoPainel $p): bool => $p->id === $pautaId || $p->retirada()));

        return new self($this->assembleia->comPautas($pautas), $this->emitidaEm, $this->emitidaPor, $this->votantes, $this->blocos);
    }
}
