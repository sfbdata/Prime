<?php

declare(strict_types=1);

namespace App\Voto\Repository;

use App\Voto\Entity\Assembleia;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Enum\EstadoDaAssembleia;
use App\Voto\Enum\EstadoDaPauta;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Toda busca recebe a assembleia e filtra pelo escritório DELA, por escrito: a cédula pública roda com o TenantFilter
 * desligado e não pode depender dele.
 *
 * @extends ServiceEntityRepository<PautaDaAssembleia>
 */
// Não-final: os testes de UseCase do Voto o substituem por mock.
class PautaDaAssembleiaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PautaDaAssembleia::class);
    }

    public function salvar(PautaDaAssembleia $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(PautaDaAssembleia $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** Guarda de posse: a pauta pelo id, só se for da assembleia (e do escritório dela). */
    public function buscarDaAssembleia(int $id, Assembleia $assembleia): ?PautaDaAssembleia
    {
        return $this->findOneBy(['id' => $id, 'assembleia' => $assembleia, 'tenant' => $assembleia->getTenant()]);
    }

    /**
     * A pauta da assembleia com a linha TRAVADA (SELECT ... FOR UPDATE) e o estado relido do banco, para o voto se
     * serializar com o encerramento. Exige transação aberta (Doctrine lança TransactionRequiredException sem ela).
     */
    public function buscarTravadaDaAssembleia(int $id, Assembleia $assembleia): ?PautaDaAssembleia
    {
        /** @var PautaDaAssembleia|null $pauta */
        $pauta = $this->createQueryBuilder('p')
            ->andWhere('p.id = :id')
            ->andWhere('p.assembleia = :assembleia')
            ->andWhere('p.tenant = :tenant')
            ->setParameter('id', $id)
            ->setParameter('assembleia', $assembleia)
            ->setParameter('tenant', $assembleia->getTenant())
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $pauta;
    }

    /**
     * As pautas que a programação de horário precisa mexer agora, de todos os escritórios (é o agendador do servidor,
     * sem escritório na sessão; cada pauta carrega o seu): as aguardando com a abertura chegada e o encerramento ainda
     * por vir, e as abertas, não suspensas, com o prazo vencido. Só assembleia publicada e não arquivada; pauta retirada
     * não abre. Quem chama relê cada uma TRAVADA e confere de novo.
     *
     * @return list<PautaDaAssembleia>
     */
    public function aProgramar(\DateTimeImmutable $agora): array
    {
        /** @var list<PautaDaAssembleia> $pautas */
        $pautas = $this->createQueryBuilder('p')
            ->innerJoin('p.assembleia', 'a')
            ->andWhere('a.estado = :publicada')
            ->andWhere('a.arquivadaEm IS NULL')
            ->andWhere('p.abreEm IS NOT NULL AND p.encerraEm IS NOT NULL')
            ->andWhere('(p.estado = :aguardando AND p.retiradaEm IS NULL AND p.abreEm <= :agora AND p.encerraEm > :agora)'
                . ' OR (p.estado = :aberta AND p.suspensaEm IS NULL AND p.prazoAte IS NOT NULL AND p.prazoAte <= :agora)')
            ->setParameter('publicada', EstadoDaAssembleia::Publicada)
            ->setParameter('aguardando', EstadoDaPauta::Aguardando)
            ->setParameter('aberta', EstadoDaPauta::Aberta)
            ->setParameter('agora', $agora)
            ->orderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $pautas;
    }

    /** Relê os agregados atualizados por SQL antes de mostrar um resultado final. */
    public function relerContadores(PautaDaAssembleia $pauta): void
    {
        $this->getEntityManager()->refresh($pauta);
        foreach ($pauta->getOpcoes() as $opcao) {
            $this->getEntityManager()->refresh($opcao);
        }
    }

    /** Toda participação toca esta linha, mesmo com zero nulos, para não revelar o tipo pelo xmin. */
    public function somarNulos(PautaDaAssembleia $pauta, int $quantidade, string $peso): void
    {
        if ($quantidade < 0 || $quantidade > $pauta->getVagas() || 1 !== preg_match('/^\d{1,8}(\.\d{1,6})?$/', $peso)) {
            throw new \InvalidArgumentException('Contagem de votos nulos inválida.');
        }
        $afetadas = $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE voto.assembleia_pauta SET nulos_unidades = nulos_unidades + :quantidade,
                 nulos_peso = nulos_peso + CAST(:peso AS NUMERIC) * :quantidade
             WHERE id = :pauta AND tenant_id = :tenant',
            ['quantidade' => $quantidade, 'peso' => $peso, 'pauta' => $pauta->getId(), 'tenant' => $pauta->getTenant()->getId()],
        );
        if (1 !== $afetadas) {
            throw new \RuntimeException('A pauta do voto não foi encontrada.');
        }
    }
}
