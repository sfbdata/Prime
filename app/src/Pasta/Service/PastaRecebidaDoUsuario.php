<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\PastaRecebida;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\EstadoDoCompartilhamento;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaCompartilhamento;
use App\Pasta\Exception\PastaRecebidaNaoEncontradaException;
use App\Pasta\Repository\AbaOcultaDaPastaRepository;
use App\Repository\UserTenantRepository;
use App\Service\PermissionChecker;
use App\ServiceDesk\Repository\SemFiltroDeTenant;
use App\Tenant\Service\SituacaoDaConta;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * A porta única da pasta recebida de outra conta (spec acesso-restrito-e-compartilhar §4.5.1). Quem está no escritório
 * B abre UMA pasta do escritório A pelo convite aceito; toda rota `expediente_recebida_*` começa por `localizar()`.
 *
 * O teto é quem republicou. A cada requisição, com o filtro do escritório DESLIGADO (o cadeado, as abas ocultas e os
 * vínculos de A ficam invisíveis com o filtro de B ligado, e "sem cadeado" seria falso positivo):
 *  1. o convite: destino = quem pede, de outra conta, aceito e dentro da validade;
 *  2. a pasta: fora da lixeira e sem cadeado (qualquer modo);
 *  3. a conta A: não excluída (suspensa abre só para leitura);
 *  4. quem republicou: conta ativa, vínculo ativo em A, sem acesso restrito lá e ainda edita a pasta
 *     (`AcessoAPasta::podeEditar`, a regra de quem republica);
 *  5. quem pede: SEM vínculo ativo em A (quem entrou em A usa o acesso de lá, com o escopo de lá).
 * Falhou qualquer um: {@see PastaRecebidaNaoEncontradaException}, sem dizer qual.
 *
 * Tem de ser a PRIMEIRA consulta de A na requisição: `PermissionChecker`, `NivelNoCadeado`, `EscopoDePastas` e
 * `VisibilidadeDasAbasDaPasta` guardam o resultado por pessoa, pasta e escritório até o fim dela, e o que nascesse com
 * o filtro de B ligado ("sem cadeado", "sem regra") valeria até lá. Daqui, já nascem certos.
 *
 * `quaisAbrem()` é o mesmo critério em lote, para a caixa "Recebidas" (até 100 convites): cadeados por escritório,
 * vínculos de quem republicou e de quem pede numa consulta, e a edição por grupo de quem republicou e escritório.
 */
