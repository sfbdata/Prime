<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\EspelhoRemoto;

use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\EspelhoRemoto\Command\ReenfileirarFalhasCommand;
use App\Shared\EspelhoRemoto\Outbox\OutboxDeReplicacao;
use App\Shared\EspelhoRemoto\PoliticaDeEspelhamento;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** Saída das linhas em `falhou`: voltam à fila zeradas; a retenção continua sem apagá-las. */
#[CoversClass(ReenfileirarFalhasCommand::class)]
#[CoversClass(OutboxDeReplicacao::class)]
final class ReenfileirarFalhasTest extends KernelTestCase
{
    private Connection $conexao;
    private OutboxDeReplicacao $outbox;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conexao = static::getContainer()->get(Connection::class);
        $this->conexao->executeStatement('DELETE FROM armazenamento_replicacao');
        $this->outbox = new OutboxDeReplicacao($this->conexao, new MockClock('2026-10-24 12:00:00', 'UTC'));
    }

    #[TestDox('--simular só conta; sem ele, a falha volta a pendente com tentativas zeradas e próxima tentativa agora; --categoria filtra; saída sem nome')]
    public function testReenfileira(): void
    {
        $foto   = $this->falhou(CategoriaDeArquivo::FOTO_PERFIL, 'Foto de Antônio.jpg');
        $doc    = $this->falhou(CategoriaDeArquivo::CLIENTE_DOCUMENTO, 'CPF de Antônio.pdf');
        $tester = new CommandTester(new ReenfileirarFalhasCommand($this->outbox, new PoliticaDeEspelhamento('foto_perfil,cliente_documento')));

        self::assertSame(Command::SUCCESS, $tester->execute(['--simular' => true]));
        self::assertSame('falhou', $this->estado($foto)['estado']);

        self::assertSame(Command::SUCCESS, $tester->execute(['--categoria' => 'foto_perfil']));
        $saida = preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';
        self::assertStringContainsString('1 envio(s) de volta', $saida);
        self::assertStringNotContainsString('Antônio', $saida);
        self::assertStringNotContainsString('core/', $saida);
        self::assertSame(['estado' => 'pendente', 'tentativas' => 0, 'proxima_tentativa_em' => '2026-10-24 12:00:00'], $this->estado($foto));
        self::assertSame('falhou', $this->estado($doc)['estado'], 'outra categoria fica');

        self::assertSame(Command::INVALID, $tester->execute(['--categoria' => 'nao_existe']));
    }

    #[TestDox('só categoria ligada: sem --categoria, a falha da desligada fica; com --categoria desligada, recusa')]
    public function testSoCategoriaLigada(): void
    {
        $ligada    = $this->falhou(CategoriaDeArquivo::FOTO_PERFIL, 'a.jpg');
        $desligada = $this->falhou(CategoriaDeArquivo::CLIENTE_DOCUMENTO, 'b.pdf');
        $tester    = new CommandTester(new ReenfileirarFalhasCommand($this->outbox, new PoliticaDeEspelhamento('foto_perfil')));

        self::assertSame(Command::FAILURE, $tester->execute(['--categoria' => 'cliente_documento']));
        self::assertSame('falhou', $this->estado($desligada)['estado']);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame('pendente', $this->estado($ligada)['estado']);
        self::assertSame('falhou', $this->estado($desligada)['estado'], 'categoria desligada não se mexe');
        self::assertStringNotContainsString('cliente_documento', $tester->getDisplay());
    }

    #[TestDox('a retenção continua sem apagar falhou, por mais antiga que seja')]
    public function testRetencaoNaoApagaFalhou(): void
    {
        $id = $this->falhou(CategoriaDeArquivo::FOTO_PERFIL, 'x.jpg');
        $this->conexao->executeStatement("UPDATE armazenamento_replicacao SET atualizado_em = (now() AT TIME ZONE 'UTC') - interval '400 days' WHERE id = ?", [$id]);

        $tester = new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('armazenamento:limpar-replicacao'));
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame('falhou', $this->estado($id)['estado']);
    }

    private function falhou(CategoriaDeArquivo $categoria, string $nome): int
    {
        $escopo = $categoria === CategoriaDeArquivo::FOTO_PERFIL ? EscopoDeArquivo::global() : EscopoDeArquivo::deTenant(7);
        $id     = $this->outbox->registrarEnvio(new ChaveDeArquivo($escopo, $categoria, $nome), hash('sha256', 'x'), 1, 'application/pdf');
        $this->conexao->executeStatement("UPDATE armazenamento_replicacao SET estado = 'falhou', tentativas = 6, proxima_tentativa_em = '2026-01-01 00:00:00' WHERE id = ?", [$id]);

        return $id;
    }

    /** @return array<string, mixed> */
    private function estado(int $id): array
    {
        $linha = (array) $this->conexao->fetchAssociative('SELECT estado, tentativas, proxima_tentativa_em FROM armazenamento_replicacao WHERE id = ?', [$id]);
        $linha['tentativas'] = (int) $linha['tentativas'];

        return $linha;
    }
}
