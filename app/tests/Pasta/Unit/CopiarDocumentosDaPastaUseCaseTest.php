<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Pasta\Exception\OriginalNaoEncontradoException;
use App\Pasta\Exception\SelecaoAcimaDoTetoException;
use App\Pasta\Repository\PastaDocumentoRepository;
use App\Pasta\UseCase\CopiarDocumentosDaPastaUseCase;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\ArquivoArmazenado;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\Exception\ArquivoNaoEncontrado;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\Armazenamento\MetadadosDeArquivo;
use App\Shared\Armazenamento\NovoArquivo;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\Shared\Doctrine\Transacao\DestinoDaTransacao;
use App\Shared\Doctrine\Transacao\TransacaoComArquivoNovo;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoria;
use App\Tests\Shared\Doubles\ConsultaDeDestinoFixa;
use App\Tests\Shared\Doubles\LoggerEmMemoria;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Copiar documentos (D6): linha nova + arquivo novo com os mesmos bytes, nome "(cópia)" único no
 * destino, metadados do original, `driveFileId` nulo, `enviadoPor` = quem copiou — e, na falha
 * antes do COMMIT, nenhum arquivo novo sobra.
 *
 * A `TransacaoComArquivoNovo` é a REAL (é ela que decide o destino do arquivo); EntityManager e
 * conexão são dublês que se comportam como a transação de verdade — o mesmo desenho de
 * `SubstituirAnexoDoLoteFalhaTest`.
 */
#[CoversClass(CopiarDocumentosDaPastaUseCase::class)]
final class CopiarDocumentosDaPastaUseCaseTest extends TestCase
{
    private const SHA = 'ab' . 'cd' . '0123456789abcdef0123456789abcdef0123456789abcdef0123456789ab';

    private ArmazenamentoEmMemoria $memoria;
    private PastaDocumentoRepository&Stub $documentos;
    private Tenant $tenant;
    private Pasta $pasta;
    private User $autor;
    private int $proximoId = 100;

    /** @var list<object> */
    private array $persistidos = [];

    protected function setUp(): void
    {
        $this->memoria    = new ArmazenamentoEmMemoria();
        $this->documentos = $this->createStub(PastaDocumentoRepository::class);
        $this->tenant     = self::comId(new Tenant(), 7);
        $this->pasta      = self::comId((new Pasta())->setTenant($this->tenant), 9);
        $this->autor      = self::comId((new User())->setEmail('ana@escritorio.com')->setFullName('Ana'), 3);
    }

    // ------------------------------------------------------------------ casos

    #[TestDox('a cópia é linha nova + arquivo novo com os mesmos bytes; metadados do original, driveFileId nulo, enviadoPor = quem copiou')]
    public function testCopiaFiel(): void
    {
        $a       = $this->secao('A', 10);
        $destino = $this->secao('DESTINO', 11);
        $origem  = $this->documento('contrato.pdf', $a, 'bytes do contrato', 'origem.pdf');
        $origem->setSha256(self::SHA)->setPaginas(3)->setCategoria(PastaDocumento::CATEGORIA_PROCURACAO)
            ->setDescricao('Assinado')->setNumero('12/2026')->setDriveFileId('drive-1')->setMimeType('application/pdf');
        $this->noDestino([]);

        $em = $this->emQueFalha();
        $em->expects($this->once())->method('flush');
        $resultado = $this->useCase($em)->executar($this->pasta, [$origem], $destino, $this->autor, $this->tenant);

        self::assertCount(1, $resultado->copias);
        $copia = $resultado->copias[0];
        self::assertSame([$copia], $this->persistidos, 'a cópia foi persistida — e só ela');

        self::assertSame('contrato (cópia).pdf', $copia->getNomeOriginal());
        self::assertSame('CONTRATO (CÓPIA).PDF', $copia->getTitulo(), 'o título segue o setter (maiúsculas), como no upload');
        self::assertSame($destino, $copia->getSecao());
        self::assertSame($this->pasta, $copia->getPasta());
        self::assertSame($this->tenant, $copia->getTenant());
        self::assertSame(self::SHA, $copia->getSha256());
        self::assertSame(3, $copia->getPaginas());
        self::assertSame(PastaDocumento::CATEGORIA_PROCURACAO, $copia->getCategoria());
        self::assertSame('Assinado', $copia->getDescricao());
        self::assertSame('12/2026', $copia->getNumero());
        self::assertSame('application/pdf', $copia->getMimeType());
        self::assertSame(strlen('bytes do contrato'), $copia->getTamanhoBytes(), 'o tamanho é o que o storage mediu');
        self::assertNull($copia->getDriveFileId(), 'para o Drive a cópia é documento novo');
        self::assertSame($this->autor, $copia->getEnviadoPor());
        self::assertNull($copia->getModificadoEm());
        self::assertNull($copia->getExcluidoEm());

        self::assertNotSame('origem.pdf', $copia->getCaminhoArquivo(), 'arquivo NOVO: o storage cunhou outra chave');
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.pdf$/', $copia->getCaminhoArquivo());
        self::assertSame('bytes do contrato', $this->memoria->ler(ChavesDePasta::documento($copia)), 'os mesmos bytes, pela chave que a leitura vai montar');
        self::assertSame('bytes do contrato', $this->memoria->ler(ChavesDePasta::documento($origem)), 'o original ficou intacto');
        self::assertSame([], $this->memoria->excluidas);
    }

