<?php

declare(strict_types=1);

namespace App\Voto\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tenant\Service\RegraDosPrimarios;
use App\Voto\Entity\Assembleia;
use App\Voto\Enum\NivelNaAssembleia;
use App\Voto\Enum\PapelNaEquipe;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Repository\MembroDaEquipeRepository;

/**
 * O ponto único do teto da equipe por assembleia (spec docs/specs/voto-equipe-e-master-1611.md, seção 1.2), no molde do
 * `AcessoAPasta` do cadeado. Toda superfície que pergunta "esta pessoa vê/edita esta assembleia?" pergunta aqui.
 *
 * A regra do `nivel()`, nesta ordem:
 *  1. Master ou primário ativo do escritório (`RegraDosPrimarios::podeGerirPrimarios`: "a Administração") → `Editar`.
 *     Designa a equipe e nunca é restringida;
 *  2. assembleia sem equipe própria → `Editar` (vale o módulo `voto`, como sempre);
 *  3. com equipe própria: `comissao` → `Editar`; `auditoria`, `fiscal` e `conselho` → `Visualizar`; quem não está na
 *     lista, ou está só como linha sem `user_id` (sem conta), → `Oculto`.
 *
 * É TETO, nunca permissão: o nível não olha o módulo `voto` nem a liberação do escritório. Quem a guarda atual recusa
 * ({@see GuardaDaEquipeDoVoto::exigir()}, chamada pelas actions) continua recusado, seja qual for o nível aqui.
 *
 * Para listas: `niveisEmLote()` e `visiveis()` leem as equipes do lote numa consulta só (sem N+1).
 */
final class AcessoAAssembleia
{
    public function __construct(
        private readonly RegraDosPrimarios $regraDosPrimarios,
        private readonly MembroDaEquipeRepository $membros,
        private readonly AssembleiaRepository $assembleias,
    ) {
    }

    public function nivel(User $pessoa, Assembleia $assembleia): NivelNaAssembleia
    {
        $id = $assembleia->getId();
        if (null === $id) {
            return NivelNaAssembleia::Editar;
        }

        return $this->niveisEmLote($pessoa, [$assembleia])[$id] ?? NivelNaAssembleia::Editar;
    }

    /** A Administração: o Master e o primário ativo do escritório. Designa a equipe e nunca é restringida. */
    public function ehAdministracao(User $pessoa, Tenant $tenant): bool
    {
        return $this->regraDosPrimarios->podeGerirPrimarios($pessoa, $tenant);
    }

    /**
     * O nível da pessoa em cada assembleia de um lote, por id. Uma consulta de equipes por escritório (e a da
     * Administração, uma vez por escritório).
     *
     * @param iterable<Assembleia> $assembleias
     *
     * @return array<int, NivelNaAssembleia>
     */
    public function niveisEmLote(User $pessoa, iterable $assembleias): array
    {
        $porEscritorio = [];
        foreach ($assembleias as $assembleia) {
            if (null !== $assembleia->getId()) {
                $porEscritorio[(int) $assembleia->getTenant()->getId()][] = $assembleia;
            }
        }

        $niveis = [];
        foreach ($porEscritorio as $doEscritorio) {
            $niveis += $this->niveisDoEscritorio($pessoa, $doEscritorio);
        }

        return $niveis;
    }

    /**
     * Só as assembleias que a pessoa não vê como `Oculto` (as listas do Painel, Votar, Acompanhar e Resultados).
     *
     * @param list<Assembleia> $assembleias
     *
     * @return list<Assembleia>
     */
    public function visiveis(User $pessoa, array $assembleias): array
    {
        $niveis = $this->niveisEmLote($pessoa, $assembleias);

        return array_values(array_filter(
            $assembleias,
            static fn (Assembleia $a): bool => NivelNaAssembleia::Oculto !== ($niveis[(int) $a->getId()] ?? NivelNaAssembleia::Editar),
        ));
    }

    /**
     * Dos ids do lote, tira os de assembleia do escritório que a pessoa não edita (`Oculto` ou `Visualizar`). O id que
     * não é do escritório FICA na lista, para o UseCase do lote responder o 404 dele (a barreira não o revela).
     *
     * @param list<int> $ids
     *
     * @return list<int>
     */
    public function idsQueEdita(User $pessoa, Tenant $tenant, array $ids): array
    {
        $doEscritorio = $this->assembleias->doEscritorioPorIds($tenant, $ids);
        $niveis = $this->niveisEmLote($pessoa, $doEscritorio);
        $proibidos = [];
        foreach ($niveis as $id => $nivel) {
            if (NivelNaAssembleia::Editar !== $nivel) {
                $proibidos[$id] = true;
            }
        }

        return array_values(array_filter($ids, static fn (int $id): bool => !isset($proibidos[$id])));
    }

    /**
     * @param list<Assembleia> $assembleias do mesmo escritório
     *
     * @return array<int, NivelNaAssembleia>
     */
    private function niveisDoEscritorio(User $pessoa, array $assembleias): array
    {
        $tenant = $assembleias[0]->getTenant();
        $niveis = [];
        if ($this->ehAdministracao($pessoa, $tenant)) {
            foreach ($assembleias as $assembleia) {
                $niveis[(int) $assembleia->getId()] = NivelNaAssembleia::Editar;
            }

            return $niveis;
        }

        $ids = array_map(static fn (Assembleia $a): int => (int) $a->getId(), $assembleias);
        $equipes = array_fill_keys($ids, []);
        foreach ($this->membros->linhasDoLote($tenant, $ids) as $linha) {
            $equipes[$linha['assembleia']][] = $linha;
        }
        foreach ($equipes as $id => $linhas) {
            $niveis[$id] = self::nivelPelaEquipe($linhas, (int) $pessoa->getId());
        }

        return $niveis;
    }

    /**
     * A regra 2 e 3, sem banco: a equipe da assembleia e a pessoa.
     *
     * @param list<array{assembleia: int, user: int|null, papel: string}> $linhas
     */
    public static function nivelPelaEquipe(array $linhas, int $pessoaId): NivelNaAssembleia
    {
        if ([] === $linhas) {
            return NivelNaAssembleia::Editar;
        }
        foreach ($linhas as $linha) {
            if ($linha['user'] === $pessoaId) {
                return PapelNaEquipe::tryFrom($linha['papel'])?->nivel() ?? NivelNaAssembleia::Oculto;
            }
        }

        return NivelNaAssembleia::Oculto;
    }
}
