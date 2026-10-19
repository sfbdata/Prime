<?php

declare(strict_types=1);

namespace App\Tests\Auth\Cenario;

use App\Auth\Entity\AjusteEntrada;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Montagem comum dos testes do suporte e das ferramentas SÓ MASTER da tela de entrada (desenho 10): pessoas com e
 * sem escritório, a escolha de quem recebe o suporte e a leitura do sino.
 *
 * O banco de teste é um só para a suíte (DAMA desfaz cada teste): os ajustes da tela de entrada são globais, então
 * cada teste começa apagando o que importa para ele.
 */
trait CenarioDaEntrada
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** @param list<string> $papeis */
    private function pessoa(string $nome, array $papeis = ['ROLE_USER'], bool $ativa = true, bool $comEscritorio = true): User
    {
        $user = new User();
        $user->setEmail(uniqid('entrada_') . '@adv.com');
        $user->setFullName($nome);
        $user->setRoles($papeis);
        $user->setIsActive($ativa);
        $user->setPassword('x');
        $this->em()->persist($user);

        if ($comEscritorio) {
            $escritorio = new Tenant();
            $escritorio->setName('Escritório ' . uniqid());
            $this->em()->persist($escritorio);
            $this->em()->persist(new UserTenant($user, $escritorio));
        }
        $this->em()->flush();

        return $user;
    }

    private function master(string $nome = 'Marta Master', bool $ativa = true, bool $comEscritorio = true): User
    {
        return $this->pessoa($nome, ['ROLE_SUPER_ADMIN'], $ativa, $comEscritorio);
    }

    /** Uma pessoa comum no mesmo escritório de `$de` (a equipe que o Master vê nas opções). */
    private function colega(User $de, string $nome): User
    {
        $colega = $this->pessoa($nome, comEscritorio: false);
        $escritorio = $this->em()->find(Tenant::class, $this->escritorioDe($de));
        $this->em()->persist(new UserTenant($colega, $escritorio));
        $this->em()->flush();

        return $colega;
    }

    /** Apaga todos os ajustes da tela de entrada: volta tudo ao padrão do desenho, sem destinatário escolhido. */
    private function limparAjustesDaEntrada(): void
    {
        $this->em()->createQuery('DELETE FROM ' . AjusteEntrada::class . ' a')->execute();
        $this->em()->clear();
    }

    private function escolherDestinatario(User $user): void
    {
        $user = $this->em()->find(User::class, $user->getId());
        $ajuste = new AjusteEntrada(AjusteEntrada::SUPORTE_DESTINATARIO);
        $ajuste->definirValor((string) $user->getId(), $user, new \DateTimeImmutable());
        $this->em()->persist($ajuste);
        $this->em()->flush();
    }

    /**
     * Pelo banco, sem o ORM: o filtro de tenant pode estar ligado depois de uma requisição.
     *
     * @return list<array{tipo: string, mensagem: string, url: ?string, tenant_id: int}>
     */
    private function avisosDoSuporte(User $user): array
    {
        return $this->em()->getConnection()->fetchAllAssociative(
            'SELECT tipo, mensagem, url, tenant_id FROM notificacao WHERE usuario_id = :id AND tipo = :tipo ORDER BY id',
            ['id' => $user->getId(), 'tipo' => 'suporte.mensagem'],
        );
    }

    /** O id do primeiro escritório do usuário, para conferir onde caiu o aviso. */
    private function escritorioDe(User $user): int
    {
        return (int) $this->em()->getConnection()->fetchOne(
            'SELECT MIN(tenant_id) FROM user_tenant WHERE user_id = :id',
            ['id' => $user->getId()],
        );
    }
}