    #[TestDox('nome único no destino: "(cópia)", depois "(cópia 2)"…; copiar uma cópia não empilha sufixo; duas no mesmo lote não colidem')]
    public function testNomesUnicos(): void
    {
        $existente    = $this->documento('contrato (cópia).pdf', null, 'x', 'e.pdf');
        $origem       = $this->documento('contrato.pdf', null, 'c', 'o.pdf');
        $homonimo     = $this->documento('contrato.pdf', null, 'c2', 'o2.pdf');
        $copiaDeCopia = $this->documento('x (cópia).pdf', null, 'y', 'y.pdf');
        $this->noDestino([$existente, $origem, $homonimo, $copiaDeCopia]); // o destino é a raiz, onde todos moram

        $resultado = $this->useCase($this->emQueFalha())->executar($this->pasta, [$origem, $homonimo, $copiaDeCopia], null, $this->autor, $this->tenant);

        self::assertSame(
            ['contrato (cópia 2).pdf', 'contrato (cópia 3).pdf', 'x (cópia 2).pdf'],
            array_map(static fn (PastaDocumento $d): string => $d->getNomeOriginal(), $resultado->copias),
        );
        self::assertCount(3, $this->memoria->gravadas);
    }

    #[TestDox('a comparação de nome não distingue caixa, e o nome encurta para caber nos 255 da coluna sem perder o sufixo')]
    public function testNomeCabeNaColuna(): void
    {
        $existente = $this->documento('RELATÓRIO (CÓPIA).PDF', null, 'x', 'e.pdf');
        $longo     = $this->documento(str_repeat('a', 250) . '.pdf', null, 'l', 'l.pdf');
        $origem    = $this->documento('relatório.pdf', null, 'r', 'o.pdf');
        $this->noDestino([$existente]);

        $resultado = $this->useCase($this->emQueFalha())->executar($this->pasta, [$origem, $longo], null, $this->autor, $this->tenant);

        self::assertSame('relatório (cópia 2).pdf', $resultado->copias[0]->getNomeOriginal());
        $nomeLongo = $resultado->copias[1]->getNomeOriginal();
        self::assertLessThanOrEqual(255, strlen($nomeLongo));
        self::assertStringEndsWith(' (cópia).pdf', $nomeLongo);
    }

