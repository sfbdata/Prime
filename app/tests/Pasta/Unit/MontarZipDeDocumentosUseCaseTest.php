<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Pasta\Exception\SelecaoAcimaDoTetoException;
use App\Pasta\Repository\PastaDocumentoRepository;
use App\Pasta\Repository\PastaSecaoRepository;
use App\Pasta\UseCase\MontarZipDeDocumentosUseCase;
use App\Shared\Armazenamento\ArquivoGeradoParaEntrega;
use App\Shared\Armazenamento\DiretorioTemporarioPrivado;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoria;
use App\Tests\Shared\Doubles\LoggerEmMemoria;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * O .zip da seleção (D5): estrutura das subpastas, nomes sanitizados e únicos, `LEIA-ME.txt`,
 * tetos ANTES de abrir arquivo, arquivo ausente listado em vez de abortar, auditoria manual.
 *
 * O armazenamento é o dublê em memória (que também materializa por caminho real, como o disco), e
 * o .zip gerado é aberto de verdade com `ZipArchive` — conteúdo e estrutura são lidos, não
 * inferidos. O EntityManager é mock pelo mesmo motivo dos outros UseCases de lote: aqui ele só
 * recebe o `AuditLog` e o `flush`.
 */
#[CoversClass(MontarZipDeDocumentosUseCase::class)]
final class MontarZipDeDocumentosUseCaseTest extends TestCase
{
    private const AGORA = '2026-10-07 10:00:00';

    private ArmazenamentoEmMemoria $memoria;
    private PastaDocumentoRepository&Stub $documentos;
    private PastaSecaoRepository&Stub $secoes;
    private EntityManagerInterface&MockObject $em;
    private LoggerEmMemoria $logger;
    private MontarZipDeDocumentosUseCase $useCase;
    private Tenant $tenant;
    private Pasta $pasta;
    private User $autor;
    private int $proximoId = 100;

    /** @var list<object> o que foi para `persist()` */
    private array $persistidos = [];

    /** @var list<string> .zips gerados nos testes, apagados no tearDown */
    private array $gerados = [];

    protected function setUp(): void
    {
        $this->memoria    = new ArmazenamentoEmMemoria();
        $this->documentos = $this->createStub(PastaDocumentoRepository::class);
        $this->secoes     = $this->createStub(PastaSecaoRepository::class);
        $this->em         = $this->createMock(EntityManagerInterface::class);
        $this->logger     = new LoggerEmMemoria();
        $this->em->method('persist')->willReturnCallback(function (object $entidade): void {
            $this->persistidos[] = $entidade;
        });

        $this->useCase = new MontarZipDeDocumentosUseCase(
            $this->documentos,
            $this->secoes,
            $this->memoria,
            $this->em,
            new MockClock(self::AGORA),
            $this->logger,
        );

        $this->tenant = self::comId((new Tenant())->setName('Escritório Teste'), 7);
        $this->pasta  = self::comId((new Pasta())->setNup('DOC-1')->setTenant($this->tenant), 9);
        $this->autor  = self::comId((new User())->setEmail('ana@escritorio.com')->setFullName('Ana'), 3);
    }

    protected function tearDown(): void
    {
        foreach ($this->gerados as $caminho) {
            if (is_file($caminho)) {
                @unlink($caminho);
            }
        }
    }

    // ------------------------------------------------------------------ casos

