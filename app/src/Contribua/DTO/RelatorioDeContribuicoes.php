<?php

declare(strict_types=1);

namespace App\Contribua\DTO;

/**
 * "Relatório de contribuições" do Painel do Master (desenho 08, L253: "Valores recebidos por conta e por mês").
 * Só conta o que foi CONFIRMADO; o que o escritório só informou ("Já contribuí") fica à parte, por mês.
 * Desligado (chave `rel` da Integração bancária, ou integração nunca salva): vem sem dados e com o motivo.
 */
final readonly class RelatorioDeContribuicoes
{
    public const SEM_INTEGRACAO = 'sem_integracao';
    public const CHAVE_DESLIGADA = 'chave_desligada';

    /**
     * @param list<MesDeContribuicoes>   $meses  os últimos 12, do mais antigo ao atual, inclusive os sem apoio
     * @param list<ContribuicoesDaConta> $contas maior soma primeiro
     */
    public function __construct(
        public ?string $desligado,
        public array $meses = [],
        public array $contas = [],
        public int $totalGeralCentavos = 0,
    ) {
    }

    public static function desligado(string $motivo): self
    {
        return new self($motivo);
    }

    public function ligado(): bool
    {
        return null === $this->desligado;
    }

    /** Nenhum apoio confirmado, em tempo nenhum: o estado vazio. */
    public function semConfirmados(): bool
    {
        return [] === $this->contas;
    }

    public function confirmadoNosMeses(): int
    {
        return array_sum(array_map(static fn (MesDeContribuicoes $m): int => $m->confirmadoCentavos, $this->meses));
    }

    public function informadoNosMeses(): int
    {
        return array_sum(array_map(static fn (MesDeContribuicoes $m): int => $m->informadoCentavos, $this->meses));
    }

    /** A base das barras da grade (nunca zero, para a divisão). */
    public function maiorMes(): int
    {
        return max([1, ...array_map(static fn (MesDeContribuicoes $m): int => $m->confirmadoCentavos, $this->meses)]);
    }
}
