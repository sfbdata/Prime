<?php

declare(strict_types=1);

namespace App\ChatIa\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Service\AcessoAPasta;
use App\Tarefa\Enum\AbaMetas;
use App\Tarefa\Repository\TarefaRepository;
use App\Tarefa\Service\PastasSoAcompanhadasSemVer;

/**
 * A lista da tela Minhas Metas, com os MESMOS parâmetros de `TarefaController::minhas`: a pessoa da sessão, a aba, o filtro da
 * barra, as pastas ocultas pelo cadeado (`AcessoAPasta::idsOcultosPara`) e as pastas que ela só acompanha sem poder ver
 * (`PastasSoAcompanhadasSemVer`, auditoria 3.12). Sem filtro de etapa: a lista é a do papel (aba), nunca a da esteira do
 * escritório.
 */
final class ListaDasMinhasMetasDaTela implements ListaDasMinhasMetas
{
    public function __construct(
        private readonly TarefaRepository $metas,
        private readonly AcessoAPasta $acessoAPasta,
        private readonly PastasSoAcompanhadasSemVer $soAcompanhadasSemVer,
    ) {
    }

    public function daTela(User $eu, Tenant $tenant, AbaMetas $aba, string $filtroDePrazo): array
    {
        $ocultas = $this->acessoAPasta->idsOcultosPara($eu, (int) $tenant->getId());

        return array_values($this->metas->findParaMinhasMetas(
            $eu,
            $aba,
            ['busca' => '', 'prioridade' => '', 'prazo' => $filtroDePrazo, 'etapa' => ''],
            $ocultas,
            $tenant,
            $this->soAcompanhadasSemVer->ids($eu, $tenant),
        ));
    }

    public function carregarCartoes(array $metaIds, Tenant $tenant): void
    {
        if ($metaIds !== []) {
            $this->metas->carregarCartoesDasMetas($metaIds, $tenant);
        }
    }
}