    #[TestDox('monta o .zip com a estrutura das subpastas (inclusive a vazia), o LEIA-ME e os bytes de cada documento')]
    public function testEstruturaDasSubpastas(): void
    {
        $a     = $this->secao('A', 10);
        $b     = $this->secao('B', 11, $a);
        $vazia = $this->secao('Vazia', 12, $b);
        $raiz  = $this->documento('raiz.pdf', null, 'conteúdo raiz');
        $emA   = $this->documento('em-a.pdf', $a, 'conteúdo a');
        $emB   = $this->documento('em-b.pdf', $b, 'conteúdo b');
        $fica  = $this->documento('fica.pdf', null, 'não selecionado');
        $this->arvore([$a, $b, $vazia], [$raiz, $emA, $emB, $fica]);
        $this->em->expects($this->once())->method('flush');

        $saida = $this->useCase->executar($this->pasta, [$raiz], [$a], $this->autor, $this->tenant);
        $this->gerados[] = $saida->arquivo->caminho();

        self::assertSame('documentos-DOC-1.zip', $saida->nomeDoZip);
        self::assertSame(3, $saida->arquivos);
        self::assertSame(0, $saida->naoEncontrados);
        self::assertSame(strlen('conteúdo raiz') + strlen('conteúdo a') + strlen('conteúdo b'), $saida->bytes);
        self::assertSame(
            DiretorioTemporarioPrivado::doProcesso('zip')->caminho(),
            \dirname($saida->arquivo->caminho()),
            'o .zip saiu da área temporária (que já foi liberada) para o diretório privado do processo',
        );

        $entradas = $this->entradasDoZip($saida->arquivo->caminho());
        self::assertSame(
            ['A/B/VAZIA/', 'A/B/em-b.pdf', 'A/em-a.pdf', 'LEIA-ME.txt', 'raiz.pdf'], // setter grava a seção em MAIÚSCULAS
            array_keys($entradas),
            'a subpasta sem arquivo entra como diretório; "fica.pdf" não foi selecionado',
        );
        self::assertSame('conteúdo raiz', $entradas['raiz.pdf']);
        self::assertSame('conteúdo a', $entradas['A/em-a.pdf']);
        self::assertSame('conteúdo b', $entradas['A/B/em-b.pdf']);

        $leiaMe = $entradas['LEIA-ME.txt'];
        self::assertStringContainsString('Documentos da pasta DOC-1', $leiaMe);
        self::assertStringContainsString('Escritório: Escritório Teste', $leiaMe);
        self::assertStringContainsString('Gerado em: 07/10/2026 10:00 por Ana (ana@escritorio.com)', $leiaMe);
        self::assertStringContainsString('Arquivos incluídos: 3', $leiaMe);
        self::assertStringContainsString('  - A/B/em-b.pdf (', $leiaMe);
        self::assertStringNotContainsString('fica.pdf', $leiaMe);
        self::assertStringNotContainsString('Não encontrados (', $leiaMe, 'sem ausente, sem a seção');
    }

    #[TestDox('registra o download no audit_log à mão: ação "zip" (cabe nos 10 caracteres), Pasta + id, ids da seleção, contagem e bytes')]
    public function testAuditoria(): void
    {
        $a    = $this->secao('A', 10);
        $raiz = $this->documento('raiz.pdf', null, 'r');
        $emA  = $this->documento('em-a.pdf', $a, 'a');
        $this->arvore([$a], [$raiz, $emA]);

        $saida = $this->useCase->executar($this->pasta, [$raiz], [$a], $this->autor, $this->tenant);
        $this->gerados[] = $saida->arquivo->caminho();

        self::assertCount(1, $this->persistidos);
        $log = $this->persistidos[0];
        self::assertInstanceOf(AuditLog::class, $log);
        self::assertSame('zip', $log->getAction());
        self::assertLessThanOrEqual(10, strlen($log->getAction()), 'audit_log.action é VARCHAR(10)');
        self::assertSame(Pasta::class, $log->getEntityClass());
        self::assertSame('9', $log->getEntityId());
        self::assertSame(7, $log->getTenantId());
        self::assertSame(3, $log->getActorUserId());
        self::assertSame('ana@escritorio.com', $log->getActorEmail());

        $mudancas = $log->getChanges();
        self::assertIsArray($mudancas);
        self::assertSame([$raiz->getId()], $mudancas['documentos']);
        self::assertSame([10], $mudancas['secoes']);
        self::assertSame(2, $mudancas['arquivos']);
        self::assertSame(0, $mudancas['nao_encontrados']);
        self::assertSame(2, $mudancas['bytes']);
        self::assertIsInt($mudancas['zip_bytes']);
        self::assertGreaterThan(0, $mudancas['zip_bytes']);
    }

