<?php

declare(strict_types=1);

namespace App\Termo\Repository;

use App\Entity\Auth\User;
use App\Termo\Entity\CienciaPolitica;
use App\Termo\Enum\OrigemDoAceite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CienciaPolitica>
 */
class CienciaPoliticaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CienciaPolitica::class);
    }

    public function salvar(CienciaPolitica $ciencia, bool $flush = false): void
    {
        $this->getEntityManager()->persist($ciencia);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** D2b: a ciência do cadastro deixa de valer (vira cadastro_nao_ratificado), sem apagar a linha. Na transação de quem chama. */
    public function desratificarCadastro(User $user): int
    {
        return $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE ciencia_politica SET origem = :nova WHERE user_id = :id AND origem = :velha',
            ['id' => $user->getId(), 'nova' => OrigemDoAceite::CadastroNaoRatificado->value, 'velha' => OrigemDoAceite::Cadastro->value],
        );
    }

    /**
     * D2b: a ciência `reaceite` do gate. `ON CONFLICT DO NOTHING` torna o duplo envio idempotente sem fechar o
     * EntityManager (o aceite dos Termos vem depois, no mesmo pedido). Devolve se gravou.
     */
    public function registrarReaceite(User $user, string $versao, string $ip, ?string $userAgent, \DateTimeImmutable $em): bool
    {
        return $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO ciencia_politica (user_id, versao, ip, user_agent, ciente_em, origem)
             VALUES (:id, :versao, :ip, :ua, :em, :origem) ON CONFLICT (user_id, versao, origem) DO NOTHING',
            ['id' => $user->getId(), 'versao' => $versao, 'ip' => $ip, 'ua' => $userAgent,
                'em' => $em->format('Y-m-d H:i:s'), 'origem' => OrigemDoAceite::Reaceite->value],
        ) > 0;
    }

    /**
     * A ciência da versão vigente que a pessoa tem é só a do cadastro não ratificado? Então o gate dos Termos pede a
     * Política junto. Quem não tem ciência nenhuma (contas anteriores à ciência, convites) não entra aqui.
     */
    public function precisaRatificar(User $user, string $versao): bool
    {
        $origens = $this->createQueryBuilder('c')
            ->select('c.origem')
            ->where('c.user = :user')
            ->andWhere('c.versao = :versao')
            ->setParameter('user', $user)
            ->setParameter('versao', $versao)
            ->getQuery()
            ->getSingleColumnResult();
        $origens = array_map(static fn (OrigemDoAceite|string $o): string => $o instanceof OrigemDoAceite ? $o->value : $o, $origens);

        return $origens !== [] && array_diff($origens, [OrigemDoAceite::CadastroNaoRatificado->value]) === [];
    }
}
