<?php

declare(strict_types=1);

namespace App\Tests\Backup\Functional;

use App\Backup\Armazenamento\ChavesDeBackup;
use App\Backup\Command\VencerBackupsCommand;
use App\Backup\DTO\ResultadoDoBackup;
use App\Backup\Entity\PedidoDeBackup;
use App\Backup\Enum\SituacaoDoBackup;
use App\Backup\MessageHandler\GerarBackupHandler;
use App\Backup\UseCase\VencerBackupsUseCase;
use App\Entity\Tenant\Tenant;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\Scheduler\AgendaDeTarefas;
use App\Tests\Factory\Auth\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;

#[CoversClass(VencerBackupsUseCase::class)]
#[CoversClass(VencerBackupsCommand::class)]
final class VencerBackupsTest extends KernelTestCase
{
    use Factories;

    private EntityManagerInterface $em;
    private ArmazenamentoDeArquivos $armazenamento;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->armazenamento = static::getContainer()->get(ArmazenamentoDeArquivos::class);
    }

    private function escritorio(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Backup vencer ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    /** Pedido pronto com ZIP gravado, que expira `$expira` (relativo a agora). */
    private function pronto(Tenant $tenant, string $expira): PedidoDeBackup
    {
        $dono = UserFactory::createOne()->_real();
        $inicio = new \DateTimeImmutable('-9 days');
        $pedido = new PedidoDeBackup($tenant, $dono, $inicio);
        $this->em->persist($pedido);
        $this->em->flush();
        $gravado = $this->armazenamento->gravar(ChavesDeBackup::novoZip($pedido), FonteDeConteudo::deTexto('zip'));
        $pedido->iniciarMontagem($inicio);
        $pedido->concluir(new ResultadoDoBackup($gravado->chave->nome, 3, str_repeat('a', 64), 1, 0), $inicio->modify('+1 minute'), new \DateTimeImmutable($expira));
        $this->em->flush();

        return $pedido;
    }

    private function montando(Tenant $tenant, string $iniciado): PedidoDeBackup
    {
        $pedido = new PedidoDeBackup($tenant, UserFactory::createOne()->_real(), new \DateTimeImmutable('-1 day'));
        $pedido->iniciarMontagem(new \DateTimeImmutable($iniciado));
        $this->em->persist($pedido);
        $this->em->flush();

        return $pedido;
    }

    #[TestDox('Vence o pronto fora da validade e apaga o ZIP no escopo do próprio pedido; o dentro da validade fica')]
    public function testVenceEApagaOArquivo(): void
    {
        $t1 = $this->escritorio();
        $t2 = $this->escritorio();
        $velho1 = $this->pronto($t1, '-1 minute');
        $velho2 = $this->pronto($t2, '-2 hours');
        $novo = $this->pronto($t1, '+3 days');
        $chave1 = ChavesDeBackup::zip($velho1);
        $chave2 = ChavesDeBackup::zip($velho2);
        $chaveNovo = ChavesDeBackup::zip($novo);

        $resumo = static::getContainer()->get(VencerBackupsUseCase::class)->executar();

        self::assertSame(2, $resumo['vencidos']);
        $this->em->refresh($velho1);
        $this->em->refresh($velho2);
        $this->em->refresh($novo);
        self::assertSame(SituacaoDoBackup::Vencido, $velho1->getSituacao());
        self::assertSame(SituacaoDoBackup::Vencido, $velho2->getSituacao());
        self::assertSame(SituacaoDoBackup::Pronto, $novo->getSituacao());
        self::assertFalse($this->armazenamento->existe($chave1));
        self::assertFalse($this->armazenamento->existe($chave2));
        self::assertTrue($this->armazenamento->existe($chaveNovo));
        $this->armazenamento->excluir($chaveNovo);
    }

    #[TestDox('Pronto sem chave vence sem apagar nada e não trava os demais')]
    public function testProntoSemChaveNaoDerrubaOComando(): void
    {
        $tenant = $this->escritorio();
        $semChave = $this->pronto($tenant, '-1 minute');
        $normal = $this->pronto($tenant, '-1 minute');
        $chaveNormal = ChavesDeBackup::zip($normal);
        $this->em->getConnection()->executeStatement('UPDATE pedido_de_backup SET chave_arquivo = NULL WHERE id = ?', [$semChave->getId()]);
        $this->em->clear();

        $resumo = static::getContainer()->get(VencerBackupsUseCase::class)->executar();

        self::assertSame(2, $resumo['vencidos']);
        self::assertFalse($this->armazenamento->existe($chaveNormal));
        self::assertSame('vencido', $this->em->getConnection()->fetchOne('SELECT situacao FROM pedido_de_backup WHERE id = ?', [$semChave->getId()]));
    }

    #[TestDox('Montando há mais de 2 horas vira falhou; o recente não muda')]
    public function testMontandoVelhoViraFalhou(): void
    {
        $tenant = $this->escritorio();
        $velho = $this->montando($tenant, '-3 hours');
        $recente = $this->montando($tenant, '-30 minutes');

        $resumo = static::getContainer()->get(VencerBackupsUseCase::class)->executar();

        self::assertSame(1, $resumo['falhos']);
        $this->em->refresh($velho);
        $this->em->refresh($recente);
        self::assertSame(SituacaoDoBackup::Falhou, $velho->getSituacao());
        self::assertSame(GerarBackupHandler::MOTIVO_INTERROMPIDO, $velho->getErro());
        self::assertSame(SituacaoDoBackup::Montando, $recente->getSituacao());
    }

    #[TestDox('O comando app:backup:vencer existe, roda e está no agendador de hora em hora')]
    public function testComandoEAgenda(): void
    {
        $this->pronto($this->escritorio(), '-1 minute');
        $comando = (new Application(self::$kernel))->find('app:backup:vencer');
        $tester = new CommandTester($comando);

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Backups vencidos:', $tester->getDisplay());
        self::assertSame('25 * * * *', AgendaDeTarefas::tarefas()['app:backup:vencer']['cron']);
    }
}
