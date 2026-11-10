<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Functional;

use App\AssinaturaEletronica\Controller\ArquivosDaAssinaturaController;
use App\AssinaturaEletronica\Controller\ConferenciaDoPdfController;
use App\AssinaturaEletronica\Service\ArquivosDoRascunho;
use App\AssinaturaEletronica\Service\ValidadorDeBlocos;
use App\AssinaturaEletronica\UseCase\ConferirPdfEnviadoUseCase;
use App\AssinaturaEletronica\UseCase\DuplicarDocumentoUseCase;
use App\AssinaturaEletronica\UseCase\ObterOriginalComAssinaturasUseCase;
use App\AssinaturaEletronica\UseCase\SalvarRascunhoUseCase;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Tests\AssinaturaEletronica\Support\NaTelaDeAssinatura;
use App\Tests\AssinaturaEletronica\Unit\ConferenciaDePdf\OficinaDePdfAssinado;
use App\Tests\Auth\Doubles\RateLimiterFactoryEspiao;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoriaNoContainer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * O documento que já chega assinado (etapa 5, bloco 5A) pela HTTP: a conferência do passo 1 sem gravar (E5-6), com o
 * limitador por usuário, a versão `original` e o parágrafo de dc:374 ao salvar o rascunho (só o do original guardado,
 * mesmo vindo de uma aba antiga, e dentro do teto de blocos), a troca e a saída do original, o Duplicar (E5-5), o
 * quadro `jaAssinado` do detalhe e "Baixar original com as assinaturas". O armazenamento é o dublê em memória, menos
 * na entrega do original, que vai ao disco real (o dublê não entrega). O banco é desfeito pelo DAMA.
 */
#[CoversClass(ConferenciaDoPdfController::class)]
#[CoversClass(ConferirPdfEnviadoUseCase::class)]
#[CoversClass(SalvarRascunhoUseCase::class)]
#[CoversClass(ArquivosDoRascunho::class)]
#[CoversClass(DuplicarDocumentoUseCase::class)]
#[CoversClass(ArquivosDaAssinaturaController::class)]
#[CoversClass(ObterOriginalComAssinaturasUseCase::class)]
final class DocumentoJaAssinadoTest extends JusPrimeWebTestCase
{
    use NaTelaDeAssinatura;

    /** A página do PDF e o lugar de assinatura que o passo 1 monta (subir do desenho). */
    private const CONTEUDO = [['k' => 'pg', 'n' => 1], ['k' => 'a', 'i' => 0, 'r' => '']];

