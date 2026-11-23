<?php

declare(strict_types=1);

namespace App\Tests\Backup\Functional;

use App\Backup\Armazenamento\ChavesDeBackup;
use App\Backup\Coletor\ColetorDoBackup;
use App\Backup\Entity\PedidoDeBackup;
use App\Backup\Enum\SituacaoDoBackup;
use App\Backup\Message\GerarBackup;
use App\Backup\MessageHandler\GerarBackupHandler;
use App\Backup\Service\ZipDoBackup;
use App\Backup\UseCase\MontarBackupUseCase;
use App\Backup\UseCase\PedirBackupUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Service\NotificacaoService;
use App\Shared\Armazenamento\CategoriaComIsolamentoFisico;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoriaNoContainer;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Zenstruck\Foundry\Test\Factories;

#[CoversClass(GerarBackupHandler::class)]
#[CoversClass(PedirBackupUseCase::class)]
final class GerarBackupHandlerTest extends KernelTestCase
{
    use Factories;

    private const AGORA = '2026-11-23 10:00:00';

    private EntityManagerInterface $em;
    private ArmazenamentoEmMemoriaNoContainer $duble;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->duble = ArmazenamentoEmMemoriaNoContainer::instalarEm(static::getContainer());
    }

    private function escritorio(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Backup fila ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function pedido(Tenant $tenant, User $dono): PedidoDeBackup
    {
        $pedido = new PedidoDeBackup($tenant, $dono, new \DateTimeImmutable(self::AGORA));
        $this->em->persist($pedido);
        $this->em->flush();

        return $pedido;
    }

    /** @param iterable<ColetorDoBackup> $coletores */
    private function handler(iterable $coletores = [], ?EntityManagerInterface $em = null): GerarBackupHandler
    {
        $c = static::getContainer();
        $relogio = new MockClock(self::AGORA);

        return new GerarBackupHandler(
            $this->em->getRepository(PedidoDeBackup::class),
            new MontarBackupUseCase($this->duble->memoria, $this->duble->memoria, $relogio, $coletores),
            new RemocaoAposTransacao($this->duble->memoria, new NullLogger()),
            $c->get(NotificacaoService::class),
            $em ?? $this->em,
            static::getContainer()->get('doctrine'),
            $relogio,
            new NullLogger(),
        );
    }

    private function sinos(User $usuario, string $tipo): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM notificacao WHERE usuario_id = ? AND tipo = ?', [$usuario->getId(), $tipo]);
    }

    private function falhoNaMontagem(): ColetorDoBackup
    {
        return new class () implements ColetorDoBackup {
            public function coletar(User $usuario, Tenant $tenant, ZipDoBackup $zip): void
            {
                throw new \RuntimeException('Cliente SIGILOSO Fulano de Tal');
            }
        };
    }

    #[TestDox('Caminho feliz: pronto, ZIP gravado, expira em 7 dias e avisa no sino')]
    public function testCaminhoFeliz(): void
    {
        $dono = UserFactory::createOne()->_real();
        $tenant = $this->escritorio();
        $pedido = $this->pedido($tenant, $dono);

        ($this->handler())(new GerarBackup((int) $pedido->getId()));

        $this->em->refresh($pedido);
        self::assertSame(SituacaoDoBackup::Pronto, $pedido->getSituacao());
        self::assertEquals(new \DateTimeImmutable('2026-11-30 10:00:00'), $pedido->getExpiraEm());
        self::assertTrue($this->duble->memoria->existe(ChavesDeBackup::zip($pedido)));
        self::assertSame(1, $this->sinos($dono, GerarBackupHandler::TIPO_PRONTO));
        self::assertSame('/perfil#mpBackup', $this->em->getConnection()->fetchOne('SELECT url FROM notificacao WHERE usuario_id = ? AND tipo = ?', [$dono->getId(), GerarBackupHandler::TIPO_PRONTO]));
    }

    #[TestDox('Reentrega em montando vira falhou e não monta de novo')]
    public function testReentregaEmMontando(): void
    {
        $dono = UserFactory::createOne()->_real();
        $pedido = $this->pedido($this->escritorio(), $dono);
        $pedido->iniciarMontagem(new \DateTimeImmutable(self::AGORA));
        $this->em->flush();

        ($this->handler())(new GerarBackup((int) $pedido->getId()));

        $this->em->refresh($pedido);
        self::assertSame(SituacaoDoBackup::Falhou, $pedido->getSituacao());
        self::assertSame(GerarBackupHandler::MOTIVO_INTERROMPIDO, $pedido->getErro());
        self::assertNull($pedido->getChaveArquivo());
        self::assertSame(1, $this->sinos($dono, GerarBackupHandler::TIPO_FALHOU));
    }

    #[TestDox('Erro na montagem vira falhou com motivo genérico, sem relançar e sem arquivo órfão')]
    public function testErroNaMontagem(): void
    {
        $dono = UserFactory::createOne()->_real();
        $pedido = $this->pedido($this->escritorio(), $dono);

        ($this->handler([$this->falhoNaMontagem()]))(new GerarBackup((int) $pedido->getId()));

        $this->em->refresh($pedido);
        self::assertSame(SituacaoDoBackup::Falhou, $pedido->getSituacao());
        self::assertStringNotContainsString('SIGILOSO', (string) $pedido->getErro());
        self::assertNull($pedido->getChaveArquivo());
        self::assertSame([], iterator_to_array($this->duble->memoria->listar(EscopoDeArquivo::deTenant((int) $pedido->getTenant()->getId()), CategoriaComIsolamentoFisico::BACKUP_ZIP)));
        self::assertSame(1, $this->sinos($dono, GerarBackupHandler::TIPO_FALHOU));
    }

    /** EntityManager que, na chamada `$falharNa` de flush, FECHA o de verdade e lança (como um erro de banco). */
    private function emQueFechaNoFlush(int $falharNa): EntityManagerInterface
    {
        return new class ($this->em, $falharNa) extends EntityManagerDecorator {
            public int $chamadas = 0;

            public function __construct(EntityManagerInterface $em, private readonly int $falharNa)
            {
                parent::__construct($em);
            }

            public function flush(): void
            {
                if (++$this->chamadas === $this->falharNa) {
                    $this->wrapped->close();

                    throw new \RuntimeException('banco caiu: arquivo-do-cliente.pdf');
                }
                parent::flush();
            }
        };
    }

    #[TestDox('Flush do pronto falha com EM fechado: apaga o ZIP, reabre, marca falhou, avisa, e a mensagem seguinte funciona')]
    public function testFlushDoProntoFalhaApagaOrfaoEMarcaFalhou(): void
    {
        $dono = UserFactory::createOne()->_real();
        $pedido = $this->pedido($this->escritorio(), $dono);
        $proximo = $this->pedido($this->escritorio(), UserFactory::createOne()->_real());
        $escopo = EscopoDeArquivo::deTenant((int) $pedido->getTenant()->getId());
        $handler = $this->handler([], $this->emQueFechaNoFlush(2)); // 1ª: montando; 2ª: pronto

        $handler(new GerarBackup((int) $pedido->getId()));

        self::assertSame([], iterator_to_array($this->duble->memoria->listar($escopo, CategoriaComIsolamentoFisico::BACKUP_ZIP)), 'ZIP órfão ficou');
        $linha = $this->em->getConnection()->fetchAssociative('SELECT situacao, erro FROM pedido_de_backup WHERE id = ?', [$pedido->getId()]);
        self::assertSame('falhou', $linha['situacao']);
        self::assertStringNotContainsString('arquivo-do-cliente', (string) $linha['erro']);
        self::assertSame(1, $this->sinos($dono, GerarBackupHandler::TIPO_FALHOU));

        $handler(new GerarBackup((int) $proximo->getId()));
        $situacao = $this->em->getConnection()->fetchOne('SELECT situacao FROM pedido_de_backup WHERE id = ?', [$proximo->getId()]);
        self::assertSame('pronto', $situacao, 'a mensagem seguinte no mesmo handler precisa funcionar');
    }

    #[TestDox('Erro de banco ao iniciar a montagem (EM fechado) vira falhou com sino')]
    public function testEmFechadoAoIniciar(): void
    {
        $dono = UserFactory::createOne()->_real();
        $pedido = $this->pedido($this->escritorio(), $dono);

        ($this->handler([], $this->emQueFechaNoFlush(1)))(new GerarBackup((int) $pedido->getId()));

        $situacao = $this->em->getConnection()->fetchOne('SELECT situacao FROM pedido_de_backup WHERE id = ?', [$pedido->getId()]);
        self::assertSame('falhou', $situacao);
        self::assertSame(1, $this->sinos($dono, GerarBackupHandler::TIPO_FALHOU));
    }

    #[TestDox('Pedido inexistente ou já terminado é ignorado')]
    public function testIgnoraInexistenteETerminado(): void
    {
        $dono = UserFactory::createOne()->_real();
        $pedido = $this->pedido($this->escritorio(), $dono);
        $pedido->falhar('x', new \DateTimeImmutable(self::AGORA));
        $this->em->flush();

        ($this->handler())(new GerarBackup(2147483000));
        ($this->handler())(new GerarBackup((int) $pedido->getId()));

        self::assertSame('x', $pedido->getErro());
        self::assertSame(0, $this->sinos($dono, GerarBackupHandler::TIPO_PRONTO));
    }

    #[TestDox('Dois pedidos de escritórios diferentes: o ZIP e o aviso de cada um ficam no seu escritório')]
    public function testNaoMisturaEscritorios(): void
    {
        $dono = UserFactory::createOne()->_real();
        $t1 = $this->escritorio();
        $t2 = $this->escritorio();
        $p1 = $this->pedido($t1, $dono);
        $p2 = $this->pedido($t2, $dono);

        ($this->handler())(new GerarBackup((int) $p1->getId()));

        $this->em->refresh($p2);
        self::assertSame(SituacaoDoBackup::Pedido, $p2->getSituacao());
        self::assertSame([], iterator_to_array($this->duble->memoria->listar(EscopoDeArquivo::deTenant((int) $t2->getId()), CategoriaComIsolamentoFisico::BACKUP_ZIP)));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM notificacao WHERE tenant_id = ? AND tipo = ?', [$t1->getId(), GerarBackupHandler::TIPO_PRONTO]));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM notificacao WHERE tenant_id = ?', [$t2->getId()]));
    }

    #[TestDox('Pedir backup despacha GerarBackup no transport async')]
    public function testPedirDespachaNoAsync(): void
    {
        $dono = UserFactory::createOne()->_real();
        $tenant = $this->escritorio();

        $c = static::getContainer();
        $pedido = (new PedirBackupUseCase($this->em->getRepository(PedidoDeBackup::class), $this->em, $c->get(MessageBusInterface::class), new MockClock(self::AGORA)))->executar($dono, $tenant);

        /** @var InMemoryTransport $async */
        $async = static::getContainer()->get('messenger.transport.async');
        $enviadas = $async->getSent();
        self::assertCount(1, $enviadas);
        $mensagem = $enviadas[0]->getMessage();
        self::assertInstanceOf(GerarBackup::class, $mensagem);
        self::assertSame($pedido->getId(), $mensagem->pedidoId);
    }

    #[TestDox('Pedir backup toma a trava advisory da pessoa na transação (e o limite do dia vale)')]
    public function testPedirTomaATravaEBarraOSegundo(): void
    {
        $dono = UserFactory::createOne()->_real();
        $tenant = $this->escritorio();
        $c = static::getContainer();
        $pedir = new PedirBackupUseCase($this->em->getRepository(PedidoDeBackup::class), $this->em, $c->get(MessageBusInterface::class), new MockClock(self::AGORA));

        $pedir->executar($dono, $tenant);

        // A trava é xact: segue no backend enquanto a transação do teste (DAMA) está aberta, o que prova que rodou dentro de transação.
        $travas = (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM pg_locks WHERE locktype = \'advisory\' AND classid = ? AND pid = pg_backend_pid()',
            [PedirBackupUseCase::CLASSE_TRAVA],
        );
        self::assertSame(1, $travas);

        $this->expectException(\App\Backup\Exception\PedidoDeBackupNaoPermitido::class);
        $pedir->executar($dono, $tenant);
    }
}
