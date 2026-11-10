<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Functional;

use App\AssinaturaEletronica\Controller\AssinaturaPublicaController;
use App\AssinaturaEletronica\Repository\DocumentoParaAssinarRepository;
use App\AssinaturaEletronica\Repository\VersaoDoPdfRepository;
use App\AssinaturaEletronica\Service\Cadeia\HttpValidadorDeCadeia;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\ConferenciaDePdf;
use App\AssinaturaEletronica\Service\PdfDaAssinatura;
use App\AssinaturaEletronica\Service\PdfParaAssinarCongelado;
use App\AssinaturaEletronica\Service\SignatarioDoLink;
use App\AssinaturaEletronica\Service\TrilhaDoDocumento;
use App\AssinaturaEletronica\UseCase\ObterPdfParaAssinarUseCase;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\Tests\AssinaturaEletronica\Support\NaAssinaturaEnviada;
use App\Tests\AssinaturaEletronica\Support\NaTelaDeAssinatura;
use App\Tests\AssinaturaEletronica\Support\ValidadorDeCadeiaContado;
use App\Tests\AssinaturaEletronica\Unit\ConferenciaDePdf\OficinaDePdfAssinado;
use App\Tests\AssinaturaEletronica\Unit\Pdf\FolhaDeTeste;
use App\Tests\Auth\Doubles\RateLimiterFactoryEspiao;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Clock\ClockInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;

/**
 * "Baixar PDF para assinar" pela HTTP (etapa 5, bloco 5C): `GET /assinar/{token}/para-assinar`, contra o disco real (a
 * entrega lê o arquivo guardado). Bia assina com gov.br; o validador de cadeia é dublado como configurado (sem ele o
 * envio com gov.br não sai). Com o original já assinado, entrega a versão dele sem gerar; sem versão, gera, congela e
 * entrega a mesma da segunda vez. O excesso responde 429 e o PDF acima do teto 422, com o texto que a página mostra.
 */
#[CoversClass(AssinaturaPublicaController::class)]
#[CoversClass(ObterPdfParaAssinarUseCase::class)]
#[CoversClass(PdfParaAssinarCongelado::class)]
final class PdfParaAssinarPublicoTest extends JusPrimeWebTestCase
{
    use NaTelaDeAssinatura;
    use NaAssinaturaEnviada;

    private const LIMITE = 'Muitos PDFs gerados nos últimos minutos. Tente de novo em alguns minutos.';
    private const GRANDE_DEMAIS = 'O PDF deste documento passa de 19 MB, o limite para assinar com certificado. Avise quem enviou o documento para assinar.';

    private ?string $diretorioDoEscritorio = null;

    protected function setUp(): void
    {
        FolhaDeTeste::exigirFontes($this);
        $this->client = static::createClient();
        $this->client->disableReboot();
        static::getContainer()->set(HttpValidadorDeCadeia::class, new ValidadorDeCadeiaContado());
        $this->csrfPrevisivel();
    }

    protected function tearDown(): void
    {
        if ($this->diretorioDoEscritorio !== null) {
            (new Filesystem())->remove($this->diretorioDoEscritorio);
        }
        parent::tearDown();
    }

    #[TestDox('com o original já assinado: 200 com a versão guardada como "{nome} - para assinar.pdf", sem gerar nem gastar a cota; a página leva a URL')]
    public function testComVersaoAnterior(): void
    {
        [$porIp, $porToken] = $this->espiarLimitadores(true);
        $base = OficinaDePdfAssinado::pdfAssinado();
        [$id, $token] = $this->comGovbr($base);

        $pdf = $this->baixar('/assinar/' . $token . '/para-assinar');

        self::assertSame($base, $pdf);
        self::assertSame('attachment; filename="Contrato - para assinar.pdf"', $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertCabecalhosPublicos($this->client->getResponse());
        self::assertSame(['original'], $this->origens($id), 'nenhuma versão nova');
        self::assertSame([], $porIp->chavesConsumidas);
        self::assertSame([], $porToken->chavesConsumidas);

        $this->client->request('GET', '/assinar/' . $token);
        $raiz = (new Crawler((string) $this->client->getResponse()->getContent()))->filter('body > #bjaPublica');
        self::assertSame('/assinar/' . $token . '/para-assinar', $raiz->attr('data-url-para-assinar'));
    }

    #[TestDox('sem versão: 200 com o PDF gerado no modo assinar como para_assinar_<nome>.pdf, congelado com o evento; a segunda vez entrega o mesmo sem gerar')]
    public function testSemVersaoCongela(): void
    {
        [$porIp] = $this->espiarLimitadores(true);
        [$id, $token] = $this->comGovbr();

        $primeiro = $this->baixar('/assinar/' . $token . '/para-assinar');

        self::assertSame('attachment; filename=para_assinar_Contrato.pdf', $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertSame(['para_assinar'], $this->origens($id));
        self::assertSame(hash('sha256', $primeiro), $this->conexao()->fetchOne('SELECT sha256 FROM assinatura_eletronica_versao WHERE documento_id = ?', [$id]));
        self::assertContains('pdf_para_assinar_gerado', $this->trilha($id));

        self::assertSame($primeiro, $this->baixar('/assinar/' . $token . '/para-assinar'));
        self::assertSame(['para_assinar'], $this->origens($id), 'congelado uma vez só');
        self::assertCount(1, $porIp->chavesConsumidas, 'só o pedido que gera gasta a cota');
        self::assertSame(1, \count(array_filter($this->trilha($id), static fn (string $t): bool => $t === 'pdf_para_assinar_gerado')));
    }

    #[TestDox('token desconhecido, malformado ou de signatário Simples: 404 "Link inválido." com os cabeçalhos')]
    public function testLinkInvalido(): void
    {
        $this->entrarComDisco();
        [, $tokens] = $this->documentoEnviado();
        $this->sair($this->client);

        foreach ([str_repeat('b', 64), 'curto', (string) $tokens[0]] as $token) {
            $this->client->request('GET', '/assinar/' . $token . '/para-assinar');
            self::assertResponseStatusCodeSame(404, $token);
            self::assertCabecalhosPublicos($this->client->getResponse());
            self::assertCount(1, (new Crawler((string) $this->client->getResponse()->getContent()))->filter('body > #bjaPublica'), $token);
        }
    }

    #[TestDox('limitador da geração estourado: 429 {"erro": "Muitos PDFs gerados..."} pelo sha256 do token, sem congelar')]
    public function testLimitadorDaGeracao(): void
    {
        [, $porToken] = $this->espiarLimitadores(false);
        [$id, $token] = $this->comGovbr();

        $this->client->request('GET', '/assinar/' . $token . '/para-assinar');

        self::assertResponseStatusCodeSame(429);
        self::assertSame(['erro' => self::LIMITE], $this->json());
        self::assertSame([hash('sha256', $token)], $porToken->chavesConsumidas);
        self::assertSame([], $this->origens($id));
    }

    #[TestDox('PDF acima do teto: 422 {"erro": ...} com o texto do catálogo público, sem versão nem evento')]
    public function testAcimaDoTeto(): void
    {
        [$id, $token] = $this->comGovbr();
        $this->tetoDe(100);

        $this->client->request('GET', '/assinar/' . $token . '/para-assinar');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => self::GRANDE_DEMAIS], $this->json());
        self::assertSame([], $this->origens($id));
        self::assertNotContains('pdf_para_assinar_gerado', $this->trilha($id));
    }

