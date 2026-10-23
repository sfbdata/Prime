<?php

declare(strict_types=1);

namespace App\Dashboard\DTO;

/**
 * Cartão do colaborador (botão direito no nome; spec dashboard-parte-1, item 4), os blocos do
 * desenho: posição no ranking do período ("2º de 9 no período"), Comentários (registros), "1º do
 * mês" (meses fechados em que foi o campeão) e Rendimento (concluídas dele contra a média dos
 * outros). Os textos são montados no JS pelo catálogo; aqui só números e datas.
 */
final class RankingDoColaboradorOutput
{
    /**
     * @param array{anos: list<int>, anoAtual: int, ultimoMesFechado: int, ganhos: array<int, list<int>>} $primeiros
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $nome,
        public readonly ?string $cargo,
        /** 'periodo' (o do painel) ou uma janela do seletor (mes, trimestre, semestre, ano). */
        public readonly string $janela,
        public readonly string $dataDe,
        public readonly string $dataAte,
        public readonly int $posicao,
        public readonly int $pessoas,
        public readonly int $registros,
        public readonly bool $campeao,
        /** Metas concluídas dele no período (total − ativas). */
        public readonly int $concluidas,
        /** Média de concluídas dos OUTROS colaboradores ativos (a própria pessoa fora). */
        public readonly float $mediaOutros,
        public readonly int $outros,
        public readonly array $primeiros,
    ) {
    }

    /** @return array<string, mixed> */
    public function paraArray(): array
    {
        return [
            'userId'      => $this->userId,
            'nome'        => $this->nome,
            'cargo'       => $this->cargo,
            'janela'      => $this->janela,
            'dataDe'      => $this->dataDe,
            'dataAte'     => $this->dataAte,
            'posicao'     => $this->posicao,
            'pessoas'     => $this->pessoas,
            'registros'   => $this->registros,
            'campeao'     => $this->campeao,
            'rendimento'  => ['eu' => $this->concluidas, 'media' => $this->mediaOutros, 'outros' => $this->outros],
            'primeiros'   => $this->primeiros,
        ];
    }
}
