<?php

declare(strict_types=1);

namespace App\Cobranca\Repository;

use App\Cobranca\Entity\Acordo;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\StatusAcordo;
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
}