final class PastaRecebidaDoUsuario
{
    use SemFiltroDeTenant;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AcessoAPasta $acessoAPasta,
        private readonly NivelNoCadeado $nivelNoCadeado,
        private readonly EscopoDePastas $escopo,
        private readonly UserTenantRepository $vinculos,
        private readonly PermissionChecker $permissoes,
        private readonly VisibilidadeDasAbasDaPasta $abas,
        private readonly AbaOcultaDaPastaRepository $regrasDasAbas,
        private readonly SituacaoDaConta $situacao,
        private readonly ClockInterface $relogio,
    ) {
    }

    /** @throws PastaRecebidaNaoEncontradaException */
    public function localizar(int $compartilhamentoId, User $usuario): PastaRecebida
    {
        return $this->semFiltroDeTenant(function () use ($compartilhamentoId, $usuario): PastaRecebida {
            $convite = $this->conviteAceito($compartilhamentoId, $usuario);
            if ($convite === null || !$this->abreSemOlharAConta($convite, $usuario)) {
                throw new PastaRecebidaNaoEncontradaException();
            }
            $pasta = $convite->getPasta();
            $tenant = $convite->getTenant();
            $origem = $convite->getOrigem();

            if ($this->acessoAPasta->cadeadoDe($pasta) !== null) {
                throw new PastaRecebidaNaoEncontradaException();
            }
            $vinculoDaOrigem = $this->vinculos->findAtivoPorUserETenant($origem, $tenant);
            if ($vinculoDaOrigem === null || $this->escopo->ehRestrito($origem, $vinculoDaOrigem) || !$this->acessoAPasta->podeEditar($origem, $pasta)) {
                throw new PastaRecebidaNaoEncontradaException();
            }
            if ($this->vinculos->existeVinculoAtivo($usuario, $tenant)) {
                throw new PastaRecebidaNaoEncontradaException();
            }

            return new PastaRecebida($convite, $pasta, $tenant, $origem, $this->situacao->foraDoAr($tenant), $this->abasOcultasParaQuemRecebeu($pasta, $tenant, $origem));
        });
    }

    /**
     * O critério do `localizar()` para a caixa "Recebidas", sem uma consulta por convite.
     *
     * @param list<PastaCompartilhamento> $convites
     *
     * @return array<int, true> id do convite que abre → true
     */
    public function quaisAbrem(array $convites, User $usuario): array
    {
        return $this->semFiltroDeTenant(function () use ($convites, $usuario): array {
            $candidatos = array_values(array_filter($convites, fn (PastaCompartilhamento $c): bool => $c->getStatus() === EstadoDoCompartilhamento::Aceita && $this->abreSemOlharAConta($c, $usuario)));
            if ($candidatos === []) {
                return [];
            }
            $porEscritorio = [];
            foreach ($candidatos as $c) {
                $porEscritorio[(int) $c->getTenant()->getId()][] = $c;
            }
            // Cadeados: uma consulta por escritório, que já deixa o `NivelNoCadeado` com a resposta certa.
            foreach ($porEscritorio as $doEscritorio) {
                $this->nivelNoCadeado->carregar(array_map(static fn (PastaCompartilhamento $c): Pasta => $c->getPasta(), $doEscritorio), $doEscritorio[0]->getTenant());
            }
            $vinculos = $this->vinculosAtivos($candidatos, $usuario);

            return $this->queEditam($this->agrupar($candidatos, $vinculos, $usuario));
        });
    }

    /**
     * Condições 2, 4 e 5 já com os cadeados e os vínculos carregados: sem cadeado, quem pede sem vínculo em A e quem
     * republicou com vínculo ativo e sem restrição. Os que passam, agrupados por quem republicou e escritório.
     *
     * @param list<PastaCompartilhamento> $candidatos
     * @param array<string, UserTenant>   $vinculos
     *
     * @return list<array{vinculo: UserTenant, convites: list<PastaCompartilhamento>}>
     */
    private function agrupar(array $candidatos, array $vinculos, User $usuario): array
    {
        $grupos = [];
        foreach ($candidatos as $c) {
            $tenantId = (int) $c->getTenant()->getId();
            $chave = $c->getOrigem()->getId() . ':' . $tenantId;
            $vinculoDaOrigem = $vinculos[$chave] ?? null;
            if ($this->acessoAPasta->cadeadoDe($c->getPasta()) !== null
                || isset($vinculos[$usuario->getId() . ':' . $tenantId])
                || $vinculoDaOrigem === null
                || $this->escopo->ehRestrito($c->getOrigem(), $vinculoDaOrigem)) {
                continue;
            }
            $grupos[$chave]['vinculo'] = $vinculoDaOrigem;
            $grupos[$chave]['convites'][] = $c;
        }

        return array_values($grupos);
    }

    /**
     * Condição 4 (quem republicou ainda edita a pasta), uma consulta por grupo.
     *
     * @param list<array{vinculo: UserTenant, convites: list<PastaCompartilhamento>}> $grupos
     *
     * @return array<int, true>
     */
    private function queEditam(array $grupos): array
    {
        $abrem = [];
        foreach ($grupos as $grupo) {
            $vinculo = $grupo['vinculo'];
            $editaveis = $this->permissoes->idsPermitidosComVinculo(
                $vinculo->getUser(),
                $vinculo,
                AccessRequest::RESOURCE_PASTA,
                array_map(static fn (PastaCompartilhamento $c): int => (int) $c->getPasta()->getId(), $grupo['convites']),
                AccessRequest::ACTION_EDIT,
            );
            foreach ($grupo['convites'] as $c) {
                if (\in_array((int) $c->getPasta()->getId(), $editaveis, true)) {
                    $abrem[(int) $c->getId()] = true;
                }
            }
        }

        return $abrem;
    }

    /**
     * Condições 1 a 4 que não pedem consulta: convite vigente de quem pede, pasta viva, conta A não excluída, origem ativa.
     *
     * Só roda DENTRO da janela sem filtro (`localizar()` e `quaisAbrem()` a chamam de lá): pasta, conta e origem podem
     * chegar como proxies ainda não carregados (o convite que veio de outra consulta), e a pasta de A, carregada com o
     * filtro de B ligado, não existiria.
     */
    private function abreSemOlharAConta(PastaCompartilhamento $c, User $usuario): bool
    {
        $pasta = $c->getPasta();

        return !$c->isInterno()
            && $usuario->getId() !== null && $c->getDestino()->getId() === $usuario->getId()
            && $c->estaAtivo($this->relogio->now())
            && !$pasta->estaExcluida()
            && $pasta->getTenant()?->getId() === $c->getTenant()->getId()
            && !$this->situacao->excluida($c->getTenant())
            && $c->getOrigem()->isActive();
    }

    private function conviteAceito(int $id, User $usuario): ?PastaCompartilhamento
    {
        return $this->em->createQueryBuilder()
            ->select('c', 'p', 't', 'o')->from(PastaCompartilhamento::class, 'c')
            ->join('c.pasta', 'p')->join('c.tenant', 't')->join('c.origem', 'o')
            ->andWhere('c.id = :id')->andWhere('c.destino = :destino')->andWhere('c.interno = false')->andWhere('c.status = :aceita')
            ->andWhere('p.excluidaEm IS NULL')->andWhere('p.tenant = c.tenant')
            ->setParameter('id', $id)->setParameter('destino', $usuario)
            ->setParameter('aceita', EstadoDoCompartilhamento::Aceita->value)
            ->getQuery()->getOneOrNullResult();
    }

    /**
     * Os vínculos ativos de quem republicou e de quem pede com os escritórios dos convites, numa consulta.
     *
     * @param list<PastaCompartilhamento> $convites
     *
     * @return array<string, UserTenant> por "usuário:escritório"
     */
    private function vinculosAtivos(array $convites, User $usuario): array
    {
        $pessoas = [$usuario];
        $tenants = [];
        foreach ($convites as $c) {
            $pessoas[] = $c->getOrigem();
            $tenants[] = $c->getTenant();
        }
        /** @var list<UserTenant> $achados */
        $achados = $this->em->createQueryBuilder()
            ->select('ut', 'r')->from(UserTenant::class, 'ut')->leftJoin('ut.tenantRole', 'r')
            ->andWhere('ut.isActive = true')->andWhere('ut.user IN (:pessoas)')->andWhere('ut.tenant IN (:tenants)')
            ->setParameter('pessoas', $pessoas)->setParameter('tenants', $tenants)
            ->getQuery()->getResult();
        $mapa = [];
        foreach ($achados as $ut) {
            $mapa[$ut->getUser()->getId() . ':' . $ut->getTenant()->getId()] = $ut;
        }

        return $mapa;
    }

    /**
     * As abas que somem para quem recebeu: as ocultas para quem republicou e as "para todos", mesmo que quem
     * republicou seja primário ou Master (quem configura vê a própria aba oculta; quem recebeu não configura nada em A).
     *
     * @return list<string>
     */
    private function abasOcultasParaQuemRecebeu(Pasta $pasta, Tenant $tenant, User $origem): array
    {
        $ocultas = array_map(static fn (AbaDaPasta $a): string => $a->value, $this->abas->abasOcultasPara($pasta, $origem));
        foreach ($this->regrasDasAbas->daPasta($pasta, $tenant) as $regra) {
            if ($regra->isParaTodos()) {
                $ocultas[] = $regra->getAba()->value;
            }
        }

        return array_values(array_unique($ocultas));
    }

    private function getEntityManager(): EntityManagerInterface
    {
        return $this->em;
    }
}