    #[TestDox('a extensão da chave nova vem do arquivo armazenado (normalizada); sem ela, do nome')]
    public function testExtensaoDaChaveNova(): void
    {
        $maiuscula = $this->documento('a.pdf', null, 'a', 'orig.PDF');
        $semExt    = $this->documento('b.docx', null, 'b', 'hashsemextensao');
        $this->noDestino([]);

        $resultado = $this->useCase($this->emQueFalha())->executar($this->pasta, [$maiuscula, $semExt], null, $this->autor, $this->tenant);

        self::assertStringEndsWith('.pdf', $resultado->copias[0]->getCaminhoArquivo());
        self::assertStringEndsWith('.docx', $resultado->copias[1]->getCaminhoArquivo());
    }

    #[TestDox('destino de outro escritório: AccessDenied; de outra pasta: InvalidArgument — nada gravado')]
    public function testDestinoInvalido(): void
    {
        $origem = $this->documento('a.pdf', null, 'a', 'o.pdf');
        $this->noDestino([]);
        $useCase = $this->useCase($this->emQueFalha());

        $deOutroEscritorio = $this->secao('X', 20);
        $deOutroEscritorio->setTenant(self::comId(new Tenant(), 8));
        try {
            $useCase->executar($this->pasta, [$origem], $deOutroEscritorio, $this->autor, $this->tenant);
            self::fail('devia recusar');
        } catch (AccessDeniedException) {
        }

        $deOutraPasta = $this->secao('Y', 21);
        $deOutraPasta->setPasta(self::comId((new Pasta())->setTenant($this->tenant), 99));
        try {
            $useCase->executar($this->pasta, [$origem], $deOutraPasta, $this->autor, $this->tenant);
            self::fail('devia recusar');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('destino', $e->getMessage());
        }

        self::assertSame([], $this->memoria->gravadas);
        self::assertSame([], $this->persistidos);
    }

    #[TestDox('seleção vazia, documento de outra pasta ou de outro escritório: recusado antes de tocar o storage')]
    public function testSelecaoInvalida(): void
    {
        $this->noDestino([]);
        $useCase = $this->useCase($this->emQueFalha());

        try {
            $useCase->executar($this->pasta, [], null, $this->autor, $this->tenant);
            self::fail('devia recusar');
        } catch (\InvalidArgumentException) {
        }

        $deOutraPasta = $this->documento('a.pdf', null, 'a', 'o.pdf');
        $deOutraPasta->setPasta(self::comId((new Pasta())->setTenant($this->tenant), 99));
        try {
            $useCase->executar($this->pasta, [$deOutraPasta], null, $this->autor, $this->tenant);
            self::fail('devia recusar');
        } catch (\InvalidArgumentException) {
        }

        $alheio = $this->documento('b.pdf', null, 'b', 'p.pdf');
        $alheio->setTenant(self::comId(new Tenant(), 8));
        try {
            $useCase->executar($this->pasta, [$alheio], null, $this->autor, $this->tenant);
            self::fail('devia recusar');
        } catch (AccessDeniedException) {
        }

        self::assertSame([], $this->memoria->gravadas);
        self::assertSame([], $this->memoria->lidas);
    }

    #[TestDox('tetos: mais de 2.000 documentos ou mais de 1 GB somados → recusa antes de abrir qualquer arquivo')]
    public function testTetos(): void
    {
        $this->noDestino([]);
        $useCase = $this->useCase($this->emQueFalha());

        $muitos = [];
        for ($i = 0; $i <= CopiarDocumentosDaPastaUseCase::TETO_DE_DOCUMENTOS; ++$i) {
            $muitos[] = $this->documento("d{$i}.pdf", null, null, "d{$i}.pdf");
        }
        try {
            $useCase->executar($this->pasta, $muitos, null, $this->autor, $this->tenant);
            self::fail('devia recusar');
        } catch (SelecaoAcimaDoTetoException $e) {
            self::assertStringContainsString('2.001', $e->getMessage());
        }

        $grandes = [
            $this->documento('g1.pdf', null, null, 'g1.pdf', 600 * 1024 * 1024),
            $this->documento('g2.pdf', null, null, 'g2.pdf', 600 * 1024 * 1024),
        ];
        try {
            $useCase->executar($this->pasta, $grandes, null, $this->autor, $this->tenant);
            self::fail('devia recusar');
        } catch (SelecaoAcimaDoTetoException $e) {
            self::assertStringContainsString('GB', $e->getMessage());
        }

        self::assertSame([], $this->memoria->lidas, 'nenhum arquivo foi aberto');
        self::assertSame([], $this->memoria->gravadas);
    }

