<?php

declare(strict_types=1);

namespace App\Processo\UseCase;

use App\Processo\DTO\CorrecaoDeAcentuacaoOutput;
use App\Processo\DTO\LinhaDaCorrecaoDeAcentuacao;
use App\Processo\Repository\MovimentacaoProcessoRepository;
use App\Processo\Repository\ProcessoRepository;
use App\Processo\Service\AcentuacaoEstragada;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reparo dos nomes de órgão gravados com a acentuação estragada pelo `DatajudProcessoMapper::fixOrgaoJulgador`
 * antigo (`processo.orgao_julgador` e `movimentacao_processo.orgao`), pela reversão exata de
 * {@see AcentuacaoEstragada}. Decisão do coordenador (21/10/2026): reverter localmente, e não rebuscar no CNJ.
 *
 * Quem: o dono, pelo comando `app:processo:corrigir-acentuacao-do-orgao`, depois do deploy do mapper corrigido.
 * Sem `aplicar`, só mostra. Com `aplicar`, troca cada nome distinto numa transação só; o nome que não reverte
 * limpo fica intocado e listado. Idempotente: o nome revertido não tem mais o padrão e sai da consulta.
 * Todos os escritórios: o nome do órgão é público e o estrago é o mesmo em qualquer um.
 */
final class CorrigirAcentuacaoDoOrgaoUseCase
{
    public function __construct(
        private readonly ProcessoRepository $processos,
        private readonly MovimentacaoProcessoRepository $movimentacoes,
        private readonly AcentuacaoEstragada $acentuacao,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function executar(bool $aplicar): CorrecaoDeAcentuacaoOutput
    {
        $linhas = [
            ...$this->linhas(LinhaDaCorrecaoDeAcentuacao::NO_PROCESSO, $this->processos->orgaosJulgadoresComAcentuacaoEstragada()),
            ...$this->linhas(LinhaDaCorrecaoDeAcentuacao::NA_MOVIMENTACAO, $this->movimentacoes->orgaosDasMovimentacoesComAcentuacaoEstragada()),
        ];

        if ($aplicar) {
            $this->em->wrapInTransaction(fn () => $this->trocar($linhas));
        }

        return new CorrecaoDeAcentuacaoOutput($linhas, $aplicar);
    }

    /**
     * @param array<string, int> $valores valor estragado → quantidade
     *
     * @return list<LinhaDaCorrecaoDeAcentuacao>
     */
    private function linhas(string $onde, array $valores): array
    {
        $linhas = [];
        foreach ($valores as $antes => $quantidade) {
            $linhas[] = new LinhaDaCorrecaoDeAcentuacao($onde, (string) $antes, $this->acentuacao->reverter((string) $antes), $quantidade);
        }

        return $linhas;
    }

    /** @param list<LinhaDaCorrecaoDeAcentuacao> $linhas */
    private function trocar(array $linhas): void
    {
        foreach ($linhas as $linha) {
            if ($linha->depois === null) {
                continue;
            }
            $linha->onde === LinhaDaCorrecaoDeAcentuacao::NO_PROCESSO
                ? $this->processos->trocarOrgaoJulgador($linha->antes, $linha->depois)
                : $this->movimentacoes->trocarOrgaoDasMovimentacoes($linha->antes, $linha->depois);
        }
    }
}
