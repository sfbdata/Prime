<?php

declare(strict_types=1);

namespace App\Cobranca\Repository;

use App\Cobranca\Entity\Acordo;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\StatusAcordo;
use App\Cobranca\Enum\StatusCaso;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Obrigacao>
 */
// Não-final: permite substituição por mock nos testes de UseCase.
class ObrigacaoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Obrigacao::class);
    }

    public function salvar(Obrigacao $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(Obrigacao $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Busca tenant-safe por id — o filtro SQL do Doctrine NÃO se aplica a find() por PK
     * (risco cross-tenant). Sempre passar o tenant explícito.
     */
    public function findOneByIdDoTenant(int $id, Tenant $tenant): ?Obrigacao
    {
        return $this->findOneBy(['id' => $id, 'tenant' => $tenant]);
    }

    /**
     * Obrigação de um caso por sua referência externa (o NN do boleto na importação — decisão C).
     * Chave de idempotência: reimportar o mesmo boleto ATUALIZA em vez de duplicar. Escopo por tenant
     * do caso (defesa em profundidade). A referência é comparada aparada; null/'' nunca casa.
     */
    public function findOnePorReferenciaExternaNoCaso(CasoCobranca $caso, string $referenciaExterna): ?Obrigacao
    {
        $referencia = trim($referenciaExterna);
        if ($referencia === '') {
            return null;
        }

        return $this->findOneBy([
            'caso' => $caso,
            'tenant' => $caso->getTenant(),
            'referenciaExterna' => $referencia,
        ]);
    }

    /**
     * Obrigações de um caso (fonte do saldo derivado — SPEC §10, invariável 20). Escopo por
     * tenant do caso (defesa em profundidade).
     *
     * @return Obrigacao[]
     */
    public function doCaso(CasoCobranca $caso): array
    {
        return $this->createQueryBuilder('o')
            // Fetch-join dos acordos: o ObrigacaoOutput lê o STATUS dos dois (substituída/parcela/acordo
            // desfeito) e o agrupamento da aba lê o id da origem — sem isto, cada obrigação ligada a um
            // acordo dispara um lazy-load (N+1). leftJoin+addSelect não filtra linha nenhuma.
            ->leftJoin('o.acordoOrigem', 'aorig')->addSelect('aorig')
            ->leftJoin('o.acordoSubstituto', 'asub')->addSelect('asub')
            ->andWhere('o.caso = :caso')
            ->andWhere('o.tenant = :tenant')
            ->setParameter('caso', $caso)
            ->setParameter('tenant', $caso->getTenant())
            ->orderBy('o.vencimentoOriginal', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Obrigações EXIGÍVEIS do caso (fonte do saldo — SPEC §12, invariável 15): exclui as substituídas
     * por um acordo VIGENTE (ativo/cumprido) e as parcelas de um acordo NÃO vigente (rompido/cancelado).
     * Assim, romper/cancelar um acordo restaura os originais e descarta as parcelas por derivação
     * (invariável 20). Parênteses explícitos: `andWhere` junta com AND sem parentetizar o OR.
     *
     * @return Obrigacao[]
     */
    public function doCasoExigiveis(CasoCobranca $caso): array
    {
        return $this->createQueryBuilder('o')
            ->leftJoin('o.acordoSubstituto', 'asub')
            ->leftJoin('o.acordoOrigem', 'aorig')
            ->andWhere('o.caso = :caso')
            ->andWhere('o.tenant = :tenant')
            ->andWhere('(asub.id IS NULL OR asub.status IN (:naoVigentes))')
            ->andWhere('(aorig.id IS NULL OR aorig.status IN (:vigentes))')
            ->setParameter('caso', $caso)
            ->setParameter('tenant', $caso->getTenant())
            ->setParameter('naoVigentes', [StatusAcordo::Rompido->value, StatusAcordo::Cancelado->value])
            ->setParameter('vigentes', [StatusAcordo::Ativo->value, StatusAcordo::Cumprido->value])
            ->orderBy('o.vencimentoOriginal', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Obrigações que um acordo NOVO pode substituir: só DÍVIDAS ORIGINAIS (ajuste 9, INV-I). É a lista que
     * a tela de criar acordo oferece — NÃO é regra de saldo.
     *
     * Reusa `doCasoExigiveis` de propósito: os critérios de exigibilidade não podem divergir em dois
     * lugares. Dentro do conjunto exigível, toda parcela é necessariamente de um acordo VIGENTE (garantia
     * da cláusula `aorig.status IN (:vigentes)` acima), logo "excluir parcela de acordo vigente" equivale
     * aqui a `acordoOrigem === null`.
     *
     * Por que existe: substituir a parcela de um acordo ainda vigente (acordo sobre acordo) duplica a
     * dívida no saldo quando o acordo de origem é rompido/cancelado — a original volta ao exigível E as
     * parcelas do acordo novo continuam nele. Renegociar um acordo se faz rompendo-o primeiro.
     *
     * @return list<Obrigacao>
     */
    public function doCasoSubstituiveis(CasoCobranca $caso): array
    {
        return array_values(array_filter(
            $this->doCasoExigiveis($caso),
            static fn (Obrigacao $obrigacao): bool => $obrigacao->getAcordoOrigem() === null,
        ));
    }

    /**
     * Parcelas DESTE acordo que um OUTRO acordo VIGENTE substituiu como dívida original — o estado
     * "acordo sobre acordo" (ajuste 9 §2.1). Vazio = o acordo pode ser rompido/cancelado sem duplicar
     * dívida no saldo.
     *
     * Existe porque romper/cancelar um acordo cujas parcelas outro acordo renegociou faz a dívida entrar
     * DUAS vezes no exigível: as originais que este acordo substituiu voltam E as parcelas do acordo novo
     * continuam. Com a criação bloqueada (INV-I) isto só ocorre em dado legado — é um alarme.
     *
     * Query única e tenant-scoped de propósito: iterar `Acordo::getParcelas()` custaria um lazy load da
     * coleção + um do `acordoSubstituto` de cada parcela.
     *
     * @return list<Obrigacao>
     */
    public function parcelasRenegociadasPorAcordoVigente(Acordo $acordo): array
    {
        return $this->createQueryBuilder('o')
            ->innerJoin('o.acordoSubstituto', 'asub')
            ->andWhere('o.acordoOrigem = :acordo')
            ->andWhere('o.tenant = :tenant')
            ->andWhere('asub.status IN (:vigentes)')
            ->setParameter('acordo', $acordo)
            ->setParameter('tenant', $acordo->getTenant())
            ->setParameter('vigentes', [StatusAcordo::Ativo->value, StatusAcordo::Cumprido->value])
            ->orderBy('o.vencimentoOriginal', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Versão em LOTE de `doCasoExigiveis` para a agregação tenant-wide (Dashboard, Etapa 9): as obrigações
     * EXIGÍVEIS de VÁRIOS casos numa única query, com o MESMO filtro de exclusão (substituídas por acordo
     * vigente / parcelas de acordo rompido-cancelado — SPEC §12, invariáveis 15/20). Evita o N+1 de chamar
     * `doCasoExigiveis` por caso. Tenant SEMPRE explícito. `$casoIds` vazio → `[]` (sem query).
     *
     * @param int[] $casoIds
     *
     * @return Obrigacao[]
     */
    public function exigiveisDosCasos(array $casoIds, Tenant $tenant): array
    {
        if ($casoIds === []) {
            return [];
        }

        return $this->createQueryBuilder('o')
            ->leftJoin('o.acordoSubstituto', 'asub')
            ->leftJoin('o.acordoOrigem', 'aorig')
            ->andWhere('o.caso IN (:casos)')
            ->andWhere('o.tenant = :tenant')
            ->andWhere('(asub.id IS NULL OR asub.status IN (:naoVigentes))')
            ->andWhere('(aorig.id IS NULL OR aorig.status IN (:vigentes))')
            ->setParameter('casos', $casoIds)
            ->setParameter('tenant', $tenant)
            ->setParameter('naoVigentes', [StatusAcordo::Rompido->value, StatusAcordo::Cancelado->value])
            ->setParameter('vigentes', [StatusAcordo::Ativo->value, StatusAcordo::Cumprido->value])
            ->orderBy('o.vencimentoOriginal', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * IDs das obrigações candidatas ao recálculo de encargos (cron da F3): NÃO congeladas
     * (`encargosCongeladosEm IS NULL`, INV-E4) e de caso NÃO encerrado (SPEC §17: caso encerrado é
     * fim de ciclo, não cresce mais). `Judicializado` NÃO é terminal — é fase viva e continua sendo
     * atualizado.
     *
     * Devolve IDs ESCALARES (não entidades) porque o cron faz `em->clear()` entre lotes: entidades
     * carregadas aqui virariam objetos destacados no lote seguinte. O id inteiro sobrevive ao clear.
     *
     * `innerJoin` no caso de propósito: obrigação ÓRFÃ (sem caso) resolveria para a config `neutra()`
     * e o cron a ZERARIA. Fora do lote é o comportamento conservador — dinheiro não se apaga por
     * navegação nula.
     *
     * Tenant SEMPRE explícito: o `TenantFilter` do Doctrine fica DESLIGADO fora de um request, então
     * no CLI este `andWhere` é a única defesa contra vazamento entre escritórios.
     *
     * ACORDO — o predicado é o de `doCasoSubstituiveis()`: exigível E que NÃO seja parcela de acordo.
     * Deliberadamente NÃO se usa `foiSubstituida()`/`participaDeAcordoVigente()`, que respondem outra
     * pergunta ("está travada para edição?") e divergem em dois pontos que custam dinheiro — ambos
     * observados em dado real de dev:
     *   1. `foiSubstituida()` ignora o STATUS do substituto, então uma original cujo acordo foi
     *      CANCELADO — que voltou ao saldo (invariável 20) — nunca mais cresceria;
     *   2. nada excluía a PARCELA de acordo cancelado/rompido, que virou histórico e não é dívida:
     *      o cron materializava encargos nela.
     *
     * `aorig.id IS NULL` (e não `aorig.status IN (:vigentes)`, como em `exigiveisDosCasos`): parcela
     * de acordo tem valor PACTUADO e não recebe mora automática. Isto é mais restritivo que a
     * exigibilidade de propósito — as duas perguntas são diferentes. "Quanto se deve hoje" inclui a
     * parcela do acordo vigente; "sobre o que o robô faz juros correrem" não, porque aquele número
     * saiu de uma negociação com o devedor. Se o acordo furar, o caminho é rompê-lo: aí as originais
     * voltam ao saldo (invariável 20) e crescem com o atraso inteiro, por este mesmo filtro.
     *
     * @return list<int>
     */
    public function idsParaAtualizarEncargos(Tenant $tenant, ?int $limite = null): array
    {
        $qb = $this->createQueryBuilder('o')
            ->select('o.id')
            ->innerJoin('o.caso', 'c')
            ->leftJoin('o.acordoSubstituto', 'asub')
            ->leftJoin('o.acordoOrigem', 'aorig')
            ->andWhere('o.encargosCongeladosEm IS NULL')
            ->andWhere('c.status != :encerrado')
            ->andWhere('o.tenant = :tenant')
            // Parênteses explícitos: `andWhere` junta com AND sem parentetizar o OR.
            ->andWhere('(asub.id IS NULL OR asub.status IN (:naoVigentes))')
            ->andWhere('aorig.id IS NULL')
            ->setParameter('encerrado', StatusCaso::Encerrado->value)
            ->setParameter('tenant', $tenant)
            ->setParameter('naoVigentes', [StatusAcordo::Rompido->value, StatusAcordo::Cancelado->value])
            // Ordem estável: o lote N do `array_chunk` tem de ser sempre o mesmo conjunto.
            ->orderBy('o.id', 'ASC');

        if ($limite !== null) {
            $qb->setMaxResults($limite);
        }

        return array_map(
            static fn (mixed $id): int => (int) $id,
            $qb->getQuery()->getSingleColumnResult(),
        );
    }

    /**
     * Obrigações de UM caso candidatas ao recálculo imediato de encargos (Ajuste 2, Fatia A): o MESMO
     * predicado do cron `idsParaAtualizarEncargos` — NÃO congeladas (`encargosCongeladosEm IS NULL`,
     * INV-E4), de caso NÃO encerrado (SPEC §17), exigíveis que NÃO sejam parcela de acordo
     * (`aorig.id IS NULL`) nem substituídas por acordo VIGENTE (`asub.id IS NULL OR asub.status IN
     * (:naoVigentes)`) —, porém ESCOPADO ao caso (`o.caso = :caso`).
     *
     * Difere do cron em dois pontos deliberados, porque aqui NÃO há `em->clear()` entre lotes:
     *  - devolve ENTIDADES managed (não ids escalares): o UseCase recalcula e flusha na MESMA unidade
     *    de trabalho, então precisa dos objetos gerenciados, não de ids;
     *  - NÃO usa `doCasoExigiveis` de propósito — aquele inclui a parcela de acordo VIGENTE, que o cron
     *    (e este recálculo) EXCLUI: valor pactuado não recebe mora automática (ver
     *    `idsParaAtualizarEncargos`).
     *
     * Tenant do caso SEMPRE explícito (defesa em profundidade). Ordem estável por id.
     *
     * @return list<Obrigacao>
     */
    public function paraRecalculoDeEncargosDoCaso(CasoCobranca $caso): array
    {
        return $this->createQueryBuilder('o')
            ->innerJoin('o.caso', 'c')
            ->leftJoin('o.acordoSubstituto', 'asub')
            ->leftJoin('o.acordoOrigem', 'aorig')
            ->andWhere('o.caso = :caso')
            ->andWhere('o.encargosCongeladosEm IS NULL')
            ->andWhere('c.status != :encerrado')
            ->andWhere('o.tenant = :tenant')
            // Parênteses explícitos: `andWhere` junta com AND sem parentetizar o OR.
            ->andWhere('(asub.id IS NULL OR asub.status IN (:naoVigentes))')
            ->andWhere('aorig.id IS NULL')
            ->setParameter('caso', $caso)
            ->setParameter('encerrado', StatusCaso::Encerrado->value)
            ->setParameter('tenant', $caso->getTenant())
            ->setParameter('naoVigentes', [StatusAcordo::Rompido->value, StatusAcordo::Cancelado->value])
            ->orderBy('o.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Carrega um LOTE de obrigações com caso, objeto e carteira em join fetch. Sem isso o
     * `ResolvedorConfigEncargos` (que sobe a cascata Obrigação → Caso → Objeto → Carteira) dispara
     * ~3 queries de lazy-load POR obrigação — o cron rodaria milhares de queries por rodada.
     *
     * `leftJoin` aqui (e não `innerJoin`): a seleção de quem entra no lote já foi feita em
     * `idsParaAtualizarEncargos`; este método só materializa o grafo, sem re-filtrar linha nenhuma.
     *
     * Tenant SEMPRE explícito: defesa em profundidade contra um id de outro escritório entrar na
     * lista (o filtro automático não existe no CLI). `$ids` vazio → `[]` sem query.
     *
     * @param list<int> $ids
     *
     * @return list<Obrigacao>
     */
    public function loteParaAtualizarEncargos(array $ids, Tenant $tenant): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->createQueryBuilder('o')
            ->leftJoin('o.caso', 'c')->addSelect('c')
            ->leftJoin('c.objeto', 'ob')->addSelect('ob')
            ->leftJoin('ob.carteira', 'cart')->addSelect('cart')
            ->andWhere('o.id IN (:ids)')
            ->andWhere('o.tenant = :tenant')
            ->setParameter('ids', $ids)
            ->setParameter('tenant', $tenant)
            ->orderBy('o.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
