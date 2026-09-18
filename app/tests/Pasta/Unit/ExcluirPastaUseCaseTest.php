<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Service\NumeracaoDePastaInterface;
use App\Pasta\UseCase\ExcluirPastaUseCase;
use App\Pasta\UseCase\ResultadoExclusaoPasta;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoria;
use App\Tests\Shared\Doubles\LoggerEmMemoria;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * O que este teste prova: DADA a resposta da sequência ("existe pasta com número maior?"), o
 * UseCase faz a coisa certa — lápide preservando arquivo, ou exclusão real apagando arquivo.
 *
 * O que ele NÃO prova, de propósito: se a resposta da sequência está certa. Isso é expressão SQL
 * contra o Postgres e tem prova própria em ExcluirPastaLapideTest (funcional).
 *
 * E2.5: os arquivos só saem DEPOIS do COMMIT do `wrapInTransaction` — o dublê marca o COMMIT
 * quando o callback retorna, e o armazenamento em memória conta as remoções que chegaram antes
 * dele (tem de ser zero). O dublê materializa o escopo na chave: tenant errado quebra aqui (R1).
 */
#[CoversClass(ExcluirPastaUseCase::class)]
final class ExcluirPastaUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private ArmazenamentoEmMemoria $armazenamento;
    private LoggerEmMemoria $logger;
    private bool $commitado = false;

    /**
     * Quantas remoções aconteceram antes do COMMIT. Um contador, e não um `assert` dentro do
     * gancho: a `RemocaoAposTransacao` captura qualquer `Throwable` da remoção — inclusive o de uma
     * asserção — e o transforma em log.
     */
    private int $removidosAntesDoCommit = 0;
    private ?\Throwable $falhaNoCommit = null;
    private NumeracaoDePastaInterface&MockObject $numeracao;
    private ExcluirPastaUseCase $useCase;
    private Tenant $tenant;
    private User $autor;

    protected function setUp(): void
    {
        $this->em            = $this->createMock(EntityManagerInterface::class);
        $this->armazenamento = new ArmazenamentoEmMemoria();
        $this->logger        = new LoggerEmMemoria();
        $this->numeracao     = $this->createMock(NumeracaoDePastaInterface::class);

        // Como o `wrapInTransaction` real: o COMMIT acontece DEPOIS que o callback retorna.
        $this->em->method('wrapInTransaction')->willReturnCallback(
            function (callable $fn) {
                $retorno = $fn($this->em);
                if ($this->falhaNoCommit !== null) {
                    throw $this->falhaNoCommit;
                }
                $this->commitado = true;

                return $retorno;
            }
        );
        $this->armazenamento->aoExcluir = function (): void {
            if (!$this->commitado) {
                ++$this->removidosAntesDoCommit;
            }
        };

        $this->useCase = new ExcluirPastaUseCase(
            $this->em,
            new RemocaoAposTransacao($this->armazenamento, $this->logger),
            $this->numeracao,
        );

        $this->tenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($this->tenant, 7);
        $this->autor  = (new User())->setEmail('autor@test.com');
    }

    private function ehAUltima(bool $ehAUltima): void
    {
        $this->numeracao->method('existeNumeroMaiorQue')->willReturn(!$ehAUltima);
    }

    #[TestDox('Tenant diverge: recusa antes de tocar em qualquer coisa')]
    public function testTenantDivergeLancaAccessDeniedException(): void
    {
        $pasta = $this->criarPasta($this->tenant, []);

        $this->em->expects($this->never())->method('remove');
        $this->em->expects($this->never())->method('wrapInTransaction');

        $this->expectException(AccessDeniedException::class);

        $this->useCase->executar($pasta, $this->autor, new Tenant());
    }

    #[TestDox('É a última da sequência: apaga de verdade e o número volta a ser livre')]
    public function testUltimaDaSequenciaEhRemovidaDeVerdade(): void
    {
        $this->ehAUltima(true);
        $pasta = $this->criarPasta($this->tenant, []);

        $pasta->expects($this->never())->method('marcarExcluida');
        $this->em->expects($this->once())->method('remove')->with($pasta);
        $this->em->expects($this->once())->method('flush');

        self::assertSame(
            ResultadoExclusaoPasta::Removida,
            $this->useCase->executar($pasta, $this->autor, $this->tenant),
        );
    }

    #[TestDox('É a última e tem documentos: apaga os arquivos do disco junto')]
    public function testUltimaComDocumentosApagaOsArquivos(): void
    {
        $this->ehAUltima(true);
        $doc1  = $this->criarDocumento('arquivo1.pdf');
        $doc2  = $this->criarDocumento('arquivo2.pdf');
        $pasta = $this->criarPasta($this->tenant, [$doc1, $doc2]);
        $this->armazenamento->gravar(ChavesDePasta::documento($doc1), FonteDeConteudo::deTexto('1'));
        $this->armazenamento->gravar(ChavesDePasta::documento($doc2), FonteDeConteudo::deTexto('2'));

        $this->em->expects($this->once())->method('remove')->with($pasta);

        self::assertSame(
            ResultadoExclusaoPasta::Removida,
            $this->useCase->executar($pasta, $this->autor, $this->tenant),
        );
        self::assertCount(2, $this->armazenamento->excluidas);
        self::assertSame(0, $this->removidosAntesDoCommit, 'arquivo removido antes do COMMIT (INV-6)');
        self::assertFalse($this->armazenamento->existe(ChavesDePasta::documento($doc1)));
        self::assertFalse($this->armazenamento->existe(ChavesDePasta::documento($doc2)));
    }

    /**
     * O defeito que a E2.5 fechou: antes os arquivos saíam DENTRO da transação, e um COMMIT (ou
     * flush) recusado deixava a pasta de pé apontando para arquivos apagados.
     *
     * @return iterable<string, array{string}>
     */
    public static function falhasDoBanco(): iterable
    {
        yield 'flush recusado'  => ['flush'];
        yield 'COMMIT recusado' => ['commit'];
    }

    #[TestDox('Banco recusa ($onde): a pasta fica e NENHUM arquivo sai')]
    #[\PHPUnit\Framework\Attributes\DataProvider('falhasDoBanco')]
    public function testBancoQueRecusaNaoApagaArquivo(string $onde): void
    {
        $this->ehAUltima(true);
        $doc   = $this->criarDocumento('arquivo1.pdf');
        $pasta = $this->criarPasta($this->tenant, [$doc]);
        $this->armazenamento->gravar(ChavesDePasta::documento($doc), FonteDeConteudo::deTexto('1'));

        $recusa = new \RuntimeException('recusado no ' . $onde);
        if ($onde === 'flush') {
            $this->em->method('flush')->willThrowException($recusa);
        } else {
            $this->falhaNoCommit = $recusa;
        }

        $capturada = null;
        try {
            $this->useCase->executar($pasta, $this->autor, $this->tenant);
        } catch (\RuntimeException $e) {
            $capturada = $e;
        }

        self::assertSame($recusa, $capturada);
        self::assertTrue($this->armazenamento->existe(ChavesDePasta::documento($doc)), 'rollback sem perda física');
        self::assertSame([], $this->armazenamento->excluidas);
    }

    #[TestDox('Disco falha depois do COMMIT: a exclusão fica confirmada e o órfão é registrado')]
    public function testDiscoQueFalhaDepoisDoCommitNaoDesfaz(): void
    {
        $this->ehAUltima(true);
        $doc1  = $this->criarDocumento('arquivo1.pdf');
        $doc2  = $this->criarDocumento('arquivo2.pdf');
        $pasta = $this->criarPasta($this->tenant, [$doc1, $doc2]);
        $this->armazenamento->gravar(ChavesDePasta::documento($doc1), FonteDeConteudo::deTexto('1'));
        $this->armazenamento->gravar(ChavesDePasta::documento($doc2), FonteDeConteudo::deTexto('2'));
        $this->armazenamento->falhaAoExcluir = static fn (ChaveDeArquivo $c): ?\Throwable => $c->nome === 'arquivo1.pdf'
            ? new \App\Shared\Armazenamento\Exception\FalhaDeArmazenamento('disco')
            : null;

        self::assertSame(
            ResultadoExclusaoPasta::Removida,
            $this->useCase->executar($pasta, $this->autor, $this->tenant),
        );
        self::assertTrue($this->armazenamento->existe(ChavesDePasta::documento($doc1)));
        self::assertFalse($this->armazenamento->existe(ChavesDePasta::documento($doc2)), 'a falha de um não segura o outro');
        self::assertSame(0, $this->removidosAntesDoCommit);
        self::assertCount(1, $this->logger->doNivel('error'));
    }

    #[TestDox('É a última e o arquivo já sumiu do disco: remove do banco sem chamar excluir')]
    public function testDocumentoInexistenteNaoChamaExcluirMasRemoveDoBanco(): void
    {
        $this->ehAUltima(true);
        $pasta = $this->criarPasta($this->tenant, [$this->criarDocumento('ausente.pdf')]);

        $this->em->expects($this->once())->method('remove')->with($pasta);

        $this->useCase->executar($pasta, $this->autor, $this->tenant);

        self::assertSame([], $this->armazenamento->excluidas);
    }

    #[TestDox('Arquivo de OUTRO escritório com o mesmo nome não é enxergado — nem apagado')]
    public function testNaoEnxergaArquivoDeOutroEscritorioComOMesmoNome(): void
    {
        $this->ehAUltima(true);
        $pasta = $this->criarPasta($this->tenant, [$this->criarDocumento('mesmo-nome.pdf')]);
        $alheio = new ChaveDeArquivo(EscopoDeArquivo::deTenant(99), CategoriaDeArquivo::PASTA_DOCUMENTO, 'mesmo-nome.pdf');
        $this->armazenamento->gravar($alheio, FonteDeConteudo::deTexto('do escritório 99'));

        $this->em->expects($this->once())->method('remove')->with($pasta);

        $this->useCase->executar($pasta, $this->autor, $this->tenant);

        self::assertTrue($this->armazenamento->existe($alheio));
    }

    #[TestDox('TEM posterior: vira lápide — a linha NÃO é removida')]
    public function testComPosteriorViraLapideSemRemoverALinha(): void
    {
        $this->ehAUltima(false);
        $pasta = $this->criarPasta($this->tenant, []);

        $pasta->expects($this->once())->method('marcarExcluida')
              ->with($this->autor, $this->isInstanceOf(\DateTimeImmutable::class));
        $this->em->expects($this->never())->method('remove');
        $this->em->expects($this->once())->method('flush');

        self::assertSame(
            ResultadoExclusaoPasta::Lapide,
            $this->useCase->executar($pasta, $this->autor, $this->tenant),
        );
    }

    #[TestDox('LÁPIDE PRESERVA OS ARQUIVOS: nenhum documento é apagado do disco')]
    public function testLapidePreservaOsArquivosNoDisco(): void
    {
        $this->ehAUltima(false);
        $doc1  = $this->criarDocumento('contrato.pdf');
        $doc2  = $this->criarDocumento('procuracao.pdf');
        $pasta = $this->criarPasta($this->tenant, [$doc1, $doc2]);
        $this->armazenamento->gravar(ChavesDePasta::documento($doc1), FonteDeConteudo::deTexto('c'));
        $this->armazenamento->gravar(ChavesDePasta::documento($doc2), FonteDeConteudo::deTexto('p'));

        // O ponto da decisão do dono: a pasta riscada tem que abrir e mostrar o que já foi feito.
        self::assertSame(
            ResultadoExclusaoPasta::Lapide,
            $this->useCase->executar($pasta, $this->autor, $this->tenant),
        );
        self::assertTrue($this->armazenamento->existe(ChavesDePasta::documento($doc1)));
        self::assertTrue($this->armazenamento->existe(ChavesDePasta::documento($doc2)));
        self::assertSame([], $this->armazenamento->excluidas);
    }

    #[TestDox('A sequência é travada ANTES de decidir, senão a decisão nasce errada em silêncio')]
    public function testTravaASequenciaAntesDeDecidir(): void
    {
        $ordem = [];
        $this->numeracao->method('travar')
             ->willReturnCallback(function () use (&$ordem): void { $ordem[] = 'travar'; });
        $this->numeracao->method('existeNumeroMaiorQue')
             ->willReturnCallback(function () use (&$ordem): bool { $ordem[] = 'decidir'; return true; });

        $this->useCase->executar($this->criarPasta($this->tenant, []), $this->autor, $this->tenant);

        self::assertSame(['travar', 'decidir'], $ordem);
    }

    #[TestDox('Excluir pasta que já é lápide é recusado')]
    public function testPastaJaExcluidaEhRecusada(): void
    {
        $pasta = $this->criarPasta($this->tenant, []);
        $pasta->method('estaExcluida')->willReturn(true);

        $this->em->expects($this->never())->method('remove');

        $this->expectException(\LogicException::class);

        $this->useCase->executar($pasta, $this->autor, $this->tenant);
    }

    /** @param list<PastaDocumento> $documentos */
    private function criarPasta(Tenant $tenant, array $documentos): Pasta&MockObject
    {
        $pasta = $this->createMock(Pasta::class);
        $pasta->method('getTenant')->willReturn($tenant);
        $pasta->method('getNup')->willReturn('1238');
        $pasta->method('getDocumentos')->willReturn(new ArrayCollection($documentos));

        return $pasta;
    }

    private function criarDocumento(string $caminhoArquivo): PastaDocumento
    {
        $doc = $this->createMock(PastaDocumento::class);
        $doc->method('getCaminhoArquivo')->willReturn($caminhoArquivo);
        $doc->method('getTenant')->willReturn($this->tenant);

        return $doc;
    }
}