    /**
     * Rascunho com Bia gov.br (com CPF), enviado; com `$original`, o PDF já assinado vira a versão guardada no rascunho.
     * Sai do escritório antes de devolver: a tela pública é sem login.
     *
     * @return array{int, string} o id e o token de Bia
     */
    private function comGovbr(?string $original = null): array
    {
        $this->entrarComDisco();
        $dados = [
            'nome' => 'Contrato',
            'signatarios' => [['nome' => 'Bia Souza', 'email' => 'bia@exemplo.com', 'nivel' => 'govbr', 'cpf' => OficinaDePdfAssinado::CPF]],
            'blocos' => $original !== null
                ? [['k' => 'pg', 'n' => 1], ['k' => 'a', 'i' => 0, 'r' => '']]
                : [['k' => 't', 'x' => 'Cláusula primeira.', 'full' => 0], ['k' => 'a', 'i' => 0, 'r' => '']],
        ];
        $id = $this->salvar(null, $dados, $original !== null ? $this->temporario('contrato.pdf', $original) : null);
        self::assertGreaterThan(0, $id, (string) $this->client->getResponse()->getContent());
        [$token] = self::tokens($this->enviar($id));
        $this->sair($this->client);

        return [$id, (string) $token];
    }

    private function entrarComDisco(): void
    {
        $entrou = $this->entrar(true);
        $this->diretorioDoEscritorio = rtrim((string) static::getContainer()->getParameter('uploads_dir'), '/') . '/assinatura/' . $entrou[1]->getId();
    }

    /** O corpo do download guardado, com 200, PDF e `nosniff`. */
    private function baixar(string $url): string
    {
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $resposta = $this->client->getResponse();
        self::assertSame('application/pdf', $resposta->headers->get('Content-Type'));
        self::assertSame('nosniff', $resposta->headers->get('X-Content-Type-Options'));
        $corpo = (string) $this->client->getInternalResponse()->getContent();
        self::assertStringStartsWith('%PDF-', $corpo);

        return $corpo;
    }

    /**
     * Os limitadores da geração no link (por IP e pelo sha256 do token) trocados por espiões, antes da primeira
     * requisição: o controller os recebe no construtor.
     *
     * @return array{RateLimiterFactoryEspiao, RateLimiterFactoryEspiao} IP e token
     */
    private function espiarLimitadores(bool $aceita): array
    {
        $espioes = [new RateLimiterFactoryEspiao(true), new RateLimiterFactoryEspiao($aceita)];
        static::getContainer()->set('limiter.assinatura_pdf_publico', $espioes[0]);
        static::getContainer()->set('limiter.assinatura_pdf_publico_token', $espioes[1]);

        return $espioes;
    }

    /** O mesmo serviço do container, com outro teto (o padrão de 19 MiB não cabe num teste). */
    private function tetoDe(int $teto): void
    {
        $c = static::getContainer();
        $c->set(PdfParaAssinarCongelado::class, new PdfParaAssinarCongelado(
            $c->get(PdfDaAssinatura::class),
            $c->get(ArmazenamentoDeArquivos::class),
            $c->get(DocumentoParaAssinarRepository::class),
            $c->get(VersaoDoPdfRepository::class),
            $c->get(SignatarioDoLink::class),
            $c->get(TrilhaDoDocumento::class),
            $c->get(ConferenciaDePdf::class),
            $c->get(RemocaoAposTransacao::class),
            $c->get(ClockInterface::class),
            $teto,
        ));
    }

    /** @return list<string> a origem das versões do documento, pela ordem */
    private function origens(int $documento): array
    {
        return array_map('strval', $this->conexao()->fetchFirstColumn('SELECT origem FROM assinatura_eletronica_versao WHERE documento_id = ? ORDER BY ordem', [$documento]));
    }
}
