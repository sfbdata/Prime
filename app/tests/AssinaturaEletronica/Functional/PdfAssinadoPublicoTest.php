<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Functional;

use App\AssinaturaEletronica\Controller\AssinaturaPublicaController;
use App\AssinaturaEletronica\DTO\PdfAssinadoConferido;
use App\AssinaturaEletronica\Service\Cadeia\HttpValidadorDeCadeia;
use App\AssinaturaEletronica\Service\ConferenciaDoPdfAssinado;
use App\AssinaturaEletronica\UseCase\ConferirPdfAssinadoUseCase;
use App\Tests\AssinaturaEletronica\Support\NaAssinaturaEnviada;
use App\Tests\AssinaturaEletronica\Support\NaTelaDeAssinatura;
use App\Tests\AssinaturaEletronica\Support\ValidadorDeCadeiaContado;
use App\Tests\AssinaturaEletronica\Unit\ConferenciaDePdf\OficinaDePdfAssinado;
use App\Tests\Auth\Doubles\RateLimiterFactoryEspiao;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoriaNoContainer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * A pré-conferência do PDF assinado (etapa 5, bloco 5C) pela HTTP: `POST /assinar/{token}/pdf-assinado`, multipart
 * com `arquivo` e o CSRF do link. Um documento com o original já assinado (a versão base) e Bia gov.br, enviado com o
 * validador de cadeia dublado como configurado (o dublê falha se for chamado). Nada é gravado: nem versão, nem trilha,
 * nem arquivo. O banco é desfeito pelo DAMA.
 */
#[CoversClass(AssinaturaPublicaController::class)]
#[CoversClass(ConferirPdfAssinadoUseCase::class)]
#[CoversClass(ConferenciaDoPdfAssinado::class)]
#[CoversClass(PdfAssinadoConferido::class)]
final class PdfAssinadoPublicoTest extends JusPrimeWebTestCase
{
    use NaTelaDeAssinatura;
    use NaAssinaturaEnviada;

    /** A página do PDF e o lugar de assinatura de Bia. */
    private const CONTEUDO = [['k' => 'pg', 'n' => 1], ['k' => 'a', 'i' => 0, 'r' => '']];

