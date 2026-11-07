<?php

declare(strict_types=1);

namespace App\Ponto\Repository;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\JornadaColaborador;
use App\Ponto\Service\HistoricoDeJornadas;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;

/**
 * Sem `final`: os testes de serviço e de UseCase mockam este repositório (skill criar-repository).
 */
class JornadaColaboradorRepository extends ServiceEntityRepository
{
    use ConsultaSemFiltroDeEscritorio;

    public function __construct(ManagerRegistry $registry, private readonly ClockInterface $relogio)
    {
        parent::__construct($registry, JornadaColaborador::class);
    }

    /**
     * A jornada pessoal da pessoa NAQUELE escritório (spec seguranca-b-jornada §3.2), na versão que vale HOJE (spec
     * ponto-jornada-vigencia §3.2): a tela do ponto, o alerta, o choque e a edição leem esta. Quem varre dias do
     * passado usa `historicoDe()`. O escritório vai escrito na consulta, então ela não depende do filtro de escritório:
     * funciona do escritório da URL, da folha e da batida, e também para o choque, que lê a jornada da mesma pessoa em
     * outro escritório.
     */
    public function findByUserETenant(User $user, Tenant $tenant): ?JornadaColaborador
    {
        return $this->historicoDe($user, $tenant)?->vigenteEm($this->relogio->now());
    }

    /** Todas as versões da jornada pessoal da pessoa naquele escritório, com os blocos, numa consulta só. */
    public function historicoDe(User $user, Tenant $tenant): ?HistoricoDeJornadas
    {
        /** @var list<JornadaColaborador> $versoes */
        $versoes = $this->semFiltroDeEscritorio(fn (): array => $this->createQueryBuilder('j')
            ->leftJoin('j.blocos', 'b')
            ->addSelect('b')
            ->andWhere('j.user = :user')
            ->andWhere('j.tenant = :tenant')
            ->setParameter('user', $user)
            ->setParameter('tenant', $tenant)
            ->orderBy('j.valeAPartirDe', 'ASC')
            // Ordem fixa dos blocos: o cabeçalho do PDF/XLSX mostra o horário do primeiro bloco com dia útil.
            ->addOrderBy('b.id', 'ASC')
            ->getQuery()
            ->getResult());

        return HistoricoDeJornadas::das($versoes);
    }

    public function salvar(JornadaColaborador $jornada, bool $flush = false): void
    {
        $this->getEntityManager()->persist($jornada);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(JornadaColaborador $jornada): void
    {
        $this->getEntityManager()->remove($jornada);
    }

    /** Grava o que os UseCases da jornada mudaram (versões novas, editadas e removidas) numa transação só. */
    public function gravar(): void
    {
        $this->getEntityManager()->flush();
    }
}
