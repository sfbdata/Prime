<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Functional;

use App\AssinaturaEletronica\Controller\ArquivosDaAssinaturaController;
use App\AssinaturaEletronica\Controller\AssinaturaPublicaController;
use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Message\GerarPdfFinalDoDocumento;
use App\AssinaturaEletronica\Service\ChaveDaAssinatura;
use App\AssinaturaEletronica\Service\EntregaDoPdfDaAssinatura;
use App\AssinaturaEletronica\Service\PdfFinalComSelo;
use App\AssinaturaEletronica\Service\PdfFinalDoDocumento;
use App\AssinaturaEletronica\Service\VagasDeGeracaoDoPdf;
use App\AssinaturaEletronica\UseCase\ObterPdfDaAssinaturaUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tests\AssinaturaEletronica\Support\NaAssinaturaEnviada;
use App\Tests\AssinaturaEletronica\Support\NaTelaDeAssinatura;
use App\Tests\AssinaturaEletronica\Support\TravasContadas;
use App\Tests\AssinaturaEletronica\Unit\Pdf\FolhaDeTeste;
use App\Tests\AssinaturaEletronica\Unit\Pdf\PdfLido;
use App\Tests\Auth\Doubles\RateLimiterFactoryEspiao;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * "Baixar PDF com selo" pela HTTP (etapa 3, E3-4 e E3-5), contra o disco real (a entrega do guardado lê o arquivo): a
 * rota da tela (sem permissão 403; outro escritório 404), a do signatário (só quem já assinou e com o link valendo), o
 * gerado na hora sem guardar e o concluído guardado uma vez só, com o pedido ao worker na conclusão. Gerar pesa: as
 * duas vagas ocupadas e os limitadores respondem 429 com o texto que a tela mostra.
 */
#[CoversClass(ArquivosDaAssinaturaController::class)]
#[CoversClass(AssinaturaPublicaController::class)]
#[CoversClass(ObterPdfDaAssinaturaUseCase::class)]
#[CoversClass(EntregaDoPdfDaAssinatura::class)]
#[CoversClass(PdfFinalComSelo::class)]
#[CoversClass(VagasDeGeracaoDoPdf::class)]
final class PdfDaAssinaturaTest extends JusPrimeWebTestCase
{
    use NaTelaDeAssinatura;
    use NaAssinaturaEnviada;

    private const MUITOS = 'Muitos PDFs sendo gerados agora. Tente de novo em instantes.';
    private const LIMITE = 'Muitos PDFs gerados nos últimos minutos. Tente de novo em alguns minutos.';
    private const DOCUMENTO_GERANDO = 'O PDF deste documento está sendo gerado. Tente de novo em alguns minutos.';

    private ?string $diretorioDoEscritorio = null;

