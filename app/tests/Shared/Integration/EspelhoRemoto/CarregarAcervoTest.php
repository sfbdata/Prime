<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\EspelhoRemoto;

use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\EspelhoRemoto\Acervo\CarregadorDoAcervo;
use App\Shared\EspelhoRemoto\Acervo\ResumoDaCarga;
use App\Shared\EspelhoRemoto\Command\CarregarAcervoCommand;
use App\Shared\EspelhoRemoto\Outbox\OutboxDeReplicacao;
use App\Shared\EspelhoRemoto\PoliticaDeEspelhamento;
use App\Shared\EspelhoRemoto\Reconciliacao\ClasseDeReconciliacao;
use App\Shared\EspelhoRemoto\Reconciliacao\FonteDeReferencias;
use App\Shared\EspelhoRemoto\Reconciliacao\ReconciliadorDoEspelho;
use App\Shared\EspelhoRemoto\Reconciliacao\ReferenciaDeArquivo;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoria;
use App\Tests\Shared\Doubles\EspelhoRemotoEmMemoria;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** A carga do acervo existente (P2.6): só a categoria pedida e ligada, idempotente, só contagens na saída. */
#[CoversClass(CarregadorDoAcervo::class)]
#[CoversClass(ResumoDaCarga::class)]
#[CoversClass(CarregarAcervoCommand::class)]
final class CarregarAcervoTest extends KernelTestCase
{
    private Connection $conexao;
    private ArmazenamentoEmMemoria $local;
    private OutboxDeReplicacao $outbox;

