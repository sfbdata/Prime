<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

/**
 * O relatório mensal de pastas paradas (desenho 02, `paradasHTML` L4739-4749; spec
 * docs/specs/pastas-paradas.md, "Página e PDF"), pronto para a folha: as pastas paradas, da mais
 * parada para a menos, e as contagens dos quatro cartões.
 */
final readonly class RelatorioPastasParadasOutput
{
    public const TITULO = 'Pastas paradas há mais de 30 dias';

    /**
     * Rodapé da folha. Desvio do desenho pela regra 1 da Inteligência (spec, "Rodapé"): a pré-análise
     * é por regra, sem modelo, e não pode ser atribuída à I.A. Um lugar só para o texto.
     */
    public const NOTA_FINAL = 'Pré-análise gerada a partir dos dados do sistema; não substitui a conferência no PJe. Relatório enviado automaticamente na primeira segunda-feira do mês, às 10h.';

    /** O que a página mostra sem pasta parada (desenho `paradasNotificar`, L3240). */
    public const NENHUMA = 'Nenhuma pasta parada há mais de 30 dias.';

    /**
     * @param list<PastaParada> $pastas da mais parada para a menos
     */
    public function __construct(
        public \DateTimeImmutable $hoje,
        public array $pastas,
    ) {
    }

    /** "Pastas paradas há mais de 30 dias · dd/mm/aaaa" (desenho `paradasBaixarPDF`, L4815). */
    public function titulo(): string
    {
        return self::TITULO . ' · ' . $this->hoje->format('d/m/Y');
    }

    /**
     * Os quatro cartões do topo (L4742).
     *
     * @return list<array{rotulo: string, valor: int}>
     */
    public function cartoes(): array
    {
        return [
            ['rotulo' => 'Pastas paradas', 'valor' => \count($this->pastas)],
            ['rotulo' => 'Mais de 90 dias', 'valor' => $this->maisDe90()],
            ['rotulo' => 'Sem contrato', 'valor' => $this->semContrato()],
            ['rotulo' => 'Sem processo', 'valor' => $this->semProcesso()],
        ];
    }

    public function maisDe90(): int
    {
        return \count(array_filter($this->pastas, static fn (PastaParada $p): bool => $p->critica()));
    }

    public function semContrato(): int
    {
        return \count(array_filter($this->pastas, static fn (PastaParada $p): bool => $p->semContrato));
    }

    public function semProcesso(): int
    {
        return \count(array_filter($this->pastas, static fn (PastaParada $p): bool => $p->semProcesso()));
    }
}
