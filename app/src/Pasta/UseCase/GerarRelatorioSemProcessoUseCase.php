<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\RelatorioSemProcessoOutput;
use App\Pasta\Service\MontagemDoRelatorioSemProcesso;
use App\Pasta\Service\AcessoAPasta;
use App\Pasta\Service\PastasVisiveisNoRelatorio;
use Psr\Clock\ClockInterface;

/**
 * "Relatório geral de processos" (⋮ da aba Processo, desenho 1.2.3 `spPDF` e `spHTML`).
 *
 * Quem: quem tem o módulo Expediente (a guarda é do controller). O quê: ver, em todas as pastas que
 * pode ver, quais deveriam ter processo vinculado, quais administrativas merecem revisão e quais
 * estão sem classificação, com a sugestão e as metas para cada uma.
 *
 * Visibilidade (spec D4): Master, primário, Controladoria e quem tem `resources.pasta.view` veem o
 * escritório inteiro; os demais, só as pastas com acesso item a item. A "Evolução" compara com os
 * envios mensais, que contam o escritório inteiro: por isso só aparece para quem vê todas as
 * pastas (para os outros a comparação seria de conjuntos diferentes).
 *
 * Só leitura: nada é gravado.
 */
final class GerarRelatorioSemProcessoUseCase
{
    public function __construct(
        private readonly MontagemDoRelatorioSemProcesso $montagem,
        private readonly PastasVisiveisNoRelatorio $visiveis,
        private readonly ClockInterface $clock,
        private readonly AcessoAPasta $acessoAPasta,
    ) {
    }

    public function executar(Tenant $tenant, User $leitor): RelatorioSemProcessoOutput
    {
        $ids = $this->visiveis->ids($leitor, $tenant);
        // Cadeado (spec pasta-cadeado D1): a pasta Oculta para o leitor não entra no relatório.
        $ocultas = $this->acessoAPasta->idsOcultosPara($leitor, (int) $tenant->getId());

        return $this->montagem->montar($tenant, $ids, $ids === null, $this->clock->now(), $ocultas);
    }
}
