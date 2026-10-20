<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Service\VisibilidadeDoFinanceiroDaPasta;
use App\Pasta\DTO\RelatorioPastasParadasOutput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;

/**
 * Monta o relatório de pastas paradas de um escritório: coleta, analisa e ordena (a mais parada
 * primeiro). Uma peça só para a tela, o PDF e o envio mensal, para os três contarem a mesma coisa.
 *
 * O financeiro de cada pasta só aparece para o `$leitor` que pode vê-lo
 * ({@see VisibilidadeDoFinanceiroDaPasta}), perguntado só para as pastas que entram na lista. Sem
 * leitor (o envio automático), ninguém vê: os avisos nunca falam do financeiro.
 */
final class MontagemDoRelatorioPastasParadas
{
    public function __construct(
        private readonly ColetorDePastasParadas $coletor,
        private readonly AnalisadorDePastasParadas $analisador,
        private readonly VisibilidadeDoFinanceiroDaPasta $visibilidadeDoFinanceiro,
        private readonly PastaRepository $pastaRepository,
    ) {
    }

    /**
     * @param list<int>|null $ids nulo = todas as pastas ativas do escritório
     */
    public function montar(Tenant $tenant, ?array $ids, ?User $leitor, \DateTimeImmutable $hoje): RelatorioPastasParadasOutput
    {
        $podeVerFinanceiro = $leitor === null ? null : function (int $pastaId) use ($leitor, $tenant): bool {
            // A pasta já está no mapa de identidade (veio na coleta): o find não vai ao banco.
            $pasta = $this->pastaRepository->find($pastaId);

            return $pasta instanceof Pasta && $this->visibilidadeDoFinanceiro->podeVer($leitor, $tenant, $pasta);
        };

        return new RelatorioPastasParadasOutput(
            $hoje,
            $this->analisador->analisar($this->coletor->coletar($tenant, $ids, $hoje), $hoje, $podeVerFinanceiro),
        );
    }
}
