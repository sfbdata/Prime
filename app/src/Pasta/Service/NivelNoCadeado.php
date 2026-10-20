<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\AcessoAPastaTrancada;
use App\Pasta\Entity\CadeadoDaPasta;
use App\Pasta\Entity\ModoDoCadeado;
use App\Pasta\Entity\NivelDeAcesso;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\CadeadoDaPastaRepository;
use App\Repository\UserTenantRepository;
use App\Service\Tenant\TenantContext;
use App\Tenant\Service\RegraDosPrimarios;
use Doctrine\ORM\QueryBuilder;
use Symfony\Contracts\Service\ResetInterface;

/**
 * O nível de uma pessoa numa pasta pelo cadeado, SEM as outras camadas de permissão (spec pasta-cadeado
 * §3). É o teto que o `PermissionChecker::canAccessResource` aplica; fora dele, use o `AcessoAPasta`,
 * que é o ponto único (este serviço existe separado só para o `PermissionChecker` não depender de quem
 * depende dele).
 *
 * A regra, nesta ordem:
 *  1. Master, ou primário com vínculo ativo no escritório da pasta → `Editar` (K1: quem configura nunca
 *     é restringido; é o mesmo critério do `RegraDosPrimarios::podeGerirPrimarios`);
 *  2. sem vínculo ativo com o escritório da pasta → `Oculto`;
 *  3. pasta sem cadeado → `Editar` (as outras camadas, módulo e recurso, decidem o resto);
 *  4. cadeado `Todos` → `Oculto`; `PorPessoa` → o nível da pessoa na lista, ou `Oculto`.
 *
 * O nível é TETO, não permissão: `Editar` aqui não dá acesso nenhum que o `PermissionChecker` não dê.
 * Quem aplica o teto nas rotas de pasta é o `canAccessResource` (que chama este serviço) e a
 * `BarreiraDoCadeadoListener`.
 *
 * Memória por request (o serviço é zerado entre requisições pelo `ResetInterface`): uma consulta por
 * pasta e uma por vínculo. Quem grava um cadeado chama `esquecer()`.
 */
final class NivelNoCadeado implements ResetInterface
{
    /** @var array<int, CadeadoDaPasta|false> por id de pasta; `false` = sem cadeado */
    private array $cadeados = [];

    /** @var array<string, UserTenant|false> por "user:tenant"; `false` = sem vínculo ativo */
    private array $vinculos = [];

    /** Sufixo dos parâmetros do critério de DQL: dois critérios no mesmo QueryBuilder não colidem. */
    private int $criterios = 0;