    #[TestDox('flush recusado (antes do COMMIT): a exceção sobe e os arquivos novos já gravados SAEM — nenhum órfão')]
    public function testFalhaDoFlushApagaOsArquivosNovos(): void
    {
        $um   = $this->documento('um.pdf', null, 'um', 'um.pdf');
        $dois = $this->documento('dois.pdf', null, 'dois', 'dois.pdf');
        $this->noDestino([]);

        $useCase = $this->useCase($this->emQueFalha(noFlush: new \RuntimeException('banco recusou')));

        try {
            $useCase->executar($this->pasta, [$um, $dois], null, $this->autor, $this->tenant);
            self::fail('a exceção original tem de subir');
        } catch (\RuntimeException $e) {
            self::assertSame('banco recusou', $e->getMessage());
        }

        self::assertCount(2, $this->memoria->gravadas, 'as duas cópias chegaram a ser gravadas');
        self::assertCount(2, $this->memoria->excluidas, '…e as duas saíram');
        foreach ($this->memoria->gravadas as $chave) {
            self::assertFalse($this->memoria->existe($chave));
        }
        self::assertTrue($this->memoria->existe(ChavesDePasta::documento($um)), 'os originais não são tocados');
        self::assertTrue($this->memoria->existe(ChavesDePasta::documento($dois)));
    }

    #[TestDox('o storage falha no SEGUNDO arquivo: a exceção sobe e o primeiro, já gravado, sai')]
    public function testFalhaDoStorageNoMeioApagaOQueJaEntrou(): void
    {
        $um   = $this->documento('um.pdf', null, 'um', 'um.pdf');
        $dois = $this->documento('dois.pdf', null, 'dois', 'dois.pdf');
        $this->noDestino([]);

        $em = $this->emQueFalha();
        $em->expects($this->never())->method('flush');
        $useCase = $this->useCase($em, $this->armazenamentoQueFalhaNaSegundaGravacao());

        try {
            $useCase->executar($this->pasta, [$um, $dois], null, $this->autor, $this->tenant);
            self::fail('devia falhar');
        } catch (FalhaDeArmazenamento $e) {
            self::assertSame('disco cheio', $e->getMessage());
        }

        self::assertCount(1, $this->memoria->gravadas);
        self::assertSame([$this->memoria->gravadas[0]->comoTexto()], array_map(static fn (ChaveDeArquivo $c): string => $c->comoTexto(), $this->memoria->excluidas));
        self::assertFalse($this->memoria->existe($this->memoria->gravadas[0]));
    }

    #[TestDox('original sem arquivo no armazenamento: recusa ANTES de gravar qualquer cópia, com o nome — sem transação, sem flush')]
    public function testOriginalAusenteRecusaAntesDeGravar(): void
    {
        $ok     = $this->documento('ok.pdf', null, 'ok', 'ok.pdf');
        $sumido = $this->documento('sumido.pdf', null, null, 'sumido.pdf'); // linha sem arquivo
        $this->noDestino([]);

        $em = $this->emQueFalha();
        $em->expects($this->never())->method('getConnection');
        $em->expects($this->never())->method('flush');

        try {
            $this->useCase($em)->executar($this->pasta, [$ok, $sumido], null, $this->autor, $this->tenant);
            self::fail('devia recusar');
        } catch (OriginalNaoEncontradoException $e) {
            self::assertSame('O arquivo original de «sumido.pdf» não foi encontrado; nada foi copiado.', $e->getMessage());
        }

        self::assertSame([], $this->memoria->gravadas, 'nada entrou');
        self::assertSame([], $this->persistidos);
    }

