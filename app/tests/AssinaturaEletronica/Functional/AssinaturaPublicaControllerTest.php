<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Functional;

use App\AssinaturaEletronica\Controller\AssinaturaPublicaController;
use App\AssinaturaEletronica\EventListener\AssinaturaPublicaListener;
use App\AssinaturaEletronica\Service\CorreioDaAssinatura;
use App\AssinaturaEletronica\Service\PaginaDoSignatario;
use App\AssinaturaEletronica\UseCase\AbrirPaginaDoSignatarioUseCase;
use App\AssinaturaEletronica\UseCase\AssinarDocumentoUseCase;
use App\AssinaturaEletronica\UseCase\ObterArquivoDoSignatarioUseCase;
use App\AssinaturaEletronica\UseCase\RecusarAssinaturaUseCase;
use App\AssinaturaEletronica\UseCase\SolicitarCodigoUseCase;
use App\Tests\AssinaturaEletronica\Support\NaAssinaturaEnviada;
use App\Tests\AssinaturaEletronica\Support\NaTelaDeAssinatura;
use App\Tests\Auth\Doubles\RateLimiterFactoryEspiao;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoriaNoContainer;
use PHPUnit\Framework\Attributes\CoversClass;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\DomCrawler\Crawler;

/**
 * A tela pública do signatário pela HTTP (contrato da etapa 2, seções 3, 5.2 e 5.3), sem login: link inválido igual
 * para qualquer token ruim, cabeçalhos, CSRF, limitadores, código (erros, validade, teto diário), ordem, prazo,
 * cancelado, recusado, concluído, e-mails e o que a resposta pública NÃO traz.
 */
#[CoversClass(AssinaturaPublicaController::class)]
#[CoversClass(AssinaturaPublicaListener::class)]
#[CoversClass(AbrirPaginaDoSignatarioUseCase::class)]
#[CoversClass(SolicitarCodigoUseCase::class)]
#[CoversClass(AssinarDocumentoUseCase::class)]
#[CoversClass(RecusarAssinaturaUseCase::class)]
#[CoversClass(ObterArquivoDoSignatarioUseCase::class)]
#[CoversClass(PaginaDoSignatario::class)]
#[CoversClass(CorreioDaAssinatura::class)]
final class AssinaturaPublicaControllerTest extends JusPrimeWebTestCase
{
    use NaTelaDeAssinatura;
    use NaAssinaturaEnviada;

