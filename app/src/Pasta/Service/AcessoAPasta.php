<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\CadeadoDaPasta;
use App\Pasta\Entity\NivelDeAcesso;
use App\Pasta\Entity\Pasta;
use App\Service\PermissionChecker;
use Doctrine\ORM\QueryBuilder;

/**
 * O ponto único do cadeado da pasta (spec pasta-cadeado §3). Toda superfície que pergunta "esta pessoa
 * vê/edita esta pasta?" pergunta aqui.
 *
 *  - `nivel()` é o nível do CADEADO (`Oculto`, `Visualizar`, `Editar`), a regra do `NivelNoCadeado`:
 *    Master e primário ativo → `Editar`; sem vínculo ativo → `Oculto`; sem cadeado → `Editar`; `Todos`
 *    → `Oculto`; `PorPessoa` → o nível da pessoa, ou `Oculto`;
 *  - `podeVer()`/`podeEditar()` são a decisão COMPLETA: o `canAccessResource` de `view`/`edit` da pasta,
 *    que já aplica o cadeado como teto (vê = não `Oculto` + permissão de ver a pasta). É a mesma assinatura
 *    e a mesma semântica do serviço provisório combinado com a 5d ("Encaminhar via Chat I.A").
 *
 * Para listas e buscas: `niveisEmLote()`, `restringirAoVisivel()` e `criterioDeVisivel()`.
 */
final class AcessoAPasta
{
    public function __construct(
        private readonly NivelNoCadeado $nivelNoCadeado,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    public function nivel(User $pessoa, Pasta $pasta): NivelDeAcesso
    {
        return $this->nivelNoCadeado->nivel($pessoa, $pasta);
    }

    public function podeVer(User $pessoa, Pasta $pasta): bool
    {
        return $this->pode($pessoa, $pasta, AccessRequest::ACTION_VIEW);
    }

    public function podeEditar(User $pessoa, Pasta $pasta): bool
    {
        return $this->pode($pessoa, $pasta, AccessRequest::ACTION_EDIT);
    }

    /**
     * Veria a pasta se ela estivesse livre (a permissão de ver, sem o teto do cadeado). Só para o pedido de
     * acesso: quem não veria nem sem cadeado não tem o que pedir ao primário (K5).
     */
    public function veriaSemCadeado(User $pessoa, Pasta $pasta): bool
    {
        $tenant = $pasta->getTenant();
        if ($tenant === null || $pasta->getId() === null) {
            return false;
        }

        return $this->permissionChecker->canAccessResourceIgnoringPastaLock($pessoa, $tenant, AccessRequest::RESOURCE_PASTA, (int) $pasta->getId(), AccessRequest::ACTION_VIEW);
    }

    /** Quem abre, altera e remove o cadeado e decide os pedidos (K1): o Master e o primário ativo. */
    public function podeConfigurar(User $pessoa, Tenant $tenant): bool
    {
        return $this->nivelNoCadeado->podeConfigurar($pessoa, $tenant);
    }

    public function cadeadoDe(Pasta $pasta): ?CadeadoDaPasta
    {
        return $this->nivelNoCadeado->cadeadoDe($pasta);
    }

    /**
     * @param iterable<Pasta> $pastas
     *
     * @return array<int, NivelDeAcesso> o nível do cadeado por id de pasta, uma consulta para o lote
     */
    public function niveisEmLote(User $pessoa, iterable $pastas): array
    {
        return $this->nivelNoCadeado->niveisEmLote($pessoa, $pastas);
    }

    /** Uso (a): só as pastas que a pessoa vê pelo cadeado. Master e primário: nada muda. */
    public function restringirAoVisivel(QueryBuilder $qb, string $aliasPasta, User $pessoa): void
    {
        $this->nivelNoCadeado->restringirAoVisivel($qb, $aliasPasta, $pessoa);
    }

    /** Uso (b): o critério em DQL, para combinar (pasta oculta casa só por número, cliente e CPF). */
    public function criterioDeVisivel(QueryBuilder $qb, string $aliasPasta, User $pessoa): ?string
    {
        return $this->nivelNoCadeado->criterioDeVisivel($qb, $aliasPasta, $pessoa);
    }

    /** @return list<string> dígitos dos números de processo das pastas ocultas para a pessoa (Push global, D3) */
    public function numerosDeProcessoOcultos(User $pessoa, Tenant $tenant): array
    {
        return $this->nivelNoCadeado->numerosDeProcessoOcultos($pessoa, $tenant);
    }

    /** @return list<int> ids das pastas ocultas para a pessoa (metas, auditoria; D3, D4) */
    public function idsDasPastasOcultas(User $pessoa, Tenant $tenant): array
    {
        return $this->nivelNoCadeado->idsDasPastasOcultas($pessoa, $tenant);
    }

    /**
     * Para quem monta SQL nativo (a busca Ctrl+K, #106): os ids das pastas do escritório OCULTAS para a
     * pessoa, numa consulta só (vazio para primário e Master). Quem usa aplica `p.id NOT IN (:ocultos)` para
     * os dados completos e limita os campos de busca dessas ao número, cliente e CPF.
     *
     * @return list<int>
     */
    public function idsOcultosPara(User $pessoa, int $tenantId): array
    {
        return $this->nivelNoCadeado->idsDasPastasOcultasPorTenantId($pessoa, $tenantId);
    }

    /** @return list<int> processos cujas pastas são todas ocultas para a pessoa (não abrem para ela, K3) */
    public function idsDosProcessosOcultos(User $pessoa, Tenant $tenant): array
    {
        return $this->nivelNoCadeado->idsDosProcessosOcultos($pessoa, $tenant);
    }

    /** Depois de gravar um cadeado na mesma requisição. */
    public function esquecer(): void
    {
        $this->nivelNoCadeado->esquecer();
    }

    private function pode(User $pessoa, Pasta $pasta, string $acao): bool
    {
        $tenant = $pasta->getTenant();
        if ($tenant === null || $pasta->getId() === null) {
            return false;
        }

        return $this->permissionChecker->canAccessResource($pessoa, $tenant, AccessRequest::RESOURCE_PASTA, (int) $pasta->getId(), $acao);
    }
}
