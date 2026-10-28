<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\EspelhoRemoto;

use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\EspelhoRemoto\LayoutDeChaveRemota;
use App\Shared\EspelhoRemoto\Outbox\OutboxDeReplicacao;
use App\Shared\EspelhoRemoto\R2\ClienteR2;
use App\Shared\EspelhoRemoto\R2\EspelhoRemotoR2;
use App\Shared\EspelhoRemoto\Reconciliacao\ClasseDeReconciliacao;
use App\Shared\EspelhoRemoto\Reconciliacao\FonteDeReferencias;
use App\Shared\EspelhoRemoto\Reconciliacao\ReconciliadorDoEspelho;
use App\Shared\EspelhoRemoto\Reconciliacao\ReferenciaDeArquivo;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoria;
use App\Tests\Shared\Doubles\S3Simulado;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * A reconciliação em dry-run (spec P2 §3.4, §14): cada classe, o relatório sem nome, e a prova de
 * que ela NÃO escreve nada — nem na outbox, nem no espelho.
 */
#[CoversClass(ReconciliadorDoEspelho::class)]
final class ReconciliadorDoEspelhoTest extends KernelTestCase
{
    private Connection $conexao;
    private ArmazenamentoEmMemoria $local;
    private OutboxDeReplicacao $outbox;
    private S3Simulado $s3;
    private EspelhoRemotoR2 $espelho;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conexao = static::getContainer()->get(Connection::class);
        $this->conexao->executeStatement('DELETE FROM armazenamento_replicacao');
        $this->local   = new ArmazenamentoEmMemoria();
        $this->outbox  = new OutboxDeReplicacao($this->conexao, new MockClock('2026-10-15 12:00:00'));
        $this->s3      = new S3Simulado();
        $this->espelho = new EspelhoRemotoR2(ClienteR2::criar($this->s3->endpoint, $this->s3->chaveDeAcesso, 'SEGREDO', $this->s3->cliente()), $this->s3->bucket);
    }

    #[TestDox('classifica cada caso: igual, ausente, pendente na outbox, tamanho e SHA divergentes, sem SHA, local ausente, suspeito do Drive, chave inválida')]
    public function testClasses(): void
    {
        $refs = [
            'igual'      => $this->comLocal('igual.pdf', 'x', remoto: ['x', hash('sha256', 'x')]),
            'ausente'    => $this->comLocal('ausente.pdf', 'x'),
            'pendente'   => $this->comLocal('pendente.pdf', 'x'),
            'tamanho'    => $this->comLocal('tamanho.pdf', 'x', remoto: ['xyz', hash('sha256', 'xyz')]),
            'sha'        => $this->comLocal('sha.pdf', 'x', remoto: ['y', hash('sha256', 'y')]),
            'semSha'     => $this->comLocal('semsha.pdf', 'x', remoto: ['x', null]),
            'local'      => new ReferenciaDeArquivo('pasta_documento', 7, $this->chave('nao-existe.pdf')),
            'suspeito'   => $this->comLocal('drive.pdf', $this->conteudoDoErro401()),
            'invalida'   => new ReferenciaDeArquivo('tarefa_mensagem', 9, null),
        ];
        $this->outbox->registrarEnvio($refs['pendente']->chave ?? self::fail(), hash('sha256', 'x'), 1, 'application/pdf');

        $relatorio = $this->reconciliador($refs)->reconciliar($this->espelho);

        foreach ([
            ClasseDeReconciliacao::IGUAL, ClasseDeReconciliacao::AUSENTE_NO_ESPELHO, ClasseDeReconciliacao::AUSENTE_PENDENTE_OUTBOX,
            ClasseDeReconciliacao::TAMANHO_DIVERGENTE, ClasseDeReconciliacao::SHA_DIVERGENTE, ClasseDeReconciliacao::SEM_SHA_NO_ESPELHO,
            ClasseDeReconciliacao::LOCAL_AUSENTE, ClasseDeReconciliacao::SUSPEITO_ERRO_DO_DRIVE, ClasseDeReconciliacao::CHAVE_INVALIDA,
        ] as $classe) {
            self::assertSame(1, $relatorio->quantos($classe), $classe->value);
        }
        self::assertSame(9, $relatorio->total());
        self::assertCount(8, $relatorio->divergencias());
    }

    #[TestDox('pane do espelho na consulta é "erro_de_consulta", não "ausente" (D10)')]
    public function testPaneNaoViraAusente(): void
    {
        $ref = $this->comLocal('p.pdf', 'x');
        $this->s3->falharAProxima(new MockResponse('<?xml version="1.0"?><Error><Code>InternalError</Code></Error>', ['http_code' => 500]));

        $relatorio = $this->reconciliador([$ref])->reconciliar($this->espelho);

        self::assertSame(1, $relatorio->quantos(ClasseDeReconciliacao::ERRO_DE_CONSULTA));
    }

    #[TestDox('sem SHA (--sem-sha): presença e tamanho batendo saem como "presente_sem_sha_conferido", NUNCA como igual (DP-7: os suspeitos do Drive não passam por válidos)')]
    public function testSemSha(): void
    {
        $refs = [$this->comLocal('s.pdf', 'x', remoto: ['y', hash('sha256', 'y')]), $this->comLocal('drive.pdf', $this->conteudoDoErro401(), remoto: [$this->conteudoDoErro401(), hash('sha256', $this->conteudoDoErro401())])];

        $relatorio = $this->reconciliador($refs)->reconciliar($this->espelho, conferirSha: false);

        self::assertSame(2, $relatorio->quantos(ClasseDeReconciliacao::PRESENTE_SEM_SHA_CONFERIDO));
        self::assertSame(0, $relatorio->quantos(ClasseDeReconciliacao::IGUAL));
    }

    #[TestDox('pane fora do espelho (exceção inesperada) também é "erro_de_consulta" — a rodada não aborta')]
    public function testPaneInesperadaNaoAbortaARodada(): void
    {
        $quebrado = new class () implements \App\Shared\EspelhoRemoto\EspelhoRemoto {
            public function enviar(ChaveDeArquivo $chave, mixed $conteudo, int $tamanhoBytes, string $sha256, string $mimeType): void
            {
            }

            public function consultar(ChaveDeArquivo $chave): ?\App\Shared\EspelhoRemoto\MetadadosRemotos
            {
                throw new \TypeError('bug simulado');
            }

            public function existe(ChaveDeArquivo $chave): bool
            {
                return false;
            }

            public function baixar(ChaveDeArquivo $chave, mixed $destino, int $tamanhoEsperado, string $sha256Esperado): void
            {
                throw new \LogicException('a réplica e a reconciliação não baixam conteúdo');
            }
        };

        $relatorio = $this->reconciliador([$this->comLocal('1.pdf', '1'), $this->comLocal('2.pdf', '2')])->reconciliar($quebrado);

        self::assertSame(2, $relatorio->quantos(ClasseDeReconciliacao::ERRO_DE_CONSULTA));
    }

    #[TestDox('dry-run de verdade: não cria linha na outbox, não envia nada ao espelho (só HEAD)')]
    public function testNaoEscreveNada(): void
    {
        $refs  = [$this->comLocal('a.pdf', 'x'), $this->comLocal('b.pdf', 'y', remoto: ['z', hash('sha256', 'z')])];
        $antes = (int) $this->conexao->fetchOne('SELECT count(*) FROM armazenamento_replicacao');

        $this->reconciliador($refs)->reconciliar($this->espelho);

        self::assertSame($antes, (int) $this->conexao->fetchOne('SELECT count(*) FROM armazenamento_replicacao'));
        self::assertSame(['HEAD'], $this->s3->metodosUsados());
    }

    #[TestDox('o relatório traz fonte, id da linha e classe — nunca o nome do arquivo nem a chave remota')]
    public function testRelatorioSemNome(): void
    {
        $nome = 'a1b2c3d4e5f60718293a4b5c6d7e8f90. RG - Antônio Carlos';
        $ref  = new ReferenciaDeArquivo('pasta_documento', 42, $this->chave($nome));
        $this->local->gravar($this->chave($nome), FonteDeConteudo::deTexto('x'));

        $relatorio = $this->reconciliador([$ref])->reconciliar($this->espelho);
        $json      = json_encode([$relatorio->contagem(), $relatorio->divergencias()], \JSON_UNESCAPED_UNICODE);

        self::assertSame([['fonte' => 'pasta_documento', 'id' => 42, 'classe' => 'ausente_no_espelho']], $relatorio->divergencias());
        self::assertStringNotContainsString('Antônio', (string) $json);
        self::assertStringNotContainsString('core/t/', (string) $json);
    }

    #[TestDox('fatia do SHA: estável, de 0 a 6, e só a fatia pedida tem o SHA conferido')]
    public function testFatiaDoSha(): void
    {
        $chave = $this->chave('a.pdf');
        self::assertSame(ReconciliadorDoEspelho::fatiaDe($chave), ReconciliadorDoEspelho::fatiaDe($this->chave('a.pdf')));
        self::assertSame(crc32(LayoutDeChaveRemota::chaveDe($chave)) % 7, ReconciliadorDoEspelho::fatiaDe($chave));

        $ref   = $this->comLocal('a.pdf', 'x', remoto: ['y', hash('sha256', 'y')]);
        $fatia = ReconciliadorDoEspelho::fatiaDe($chave);

        self::assertSame(1, $this->reconciliador([$ref])->reconciliar($this->espelho, true, null, null, $fatia)->quantos(ClasseDeReconciliacao::SHA_DIVERGENTE));
        self::assertSame(1, $this->reconciliador([$ref])->reconciliar($this->espelho, true, null, null, ($fatia + 1) % 7)->quantos(ClasseDeReconciliacao::PRESENTE_SEM_SHA_CONFERIDO));

        $this->expectException(\InvalidArgumentException::class);
        $this->reconciliador([$ref])->reconciliar($this->espelho, true, null, null, 7);
    }

    #[TestDox('limite: para depois de N referências')]
    public function testLimite(): void
    {
        $refs = [$this->comLocal('1.pdf', '1'), $this->comLocal('2.pdf', '2'), $this->comLocal('3.pdf', '3')];

        self::assertSame(2, $this->reconciliador($refs)->reconciliar($this->espelho, true, 2)->total());
    }

    /** @param array{0: string, 1: ?string}|null $remoto conteúdo e metadado sha256 do objeto no espelho */
    private function comLocal(string $nome, string $conteudo, ?array $remoto = null): ReferenciaDeArquivo
    {
        $chave = $this->chave($nome);
        $this->local->gravar($chave, FonteDeConteudo::deTexto($conteudo));
        if ($remoto !== null) {
            $this->s3->semear(LayoutDeChaveRemota::chaveDe($chave), $remoto[0], $remoto[1] === null ? [] : ['sha256' => $remoto[1]]);
        }

        return new ReferenciaDeArquivo('pasta_documento', random_int(1, 1_000_000), $chave);
    }

    private function chave(string $nome): ChaveDeArquivo
    {
        return new ChaveDeArquivo(EscopoDeArquivo::deTenant(7), CategoriaDeArquivo::PASTA_DOCUMENTO, $nome);
    }

    /** Um conteúdo cujo SHA é o do erro 401 do Drive — o teste o "fabrica" trocando a constante pelo hash de um texto. */
    private function conteudoDoErro401(): string
    {
        return 'erro-401-do-drive';
    }

    /** @param iterable<ReferenciaDeArquivo> $referencias */
    private function reconciliador(iterable $referencias): ReconciliadorDoEspelho
    {
        $fonte = new class ($referencias) implements FonteDeReferencias {
            /** @param iterable<ReferenciaDeArquivo> $referencias */
            public function __construct(private readonly iterable $referencias)
            {
            }

            public function referencias(): iterable
            {
                return $this->referencias;
            }
        };

        return new ReconciliadorDoEspelho($fonte, $this->local, $this->outbox, hash('sha256', $this->conteudoDoErro401()));
    }
}
