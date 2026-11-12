<?php

declare(strict_types=1);

namespace App\Voto\Repository;

use App\Voto\Entity\Assembleia;
use App\Voto\Entity\ParticipacaoNaPauta;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Entity\UnidadeDaAssembleia;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Quem votou em cada pauta (unidade, pauta, hora). Nada aqui diz em quê: a opção nunca está ligada à participação
 * (spec voto-assembleia-condominio, seção 2). Toda consulta filtra pelo escritório da assembleia/pauta, por escrito.
 *
 * @extends ServiceEntityRepository<ParticipacaoNaPauta>
 */
// Não-final: os testes de UseCase do Voto o substituem por mock.
class ParticipacaoNaPautaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ParticipacaoNaPauta::class);
    }

    public function salvar(ParticipacaoNaPauta $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Por pauta da assembleia (todas, inclusive as sem voto): unidades que votaram e a soma do peso delas, com 6 casas
     * em string (fração da unidade, 0 se faltar, ou 1 por unidade). Chave: id da pauta.
     *
     * @return array<int, array{unidades: int, peso: string}>
     */
    public function contarPorPauta(Assembleia $assembleia): array
    {
        $linhas = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            "SELECT p.id AS pauta_id, COUNT(pp.id) AS unidades,
                    COALESCE(SUM(CASE WHEN a.peso = 'unidade' THEN 1 ELSE COALESCE(u.fracao, 0) END), 0) AS peso
               FROM voto.assembleia_pauta p
               JOIN voto.assembleia a ON a.id = p.assembleia_id AND a.tenant_id = p.tenant_id
          LEFT JOIN voto.assembleia_participacao pp ON pp.pauta_id = p.id AND pp.tenant_id = p.tenant_id
          LEFT JOIN voto.assembleia_unidade u ON u.id = pp.unidade_id AND u.tenant_id = p.tenant_id
              WHERE p.assembleia_id = :assembleia AND p.tenant_id = :tenant
           GROUP BY p.id",
            ['assembleia' => $assembleia->getId(), 'tenant' => $assembleia->getTenant()->getId()],
        );
        $contagem = [];
        foreach ($linhas as $linha) {
            $contagem[(int) $linha['pauta_id']] = [
                'unidades' => (int) $linha['unidades'],
                'peso' => bcadd((string) $linha['peso'], '0', 6),
            ];
        }

        return $contagem;
    }

    /**
     * Lista nominal de quem votou na pauta, por bloco e identificação, com a unidade junto. Só quem e quando; nunca o
     * voto.
     *
     * @return list<ParticipacaoNaPauta>
     */
    public function listarNominalDaPauta(PautaDaAssembleia $pauta): array
    {
        /** @var list<ParticipacaoNaPauta> $participacoes */
        $participacoes = $this->createQueryBuilder('pp')
            ->addSelect('u')
            ->innerJoin('pp.unidade', 'u')
            ->andWhere('pp.pauta = :pauta')
            ->andWhere('pp.tenant = :tenant')
            ->setParameter('pauta', $pauta)
            ->setParameter('tenant', $pauta->getTenant())
            ->orderBy('u.bloco', 'ASC')
            ->addOrderBy('u.identificacao', 'ASC')
            ->addOrderBy('u.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $participacoes;
    }

    /**
     * Participações da assembleia para conferir um comprovante: unidade, pauta, título da pauta e hora (nunca a opção,
     * que a participação não guarda).
     *
     * @return list<array{unidadeId: int, pautaId: int, pauta: string, registradaEm: \DateTimeImmutable}>
     */
    public function paraConferencia(Assembleia $assembleia): array
    {
        /** @var list<array{unidadeId: int|string, pautaId: int|string, pauta: string, registradaEm: \DateTimeImmutable}> $linhas */
        $linhas = $this->createQueryBuilder('p')
            ->select('IDENTITY(p.unidade) AS unidadeId', 'pa.id AS pautaId', 'pa.titulo AS pauta', 'p.registradaEm AS registradaEm')
            ->join('p.pauta', 'pa')
            ->where('pa.assembleia = :assembleia')
            ->setParameter('assembleia', $assembleia)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $l): array => [
            'unidadeId' => (int) $l['unidadeId'],
            'pautaId' => (int) $l['pautaId'],
            'pauta' => $l['pauta'],
            'registradaEm' => $l['registradaEm'],
        ], $linhas);
    }

    public function jaVotou(UnidadeDaAssembleia $unidade, PautaDaAssembleia $pauta): bool
    {
        return null !== $this->findOneBy(['unidade' => $unidade, 'pauta' => $pauta, 'tenant' => $unidade->getTenant()]);
    }

    /**
     * Quando a unidade votou em cada pauta ("Você já votou nesta pauta." com a hora). Chave: id da pauta.
     *
     * @return array<int, \DateTimeImmutable>
     */
    public function horariosDaUnidade(UnidadeDaAssembleia $unidade): array
    {
        $horarios = [];
        foreach ($this->findBy(['unidade' => $unidade, 'tenant' => $unidade->getTenant()]) as $participacao) {
            $horarios[(int) $participacao->getPauta()->getId()] = $participacao->getRegistradaEm();
        }

        return $horarios;
    }
}
