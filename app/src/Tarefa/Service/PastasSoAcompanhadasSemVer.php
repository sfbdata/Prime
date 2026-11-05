<?php

declare(strict_types=1);

namespace App\Tarefa\Service;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Service\AcessoAPasta;
use App\Repository\UserTenantRepository;
use App\Service\PermissionChecker;
use App\Tarefa\Repository\TarefaRepository;

/**
 * Auditoria 3.12 nas listas (docs/AUTORIZACAO.md, seção 7, "Meta aberta por id"): só acompanhar uma meta não
 * dá o direito de vê-la. A meta aparece para quem cuida dela (responsável ou criador, {@see QuemCuidaDaMeta}) ou
 * pode VER a pasta (`AcessoAPasta::podeVer`, o `canAccessResource` completo: `resources.pasta.view`,
 * `ResourceAccess` de ver aquela pasta, perfil de sistema ou Master, com o teto do cadeado), a mesma regra do
 * {@see AcessoAMeta::exigir}.
 *
 * Devolve os ids das pastas em que a pessoa só acompanha meta e que ela NÃO pode ver: as abas Acompanhando e
 * Todas, as contagens e o trilho das Minhas Metas tiram a meta acompanhada delas
 * (`TarefaRepository::escoparPelaAba`). Em lote: uma consulta para as pastas candidatas, uma para os cadeados,
 * uma para o vínculo e uma para os acessos item a item ({@see PermissionChecker::idsPermitidosComVinculo()}).
 * Primário e Master: nenhuma (veem tudo).
 */
final class PastasSoAcompanhadasSemVer
{
    public function __construct(
        private readonly TarefaRepository $metas,
        private readonly AcessoAPasta $acessoAPasta,
        private readonly PermissionChecker $permissionChecker,
        private readonly UserTenantRepository $vinculos,
    ) {
    }

    /** @return list<int> */
    public function ids(User $pessoa, Tenant $escritorio): array
    {
        $pastas = $this->metas->pastasSoAcompanhadasPor($pessoa, $escritorio);
        if ($pastas === []) {
            return [];
        }

        // Carrega os cadeados das candidatas numa consulta: o checker os lê da memória.
        $this->acessoAPasta->niveisEmLote($pessoa, $pastas);
        $ids        = array_map(static fn (Pasta $p): int => (int) $p->getId(), $pastas);
        $vinculo    = $this->vinculos->findAtivoPorUserETenant($pessoa, $escritorio);
        $permitidas = array_flip($this->permissionChecker->idsPermitidosComVinculo($pessoa, $vinculo, AccessRequest::RESOURCE_PASTA, $ids, AccessRequest::ACTION_VIEW));

        return array_values(array_filter($ids, static fn (int $id): bool => !isset($permitidas[$id])));
    }
}