    private ArmazenamentoEmMemoriaNoContainer $disco;
    private ValidadorDeCadeiaContado $validador;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->disco = ArmazenamentoEmMemoriaNoContainer::instalarEm(static::getContainer());
        $this->validador = new ValidadorDeCadeiaContado();
        static::getContainer()->set(HttpValidadorDeCadeia::class, $this->validador);
        $this->csrfPrevisivel();
    }

    #[TestDox('PDF assinado sobre a base: 200 com o número de assinaturas, o nome e as anteriores preservadas; nada gravado e o validador não é chamado')]
    public function testConfereSemGravar(): void
    {
        [$id, $token, $base] = $this->documentoComGovbr();
        $versoes = $this->versoes($id);
        $trilha = $this->trilha($id);
        $gravadas = \count($this->disco->memoria->gravadas);

        $this->enviarPdf($token, $this->temporario('Contrato assinado.pdf', OficinaDePdfAssinado::pdfAssinadoComVinculo('ec', null, $base)));

        self::assertResponseIsSuccessful();
        self::assertSame(['ok' => true, 'n' => 2, 'arquivo' => 'Contrato assinado.pdf', 'preservadas' => true], $this->json());
        self::assertCabecalhosPublicos($this->client->getResponse());
        self::assertCount(1, $versoes, 'só o original guardado no rascunho');
        self::assertSame($versoes, $this->versoes($id), 'nenhuma versão nova');
        self::assertSame($trilha, $this->trilha($id), 'nenhum evento na trilha');
        self::assertCount($gravadas, $this->disco->memoria->gravadas, 'nenhum arquivo gravado');
        self::assertSame(0, $this->validador->conferencias, 'o validador de cadeia não é chamado');
        self::assertSame('pendente', $this->conexao()->fetchOne('SELECT situacao FROM assinatura_eletronica_signatario WHERE documento_id = ?', [$id]));
    }

    #[TestDox('a página do link leva a URL da pré-conferência')]
    public function testUrlNaPagina(): void
    {
        [, $token] = $this->documentoComGovbr();

        $this->client->request('GET', '/assinar/' . $token);

        self::assertResponseIsSuccessful();
        $raiz = (new Crawler((string) $this->client->getResponse()->getContent()))->filter('body > #bjaPublica');
        self::assertSame('/assinar/' . $token . '/pdf-assinado', $raiz->attr('data-url-pdf-assinado'));
    }

    #[TestDox('recusa da conferência: 422 com o texto do catálogo (o arquivo que não preserva a base, o CPF de outro)')]
    public function testRecusaComOTextoDoCatalogo(): void
    {
        [$id, $token, $base] = $this->documentoComGovbr();
        $trilha = $this->trilha($id);

        $this->enviarPdf($token, $this->temporario('outro.pdf', OficinaDePdfAssinado::pdfAssinadoComVinculo('ec')));
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'Este arquivo não preserva as assinaturas anteriores. Assine o PDF baixado nesta tela, sem imprimir ou salvar como novo.'], $this->json());

        $deOutro = OficinaDePdfAssinado::certificado(OficinaDePdfAssinado::chave('ec'), 'BELTRANA:' . OficinaDePdfAssinado::CPF_2);
        $this->enviarPdf($token, $this->temporario('outro.pdf', OficinaDePdfAssinado::pdfAssinadoComVinculo('ec', $deOutro, $base)));
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'O CPF do certificado não corresponde ao cadastrado para este signatário.'], $this->json());
        self::assertSame($trilha, $this->trilha($id), 'a recusa da pré-conferência não vai para a trilha');
    }

    #[TestDox('arquivo que não é PDF ou que passa de 20 MB: 422 "Não foi possível ler este arquivo."; sem arquivo: "Envie o PDF assinado."')]
    public function testArquivoForaDoTeto(): void
    {
        [, $token] = $this->documentoComGovbr();

        $this->enviarPdf($token, $this->temporario('falso.pdf', 'só texto'));
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'Não foi possível ler este arquivo.'], $this->json());

        $this->enviarPdf($token, $this->grande());
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'Não foi possível ler este arquivo.'], $this->json());

        $this->enviarPdf($token, null);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'Envie o PDF assinado.'], $this->json());
    }

    #[TestDox('CSRF: sem o token da sessão do link é 403 e a cota do limitador próprio não é gasta; com ele, a cota é pelo sha256 do token')]
    public function testCsrfELimitador(): void
    {
        $espiao = new RateLimiterFactoryEspiao();
        static::getContainer()->set('limiter.assinatura_publica_pdf_assinado', $espiao);
        [, $token, $base] = $this->documentoComGovbr();
        $devolvido = OficinaDePdfAssinado::pdfAssinadoComVinculo('ec', null, $base);

        $this->enviarPdf($token, $this->temporario('a.pdf', $devolvido), 'TOKEN_ajax');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(['erro' => 'A página expirou. Recarregue a página e tente de novo.'], $this->json());
        self::assertSame([], $espiao->chavesConsumidas);

        $this->enviarPdf($token, $this->temporario('a.pdf', $devolvido));
        self::assertResponseIsSuccessful();
        self::assertSame([hash('sha256', $token)], $espiao->chavesConsumidas);
    }

    #[TestDox('limitador próprio estourado: 429 com o texto, sem conferir o arquivo')]
    public function testLimiteEstourado(): void
    {
        static::getContainer()->set('limiter.assinatura_publica_pdf_assinado', new RateLimiterFactoryEspiao(false));
        [, $token, $base] = $this->documentoComGovbr();

        $this->enviarPdf($token, $this->temporario('a.pdf', OficinaDePdfAssinado::pdfAssinadoComVinculo('ec', null, $base)));

        self::assertResponseStatusCodeSame(429);
        self::assertSame(['erro' => 'Muitas tentativas. Aguarde alguns minutos e tente de novo.'], $this->json());
        self::assertCabecalhosPublicos($this->client->getResponse());
    }

    /**
     * O 429 de verdade não se vê pela HTTP no teste (o pool do limitador é zerado ao fim de cada requisição, ver
     * {@see RateLimiterFactoryEspiao}): a cota de `assinatura_eletronica.yaml` é conferida no próprio limitador.
     */
    #[TestDox('assinatura_eletronica.yaml dá 6 pré-conferências por minuto a cada link')]
    public function testCotaConfigurada(): void
    {
        /** @var RateLimiterFactoryInterface $limitador */
        $limitador = static::getContainer()->get('limiter.assinatura_publica_pdf_assinado');
        $doLink = $limitador->create(hash('sha256', 'um-link'));
        for ($i = 0; $i < 6; ++$i) {
            self::assertTrue($doLink->consume()->isAccepted(), 'pré-conferência ' . ($i + 1));
        }

        self::assertFalse($doLink->consume()->isAccepted(), 'a 7ª no mesmo minuto');
        self::assertTrue($limitador->create(hash('sha256', 'outro-link'))->consume()->isAccepted(), 'outro link');
    }

    #[TestDox('token desconhecido ou malformado: 404 "Link inválido." com os cabeçalhos')]
    public function testTokenInvalido(): void
    {
        $this->chaveDoMasterLigada();
        foreach ([str_repeat('b', 64), 'curto'] as $token) {
            $this->enviarPdf($token, $this->temporario('a.pdf', OficinaDePdfAssinado::pdfAssinado()));
            self::assertResponseStatusCodeSame(404, $token);
            self::assertSame(['erro' => 'Link inválido.'], $this->json());
            self::assertCabecalhosPublicos($this->client->getResponse());
        }
    }

    /**
     * Rascunho com o original já assinado (a versão base, guardada ao salvar) e Bia gov.br com CPF, enviado. Sai do
     * escritório antes de devolver: a tela pública é sem login.
     *
     * @return array{int, string, string} o id, o token de Bia e os bytes da base
     */
    private function documentoComGovbr(): array
    {
        $this->entrar(true);
        $base = OficinaDePdfAssinado::pdfAssinado();
        $id = $this->salvar(null, [
            'nome' => 'Contrato',
            'signatarios' => [['nome' => 'Bia Souza', 'email' => 'bia@exemplo.com', 'nivel' => 'govbr', 'cpf' => OficinaDePdfAssinado::CPF]],
            'blocos' => self::CONTEUDO,
        ], $this->temporario('contrato.pdf', $base));
        self::assertResponseStatusCodeSame(201);
        [$token] = self::tokens($this->enviar($id));
        $this->sair($this->client);

        return [$id, (string) $token, $base];
    }

    private function enviarPdf(string $token, ?UploadedFile $arquivo, ?string $csrf = null): void
    {
        $this->client->request('POST', '/assinar/' . $token . '/pdf-assinado', [], array_filter(['arquivo' => $arquivo]), [
            'HTTP_X_CSRF_TOKEN' => $csrf ?? 'TOKEN_assinar_' . hash('sha256', $token),
        ]);
    }

    /** PDF de um byte além do teto do motor (20 MB), esparso: só o cabeçalho tem conteúdo. */
    private function grande(): UploadedFile
    {
        $arquivo = $this->temporario('grande.pdf', "%PDF-1.7\n");
        $recurso = fopen($arquivo->getPathname(), 'r+b') ?: self::fail('não abriu o temporário');
        ftruncate($recurso, 20 * 1024 * 1024 + 1);
        fclose($recurso);
        clearstatcache(true, $arquivo->getPathname());

        return new UploadedFile($arquivo->getPathname(), 'grande.pdf', null, null, true);
    }

    /** @return list<array<string, mixed>> */
    private function versoes(int $documento): array
    {
        return $this->conexao()->fetchAllAssociative('SELECT origem, ordem, arquivo_chave, sha256 FROM assinatura_eletronica_versao WHERE documento_id = ? ORDER BY ordem', [$documento]);
    }
}
