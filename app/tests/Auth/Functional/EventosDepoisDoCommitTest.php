<?php

declare(strict_types=1);

namespace App\Tests\Auth\Functional;

use App\Auth\DTO\AceitarConviteEscritorioSemContaInput;
use App\Auth\Entity\CadastroPendente;
use App\Auth\Event\CadastroConfirmado;
use App\Auth\Event\ConviteDeEscritorioAceito;
use App\Auth\UseCase\AceitarConviteEscritorioSemContaUseCase;
use App\Auth\UseCase\ConfirmarCadastroUseCase;
use App\Entity\Auth\Invitation;
use App\Entity\Tenant\Tenant;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Prova do "depois do commit" dos ganchos de Auth (spec comercial-e-emails.md §3.5, M-b), contra o banco. Com a DAMA o
 * DBAL não enxerga a transação de fora: o nível de base é 0, e dentro do wrapInTransaction do ConfirmarCadastro seria
 * 1. Nos aceites, sem transação, a prova é o efeito do último flush (o aceite dos termos) já gravado no momento do
 * evento. E um ouvinte que lança não derruba o fluxo de Auth.
 */
#[CoversClass(ConfirmarCadastroUseCase::class)]
#[CoversClass(AceitarConviteEscritorioSemContaUseCase::class)]
final class EventosDepoisDoCommitTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $conexao;
    private EventDispatcherInterface $eventos;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->conexao = static::getContainer()->get(Connection::class);
        $this->eventos = static::getContainer()->get(EventDispatcherInterface::class);
    }

    #[TestDox('ConfirmarCadastro: o CadastroConfirmado sai fora da transação (nível 0), com a conta já gravada')]
    public function testCadastroConfirmadoForaDaTransacao(): void
    {
        $email = 'commit_' . uniqid() . '@test.com';
        $this->em->persist(new CadastroPendente($email, 'tok' . uniqid(), 'Ana Commit', 'Banca Commit', '12345', 'SP', 'HASH', '1.2.3.4', new \DateTimeImmutable('+1 hour'), 'UA'));
        $this->em->flush();
        $token = (string) $this->conexao->fetchOne('SELECT token FROM cadastro_pendente WHERE email = ?', [$email]);
        $visto = [];
        $this->eventos->addListener(CadastroConfirmado::class, function (CadastroConfirmado $e) use (&$visto, $email): void {
            $visto = [
                'nivel' => $this->conexao->getTransactionNestingLevel(),
                'conta' => (int) $this->conexao->fetchOne('SELECT COUNT(*) FROM "user" WHERE email = ?', [$email]),
            ];
        });

        static::getContainer()->get(ConfirmarCadastroUseCase::class)->executar($token);

        self::assertSame(['nivel' => 0, 'conta' => 1], $visto);
    }

    #[TestDox('Aceite sem conta: o ConviteDeEscritorioAceito sai depois do último flush (o aceite dos termos já gravado)')]
    public function testAceiteDepoisDoUltimoFlush(): void
    {
        [$token, $email] = $this->conviteDeEscritorio();
        $visto = null;
        $this->eventos->addListener(ConviteDeEscritorioAceito::class, function (ConviteDeEscritorioAceito $e) use (&$visto): void {
            $visto = (int) $this->conexao->fetchOne('SELECT COUNT(*) FROM aceite_termo WHERE user_id = ?', [$e->userId]);
        });

        static::getContainer()->get(AceitarConviteEscritorioSemContaUseCase::class)
            ->executar(new AceitarConviteEscritorioSemContaInput($token, 'Bia Convite', 'senha12345', aceiteTermos: true, ip: '1.2.3.4', genero: 'feminino'));

        self::assertSame(1, $visto, 'o aceite dos termos já está no banco quando o evento chega');
        self::assertSame(1, (int) $this->conexao->fetchOne('SELECT COUNT(*) FROM "user" WHERE email = ?', [$email]));
    }

    #[TestDox('Um ouvinte que lança não derruba o aceite: a conta nasce mesmo assim (o do Comercial captura tudo)')]
    public function testOuvinteQueLancaNaoDerrubaOAceite(): void
    {
        [$token, $email] = $this->conviteDeEscritorio();
        $this->conexao->executeStatement("UPDATE comercial_automacao SET ligada = true WHERE chave = 'conv'");
        $this->conexao->executeStatement('ALTER TABLE comercial_ciclo_conta RENAME TO comercial_ciclo_conta_fora');
        try {
            static::getContainer()->get(AceitarConviteEscritorioSemContaUseCase::class)
                ->executar(new AceitarConviteEscritorioSemContaInput($token, 'Caio Convite', 'senha12345', aceiteTermos: true, genero: 'masculino'));
        } finally {
            $this->conexao->executeStatement('ALTER TABLE comercial_ciclo_conta_fora RENAME TO comercial_ciclo_conta');
        }

        self::assertSame(1, (int) $this->conexao->fetchOne('SELECT COUNT(*) FROM "user" WHERE email = ?', [$email]));
    }

    /** @return array{0: string, 1: string} */
    private function conviteDeEscritorio(): array
    {
        $tenant = (new Tenant())->setName('Escritório Convite ' . uniqid());
        $email = 'convite_' . uniqid() . '@test.com';
        $token = bin2hex(random_bytes(16));
        $convite = (new Invitation(email: $email, token: $token, type: 'office', expiresAt: new \DateTimeImmutable('+7 days')))->setTenant($tenant);
        $this->em->persist($tenant);
        $this->em->persist($convite);
        $this->em->flush();

        return [$token, $email];
    }
}