    protected function setUp(): void
    {
        FolhaDeTeste::exigirFontes($this);
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

    #[TestDox('rascunho: 200 com o PDF gerado na hora, como download com o nome do desenho, e nada guardado')]
    public function testRascunhoNaoGuarda(): void
    {
        $this->entrarComDisco();
        $id = $this->rascunho();

        $pdf = $this->baixar('/assinatura/documentos/' . $id . '/pdf');

        self::assertSame(2, (new PdfLido($pdf))->paginas(), 'o conteúdo e o manifesto');
        self::assertSame('attachment; filename=assinado_Contrato_de_honorarios.pdf', $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertNull($this->conexao()->fetchOne('SELECT arquivo_final_chave FROM assinatura_eletronica_documento WHERE id = ?', [$id]));
        self::assertSame([], $this->pdfsGuardados());
    }

    #[TestDox('sem a permissão do módulo: 403; documento de outro escritório: 404')]
    public function testPermissaoEEscritorio(): void
    {
        $this->entrar(true);
        $id = $this->rascunho();

        $this->entrar(false);
        $this->client->request('GET', '/assinatura/documentos/' . $id . '/pdf');
        self::assertResponseStatusCodeSame(403);

        $this->entrar(true);
        $this->client->request('GET', '/assinatura/documentos/' . $id . '/pdf');
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('link: quem já assinou baixa enquanto aguarda (sem guardar); quem não assinou e o link vencido: "Link inválido."')]
    public function testLinkDoSignatario(): void
    {
        $this->entrarComDisco();
        [$id, $tokens] = $this->documentoEnviado();
        $this->assinarNoLink((string) $tokens[0], $this->pedirCodigo((string) $tokens[0]));
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        self::assertStringStartsWith('%PDF-', $this->baixar('/assinar/' . $tokens[0] . '/pdf'));
        self::assertSame([], $this->pdfsGuardados(), 'aguardando: gerado na hora, sem guardar');

        $this->client->request('GET', '/assinar/' . $tokens[1] . '/pdf');
        self::assertResponseStatusCodeSame(404, 'Bia ainda não assinou');

        $this->conexao()->executeStatement("UPDATE assinatura_eletronica_documento SET vence_em = '2000-01-01 00:00:00' WHERE id = ?", [$id]);
        $this->esquecer();
        $this->client->request('GET', '/assinar/' . $tokens[0] . '/pdf');
        self::assertResponseStatusCodeSame(404, 'o link venceu');
    }

    #[TestDox('concluído: a conclusão pede o PDF ao worker; os downloads (tela e link) entregam o mesmo arquivo, guardado uma vez')]
    public function testConcluidoGuardaUmaVez(): void
    {
        [, $tenant] = $this->entrarComDisco();
        [$id, $tokens] = $this->documentoEnviado();
        $this->assinarNoLink((string) $tokens[0], $this->pedirCodigo((string) $tokens[0]));
        $this->assinarNoLink((string) $tokens[1], $this->pedirCodigo((string) $tokens[1]), ['nome' => 'Bia Souza', 'cpf' => '11144477735']);
        self::assertSame('concluido', $this->json()['estado']['estado']);

        $transporte = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transporte);
        $pedidos = array_values(array_filter(array_map(static fn (Envelope $e): object => $e->getMessage(), $transporte->getSent()), static fn (object $m): bool => $m instanceof GerarPdfFinalDoDocumento));
        self::assertEquals([new GerarPdfFinalDoDocumento($id, (int) $tenant->getId())], $pedidos);

        $daTela = $this->baixar('/assinatura/documentos/' . $id . '/pdf');
        $chave = $this->conexao()->fetchAssociative('SELECT arquivo_final_chave, hash_final FROM assinatura_eletronica_documento WHERE id = ?', [$id]);
        self::assertIsArray($chave);
        self::assertSame(hash('sha256', $daTela), $chave['hash_final']);
        self::assertSame(2, (new PdfLido($daTela))->paginas());

        self::assertSame($daTela, $this->baixar('/assinar/' . $tokens[0] . '/pdf'));
        self::assertSame($daTela, $this->baixar('/assinatura/documentos/' . $id . '/pdf'));
        self::assertCount(1, $this->pdfsGuardados(), 'guardado uma vez só');
        self::assertSame($chave['arquivo_final_chave'], $this->conexao()->fetchOne('SELECT arquivo_final_chave FROM assinatura_eletronica_documento WHERE id = ?', [$id]));
    }

    #[TestDox('fila fora do ar na conclusão: a última assinatura responde concluído e o PDF sai no primeiro download')]
    public function testFilaCaida(): void
    {
        $fila = new class () implements TransportInterface {
            public int $tentativas = 0;

            public function get(): iterable
            {
                return [];
            }

            public function ack(Envelope $envelope): void
            {
            }

            public function reject(Envelope $envelope): void
            {
            }

            public function send(Envelope $envelope): Envelope
            {
                ++$this->tentativas;

                throw new TransportException('fila fora do ar');
            }
        };
        static::getContainer()->set('messenger.transport.async', $fila);
        $this->entrarComDisco();
        [$id, $tokens] = $this->documentoEnviado();
        $this->assinarNoLink((string) $tokens[0], $this->pedirCodigo((string) $tokens[0]));
        $this->assinarNoLink((string) $tokens[1], $this->pedirCodigo((string) $tokens[1]), ['nome' => 'Bia Souza', 'cpf' => '11144477735']);

        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame('concluido', $this->json()['estado']['estado']);
        self::assertSame(1, $fila->tentativas, 'a conclusão tentou enfileirar e a fila recusou');
        self::assertSame('concluido', $this->conexao()->fetchOne('SELECT situacao FROM assinatura_eletronica_documento WHERE id = ?', [$id]));
        self::assertNull($this->conexao()->fetchOne('SELECT arquivo_final_chave FROM assinatura_eletronica_documento WHERE id = ?', [$id]));

        $this->baixar('/assinar/' . $tokens[1] . '/pdf');
        self::assertCount(1, $this->pdfsGuardados(), 'gerado e guardado no primeiro download');
    }

    /** @return iterable<string, array{string}> */
    public static function fimDoEnvio(): iterable
    {
        yield 'documento na Lixeira' => ['lixeira'];
        yield 'envio cancelado' => ['cancelado'];
        yield 'recusado pelo outro signatário' => ['recusado'];
    }

    #[DataProvider('fimDoEnvio')]
    #[TestDox('link de quem já assinou: documento na Lixeira, cancelado ou recusado depois disso dá "Link inválido." (404)')]
    public function testLinkDepoisDoFimDoEnvio(string $fim): void
    {
        $this->entrarComDisco();
        [$id, $tokens] = $this->documentoEnviado();
        $ana = (string) $tokens[0];
        $this->assinarNoLink($ana, $this->pedirCodigo($ana));
        self::assertStringStartsWith('%PDF-', $this->baixar('/assinar/' . $ana . '/pdf'), 'antes, Ana baixa');

        $this->encerrarEnvio($fim, $id, (string) $tokens[1]);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $this->esquecer();

        $this->client->request('GET', '/assinar/' . $ana . '/pdf');
        self::assertResponseStatusCodeSame(404, $fim);
        self::assertSame([], $this->pdfsGuardados());
    }

    #[TestDox('as duas vagas ocupadas: 429 {"erro": "Muitos PDFs..."} na tela e no link; com uma vaga livre, 200, e a geração devolve a vaga')]
    public function testVagasOcupadas(): void
    {
        $this->entrarComDisco();
        [$id, $tokens] = $this->documentoEnviado();
        $ana = (string) $tokens[0];
        $this->assinarNoLink($ana, $this->pedirCodigo($ana));
        $travas = static::getContainer()->get('lock.assinatura_pdf.factory');
        self::assertInstanceOf(LockFactory::class, $travas);
        $ocupadas = [$travas->createLock('assinatura_pdf_vaga_0'), $travas->createLock('assinatura_pdf_vaga_1')];
        self::assertTrue($ocupadas[0]->acquire(false) && $ocupadas[1]->acquire(false));

        foreach (['/assinatura/documentos/' . $id . '/pdf', '/assinar/' . $ana . '/pdf'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(429, $url);
            self::assertSame(['erro' => self::MUITOS], $this->json(), $url);
        }

        $ocupadas[1]->release();
        $this->baixar('/assinar/' . $ana . '/pdf');
        self::assertTrue($travas->createLock('assinatura_pdf_vaga_1')->acquire(false), 'a geração devolveu a vaga');
    }

    #[TestDox('limitador da geração estourado: 429 "Muitos PDFs gerados nos últimos minutos", na tela (por usuário) e no link (por token)')]
    public function testLimitadorDaGeracao(): void
    {
        [$porUsuario, $porIp, $porToken] = $this->espiarLimitadores(usuario: false, ip: true, token: false);
        [$usuario] = $this->entrarComDisco();
        [$id, $tokens] = $this->documentoEnviado();
        $ana = (string) $tokens[0];
        $this->assinarNoLink($ana, $this->pedirCodigo($ana));

        foreach (['/assinatura/documentos/' . $id . '/pdf', '/assinar/' . $ana . '/pdf'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(429, $url);
            self::assertSame(['erro' => self::LIMITE], $this->json(), $url);
        }

        self::assertSame(['usuario-' . $usuario->getId()], $porUsuario->chavesConsumidas);
        self::assertCount(1, $porIp->chavesConsumidas);
        self::assertSame([ChaveDaAssinatura::hashDoToken($ana)], $porToken->chavesConsumidas);
        self::assertSame([], $this->pdfsGuardados());
    }

    #[TestDox('concluído com o PDF guardado: a tela e o link entregam o guardado sem passar por nenhum dos 3 limitadores')]
    public function testGuardadoNaoGastaACota(): void
    {
        $espioes = $this->espiarLimitadores(usuario: false, ip: false, token: false);
        $this->entrarComDisco();
        [$id, $tokens] = $this->documentoEnviado();
        $this->assinarNoLink((string) $tokens[0], $this->pedirCodigo((string) $tokens[0]));
        $this->assinarNoLink((string) $tokens[1], $this->pedirCodigo((string) $tokens[1]), ['nome' => 'Bia Souza', 'cpf' => '11144477735']);
        self::assertSame('concluido', $this->json()['estado']['estado']);
        $this->esquecer();
        $documento = static::getContainer()->get(EntityManagerInterface::class)->find(DocumentoParaAssinar::class, $id);
        self::assertInstanceOf(DocumentoParaAssinar::class, $documento);
        static::getContainer()->get(PdfFinalDoDocumento::class)->garantir($documento);

        $daTela = $this->baixar('/assinatura/documentos/' . $id . '/pdf');
        self::assertSame($daTela, $this->baixar('/assinar/' . $tokens[0] . '/pdf'));
        foreach ($espioes as $espiao) {
            self::assertSame([], $espiao->chavesConsumidas);
        }
        self::assertCount(1, $this->pdfsGuardados());
    }

    #[TestDox('concluído sem PDF guardado que outro pedido já gera: 429 "O PDF deste documento está sendo gerado", sem vaga nem cota')]
    public function testDocumentoSendoGerado(): void
    {
        [$porUsuario, $porIp, $porToken] = $this->espiarLimitadores(usuario: true, ip: true, token: true);
        $this->entrarComDisco();
        [$id, $tokens] = $this->concluido();
        $travas = static::getContainer()->get('lock.assinatura_pdf.factory');
        self::assertInstanceOf(LockFactory::class, $travas);
        $outroPedido = [$travas->createLock('assinatura_pdf_documento_' . $id), $travas->createLock('assinatura_pdf_vaga_0'), $travas->createLock('assinatura_pdf_vaga_1')];
        self::assertTrue($outroPedido[0]->acquire(false) && $outroPedido[1]->acquire(false) && $outroPedido[2]->acquire(false));

        foreach (['/assinatura/documentos/' . $id . '/pdf', '/assinar/' . $tokens[0] . '/pdf'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(429, $url);
            self::assertSame(['erro' => self::DOCUMENTO_GERANDO], $this->json(), $url . ': a trava do documento antes da vaga');
        }
        self::assertSame([[], [], []], [$porUsuario->chavesConsumidas, $porIp->chavesConsumidas, $porToken->chavesConsumidas]);

        foreach ($outroPedido as $trava) {
            $trava->release();
        }
        $this->baixar('/assinatura/documentos/' . $id . '/pdf');
        self::assertCount(1, $this->pdfsGuardados());
        self::assertCount(1, $porUsuario->chavesConsumidas, 'quem gerou gastou a cota');
        self::assertTrue($travas->createLock('assinatura_pdf_documento_' . $id)->acquire(false), 'a trava do documento foi liberada');
    }

    #[TestDox('as travas são renovadas a cada pedaço: a vaga no PDF gerado na hora; a vaga e a do documento no concluído')]
    public function testRenovaAsTravasACadaPedaco(): void
    {
        $loja = new TravasContadas();
        static::getContainer()->set('lock.assinatura_pdf.factory', new LockFactory($loja));
        $this->entrarComDisco();
        [$id, $tokens] = $this->documentoEnviado();
        $this->assinarNoLink((string) $tokens[0], $this->pedirCodigo((string) $tokens[0]));

        $naHora = (new PdfLido($this->baixar('/assinar/' . $tokens[0] . '/pdf')))->paginas() + 2;
        self::assertSame(1 + $naHora, $loja->renovacoesDe('assinatura_pdf_vaga_0'), 'o acquire e um por pedaço');
        self::assertSame(0, $loja->renovacoesDe('assinatura_pdf_documento_' . $id), 'na hora, sem trava do documento');

        $this->assinarNoLink((string) $tokens[1], $this->pedirCodigo((string) $tokens[1]), ['nome' => 'Bia Souza', 'cpf' => '11144477735']);
        $final = (new PdfLido($this->baixar('/assinatura/documentos/' . $id . '/pdf')))->paginas() + 2;
        self::assertSame(1 + $naHora + 1 + $final, $loja->renovacoesDe('assinatura_pdf_vaga_0'));
        self::assertSame(1 + $final, $loja->renovacoesDe('assinatura_pdf_documento_' . $id));
    }

    #[TestDox('as telas recebem a URL do PDF e carregam o assinatura-pdf.js antes do script da tela; o link traz os textos do PDF')]
    public function testLigacaoNasTelas(): void
    {
        $this->entrarComDisco();
        [, $tokens] = $this->documentoEnviado();

        $interna = $this->client->request('GET', '/assinatura');
        self::assertResponseIsSuccessful();
        self::assertSame('/assinatura/documentos/0/pdf', $interna->filter('#bjaPagina')->attr('data-url-pdf'));
        self::assertScriptAntes($interna, 'js/assinatura-pdf.js', 'js/assinatura.js');

        $publica = $this->client->request('GET', '/assinar/' . $tokens[0]);
        self::assertResponseIsSuccessful();
        self::assertSame('/assinar/' . $tokens[0] . '/pdf', $publica->filter('body > #bjaPublica')->attr('data-url-pdf'));
        self::assertScriptAntes($publica, 'js/assinatura-pdf.js', 'js/assinatura-publica.js');
        $catalogo = implode('', $publica->filter('script[type="application/json"][data-bj-t]')->each(static fn (Crawler $s): string => $s->text()));
        self::assertStringContainsString('assinatura_eletronica_tela.js.pdf.baixar', $catalogo);
        self::assertStringContainsString('Muitos PDFs gerados nos últimos minutos. Tente de novo em alguns minutos.', $catalogo, 'o texto do limitador chega ao assinatura-publica.js');
        self::assertStringContainsString(self::DOCUMENTO_GERANDO, $catalogo);
    }

    /** @return array{User, Tenant} */
    private function entrarComDisco(): array
    {
        $entrou = $this->entrar(true);
        $this->diretorioDoEscritorio = rtrim((string) static::getContainer()->getParameter('uploads_dir'), '/') . '/assinatura/' . $entrou[1]->getId();

        return $entrou;
    }

    /** O corpo do download (gerado em fluxo ou guardado), com 200, PDF e `nosniff`. */
    private function baixar(string $url): string
    {
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $resposta = $this->client->getResponse();
        self::assertSame('application/pdf', $resposta->headers->get('Content-Type'));
        self::assertStringStartsWith('attachment', (string) $resposta->headers->get('Content-Disposition'));
        $corpo = (string) $this->client->getInternalResponse()->getContent();
        self::assertStringStartsWith('%PDF-', $corpo);

        return $corpo;
    }

    /**
     * Os 3 limitadores da geração trocados por espiões (aceita ou estourado), antes da primeira requisição: o controller
     * do link recebe os limitadores no construtor.
     *
     * @return array{RateLimiterFactoryEspiao, RateLimiterFactoryEspiao, RateLimiterFactoryEspiao} usuário, IP e token
     */
    private function espiarLimitadores(bool $usuario, bool $ip, bool $token): array
    {
        $espioes = [new RateLimiterFactoryEspiao($usuario), new RateLimiterFactoryEspiao($ip), new RateLimiterFactoryEspiao($token)];
        static::getContainer()->set('limiter.assinatura_pdf', $espioes[0]);
        static::getContainer()->set('limiter.assinatura_pdf_publico', $espioes[1]);
        static::getContainer()->set('limiter.assinatura_pdf_publico_token', $espioes[2]);

        return $espioes;
    }

    /**
     * Ana e Bia assinam: o documento concluído, sem o PDF guardado (o worker não roda no teste).
     *
     * @return array{int, list<string|null>}
     */
    private function concluido(): array
    {
        [$id, $tokens] = $this->documentoEnviado();
        $this->assinarNoLink((string) $tokens[0], $this->pedirCodigo((string) $tokens[0]));
        $this->assinarNoLink((string) $tokens[1], $this->pedirCodigo((string) $tokens[1]), ['nome' => 'Bia Souza', 'cpf' => '11144477735']);
        self::assertSame('concluido', $this->json()['estado']['estado']);

        return [$id, $tokens];
    }

    /** Lixeira e cancelar pela tela do escritório; a recusa pelo link do outro signatário. */
    private function encerrarEnvio(string $fim, int $id, string $outroToken): void
    {
        if ($fim === 'lixeira') {
            $this->postarJson('/assinatura/documentos/lixeira', ['ids' => [$id]]);

            return;
        }
        if ($fim === 'cancelado') {
            $this->client->request('POST', '/assinatura/documentos/' . $id . '/cancelar', [], [], ['HTTP_X_CSRF_TOKEN' => self::CSRF]);

            return;
        }
        $this->postarNoLink('/assinar/' . $outroToken . '/recusa', $outroToken, ['motivo' => 'Valor errado']);
    }

    /** @return list<string> os PDF no diretório do escritório (rubricas são PNG) */
    private function pdfsGuardados(): array
    {
        return glob((string) $this->diretorioDoEscritorio . '/*.pdf') ?: [];
    }

    private static function assertScriptAntes(Crawler $pagina, string $antes, string $depois): void
    {
        $srcs = $pagina->filter('script[src]')->each(static fn (Crawler $s): string => (string) parse_url((string) $s->attr('src'), \PHP_URL_PATH));
        $posicao = static fn (string $fim): int|false => array_search(true, array_map(static fn (string $s): bool => str_ends_with($s, $fim), $srcs), true);

        self::assertIsInt($posicao($antes), implode(', ', $srcs));
        self::assertIsInt($posicao($depois), implode(', ', $srcs));
        self::assertLessThan($posicao($depois), $posicao($antes));
    }
}
