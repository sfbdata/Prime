<?php

declare(strict_types=1);

namespace App\Cobranca\Repository;

use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\RelatorioImportado;
use App\Cobranca\Enum\TipoRelatorioContabil;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RelatorioImportado>
 */
// Não-final: permite substituição por mock nos testes de UseCase.
class RelatorioImportadoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RelatorioImportado::class);
    }

    public function salvar(RelatorioImportado $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * O lote já lido deste arquivo, nesta versão do leitor — a checagem de idempotência (SPEC §4.2).
     *
     * O filtro de tenant é aplicado pelo `TenantFilter`, que age sobre `TenantAware`; a chave única
     * do banco cobre o mesmo par no nível do schema.
     */
    public function findOnePorHash(
        Carteira $carteira,
        string $arquivoHash,
        int $versaoLeitor,
    ): ?RelatorioImportado {
        return $this->createQueryBuilder('r')
            ->andWhere('r.carteira = :carteira')
            ->andWhere('r.arquivoHash = :hash')
            ->andWhere('r.versaoLeitor = :versao')
            ->setParameter('carteira', $carteira)
            ->setParameter('hash', $arquivoHash)
            ->setParameter('versao', $versaoLeitor)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * O lote mais recente de uma carteira, pela data em que a contabilidade fechou os números
     * (`dadosAte`), não pela data em que lemos. É `dadosAte` que ancora a calibração.
     */
    public function findUltimoDaCarteira(
        Carteira $carteira,
        TipoRelatorioContabil $tipo = TipoRelatorioContabil::Inadimplencia,
    ): ?RelatorioImportado {
        // Tenant explícito pelo mesmo motivo do irmão abaixo: este método é chamado pelos comandos do
        // espelho, em console, onde o `TenantFilter` não está ligado.
        return $this->daCarteiraComTenantExplicito($carteira, $tipo)
            ->orderBy('r.dadosAte', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * TODOS os lotes de uma carteira — insumo da régua do encargo gravado (INV-CE6).
     *
     * Existe porque `findUltimoDaCarteira()` responde a pergunta errada para quem precisa comparar o
     * GRAVADO com as colunas: o banco foi escrito pela importação de um lote específico, que quase
     * nunca é o último carregado no espelho. Ver {@see \App\Cobranca\Service\Espelho\ConferenciaDeEncargos}.
     *
     * @return list<RelatorioImportado>
     */
    public function todosDaCarteira(
        Carteira $carteira,
        TipoRelatorioContabil $tipo = TipoRelatorioContabil::Inadimplencia,
    ): array {
        /** @var list<RelatorioImportado> $lotes */
        $lotes = $this->daCarteiraComTenantExplicito($carteira, $tipo)
            ->orderBy('r.dadosAte', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $lotes;
    }

    /**
     * A base das consultas por carteira, com o **tenant explícito** — o `TenantFilter` só liga no
     * evento de request, e estas consultas rodam em console (mesmo motivo documentado em
     * `ObrigacaoRepository::emAbertoDaCarteiraAteComEncargos`).
     *
     * 🔑 **Falha FECHADO.** Uma versão anterior escrevia `if ($tenant !== null) { andWhere(...) }`, o
     * que com tenant nulo **removia o filtro** — a guarda destinada a apertar o isolamento afrouxava
     * exatamente no caso em que ela deveria valer. Aqui o parâmetro é setado sem guarda: tenant nulo
     * não casa com linha nenhuma, que é o comportamento seguro. (`Carteira.tenant` é `nullable: false`,
     * então na prática o caso não ocorre — mas a forma da guarda não pode depender disso.)
     */
    private function daCarteiraComTenantExplicito(Carteira $carteira, TipoRelatorioContabil $tipo): QueryBuilder
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.carteira = :carteira')
            ->andWhere('r.tipo = :tipo')
            ->andWhere('r.tenant = :tenant')
            ->setParameter('carteira', $carteira)
            ->setParameter('tipo', $tipo)
            ->setParameter('tenant', $carteira->getTenant());
    }
}