    private ?ArmazenamentoEmMemoriaNoContainer $disco = null;
    private ?string $diretorioDoEscritorio = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->csrfPrevisivel();
    }

    protected function tearDown(): void
    {
        if ($this->diretorioDoEscritorio !== null) {
            (new Filesystem())->remove($this->diretorioDoEscritorio);
        }
        parent::tearDown();
    }

    #[TestDox('conferir: sem o módulo 403, sem CSRF 403, sem arquivo ou com arquivo que não é PDF 422')]
    public function testPortaDaConferencia(): void
    {
        $this->emMemoria();
        $this->entrar(false);
        $this->conferir($this->assinado());
        self::assertResponseStatusCodeSame(403);
        self::assertSame(['erro' => 'Você não tem acesso à Assinatura de documentos.'], $this->json());

        $this->entrar(true);
        $this->client->request('POST', '/assinatura/pdf/conferir', [], ['arquivo' => $this->assinado()]);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/assinatura/pdf/conferir', [], [], ['HTTP_X_CSRF_TOKEN' => self::CSRF]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'Envie um arquivo PDF.'], $this->json());

        $this->conferir($this->temporario('falso.pdf', 'só texto'));
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'Envie um arquivo PDF.'], $this->json());
    }

    #[TestDox('conferir: a cota é por usuário e só é gasta depois do módulo e do CSRF')]
    public function testCotaPorUsuario(): void
    {
        $this->emMemoria();
        $espiao = new RateLimiterFactoryEspiao();
        static::getContainer()->set('limiter.assinatura_pdf_conferir', $espiao);
        $this->entrar(false);
        $this->conferir($this->pdf());
        self::assertResponseStatusCodeSame(403);
        [$usuario] = $this->entrar(true);
        $this->client->request('POST', '/assinatura/pdf/conferir', [], ['arquivo' => $this->pdf()]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $espiao->chavesConsumidas, 'sem o módulo ou sem CSRF não gasta a cota');

        $this->conferir($this->pdf());
        self::assertResponseIsSuccessful();
        [$outro] = $this->entrar(true);
        $this->conferir($this->pdf());
        self::assertResponseIsSuccessful();

        self::assertSame(['usuario-' . $usuario->getId(), 'usuario-' . $outro->getId()], $espiao->chavesConsumidas);
    }

    #[TestDox('conferir: cota estourada dá 429 com {erro} e não confere o arquivo')]
    public function testCotaEstourada(): void
    {
        $this->emMemoria();
        static::getContainer()->set('limiter.assinatura_pdf_conferir', new RateLimiterFactoryEspiao(false));
        $this->entrar(true);

        $this->conferir($this->temporario('falso.pdf', 'só texto'));

        self::assertResponseStatusCodeSame(429);
        self::assertSame(['erro' => 'Muitas tentativas. Aguarde alguns minutos e tente de novo.'], $this->json());
    }

    /**
     * O 429 de verdade não se vê pela HTTP no teste (o pool do limitador é zerado ao fim de cada requisição, ver
     * {@see RateLimiterFactoryEspiao}): a cota de `assinatura_eletronica.yaml` é conferida no próprio limitador.
     */
    #[TestDox('conferir: assinatura_eletronica.yaml dá 6 conferências por minuto a cada usuário')]
    public function testCotaConfigurada(): void
    {
        /** @var RateLimiterFactoryInterface $limitador */
        $limitador = static::getContainer()->get('limiter.assinatura_pdf_conferir');
        $doUsuario = $limitador->create('usuario-1');
        for ($i = 0; $i < 6; ++$i) {
            self::assertTrue($doUsuario->consume()->isAccepted(), 'conferência ' . ($i + 1));
        }

        self::assertFalse($doUsuario->consume()->isAccepted(), 'a 7ª no mesmo minuto');
        self::assertTrue($limitador->create('usuario-2')->consume()->isAccepted(), 'outro usuário');
    }

    #[TestDox('conferir: PDF sem assinatura devolve nulo; assinado devolve o quadro; nada é gravado')]
    public function testConferirSemGravar(): void
    {
        $disco = $this->emMemoria();
        $this->entrar(true);

        $this->conferir($this->pdf());
        self::assertResponseIsSuccessful();
        self::assertSame(['jaAssinado' => null], $this->json());

        $this->conferir($this->assinado());
        self::assertResponseIsSuccessful();
        $quadro = $this->json()['jaAssinado'];
        self::assertSame('Documento já assinado: 1 assinatura digital reconhecida', $quadro['titulo']);
        self::assertSame(OficinaDePdfAssinado::TITULAR, $quadro['linhas'][0]['nome']);
        self::assertTrue($quadro['carimbo']);
        self::assertSame([], $disco->memoria->gravadas);
        self::assertSame(0, (int) $this->conexao()->fetchOne('SELECT COUNT(*) FROM assinatura_eletronica_versao'));
    }

    #[TestDox('salvar com PDF assinado: versão original em arquivo próprio, com hash e análise; parágrafo antes do lugar de assinatura; quadro no detalhe')]
    public function testSalvarGuardaOriginal(): void
    {
        $disco = $this->emMemoria();
        $this->entrar(true);
        $bytes = OficinaDePdfAssinado::pdfAssinado();

        $id = $this->salvar(null, ['nome' => 'Contrato', 'blocos' => self::CONTEUDO], $this->temporario('contrato.pdf', $bytes));
        self::assertResponseStatusCodeSame(201);

        $versao = $this->versoes($id)[0];
        self::assertSame(['original', 1, 'contrato.pdf', hash('sha256', $bytes), \strlen($bytes)], [$versao['origem'], (int) $versao['ordem'], $versao['nome_original'], $versao['sha256'], (int) $versao['tamanho']]);
        $doDocumento = $this->conexao()->fetchOne('SELECT arquivo_enviado_chave FROM assinatura_eletronica_documento WHERE id = ?', [$id]);
        self::assertNotSame($doDocumento, $versao['arquivo_chave'], 'a versão tem arquivo próprio');
        self::assertSame($bytes, $disco->memoria->ler($this->chave($disco, $versao['arquivo_chave'])));
        self::assertSame(1, json_decode((string) $versao['analise'], true, 512, \JSON_THROW_ON_ERROR)['v']);

        $detalhe = $this->detalhe($id);
        self::assertSame(['pg', 'p', 'a'], array_column($detalhe['blocos'], 'k'));
        self::assertSame($this->paragrafo($bytes), $detalhe['blocos'][1]['x']);
        self::assertSame('Documento já assinado: 1 assinatura digital reconhecida', $detalhe['jaAssinado']['titulo']);
        self::assertSame('/assinatura/documentos/' . $id . '/original', $detalhe['jaAssinado']['original']);
    }

    #[TestDox('salvar de novo sem PDF mantém o original e um só parágrafo')]
    public function testResalvarSemArquivo(): void
    {
        $this->emMemoria();
        $this->entrar(true);
        $id = $this->salvar(null, ['nome' => 'Contrato', 'blocos' => self::CONTEUDO], $this->assinado());
        $antes = $this->versoes($id);

        $this->salvar($id, ['nome' => 'Contrato', 'blocos' => $this->detalhe($id)['blocos']]);
        self::assertResponseIsSuccessful();

        self::assertSame($antes, $this->versoes($id));
        self::assertSame(['pg', 'p', 'a'], array_column($this->detalhe($id)['blocos'], 'k'));
    }

    #[TestDox('trocar por PDF sem assinatura: o original e o parágrafo dele saem; o arquivo do original sai depois do COMMIT')]
    public function testTrocarPorPdfSemAssinatura(): void
    {
        $disco = $this->emMemoria();
        $this->entrar(true);
        $id = $this->salvar(null, ['nome' => 'Contrato', 'blocos' => self::CONTEUDO], $this->assinado());
        $chave = $this->chave($disco, $this->versoes($id)[0]['arquivo_chave']);

        $this->salvar($id, ['nome' => 'Contrato', 'blocos' => $this->detalhe($id)['blocos']], $this->pdf());
        self::assertResponseIsSuccessful();

        self::assertSame([], $this->versoes($id));
        self::assertFalse($disco->memoria->existe($chave));
        $detalhe = $this->detalhe($id);
        self::assertSame(['pg', 'a'], array_column($detalhe['blocos'], 'k'));
        self::assertNull($detalhe['jaAssinado']);
    }

    #[TestDox('trocar por outro PDF assinado: um só original (o novo), um só parágrafo (o novo)')]
    public function testTrocarPorOutroAssinado(): void
    {
        $disco = $this->emMemoria();
        $this->entrar(true);
        $id = $this->salvar(null, ['nome' => 'Contrato', 'blocos' => self::CONTEUDO], $this->assinado());
        $antigo = $this->chave($disco, $this->versoes($id)[0]['arquivo_chave']);
        $novo = OficinaDePdfAssinado::pdfAssinado('ec');

        $this->salvar($id, ['nome' => 'Contrato', 'blocos' => $this->detalhe($id)['blocos']], $this->temporario('novo.pdf', $novo));
        self::assertResponseIsSuccessful();

        $versoes = $this->versoes($id);
        self::assertCount(1, $versoes);
        self::assertSame([hash('sha256', $novo), 'novo.pdf'], [$versoes[0]['sha256'], $versoes[0]['nome_original']]);
        self::assertFalse($disco->memoria->existe($antigo));
        $blocos = $this->detalhe($id)['blocos'];
        self::assertSame(['pg', 'p', 'a'], array_column($blocos, 'k'));
        self::assertSame($this->paragrafo($novo), $blocos[1]['x']);
    }

    #[TestDox('aba antiga depois que outra trocou o original: fica só o parágrafo do original guardado, no mesmo lugar')]
    public function testAbaAntigaComParagrafoDeOutroOriginal(): void
    {
        $this->emMemoria();
        $this->entrar(true);
        $id = $this->salvar(null, ['nome' => 'Contrato', 'blocos' => self::CONTEUDO], $this->assinado());
        $daAbaAntiga = $this->detalhe($id)['blocos'];
        $novo = OficinaDePdfAssinado::pdfAssinado('ec');
        $this->salvar($id, ['nome' => 'Contrato', 'blocos' => $daAbaAntiga], $this->temporario('novo.pdf', $novo));
        self::assertResponseIsSuccessful();

        $this->salvar($id, ['nome' => 'Contrato da aba antiga', 'blocos' => $daAbaAntiga]);
        self::assertResponseIsSuccessful();

        $blocos = $this->detalhe($id)['blocos'];
        self::assertSame(['pg', 'p', 'a'], array_column($blocos, 'k'));
        self::assertSame($this->paragrafo($novo), $blocos[1]['x']);
        self::assertSame([hash('sha256', $novo)], array_column($this->versoes($id), 'sha256'));
    }

    #[TestDox('aba antiga depois que outra guardou um original: o parágrafo entra antes do lugar de assinatura')]
    public function testAbaAntigaSemParagrafo(): void
    {
        $this->emMemoria();
        $this->entrar(true);
        $id = $this->salvar(null, ['nome' => 'Contrato', 'blocos' => self::CONTEUDO], $this->pdf());
        $daAbaAntiga = $this->detalhe($id)['blocos'];
        $bytes = OficinaDePdfAssinado::pdfAssinado();
        $this->salvar($id, ['nome' => 'Contrato', 'blocos' => $daAbaAntiga], $this->temporario('assinado.pdf', $bytes));
        self::assertResponseIsSuccessful();

        $this->salvar($id, ['nome' => 'Contrato da aba antiga', 'blocos' => $daAbaAntiga]);
        self::assertResponseIsSuccessful();

        $blocos = $this->detalhe($id)['blocos'];
        self::assertSame(['pg', 'p', 'a'], array_column($blocos, 'k'));
        self::assertSame($this->paragrafo($bytes), $blocos[1]['x']);
    }

    #[TestDox('aba antiga depois que outra tirou o original: o parágrafo do original que saiu sai também')]
    public function testAbaAntigaDepoisQueOOriginalSaiu(): void
    {
        $this->emMemoria();
        $this->entrar(true);
        $id = $this->salvar(null, ['nome' => 'Contrato', 'blocos' => self::CONTEUDO], $this->assinado());
        $daAbaAntiga = $this->detalhe($id)['blocos'];
        $this->salvar($id, ['nome' => 'Contrato', 'blocos' => $daAbaAntiga], $this->pdf());
        self::assertResponseIsSuccessful();

        $this->salvar($id, ['nome' => 'Contrato da aba antiga', 'blocos' => $daAbaAntiga]);
        self::assertResponseIsSuccessful();

        self::assertSame([], $this->versoes($id));
        self::assertSame(['pg', 'a'], array_column($this->detalhe($id)['blocos'], 'k'));
    }

    #[TestDox('sem lugar para o parágrafo no teto de 2.000 blocos: 422 blocos_demais antes de gravar; com um lugar, salva')]
    public function testParagrafoNoTetoDeBlocos(): void
    {
        $disco = $this->emMemoria();
        [, $tenant] = $this->entrar(true);
        $cheio = [['k' => 'pg', 'n' => 1], ...array_fill(0, ValidadorDeBlocos::BLOCOS_MAXIMO - 2, ['k' => 'p', 'x' => 'Cláusula']), ['k' => 'a', 'i' => 0, 'r' => '']];

        $this->salvar(null, ['nome' => 'Contrato', 'blocos' => $cheio], $this->assinado());
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'O documento pode ter até 2.000 blocos.'], $this->json());
        self::assertSame([], $disco->memoria->gravadas);
        self::assertSame(0, (int) $this->conexao()->fetchOne('SELECT COUNT(*) FROM assinatura_eletronica_documento WHERE tenant_id = ?', [$tenant->getId()]));

        array_splice($cheio, 1, 1);
        $id = $this->salvar(null, ['nome' => 'Contrato', 'blocos' => $cheio], $this->assinado());
        self::assertResponseStatusCodeSame(201);
        $blocos = $this->detalhe($id)['blocos'];
        self::assertCount(ValidadorDeBlocos::BLOCOS_MAXIMO, $blocos);
        self::assertSame(['p', 'a'], array_column(\array_slice($blocos, -2), 'k'));
        self::assertStringStartsWith('Este documento já continha', (string) $blocos[\count($blocos) - 2]['x']);
    }

    #[TestDox('rascunho que perde a página do PDF perde também o original e o parágrafo')]
    public function testSemPaginaSaiOOriginal(): void
    {
        $disco = $this->emMemoria();
        $this->entrar(true);
        $id = $this->salvar(null, ['nome' => 'Contrato', 'blocos' => self::CONTEUDO], $this->assinado());
        $chave = $this->chave($disco, $this->versoes($id)[0]['arquivo_chave']);
        $semPagina = array_values(array_filter($this->detalhe($id)['blocos'], static fn (array $b): bool => $b['k'] !== 'pg'));

        $this->salvar($id, ['nome' => 'Contrato', 'blocos' => $semPagina]);
        self::assertResponseIsSuccessful();

        self::assertSame([], $this->versoes($id));
        self::assertFalse($disco->memoria->existe($chave));
        self::assertSame(['a'], array_column($this->detalhe($id)['blocos'], 'k'));
    }

    #[TestDox('PDF assinado sem página dele no conteúdo: nem original nem parágrafo')]
    public function testAssinadoSemPagina(): void
    {
        $this->emMemoria();
        $this->entrar(true);

        $id = $this->salvar(null, ['nome' => 'Contrato', 'blocos' => [['k' => 'a', 'i' => 0, 'r' => '']]], $this->assinado());
        self::assertResponseStatusCodeSame(201);

        self::assertSame([], $this->versoes($id));
        self::assertSame(['a'], array_column($this->detalhe($id)['blocos'], 'k'));
    }

    #[TestDox('duplicar copia só o original, num arquivo da cópia, com a mesma análise; apagar o documento de origem não apaga o da cópia')]
    public function testDuplicarCopiaOOriginal(): void
    {
        $disco = $this->emMemoria();
        $this->entrar(true);
        $bytes = OficinaDePdfAssinado::pdfAssinado();
        $origem = $this->salvar(null, ['nome' => 'Contrato', 'blocos' => self::CONTEUDO], $this->temporario('contrato.pdf', $bytes));
        $daOrigem = $this->versoes($origem)[0];

        $this->client->request('POST', '/assinatura/documentos/' . $origem . '/duplicar', [], [], ['HTTP_X_CSRF_TOKEN' => self::CSRF]);
        self::assertResponseStatusCodeSame(201);
        $copia = (int) $this->json()['id'];

        $versoes = $this->versoes($copia);
        self::assertCount(1, $versoes);
        self::assertSame(['original', $daOrigem['sha256'], $daOrigem['analise'], 'contrato.pdf'], [$versoes[0]['origem'], $versoes[0]['sha256'], $versoes[0]['analise'], $versoes[0]['nome_original']]);
        self::assertNotSame($daOrigem['arquivo_chave'], $versoes[0]['arquivo_chave']);
        self::assertNotNull($this->detalhe($copia)['jaAssinado']);

        $this->postarJson('/assinatura/documentos/lixeira', ['ids' => [$origem]]);
        $this->postarJson('/assinatura/documentos/excluir', ['ids' => [$origem]]);
        self::assertResponseIsSuccessful();
        self::assertFalse($disco->memoria->existe($this->chave($disco, $daOrigem['arquivo_chave'])));
        self::assertSame($bytes, $disco->memoria->ler($this->chave($disco, $versoes[0]['arquivo_chave'])));
    }

    #[TestDox('"Baixar original com as assinaturas": anexo com os bytes enviados e o nome "{nome} - original.pdf"; outro escritório e documento sem original, 404')]
    public function testBaixarOriginal(): void
    {
        [, $tenant] = $this->entrar(true);
        $this->diretorioDoEscritorio = rtrim((string) static::getContainer()->getParameter('uploads_dir'), '/') . '/assinatura/' . $tenant->getId();
        $bytes = OficinaDePdfAssinado::pdfAssinado();
        $id = $this->salvar(null, ['nome' => 'Contrato', 'blocos' => self::CONTEUDO], $this->temporario('contrato.pdf', $bytes));
        $semOriginal = $this->salvar(null, ['nome' => 'Comum', 'blocos' => self::CONTEUDO], $this->pdf());

        $this->client->request('GET', '/assinatura/documentos/' . $id . '/original');
        self::assertResponseIsSuccessful();
        ob_start();
        $this->client->getResponse()->sendContent();
        self::assertSame($bytes, (string) ob_get_clean());
        $disposicao = (string) $this->client->getResponse()->headers->get('Content-Disposition');
        self::assertStringStartsWith('attachment', $disposicao);
        self::assertStringContainsString('Contrato - original.pdf', $disposicao);

        $this->client->request('GET', '/assinatura/documentos/' . $semOriginal . '/original');
        self::assertResponseStatusCodeSame(404);

        $this->entrar(true);
        $this->client->request('GET', '/assinatura/documentos/' . $id . '/original');
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('a página traz a URL da conferência e a do carimbo (marcador 0) e carrega o assinatura-ja-assinado.js antes do assinatura.js')]
    public function testPagina(): void
    {
        $this->emMemoria();
        $this->entrar(true);

        $crawler = $this->client->request('GET', '/assinatura');
        self::assertResponseIsSuccessful();

        $raiz = $crawler->filter('#bjaPagina');
        self::assertSame('/assinatura/pdf/conferir', $raiz->attr('data-url-pdf-conferir'));
        self::assertSame('/assinatura/documentos/0/carimbo', $raiz->attr('data-url-carimbo'));
        $srcs = $crawler->filter('script[src]')->each(static fn ($s): string => (string) $s->attr('src'));
        $modulo = array_keys(array_filter($srcs, static fn (string $s): bool => str_contains($s, 'js/assinatura-ja-assinado.js')));
        $tela = array_keys(array_filter($srcs, static fn (string $s): bool => str_contains($s, 'js/assinatura.js')));
        self::assertCount(1, $modulo);
        self::assertCount(1, $tela);
        self::assertLessThan($tela[0], $modulo[0]);
    }

    private function emMemoria(): ArmazenamentoEmMemoriaNoContainer
    {
        return $this->disco ??= ArmazenamentoEmMemoriaNoContainer::instalarEm(static::getContainer());
    }

    private function assinado(): UploadedFile
    {
        return $this->temporario('assinado.pdf', OficinaDePdfAssinado::pdfAssinado());
    }

    private function conferir(UploadedFile $arquivo): void
    {
        $this->client->request('POST', '/assinatura/pdf/conferir', [], ['arquivo' => $arquivo], ['HTTP_X_CSRF_TOKEN' => self::CSRF]);
    }

    /** @return list<array<string, mixed>> */
    private function versoes(int $documento): array
    {
        return $this->conexao()->fetchAllAssociative('SELECT origem, ordem, arquivo_chave, nome_original, sha256, tamanho, analise FROM assinatura_eletronica_versao WHERE documento_id = ? ORDER BY ordem', [$documento]);
    }

    private function chave(ArmazenamentoEmMemoriaNoContainer $disco, string $nome): ChaveDeArquivo
    {
        foreach ($disco->memoria->gravadas as $chave) {
            if ($chave->nome === $nome) {
                return $chave;
            }
        }

        self::fail('chave não gravada: ' . $nome);
    }

    private function paragrafo(string $bytes): string
    {
        return 'Este documento já continha 1 assinatura(s) digital(is) de ' . OficinaDePdfAssinado::TITULAR . '. O arquivo original foi preservado sem alteração, com hash SHA-256 ' . hash('sha256', $bytes) . '.';
    }
}