    #[TestDox('original que some ENTRE a conferência e a leitura: a cópia já gravada SAI, a recusa sobe com o nome — "nada foi copiado" continua verdade')]
    public function testOriginalQueSomeNoMeio(): void
    {
        $um   = $this->documento('um.pdf', null, 'um', 'um.pdf');
        $dois = $this->documento('dois.pdf', null, 'dois', 'dois.pdf');
        $this->noDestino([]);

        $em = $this->emQueFalha();
        $em->expects($this->never())->method('flush');
        $useCase = $this->useCase($em, $this->armazenamentoQueFalhaNa('abrir', 2, ArquivoNaoEncontrado::para(ChavesDePasta::documento($dois))));

        try {
            $useCase->executar($this->pasta, [$um, $dois], null, $this->autor, $this->tenant);
            self::fail('devia recusar');
        } catch (OriginalNaoEncontradoException $e) {
            self::assertStringContainsString('«dois.pdf»', $e->getMessage());
            self::assertStringContainsString('nada foi copiado', $e->getMessage());
        }

        self::assertCount(1, $this->memoria->gravadas, 'a cópia de "um" chegou a entrar…');
        self::assertCount(1, $this->memoria->excluidas, '…e saiu');
        self::assertFalse($this->memoria->existe($this->memoria->gravadas[0]));
        self::assertTrue($this->memoria->existe(ChavesDePasta::documento($um)), 'o original não é tocado');
    }

    #[TestDox('COMMIT que falha com destino incerto PRESERVA os arquivos novos (as linhas podem ter sido confirmadas)')]
    public function testCommitIncertoPreserva(): void
    {
        $um = $this->documento('um.pdf', null, 'um', 'um.pdf');
        $this->noDestino([]);

        $useCase = $this->useCase(
            $this->emQueFalha(noCommit: new \RuntimeException('resposta perdida')),
            destino: DestinoDaTransacao::Incerta,
        );

        try {
            $useCase->executar($this->pasta, [$um], null, $this->autor, $this->tenant);
            self::fail('a exceção original tem de subir');
        } catch (\RuntimeException $e) {
            self::assertSame('resposta perdida', $e->getMessage());
        }

        self::assertCount(1, $this->memoria->gravadas);
        self::assertSame([], $this->memoria->excluidas, 'INV-6: sem prova de que nada foi confirmado, o arquivo fica');
        self::assertTrue($this->memoria->existe($this->memoria->gravadas[0]));
    }

    // ---------------------------------------------------------------- helpers

    private function useCase(EntityManagerInterface $em, ?ArmazenamentoDeArquivos $armazenamento = null, DestinoDaTransacao $destino = DestinoDaTransacao::NaoConfirmada): CopiarDocumentosDaPastaUseCase
    {
        $armazenamento ??= $this->memoria;
        $logger          = new LoggerEmMemoria();
        $remocao         = new RemocaoAposTransacao($this->memoria, $logger);

        return new CopiarDocumentosDaPastaUseCase(
            $em,
            $armazenamento,
            new TransacaoComArquivoNovo($em, new ConsultaDeDestinoFixa($destino), $remocao, $logger),
            $this->documentos,
        );
    }

    /**
     * EntityManager + conexão que se comportam como a transação real e falham onde o teste pedir.
     * `persist` é registrado para a asserção.
     */
    private function emQueFalha(?\Throwable $noFlush = null, ?\Throwable $noCommit = null): EntityManagerInterface&MockObject
    {
        $nivel = 0;

        $conn = $this->createMock(Connection::class);
        $conn->method('getTransactionNestingLevel')->willReturnCallback(static function () use (&$nivel): int {
            return $nivel;
        });
        $conn->method('beginTransaction')->willReturnCallback(static function () use (&$nivel): void {
            ++$nivel;
        });
        $conn->method('rollBack')->willReturnCallback(static function () use (&$nivel): void {
            --$nivel;
        });
        $conn->method('commit')->willReturnCallback(static function () use (&$nivel, $noCommit): void {
            --$nivel;
            if ($noCommit !== null) {
                throw $noCommit;
            }
        });
        $conn->method('fetchOne')->willReturn('11117396');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);
        $em->method('persist')->willReturnCallback(function (object $entidade): void {
            $this->persistidos[] = $entidade;
        });
        if ($noFlush !== null) {
            $em->method('flush')->willThrowException($noFlush);
        }