    public function __construct(
        private readonly CadeadoDaPastaRepository $cadeadoRepository,
        private readonly UserTenantRepository $userTenantRepository,
        private readonly RegraDosPrimarios $regraDosPrimarios,
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function nivel(User $pessoa, Pasta $pasta): NivelDeAcesso
    {
        $tenant = $pasta->getTenant();
        if ($tenant === null || $pasta->getId() === null) {
            return NivelDeAcesso::Editar;
        }

        return $this->nivelPorId($pessoa, $tenant, (int) $pasta->getId());
    }

    /** O mesmo `nivel()`, para quem só tem o id da pasta e o escritório da sessão (o `canAccessResource`). */
    public function nivelPorId(User $pessoa, Tenant $tenant, int $pastaId): NivelDeAcesso
    {
        if ($this->regraDosPrimarios->ehMaster($pessoa)) {
            return NivelDeAcesso::Editar;
        }

        $vinculo = $this->vinculoAtivo($pessoa, $tenant);
        if ($vinculo === null) {
            return NivelDeAcesso::Oculto;
        }

        return $this->nivelComVinculo($pessoa, $vinculo, $pastaId);
    }

    /**
     * O mesmo `nivelPorId()`, para quem já tem o vínculo ativo da pessoa no escritório da pasta (o
     * `canAccessResource`, que acabou de resolvê-lo): poupa a consulta do vínculo.
     */
    public function nivelComVinculo(User $pessoa, UserTenant $vinculoAtivo, int $pastaId): NivelDeAcesso
    {
        if ($this->regraDosPrimarios->ehMaster($pessoa) || $this->regraDosPrimarios->ehPrimario($vinculoAtivo)) {
            return NivelDeAcesso::Editar;
        }

        return $this->cadeadoPorId($pastaId, $vinculoAtivo->getTenant())?->nivelDe($pessoa) ?? NivelDeAcesso::Editar;
    }

    /** Quem abre, altera e remove o cadeado e decide os pedidos (K1): o Master e o primário ativo. */
    public function podeConfigurar(User $pessoa, Tenant $tenant): bool
    {
        return $this->regraDosPrimarios->podeGerirPrimarios($pessoa, $tenant);
    }

    public function cadeadoDe(Pasta $pasta): ?CadeadoDaPasta
    {
        $tenant = $pasta->getTenant();
        if ($tenant === null || $pasta->getId() === null) {
            return null;
        }

        return $this->cadeadoPorId((int) $pasta->getId(), $tenant);
    }

    /**
     * Carrega de uma vez os cadeados de uma página de pastas (Lista do Expediente, busca), para o
     * `nivel()` de cada linha não fazer uma consulta por pasta.
     *
     * @param iterable<Pasta> $pastas
     */
    public function carregar(iterable $pastas, Tenant $tenant): void
    {
        $ids = [];
        foreach ($pastas as $pasta) {
            $id = $pasta->getId();
            if ($id !== null && !array_key_exists($id, $this->cadeados)) {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            return;
        }

        $achados = $this->cadeadoRepository->dasPastas($ids, $tenant);
        foreach ($ids as $id) {
            $this->cadeados[$id] = $achados[$id] ?? false;
        }
    }

    /**
     * O nível da pessoa em cada pasta de um lote, por id de pasta. Uma consulta de cadeados para as N
     * pastas (mais a do vínculo, uma vez por escritório). Para listas e buscas.
     *
     * @param iterable<Pasta> $pastas
     *
     * @return array<int, NivelDeAcesso>
     */
    public function niveisEmLote(User $pessoa, iterable $pastas): array
    {
        $porTenant = [];
        foreach ($pastas as $pasta) {
            if ($pasta->getId() !== null && $pasta->getTenant() !== null) {
                $porTenant[(int) $pasta->getTenant()->getId()][] = $pasta;
            }
        }

        $niveis = [];
        foreach ($porTenant as $doEscritorio) {
            $this->carregar($doEscritorio, $doEscritorio[0]->getTenant());
            foreach ($doEscritorio as $pasta) {
                $niveis[(int) $pasta->getId()] = $this->nivel($pessoa, $pasta);
            }
        }

        return $niveis;
    }

    /**
     * Uso (a): o QueryBuilder passa a devolver só as pastas que a pessoa VÊ (sem cadeado, ou com ela em
     * `Visualizar`/`Editar`). Master e primário do escritório da sessão veem tudo: nada é acrescentado.
     */
    public function restringirAoVisivel(QueryBuilder $qb, string $aliasPasta, User $pessoa): void
    {
        $criterio = $this->criterioDeVisivel($qb, $aliasPasta, $pessoa);
        if ($criterio !== null) {
            $qb->andWhere($criterio);
        }
    }

    /**
     * Uso (b): o critério de "a pessoa vê esta pasta", em DQL, com os parâmetros já postos no
     * QueryBuilder, para quem precisa combiná-lo (a busca do Expediente: pasta oculta continua listada,
     * mas só casa por número, cliente e CPF, spec D1). `null` quando a pessoa vê tudo (Master, primário).
     *
     * Bate com `nivel()`: o cadeado `Todos` não tem lista; no `PorPessoa` a lista só guarda quem vê.
     */
    public function criterioDeVisivel(QueryBuilder $qb, string $aliasPasta, User $pessoa): ?string
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant !== null && $this->regraDosPrimarios->podeGerirPrimarios($pessoa, $tenant)) {
            return null;
        }
        if ($tenant === null && $this->regraDosPrimarios->ehMaster($pessoa)) {
            return null;
        }

        $n = ++$this->criterios;
        $qb->setParameter('cad_pessoa_' . $n, $pessoa)
            ->setParameter('cad_modo_' . $n, ModoDoCadeado::PorPessoa);

        return sprintf(
            '(NOT EXISTS (SELECT cv%1$d.id FROM %2$s cv%1$d WHERE cv%1$d.pasta = %3$s)'
            . ' OR EXISTS (SELECT av%1$d.id FROM %4$s av%1$d JOIN av%1$d.cadeado ca%1$d'
            . ' WHERE ca%1$d.pasta = %3$s AND ca%1$d.modo = :cad_modo_%1$d AND av%1$d.pessoa = :cad_pessoa_%1$d))',
            $n,
            CadeadoDaPasta::class,
            $aliasPasta,
            AcessoAPastaTrancada::class,
        );
    }