    /** @var list<ReferenciaDeArquivo> */
    private array $referencias = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conexao = static::getContainer()->get(Connection::class);
        $this->conexao->executeStatement('DELETE FROM armazenamento_replicacao');
        $this->local  = new ArmazenamentoEmMemoria();
        $this->outbox = new OutboxDeReplicacao($this->conexao, new MockClock('2026-10-24 12:00:00', 'UTC'));
    }

    #[TestDox('enfileira só o que está no disco, da categoria pedida; rodar de novo não duplica')]
    public function testEnfileiraEEIdempotente(): void
    {
        $this->foto('Foto de Antônio.jpg');
        $this->foto('b.jpg');
        $this->referencias[] = new ReferenciaDeArquivo('user_profiles', 3, $this->chaveDeFoto('sumiu.jpg'), CategoriaDeArquivo::FOTO_PERFIL);
        $this->referencias[] = new ReferenciaDeArquivo('user_profiles', 4, null, CategoriaDeArquivo::FOTO_PERFIL);
        $outra               = new ChaveDeArquivo(EscopoDeArquivo::deTenant(7), CategoriaDeArquivo::PASTA_DOCUMENTO, 'rg.pdf');
        $this->local->gravar($outra, FonteDeConteudo::deTexto('rg'));
        $this->referencias[] = new ReferenciaDeArquivo('pasta_documento', 5, $outra, CategoriaDeArquivo::PASTA_DOCUMENTO);

        $tester = $this->comando('foto_perfil');
        self::assertSame(Command::SUCCESS, $tester->execute(['--categoria' => 'foto_perfil']));
        $saida = preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';
        self::assertStringContainsString('enfileiradas 2', $saida);
        self::assertStringContainsString('ausentes no disco local 1', $saida);
        self::assertStringContainsString('chave inválida 1', $saida);
        self::assertStringNotContainsString('Antônio', $saida);
        self::assertStringNotContainsString('core/', $saida);
        self::assertSame(2, $this->linhas());
        self::assertSame(['foto_perfil'], $this->conexao->fetchFirstColumn('SELECT DISTINCT categoria FROM armazenamento_replicacao'));

        self::assertSame(Command::SUCCESS, $tester->execute(['--categoria' => 'foto_perfil']));
        self::assertStringContainsString('já na fila ou verificadas 2', preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '');
        self::assertSame(2, $this->linhas(), 'idempotente');
    }

    #[TestDox('verificado não volta à fila; superado e falhou voltam (envio novo)')]
    public function testUltimoEstadoDecide(): void
    {
        $verificado = $this->foto('v.jpg');
        $superado   = $this->foto('s.jpg');
        foreach ([[$verificado, 'verificado'], [$superado, 'superado']] as [$chave, $estado]) {
            $id = $this->outbox->registrarEnvio($chave, hash('sha256', 'x'), 1, 'image/jpeg');
            $this->conexao->executeStatement('UPDATE armazenamento_replicacao SET estado = ? WHERE id = ?', [$estado, $id]);
        }

        $resumo = $this->carregador()->carregar(CategoriaDeArquivo::FOTO_PERFIL);

        self::assertSame(1, $resumo->enfileirados);
        self::assertSame(1, $resumo->jaNaFilaOuVerificados);
    }

    #[TestDox('--simular só conta; --limite para depois de N enfileirados')]
    public function testSimularELimite(): void
    {
        $this->foto('1.jpg');
        $this->foto('2.jpg');
        $this->foto('3.jpg');

        self::assertSame(3, $this->carregador()->carregar(CategoriaDeArquivo::FOTO_PERFIL, null, true)->enfileirados);
        self::assertSame(0, $this->linhas(), 'a simulação não grava');

        self::assertSame(Command::SUCCESS, $this->comando('foto_perfil')->execute(['--categoria' => 'foto_perfil', '--limite' => '2']));
        self::assertSame(2, $this->linhas());
    }

    #[TestDox('recusa categoria desligada na política, categoria que não existe e limite inválido')]
    public function testRecusas(): void
    {
        $this->foto('x.jpg');

        self::assertSame(Command::FAILURE, $this->comando('cliente_documento')->execute(['--categoria' => 'foto_perfil']));
        self::assertSame(Command::INVALID, $this->comando('foto_perfil')->execute(['--categoria' => 'nao_existe']));
        self::assertSame(Command::INVALID, $this->comando('foto_perfil')->execute(['--categoria' => 'foto_perfil', '--limite' => '0']));
        self::assertSame(0, $this->linhas());
    }

    #[TestDox('depois da carga, o arquivo ausente no espelho conta como "em fila" na reconciliação, que não dispara alerta')]
    public function testAusenteComCargaFicaEmFila(): void
    {
        $this->foto('f.jpg');
        $this->carregador()->carregar(CategoriaDeArquivo::FOTO_PERFIL);

        $relatorio = (new ReconciliadorDoEspelho($this->fonte(), $this->local, $this->outbox))->reconciliar(new EspelhoRemotoEmMemoria());

        self::assertSame(1, $relatorio->quantos(ClasseDeReconciliacao::AUSENTE_PENDENTE_OUTBOX));
        self::assertSame(0, $relatorio->quantos(ClasseDeReconciliacao::AUSENTE_NO_ESPELHO));
    }

    private function foto(string $nome): ChaveDeArquivo
    {
        $chave = $this->chaveDeFoto($nome);
        $this->local->gravar($chave, FonteDeConteudo::deTexto('conteudo ' . $nome));
        $this->referencias[] = new ReferenciaDeArquivo('user_profiles', \count($this->referencias) + 1, $chave, CategoriaDeArquivo::FOTO_PERFIL);

        return $chave;
    }

    private function chaveDeFoto(string $nome): ChaveDeArquivo
    {
        return new ChaveDeArquivo(EscopoDeArquivo::global(), CategoriaDeArquivo::FOTO_PERFIL, $nome);
    }

    private function linhas(): int
    {
        return (int) $this->conexao->fetchOne('SELECT count(*) FROM armazenamento_replicacao');
    }

    private function comando(string $ligadas): CommandTester
    {
        return new CommandTester(new CarregarAcervoCommand($this->carregador(), new PoliticaDeEspelhamento($ligadas)));
    }

    private function carregador(): CarregadorDoAcervo
    {
        return new CarregadorDoAcervo($this->fonte(), $this->local, $this->outbox);
    }

    private function fonte(): FonteDeReferencias
    {
        return new class ($this->referencias) implements FonteDeReferencias {
            /** @param list<ReferenciaDeArquivo> $referencias */
            public function __construct(private readonly array $referencias)
            {
            }

            public function referencias(): iterable
            {
                return $this->referencias;
            }
        };
    }
}
