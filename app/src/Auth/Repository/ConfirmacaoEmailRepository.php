<?php

declare(strict_types=1);

namespace App\Auth\Repository;

use App\Auth\Entity\ConfirmacaoEmail;
use App\Entity\Auth\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ConfirmacaoEmail>
 */
class ConfirmacaoEmailRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConfirmacaoEmail::class);
    }

    public function encontrarPorToken(string $token): ?ConfirmacaoEmail
    {
        return $this->findOneBy(['tokenHash' => ConfirmacaoEmail::hashDoToken($token)]);
    }

    /** O lembrete automático é único por conta: este é o registro de que ele já saiu. */
    public function existeLembreteDe(User $user): bool
    {
        return $this->count(['user' => $user, 'lembrete' => true]) > 0;
    }

    /**
     * Confirma pelo link numa transação só: zera a pendência da conta num UPDATE condicional e marca o link usado.
     * Com dois cliques simultâneos (o pré-carregamento do cliente de e-mail faz isso), só um ganha, e só ele
     * despacha o EmailDaContaConfirmado; e nunca fica conta confirmada com o link sem a marca de uso.
     *
     * @return bool true se FOI ESTA chamada que confirmou
     */
    public function confirmarComLink(ConfirmacaoEmail $confirmacao, \DateTimeImmutable $agora): bool
    {
        return $this->getEntityManager()->getConnection()->transactional(
            static function (Connection $conexao) use ($confirmacao, $agora): bool {
                $ganhou = $conexao->executeStatement(
                    'UPDATE "user" SET email_a_confirmar_desde = NULL WHERE id = :id AND email_a_confirmar_desde IS NOT NULL',
                    ['id' => $confirmacao->getUser()->getId()],
                ) === 1;
                if ($ganhou) {
                    $conexao->executeStatement(
                        'UPDATE confirmacao_email SET usado_em = :agora WHERE id = :id AND usado_em IS NULL',
                        ['id' => $confirmacao->getId(), 'agora' => $agora->format('Y-m-d H:i:s')],
                    );
                }

                return $ganhou;
            },
        );
    }

    /**
     * Saiu algum link de confirmação para a conta desde `$desde` (cadastro, reenvio ou lembrete)? É o teto curto do
     * botão "Reenviar o link de confirmação", e só o controller dele chama: a automação da 5d não passa por aqui.
     */
    public function houveEmissaoDesde(User $user, \DateTimeImmutable $desde): bool
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.user = :user')
            ->andWhere('c.criadoEm >= :desde')
            ->setParameter('user', $user)
            ->setParameter('desde', $desde)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * Confirma o e-mail da conta por outro caminho que não o link (o código da troca de senha), num UPDATE condicional
     * na conexão corrente: roda dentro da transação de quem chama. Só uma chamada ganha, inclusive contra o clique no
     * link ao mesmo tempo, e só quem ganhou despacha o EmailDaContaConfirmado.
     *
     * @return bool true se FOI ESTA chamada que confirmou (a conta estava com o e-mail a confirmar)
     */
    public function confirmarEmailDaConta(User $user): bool
    {
        return $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE "user" SET email_a_confirmar_desde = NULL WHERE id = :id AND email_a_confirmar_desde IS NOT NULL',
            ['id' => $user->getId()],
        ) === 1;
    }

    public function salvar(ConfirmacaoEmail $confirmacao, bool $flush = false): void
    {
        $this->getEntityManager()->persist($confirmacao);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