        return $em;
    }

    /** Delega ao armazenamento em memória; a SEGUNDA gravação falha antes de tocar nada. */
    private function armazenamentoQueFalhaNaSegundaGravacao(): ArmazenamentoDeArquivos
    {
        return $this->armazenamentoQueFalhaNa('gravar', 2, new FalhaDeArmazenamento('disco cheio'));
    }

    /** Delega ao armazenamento em memória; a N-ésima chamada de `$verbo` (gravar|abrir) lança `$falha`. */
    private function armazenamentoQueFalhaNa(string $verbo, int $chamada, \Throwable $falha): ArmazenamentoDeArquivos
    {
        return new class ($this->memoria, $verbo, $chamada, $falha) implements ArmazenamentoDeArquivos {
            /** @var array<string, int> */
            private array $chamadas = ['gravar' => 0, 'abrir' => 0];

            public function __construct(
                private readonly ArmazenamentoEmMemoria $memoria,
                private readonly string $verbo,
                private readonly int $chamada,
                private readonly \Throwable $falha,
            ) {
            }

            private function contar(string $verbo): void
            {
                if (++$this->chamadas[$verbo] === $this->chamada && $verbo === $this->verbo) {
                    throw $this->falha;
                }
            }

            public function gravar(ChaveDeArquivo|NovoArquivo $destino, FonteDeConteudo $fonte): ArquivoArmazenado
            {
                $this->contar('gravar');

                return $this->memoria->gravar($destino, $fonte);
            }

            public function abrir(ChaveDeArquivo $chave): mixed
            {
                $this->contar('abrir');

                return $this->memoria->abrir($chave);
            }

            public function ler(ChaveDeArquivo $chave): string
            {
                return $this->memoria->ler($chave);
            }

            public function existe(ChaveDeArquivo $chave): bool
            {
                return $this->memoria->existe($chave);
            }

            public function excluir(ChaveDeArquivo $chave): void
            {
                $this->memoria->excluir($chave);
            }

            public function metadados(ChaveDeArquivo $chave): ?MetadadosDeArquivo
            {
                return $this->memoria->metadados($chave);
            }
        };
    }

    /** O que `findByPastaComSecao` devolve: os documentos vivos da pasta (o filtro por destino é do UseCase). */
    private function noDestino(array $documentos): void
    {
        $this->documentos->method('findByPastaComSecao')->willReturn($documentos);
    }

    private function secao(string $nome, int $id): PastaSecao
    {
        $secao = (new PastaSecao())->setNome($nome);
        $secao->setPasta($this->pasta);
        $secao->setTenant($this->tenant);

        return self::comId($secao, $id);
    }

    /** `$conteudo = null` cria só a linha (para os tetos, que não abrem arquivo). */
    private function documento(string $nome, ?PastaSecao $secao, ?string $conteudo, string $caminho, ?int $tamanho = null): PastaDocumento
    {
        $doc = new PastaDocumento();
        $doc->setPasta($this->pasta);
        $doc->setTenant($this->tenant);
        $doc->setTitulo($nome);
        $doc->setNomeOriginal($nome);
        $doc->setCategoria(PastaDocumento::CATEGORIA_DEMAIS);
        $doc->setCaminhoArquivo($caminho);
        $doc->setMimeType('application/pdf');
        $doc->setTamanhoBytes($tamanho ?? strlen((string) $conteudo));
        $doc->setSecao($secao);
        self::comId($doc, $this->proximoId++);

        if ($conteudo !== null) {
            $this->memoria->semear(ChavesDePasta::documento($doc), $conteudo);
        }

        return $doc;
    }

    /**
     * @template T of object
     *
     * @param T $entidade
     *
     * @return T
     */
    private static function comId(object $entidade, int $id): object
    {
        (new \ReflectionProperty($entidade, 'id'))->setValue($entidade, $id);

        return $entidade;
    }
}
