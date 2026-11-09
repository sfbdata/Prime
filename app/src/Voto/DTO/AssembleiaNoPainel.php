<?php

declare(strict_types=1);

namespace App\Voto\DTO;

use App\Voto\Enum\EstadoDaAssembleia;
use App\Voto\Enum\PesoDoVoto;

/** A assembleia como o gestor a vê, com as pautas em ordem e os totais da lista de unidades. */
final readonly class AssembleiaNoPainel
{
    /**
     * @param list<PautaNoPainel> $pautas
     * @param string              $pesoApto soma do peso apto, 6 casas
     */
    public function __construct(
        public int $id,
        public string $titulo,
        public PesoDoVoto $peso,
        public EstadoDaAssembleia $estado,
        public string $criadaPorNome,
        public \DateTimeImmutable $criadaEm,
        public ?\DateTimeImmutable $publicadaEm,
        public bool $podeAlterarPautas,
        public bool $podeAlterarUnidades,
        public int $unidadesAptas,
        public int $unidadesInaptas,
        public string $pesoApto,
        public array $pautas,
    ) {
    }

    public function publicada(): bool
    {
        return EstadoDaAssembleia::Publicada === $this->estado;
    }

    public function porFracao(): bool
    {
        return PesoDoVoto::Fracao === $this->peso;
    }

    /** Pautas já abertas ou encerradas (o que Acompanhar e Resultados mostram). */
    public function temPautaQueSaiuDaEspera(): bool
    {
        foreach ($this->pautas as $pauta) {
            if ($pauta->aberta() || $pauta->encerrada()) {
                return true;
            }
        }

        return false;
    }

    /** @return list<PautaNoPainel> */
    public function pautasEncerradas(): array
    {
        return array_values(array_filter($this->pautas, static fn (PautaNoPainel $p): bool => $p->encerrada()));
    }
}