    #[TestDox('nomes maliciosos (travessia, barra inicial, \\, controle) de documento e de subpasta viram entradas inofensivas')]
    public function testNomesSaneados(): void
    {
        $fora = $this->secao('../fora', 10);
        $docs = [
            $this->documento('../../etc/passwd', null, 'p'),
            $this->documento('C:\\x\\y.pdf', null, 'w'),
            $this->documento("a\x00b.txt", null, 'n'),
            $this->documento('/abs.pdf', null, 's'),
            $this->documento('..', $fora, 'd'),
        ];
        $this->arvore([$fora], $docs);

        $saida = $this->useCase->executar($this->pasta, array_slice($docs, 0, 4), [$fora], $this->autor, $this->tenant);
        $this->gerados[] = $saida->arquivo->caminho();

        $nomes = array_keys($this->entradasDoZip($saida->arquivo->caminho()));
        self::assertSame(['C:_x_y.pdf', 'LEIA-ME.txt', '_._etc_passwd', '_FORA/sem nome', '_abs.pdf', 'ab.txt'], $nomes); // seção em MAIÚSCULAS pelo setter
        foreach ($nomes as $nome) {
            self::assertStringNotContainsString('..', $nome);
            self::assertStringNotContainsString('\\', $nome);
            self::assertStringStartsNotWith('/', $nome);
            self::assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $nome);
        }
    }

    #[TestDox('nomes repetidos ficam únicos por diretório, e um documento chamado LEIA-ME.txt não toma o lugar do nosso')]
    public function testNomesUnicos(): void
    {
        $docs = [
            $this->documento('rel.pdf', null, 'um'),
            $this->documento('rel.pdf', null, 'dois'),
            $this->documento('REL.PDF', null, 'três'),
            $this->documento('LEIA-ME.txt', null, 'do usuário'),
        ];
        $this->arvore([], $docs);

        $saida = $this->useCase->executar($this->pasta, $docs, [], $this->autor, $this->tenant);
        $this->gerados[] = $saida->arquivo->caminho();

        $entradas = $this->entradasDoZip($saida->arquivo->caminho());
        self::assertSame(['LEIA-ME (2).txt', 'LEIA-ME.txt', 'REL (3).PDF', 'rel (2).pdf', 'rel.pdf'], array_keys($entradas));
        self::assertSame('um', $entradas['rel.pdf']);
        self::assertSame('dois', $entradas['rel (2).pdf']);
        self::assertSame('três', $entradas['REL (3).PDF']);
        self::assertSame('do usuário', $entradas['LEIA-ME (2).txt']);
        self::assertStringStartsWith('Documentos da pasta', $entradas['LEIA-ME.txt'], 'o LEIA-ME.txt é o nosso');
    }

    #[TestDox('documento selecionado que está DENTRO de uma subpasta selecionada entra uma vez só, no lugar dela')]
    public function testDocumentoDentroDaSubpastaSelecionadaNaoDuplica(): void
    {
        $a   = $this->secao('A', 10);
        $emA = $this->documento('em-a.pdf', $a, 'a');
        $this->arvore([$a], [$emA]);

        $saida = $this->useCase->executar($this->pasta, [$emA], [$a], $this->autor, $this->tenant);
        $this->gerados[] = $saida->arquivo->caminho();

        self::assertSame(['A/em-a.pdf', 'LEIA-ME.txt'], array_keys($this->entradasDoZip($saida->arquivo->caminho())));
        self::assertSame(1, $saida->arquivos);
    }

    #[TestDox('acima de 500 arquivos: 422 (SelecaoAcimaDoTeto) ANTES de abrir qualquer arquivo e sem auditoria')]
    public function testTetoDeArquivos(): void
    {
        $docs = [];
        for ($i = 0; $i <= MontarZipDeDocumentosUseCase::TETO_DE_ARQUIVOS; ++$i) {
            $docs[] = $this->documento("doc-{$i}.pdf", null, null);
        }
        $this->arvore([], $docs);
        $this->em->expects($this->never())->method('flush');

        try {
            $this->useCase->executar($this->pasta, $docs, [], $this->autor, $this->tenant);
            self::fail('devia recusar');
        } catch (SelecaoAcimaDoTetoException $e) {
            self::assertStringContainsString('501 arquivos', $e->getMessage());
            self::assertStringContainsString('limite', $e->getMessage());
        }

        self::assertSame([], $this->memoria->lidas, 'nenhum arquivo foi aberto');
        self::assertSame([], $this->persistidos);
    }

    #[TestDox('soma de tamanho_bytes acima de 1 GB: 422 antes de abrir qualquer arquivo')]
    public function testTetoDeBytes(): void
    {
        $docs = [
            $this->documento('a.pdf', null, 'a', 400 * 1024 * 1024),
            $this->documento('b.pdf', null, 'b', 400 * 1024 * 1024),
            $this->documento('c.pdf', null, 'c', 400 * 1024 * 1024),
        ];
        $this->arvore([], $docs);

        try {
            $this->useCase->executar($this->pasta, $docs, [], $this->autor, $this->tenant);
            self::fail('devia recusar');
        } catch (SelecaoAcimaDoTetoException $e) {
            self::assertStringContainsString('1,2 GB', $e->getMessage());
            self::assertStringContainsString('1,0 GB', $e->getMessage());
        }

        self::assertSame([], $this->memoria->lidas);
    }

    #[TestDox('exatamente no teto não é recusado')]
    public function testNoTetoPassa(): void
    {
        $docs = [
            $this->documento('a.pdf', null, 'a', 512 * 1024 * 1024),
            $this->documento('b.pdf', null, 'b', 512 * 1024 * 1024),
        ];
        $this->arvore([], $docs);

        $saida = $this->useCase->executar($this->pasta, $docs, [], $this->autor, $this->tenant);
        $this->gerados[] = $saida->arquivo->caminho();

        self::assertSame(2, $saida->arquivos);
    }

    #[TestDox('documento sem arquivo no armazenamento não aborta: fica fora do .zip, entra no LEIA-ME como não encontrado e vira aviso no log')]
    public function testArquivoAusenteEntraNoLeiaMe(): void
    {
        $ok      = $this->documento('ok.pdf', null, 'ok');
        $sumido  = $this->documento('sumido.pdf', null, null);
        $this->arvore([], [$ok, $sumido]);

        $saida = $this->useCase->executar($this->pasta, [$ok, $sumido], [], $this->autor, $this->tenant);
        $this->gerados[] = $saida->arquivo->caminho();

        self::assertSame(1, $saida->arquivos);
        self::assertSame(1, $saida->naoEncontrados);
        self::assertSame(strlen('ok'), $saida->bytes, 'só o que entrou conta');

        $entradas = $this->entradasDoZip($saida->arquivo->caminho());
        self::assertSame(['LEIA-ME.txt', 'ok.pdf'], array_keys($entradas));
        self::assertStringContainsString('Não encontrados no armazenamento: 1', $entradas['LEIA-ME.txt']);
        self::assertStringContainsString("Não encontrados (ficaram fora deste .zip):\r\n  - sumido.pdf", $entradas['LEIA-ME.txt']);

        self::assertCount(1, $this->logger->doNivel('warning'));
        self::assertSame($sumido->getId(), $this->logger->doNivel('warning')[0]['contexto']['documento_id']);

        $log = $this->persistidos[0];
        self::assertInstanceOf(AuditLog::class, $log);
        self::assertSame(1, $log->getChanges()['nao_encontrados'] ?? null);
    }

    #[TestDox('seleção só com subpasta vazia: não há o que baixar — InvalidArgumentException, nada gerado')]
    public function testSelecaoSemArquivos(): void
    {
        $vazia = $this->secao('Vazia', 10);
        $this->arvore([$vazia], []);
        $antes = $this->zipsNoDiretorioDoProcesso();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('não tem arquivos');

        try {
            $this->useCase->executar($this->pasta, [], [$vazia], $this->autor, $this->tenant);
        } finally {
            self::assertSame($antes, $this->zipsNoDiretorioDoProcesso());
        }
    }

    #[TestDox('se o registro da auditoria falha, o .zip gerado é descartado e a exceção sobe')]
    public function testFalhaNaAuditoriaDescartaOZip(): void
    {
        $doc = $this->documento('a.pdf', null, 'a');
        $this->arvore([], [$doc]);
        $this->em->method('flush')->willThrowException(new \RuntimeException('banco recusou o audit_log'));
        ArquivoGeradoParaEntrega::limparSobras('zip'); // a montagem limpa sobras velhas: a foto de "antes" tem de ser tirada já limpa
        $antes = $this->zipsNoDiretorioDoProcesso();

        try {
            $this->useCase->executar($this->pasta, [$doc], [], $this->autor, $this->tenant);
            self::fail('a exceção original tem de subir');
        } catch (\RuntimeException $e) {
            self::assertSame('banco recusou o audit_log', $e->getMessage());
        }

        self::assertSame($antes, $this->zipsNoDiretorioDoProcesso(), 'nenhum .zip pode ficar esperando ninguém');
    }

    #[TestDox('entradas já comprimidas (pdf, jpg…) entram sem deflate (CM_STORE); texto entra com deflate')]
    public function testCompressaoPorTipoDeEntrada(): void
    {
        $docs = [
            $this->documento('laudo.PDF', null, str_repeat('pdf ', 1024)),
            $this->documento('foto.jpg', null, str_repeat('jpg ', 1024)),
            $this->documento('notas.txt', null, str_repeat('texto repetido ', 2048)),
        ];
        $this->arvore([], $docs);

        $saida = $this->useCase->executar($this->pasta, $docs, [], $this->autor, $this->tenant);
        $this->gerados[] = $saida->arquivo->caminho();

        $metodos = $this->metodosDeCompressao($saida->arquivo->caminho());
        self::assertSame(\ZipArchive::CM_STORE, $metodos['laudo.PDF'], 'PDF já é comprimido por dentro');
        self::assertSame(\ZipArchive::CM_STORE, $metodos['foto.jpg']);
        self::assertSame(\ZipArchive::CM_DEFLATE, $metodos['notas.txt'], 'texto comprime, e muito');
    }

    #[TestDox('a montagem remove sobras velhas do diretório privado (área morta e zip não entregue com mais de 1 h) e deixa as recentes')]
    public function testMontagemLimpaSobrasAntigas(): void
    {
        $dir   = DiretorioTemporarioPrivado::doProcesso('zip')->caminho();
        $velho = time() - 2 * ArquivoGeradoParaEntrega::IDADE_DE_SOBRA_SEGUNDOS;
        $areaVelha = $dir . '/' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($areaVelha, 0o700));
        self::assertTrue(touch($areaVelha, $velho));
        $zipVelho = $dir . '/' . bin2hex(random_bytes(8)) . '.zip';
        self::assertNotFalse(file_put_contents($zipVelho, 'x'));
        self::assertTrue(touch($zipVelho, $velho));
        $zipRecente = $dir . '/' . bin2hex(random_bytes(8)) . '.zip';
        self::assertNotFalse(file_put_contents($zipRecente, 'x'));
        $this->gerados[] = $zipRecente;
        $doc = $this->documento('a.pdf', null, 'a');
        $this->arvore([], [$doc]);

        $saida = $this->useCase->executar($this->pasta, [$doc], [], $this->autor, $this->tenant);
        $this->gerados[] = $saida->arquivo->caminho();

        self::assertDirectoryDoesNotExist($areaVelha);
        self::assertFileDoesNotExist($zipVelho);
        self::assertFileExists($zipRecente);
        self::assertFileExists($saida->arquivo->caminho());
        self::assertCount(1, $this->logger->doNivel('info'));
    }

    #[TestDox('documento de outro escritório na seleção: AccessDenied, nada gerado')]
    public function testDocumentoDeOutroEscritorio(): void
    {
        $outro  = self::comId(new Tenant(), 8);
        $alheio = $this->documento('alheio.pdf', null, 'x');
        $alheio->setTenant($outro);
        $this->arvore([], []);

        $this->expectException(AccessDeniedException::class);

        $this->useCase->executar($this->pasta, [$alheio], [], $this->autor, $this->tenant);
    }

    // ---------------------------------------------------------------- helpers

    private function secao(string $nome, int $id, ?PastaSecao $pai = null): PastaSecao
    {
        $secao = (new PastaSecao())->setNome($nome)->setPai($pai);
        $secao->setPasta($this->pasta);
        $secao->setTenant($this->tenant);

        return self::comId($secao, $id);
    }

    /** `$conteudo = null` cria a linha sem arquivo no armazenamento (o "não encontrado"). */
    private function documento(string $nome, ?PastaSecao $secao, ?string $conteudo, ?int $tamanho = null): PastaDocumento
    {
        $doc = new PastaDocumento();
        $doc->setPasta($this->pasta);
        $doc->setTenant($this->tenant);
        $doc->setTitulo($nome);
        $doc->setNomeOriginal($nome);
        $doc->setCategoria(PastaDocumento::CATEGORIA_DEMAIS);
        $doc->setCaminhoArquivo(bin2hex(random_bytes(6)) . '.pdf');
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
     * O que as duas consultas da árvore devolvem (tudo VIVO: o LixeiraFilter já ficou para trás).
     *
     * @param list<PastaSecao>     $secoes
     * @param list<PastaDocumento> $documentos
     */
    private function arvore(array $secoes, array $documentos): void
    {
        $this->secoes->method('findByPasta')->willReturn($secoes);
        $this->documentos->method('findByPastaComSecao')->willReturn($documentos);
    }

    /** @return array<string, string> nome da entrada => conteúdo, em ordem alfabética */
    private function entradasDoZip(string $caminho): array
    {
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($caminho), 'o arquivo gerado tem de ser um .zip válido');

        $entradas = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $nome            = (string) $zip->getNameIndex($i);
            $entradas[$nome] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        ksort($entradas);

        return $entradas;
    }

    /** @return array<string, int> nome da entrada => método de compressão (ZipArchive::CM_*) */
    private function metodosDeCompressao(string $caminho): array
    {
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($caminho));

        $metodos = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $stat = $zip->statIndex($i);
            self::assertIsArray($stat);
            $metodos[(string) $stat['name']] = (int) $stat['comp_method'];
        }
        $zip->close();

        return $metodos;
    }

    /** @return list<string> */
    private function zipsNoDiretorioDoProcesso(): array
    {
        $nomes = array_values(array_diff(scandir(DiretorioTemporarioPrivado::doProcesso('zip')->caminho()) ?: [], ['.', '..']));
        sort($nomes);

        return $nomes;
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