    /**
     * Os processos (só os dígitos do número) das pastas ocultas para a pessoa: o Push global some com
     * eles (D3). Master e primário: nada a esconder.
     *
     * @return list<string>
     */
    public function numerosDeProcessoOcultos(User $pessoa, Tenant $tenant): array
    {
        if ($this->regraDosPrimarios->podeGerirPrimarios($pessoa, $tenant)) {
            return [];
        }

        $digitos = [];
        foreach ($this->cadeadoRepository->numerosDeProcessoOcultosPara($pessoa, $tenant) as $numero) {
            $so = preg_replace('/\D/', '', $numero) ?? '';
            if ($so !== '') {
                $digitos[$so] = true;
            }
        }

        return array_map('strval', array_keys($digitos));
    }

    /**
     * Ids das pastas ocultas para a pessoa (metas em /tarefas, auditoria). Master e primário: nenhuma.
     *
     * @return list<int>
     */
    public function idsDasPastasOcultas(User $pessoa, Tenant $tenant): array
    {
        return $this->regraDosPrimarios->podeGerirPrimarios($pessoa, $tenant)
            ? []
            : $this->cadeadoRepository->idsDasPastasOcultasPara($pessoa, $tenant);
    }

    /** @return list<int> o mesmo `idsDasPastasOcultas()`, para quem só tem o id do escritório */
    public function idsDasPastasOcultasPorTenantId(User $pessoa, int $tenantId): array
    {
        return $this->idsDasPastasOcultas($pessoa, $this->cadeadoRepository->escritorioPorId($tenantId));
    }

    /** Depois de gravar um cadeado na mesma requisição. */
    public function esquecer(): void
    {
        $this->cadeados = [];
    }

    public function reset(): void
    {
        $this->cadeados = [];
        $this->vinculos = [];
    }

    private function cadeadoPorId(int $pastaId, Tenant $tenant): ?CadeadoDaPasta
    {
        if (!array_key_exists($pastaId, $this->cadeados)) {
            $this->cadeados[$pastaId] = $this->cadeadoRepository->daPastaPorId($pastaId, $tenant) ?? false;
        }
        $cadeado = $this->cadeados[$pastaId];

        return $cadeado instanceof CadeadoDaPasta && $cadeado->getTenant()->getId() === $tenant->getId() ? $cadeado : null;
    }

    private function vinculoAtivo(User $pessoa, Tenant $tenant): ?UserTenant
    {
        $chave = $pessoa->getId() . ':' . $tenant->getId();
        if (!array_key_exists($chave, $this->vinculos)) {
            $this->vinculos[$chave] = $this->userTenantRepository->findAtivoPorUserETenant($pessoa, $tenant) ?? false;
        }
        $vinculo = $this->vinculos[$chave];

        return $vinculo instanceof UserTenant ? $vinculo : null;
    }
}