    private ArmazenamentoEmMemoriaNoContainer $disco;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->disco = ArmazenamentoEmMemoriaNoContainer::instalarEm(static::getContainer());
        $this->csrfPrevisivel();
        $this->chaveDoMasterLigada();
    }

    #[TestDox('token desconhecido, malformado ou sem formato: a mesma página "Link inválido." com 404 e os cabeçalhos')]
    public function testLinkInvalido(): void
    {
        $paginas = [];
        foreach ([str_repeat('a', 64), str_repeat('A', 64), 'curto', str_repeat('a', 64) . '/outra/coisa'] as $token) {
            $this->client->request('GET', '/assinar/' . $token);
            self::assertResponseStatusCodeSame(404, $token);
            self::assertCabecalhosPublicos($this->client->getResponse());
            $paginas[] = (string) $this->client->getResponse()->getContent();
        }

        self::assertCount(1, array_unique($paginas), 'a página é a mesma para qualquer token ruim');
        self::assertStringContainsString('<title>Assinatura</title>', $paginas[0], 'o título da aba do desenho, L6');
        $publico = $this->publicoDaPagina();
        self::assertSame('invalido', $publico['estado']);
        self::assertNull($publico['documento']);
        self::assertStringNotContainsString('data-csrf', $paginas[0]);

        foreach (['/assinar/' . str_repeat('b', 64) . '/codigo', '/assinar/xyz/codigo', '/assinar/' . str_repeat('b', 64)] as $url) {
            $this->postarNoLink($url, 'qualquer', ['motivo' => 'x']);
            self::assertResponseStatusCodeSame(404, $url);
            self::assertSame(['erro' => 'Link inválido.'], $this->json());
            self::assertCabecalhosPublicos($this->client->getResponse());
        }
    }

    #[TestDox('abrir o link: página sem a moldura, Publico do contrato e link_aberto gravado uma vez')]
    public function testAbrir(): void
    {
        $this->entrar(true);
        [$id, $tokens] = $this->documentoEnviado();
        $this->sair($this->client);

        $publico = $this->abrir((string) $tokens[0]);
        $this->abrir((string) $tokens[0]);

        self::assertCabecalhosPublicos($this->client->getResponse());
        self::assertSame('pronto', $publico['estado']);
        self::assertFalse($publico['previa']);
        self::assertSame(['nome' => 'Ana Lima', 'cpf' => self::CPF_ANA, 'posicao' => 0], $publico['eu']);
        self::assertSame(['Contratante', 'Bia Souza'], array_column($publico['lugares'], 'rotulo'));
        self::assertSame(1, $publico['aceite']['versao']);
        self::assertStringStartsWith('Li o documento e concordo', $publico['aceite']['texto']);
        self::assertSame(['enviado' => false, 'validoAte' => null, 'bloqueadoAte' => null], $publico['codigo']);
        self::assertSame(1, array_count_values($this->trilha($id))['link_aberto']);

        $raiz = (new Crawler((string) $this->client->getResponse()->getContent()))->filter('body > #bjaPublica');
        self::assertNotSame('', (string) $raiz->attr('data-csrf'));
        self::assertStringNotContainsString((string) $tokens[0], (string) $raiz->attr('data-csrf'));
        self::assertSame('/assinar/' . $tokens[0] . '/codigo', $raiz->attr('data-url-codigo'));
        self::assertSame('/assinar/' . $tokens[0], $raiz->attr('data-url-assinar'));
        self::assertSame('/assinar/' . $tokens[0] . '/recusa', $raiz->attr('data-url-recusa'));
        self::assertStringNotContainsString('main-header', (string) $this->client->getResponse()->getContent(), 'sem a moldura do sistema');
    }

    #[TestDox('a resposta pública não traz escritório, e-mail, CPF de outro, ids nem rotas internas')]
    public function testOQueAPaginaNaoMostra(): void
    {
        [, $escritorio] = $this->entrar(true);
        [$id, $tokens] = $this->documentoEnviado();
        $this->sair($this->client);

        $this->abrir((string) $tokens[1]);
        $html = (string) $this->client->getResponse()->getContent();
        $publico = $this->publicoDaPagina();

        foreach ([(string) $escritorio->getName(), 'ana@exemplo.com', 'bia@exemplo.com', self::CPF_ANA, '/assinatura/documentos', 'Advogada Assinatura'] as $proibido) {
            self::assertStringNotContainsString($proibido, $html, $proibido);
        }
        array_walk_recursive($publico, static function (mixed $valor, string|int $chave): void {
            self::assertNotContains($chave, ['id', 'email', 'papel', 'nivel', 'situacao', 'categoria', 'pasta', 'prazoDias', 'tenant'], (string) $chave);
        });
        self::assertSame(['nome', 'cpf', 'posicao'], array_keys($publico['eu']));
        self::assertNotContains((string) $id, array_map('strval', array_column($publico['lugares'], 'rotulo')));
    }

    #[TestDox('CSRF: POST sem o token da sessão do link é 403 e nada é gravado')]
    public function testCsrf(): void
    {
        $this->entrar(true);
        [$id, $tokens] = $this->documentoEnviado();

        $this->postarNoLink('/assinar/' . $tokens[0] . '/codigo', (string) $tokens[0], null, 'TOKEN_ajax');

        self::assertResponseStatusCodeSame(403);
        self::assertSame('A página expirou. Recarregue a página e tente de novo.', $this->json()['erro']);
        self::assertCabecalhosPublicos($this->client->getResponse());
        self::assertNotContains('codigo_enviado', $this->trilha($id));
        self::assertEmailCount(0);
    }

    #[TestDox('limitadores: 429 por IP na página e no código pelo signatário, com o sha256 do token como chave')]
    public function testLimitadores(): void
    {
        $this->entrar(true);
        [, $tokens] = $this->documentoEnviado();
        $espiaoDoToken = new RateLimiterFactoryEspiao();
        static::getContainer()->set('limiter.assinatura_publica_token', $espiaoDoToken);
        static::getContainer()->set('limiter.assinatura_codigo_envio', new RateLimiterFactoryEspiao(false));

        $this->postarNoLink('/assinar/' . $tokens[0] . '/codigo', (string) $tokens[0]);

        self::assertResponseStatusCodeSame(429);
        self::assertSame('Muitas tentativas. Aguarde alguns minutos e tente de novo.', $this->json()['erro']);
        self::assertCabecalhosPublicos($this->client->getResponse());
        self::assertSame([hash('sha256', (string) $tokens[0])], $espiaoDoToken->chavesConsumidas);
        self::assertEmailCount(0);
    }

    #[TestDox('página recusada pelo limitador (429): o cabeçalho do desenho (L234) com logo, separador e nome vazio')]
    public function testPaginaDoLimiteComOCabecalhoDoDesenho(): void
    {
        static::getContainer()->set('limiter.assinatura_publica', new RateLimiterFactoryEspiao(false));

        $crawler = $this->client->request('GET', '/assinar/' . str_repeat('c', 64));

        self::assertResponseStatusCodeSame(429);
        self::assertCount(1, $crawler->filter('body > .bjaPub > header.bjaPubCab[data-bjt-cabecalho] > img.bjaPubMarca + span.bjaOc.bjaPubSep + span.bjaPubNome'));
        self::assertSame('', $crawler->filter('header.bjaPubCab > .bjaPubNome')->text(''));
    }

    #[TestDox('arquivos: o par de limitadores próprio (IP e sha256 do token); a cota da página e dos POST não é gasta')]
    public function testLimitadoresDosArquivos(): void
    {
        $daPagina = new RateLimiterFactoryEspiao(false);
        $daPaginaPorToken = new RateLimiterFactoryEspiao(false);
        $arquivosPorIp = new RateLimiterFactoryEspiao();
        $arquivosPorToken = new RateLimiterFactoryEspiao();
        static::getContainer()->set('limiter.assinatura_publica', $daPagina);
        static::getContainer()->set('limiter.assinatura_publica_token', $daPaginaPorToken);
        static::getContainer()->set('limiter.assinatura_publica_arquivos', $arquivosPorIp);
        static::getContainer()->set('limiter.assinatura_publica_arquivos_token', $arquivosPorToken);
        $token = str_repeat('c', 64);

        foreach (['/arquivo', '/logo', '/rubrica/0'] as $fim) {
            $this->client->request('GET', '/assinar/' . $token . $fim);
            self::assertResponseStatusCodeSame(404, $fim . ': o token desconhecido, não o 429 da página');
            self::assertCabecalhosPublicos($this->client->getResponse());
        }

        self::assertSame([], $daPagina->chavesConsumidas);
        self::assertSame([], $daPaginaPorToken->chavesConsumidas);
        self::assertCount(3, $arquivosPorIp->chavesConsumidas);
        self::assertSame(array_fill(0, 3, hash('sha256', $token)), $arquivosPorToken->chavesConsumidas);
    }

    #[TestDox('arquivos: estourado o limitador dos arquivos, 429 com os cabeçalhos')]
    public function testLimiteDosArquivos(): void
    {
        static::getContainer()->set('limiter.assinatura_publica_arquivos_token', new RateLimiterFactoryEspiao(false));

        $this->client->request('GET', '/assinar/' . str_repeat('c', 64) . '/arquivo');

        self::assertResponseStatusCodeSame(429);
        self::assertCabecalhosPublicos($this->client->getResponse());
    }

    #[TestDox('cabeçalhos pelo caminho: o 405 de método errado e o 404 de /assinar sem barra também os levam')]
    public function testCabecalhosSemRota(): void
    {
        $this->client->request('PUT', '/assinar/' . str_repeat('a', 64));
        self::assertResponseStatusCodeSame(405);
        self::assertCabecalhosPublicos($this->client->getResponse());

        $this->client->request('GET', '/assinar');
        self::assertResponseStatusCodeSame(404);
        self::assertCabecalhosPublicos($this->client->getResponse());
    }

    #[TestDox('logado como usuário de OUTRO escritório: o link abre o documento certo e nada do escritório da sessão aparece')]
    public function testLogadoEmOutroEscritorio(): void
    {
        [$advogada, $escritorio] = $this->entrar(true);
        [$id, $tokens] = $this->documentoEnviado();
        [$outraAdvogada, $outroEscritorio] = $this->entrar(true);
        $this->rascunho(extra: ['nome' => 'Rascunho do outro escritório']);

        $publico = $this->abrir((string) $tokens[0]);

        self::assertSame('pronto', $publico['estado']);
        self::assertSame('Contrato de honorários', $publico['documento']['nome']);
        self::assertSame(['nome' => 'Ana Lima', 'cpf' => self::CPF_ANA, 'posicao' => 0], $publico['eu']);
        self::assertSame(['Contratante', 'Bia Souza'], array_column($publico['lugares'], 'rotulo'));
        $html = (string) $this->client->getResponse()->getContent();
        foreach ([(string) $outroEscritorio->getName(), (string) $outraAdvogada->getEmail(), 'Rascunho do outro escritório', (string) $escritorio->getName(), (string) $advogada->getEmail()] as $proibido) {
            self::assertStringNotContainsString($proibido, $html, $proibido);
        }
        self::assertSame(1, array_count_values($this->trilha($id))['link_aberto']);

        // O POST também acha o documento com a sessão de outro escritório (o filtro de escritório sai nas rotas públicas).
        $this->pedirCodigo((string) $tokens[0]);
        self::assertSame('ana@exemplo.com', self::getMailerMessage()?->getTo()[0]->getAddress());
        self::assertContains('codigo_enviado', $this->trilha($id));
    }

    #[TestDox('limitador por IP estourado: a página diz para esperar, com 429, sem o JSON nem o script da tela')]
    public function testLimitePorIp(): void
    {
        static::getContainer()->set('limiter.assinatura_publica', new RateLimiterFactoryEspiao(false));

        $this->client->request('GET', '/assinar/' . str_repeat('a', 64));

        self::assertResponseStatusCodeSame(429);
        self::assertCabecalhosPublicos($this->client->getResponse());
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Muitas tentativas. Aguarde alguns minutos e tente de novo.', $html);
        self::assertStringNotContainsString('bjaPublica', $html);
    }

    #[TestDox('assinar: código por e-mail (sem link), rubrica guardada, conclusão e os e-mails ao remetente')]
    public function testAssinarAteConcluir(): void
    {
        [$advogada, $escritorio] = $this->entrar(true);
        [$id, $tokens] = $this->documentoEnviado();
        $this->sair($this->client);

        $codigo = $this->pedirCodigo((string) $tokens[0]);
        $email = self::getMailerMessage();
        self::assertSame('ana@exemplo.com', $email?->getTo()[0]->getAddress());
        self::assertSame('Código de confirmação da assinatura', $email?->getSubject());
        self::assertStringNotContainsString('/assinar/', (string) $email?->getHtmlBody());
        self::assertSame((string) $advogada->getEmail(), $email?->getReplyTo()[0]->getAddress());
        self::assertTrue($this->abrir((string) $tokens[0])['codigo']['enviado']);

        $this->assinarNoLink((string) $tokens[0], $codigo);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $resposta = $this->json();
        self::assertSame('ja_assinado', $resposta['estado']['estado']);
        self::assertStringNotContainsString($codigo, (string) $this->client->getResponse()->getContent());
        self::assertEmailCount(0);

        $bia = $this->abrir((string) $tokens[1]);
        self::assertSame('/assinar/' . $tokens[1] . '/rubrica/0', $bia['lugares'][0]['rubrica']);
        self::assertNotNull($bia['lugares'][0]['assinadoEm']);
        self::assertNull($bia['lugares'][1]['rubrica']);
        self::assertSame(base64_decode(self::PNG_1X1, true), $this->rubricaGuardada($id, 0));

        $this->assinarNoLink((string) $tokens[1], $this->pedirCodigo((string) $tokens[1]), ['nome' => 'Bia Souza', 'cpf' => '11144477735']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame('concluido', $this->json()['estado']['estado']);
        self::assertEmailCount(1);
        self::assertSame((string) $advogada->getEmail(), self::getMailerMessage()?->getTo()[0]->getAddress());
        self::assertSame('Documento assinado: Contrato de honorários', preg_replace('/^\[[^\]]+\] /', '', (string) self::getMailerMessage()?->getSubject()));

        $trilha = $this->trilha($id);
        self::assertSame(2, array_count_values($trilha)['assinado']);
        self::assertSame('documento_concluido', end($trilha));
        // Depois das páginas públicas, o detalhe pede o login de novo.
        $this->logarComTenant($this->client, $advogada, $escritorio);
        self::assertSame('Documento concluído', $this->detalhe($id)['eventos'][0]['texto'], 'o texto do desenho, L390');
        $dados = json_decode((string) $this->conexao()->fetchOne("SELECT dados FROM assinatura_eletronica_evento WHERE documento_id = ? AND tipo = 'assinado' ORDER BY id LIMIT 1", [$id]), true);
        self::assertSame(['aceite', 'chave', 'cpf_confere', 'metodo', 'modo', 'rubrica_sha256'], self::ordenadas($dados));
        self::assertSame('simples', $dados['metodo']);
        self::assertTrue($dados['cpf_confere']);
        self::assertSame(1, $dados['chave']);
        self::assertSame('concluido', $this->abrir((string) $tokens[0])['estado']);
        $this->client->request('GET', (string) $bia['lugares'][0]['rubrica']);
        self::assertResponseStatusCodeSame(404, 'fora do estado pronto, os arquivos fecham');
        self::assertCabecalhosPublicos($this->client->getResponse());
    }

    #[TestDox('código errado: 4 vezes "incorreto", a 5ª queima o código; vencido pede outro')]
    public function testCodigoErradoEVencido(): void
    {
        $this->entrar(true);
        [$id, $tokens] = $this->documentoEnviado();
        $token = (string) $tokens[0];
        $codigo = $this->pedirCodigo($token);
        $errado = $codigo === '999999' ? '999998' : '999999';

        foreach ([1, 2, 3, 4] as $vez) {
            $this->assinarNoLink($token, $errado);
            self::assertResponseStatusCodeSame(422);
            self::assertSame('Código incorreto.', $this->json()['erro'], 'erro ' . $vez);
        }
        $this->assinarNoLink($token, $errado);
        self::assertSame('Código incorreto. Solicite um novo código.', $this->json()['erro']);
        $this->assinarNoLink($token, $codigo);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Solicite o código de confirmação.', $this->json()['erro']);
        self::assertSame(5, array_count_values($this->trilha($id))['codigo_errado']);
        self::assertStringNotContainsString($codigo, (string) json_encode($this->conexao()->fetchFirstColumn('SELECT dados FROM assinatura_eletronica_evento WHERE documento_id = ?', [$id])));

        $novo = $this->pedirCodigo($token);
        $this->conexao()->executeStatement("UPDATE assinatura_eletronica_signatario SET codigo_enviado_em = codigo_enviado_em - INTERVAL '16 minutes' WHERE documento_id = ? AND ordem = 0", [$id]);
        $this->assinarNoLink($token, $novo);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('O código expirou. Solicite um novo código.', $this->json()['erro']);
    }

    #[TestDox('teto diário: 10 códigos em 24 h bloqueiam o 11º até o fim da janela')]
    public function testTetoDeCodigos(): void
    {
        $this->entrar(true);
        [$id, $tokens] = $this->documentoEnviado();
        $token = (string) $tokens[0];

        for ($vez = 1; $vez <= 10; ++$vez) {
            $this->pedirCodigo($token);
        }
        $this->postarNoLink('/assinar/' . $token . '/codigo', $token);

        self::assertResponseStatusCodeSame(429);
        self::assertNotNull($this->conexao()->fetchOne('SELECT bloqueado_ate FROM assinatura_eletronica_signatario WHERE documento_id = ? AND ordem = 0', [$id]));
        self::assertNotNull($this->abrir($token)['codigo']['bloqueadoAte']);
    }

    #[TestDox('teto diário: o 15º código errado em 24 h bloqueia')]
    public function testTetoDeErros(): void
    {
        $this->entrar(true);
        [$id, $tokens] = $this->documentoEnviado();
        $token = (string) $tokens[0];

        for ($codigo = 1; $codigo <= 3; ++$codigo) {
            $certo = $this->pedirCodigo($token);
            for ($erro = 1; $erro <= 5; ++$erro) {
                $this->assinarNoLink($token, $certo === '999999' ? '999998' : '999999');
            }
        }

        self::assertResponseStatusCodeSame(429);
        self::assertSame(15, array_count_values($this->trilha($id))['codigo_errado']);
        $this->postarNoLink('/assinar/' . $token . '/codigo', $token);
        self::assertResponseStatusCodeSame(429);
    }

    #[TestDox('em ordem: o segundo aguarda o primeiro (com o nome dele) e não pede código')]
    public function testOrdem(): void
    {
        [$advogada] = $this->entrar(true);
        [, $tokens] = $this->documentoEnviado(emOrdem: true);

        $publico = $this->abrir((string) $tokens[1]);
        self::assertSame('aguardando_anterior', $publico['estado']);
        self::assertSame('Ana Lima', $publico['anterior']);
        self::assertSame([], $publico['lugares']);
        self::assertNull($publico['eu']);
        self::assertSame(['nome', 'hashEnviado'], array_keys($publico['documento']), 'no bloqueio, só o cabeçalho');

        $this->postarNoLink('/assinar/' . $tokens[1] . '/codigo', (string) $tokens[1]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Aguardando assinatura anterior.', $this->json()['erro']);

        $this->assinarNoLink((string) $tokens[0], $this->pedirCodigo((string) $tokens[0]));
        self::assertResponseIsSuccessful();
        self::assertEmailCount(1);
        self::assertSame('bia@exemplo.com', self::getMailerMessage()?->getTo()[0]->getAddress());
        self::assertSame('Sua vez de assinar: Contrato de honorários', self::getMailerMessage()?->getSubject());
        self::assertSame((string) $advogada->getEmail(), self::getMailerMessage()?->getReplyTo()[0]->getAddress(), 'Reply-To de quem enviou');
        self::assertSame('pronto', $this->abrir((string) $tokens[1])['estado']);
    }

    #[TestDox('prazo vencido, envio cancelado e documento na Lixeira: bloqueio e arquivos fechados')]
    public function testBloqueios(): void
    {
        $this->entrar(true);
        [$id, $tokens] = $this->documentoEnviado();
        $token = (string) $tokens[0];

        // A hora do PHP (fuso do sistema), não o NOW() do banco (UTC e congelado na transação do teste).
        $this->conexao()->executeStatement('UPDATE assinatura_eletronica_documento SET vence_em = ? WHERE id = ?', [(new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'), $id]);
        $this->esquecer();
        $publico = $this->abrir($token);
        self::assertSame('prazo_encerrado', $publico['estado']);
        self::assertSame(['nome', 'hashEnviado'], array_keys($publico['documento']), 'no bloqueio, só o cabeçalho');

        $this->conexao()->executeStatement('UPDATE assinatura_eletronica_documento SET vence_em = ? WHERE id = ?', [(new \DateTimeImmutable('+1 day'))->format('Y-m-d H:i:s'), $id]);
        $this->esquecer();
        $this->postarJson('/assinatura/documentos/lixeira', ['ids' => [$id]]);
        self::assertResponseIsSuccessful();
        $publico = $this->abrir($token);
        self::assertSame('cancelado', $publico['estado']);
        self::assertSame(['nome' => 'Contrato de honorários', 'hashEnviado' => $this->detalhe($id)['hashEnviado']], $publico['documento']);
        $this->postarNoLink('/assinar/' . $token . '/codigo', $token);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Envio cancelado. Este documento não está mais disponível para assinatura.', $this->json()['erro']);
    }

    #[TestDox('recusa: quem recusou vê "recusado", o outro vê "cancelado"; o remetente recebe o motivo')]
    public function testRecusa(): void
    {
        [$advogada] = $this->entrar(true);
        [$id, $tokens] = $this->documentoEnviado();

        $this->postarNoLink('/assinar/' . $tokens[0] . '/recusa', (string) $tokens[0], ['motivo' => '']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Informe o motivo.', $this->json()['erro']);
        $this->postarNoLink('/assinar/' . $tokens[0] . '/recusa', (string) $tokens[0], ['motivo' => str_repeat('m', 501)]);
        self::assertResponseStatusCodeSame(422);

        $this->postarNoLink('/assinar/' . $tokens[0] . '/recusa', (string) $tokens[0], ['motivo' => 'Valor errado']);
        self::assertResponseIsSuccessful();
        self::assertSame('recusado', $this->json()['estado']['estado']);
        self::assertEmailCount(1);
        self::assertSame((string) $advogada->getEmail(), self::getMailerMessage()?->getTo()[0]->getAddress());
        self::assertStringContainsString('Valor errado', (string) self::getMailerMessage()?->getHtmlBody());

        self::assertSame('recusado', $this->abrir((string) $tokens[0])['estado']);
        self::assertSame('cancelado', $this->abrir((string) $tokens[1])['estado']);
        $detalhe = $this->detalhe($id);
        self::assertSame('recusado', $detalhe['situacao']);
        self::assertSame('Valor errado', $detalhe['signatarios'][0]['recusadoMotivo']);
        self::assertContains('Recusado por Ana Lima: Valor errado', array_column($detalhe['eventos'], 'texto'));
    }

    #[TestDox('o detalhe traz o nome digitado e a rubrica de quem assinou')]
    public function testDetalheDepoisDeAssinar(): void
    {
        $this->entrar(true);
        [$id, $tokens] = $this->documentoEnviado();

        $this->assinarNoLink((string) $tokens[0], $this->pedirCodigo((string) $tokens[0]), ['modo' => 'digitada']);
        self::assertResponseIsSuccessful();

        $ana = $this->detalhe($id)['signatarios'][0];
        self::assertSame('Ana Maria Lima', $ana['nomeDigitado']);
        self::assertSame('assinado', $ana['situacao']);
        self::assertNull($ana['link']);
        self::assertStringEndsWith('/assinatura/documentos/' . $id . '/rubrica/0', (string) $ana['rubrica']);
        self::assertSame(base64_decode(self::PNG_1X1, true), $this->rubricaGuardada($id, 0));
        self::assertContains('Assinado por Ana Maria Lima · E-mail e código', array_column($this->detalhe($id)['eventos'], 'texto'));
    }

    /** O PNG guardado como rubrica do signatário (o dublê em memória confere escopo e categoria da chave). */
    private function rubricaGuardada(int $id, int $ordem): string
    {
        $linha = $this->conexao()->fetchAssociative('SELECT s.rubrica_chave, d.tenant_id FROM assinatura_eletronica_signatario s JOIN assinatura_eletronica_documento d ON d.id = s.documento_id WHERE d.id = ? AND s.ordem = ?', [$id, $ordem]);
        self::assertIsArray($linha);

        return $this->disco->ler(new ChaveDeArquivo(EscopoDeArquivo::deTenant((int) $linha['tenant_id']), CategoriaDeArquivo::ASSINATURA_DOCUMENTO, (string) $linha['rubrica_chave']));
    }

    /**
     * @param array<string, mixed> $dados
     *
     * @return list<string>
     */
    private static function ordenadas(array $dados): array
    {
        $chaves = array_values(array_diff(array_keys($dados), ['porta']));
        sort($chaves);

        return $chaves;
    }
}
