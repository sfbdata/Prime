<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Functional;

use App\AssinaturaEletronica\Controller\ArquivosDaAssinaturaController;
use App\AssinaturaEletronica\Controller\AssinaturaEletronicaController;
use App\AssinaturaEletronica\Controller\DocumentosDaAssinaturaController;
use App\AssinaturaEletronica\Service\ApresentacaoDoDocumento;
use App\AssinaturaEletronica\Service\ChaveDaAssinatura;
use App\AssinaturaEletronica\UseCase\CancelarEnvioUseCase;
use App\AssinaturaEletronica\UseCase\EnviarDocumentoUseCase;
use App\AssinaturaEletronica\UseCase\EnviarLembreteUseCase;
use App\AssinaturaEletronica\UseCase\ObterPreviaDoSignatarioUseCase;
use App\Tests\AssinaturaEletronica\Support\NaAssinaturaEnviada;
use App\Tests\AssinaturaEletronica\Support\NaTelaDeAssinatura;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoriaNoContainer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * As rotas internas da etapa 2 pela HTTP (contrato, seção 5.1): enviar, lembrete, cancelar o envio, a prévia da tela do
 * signatário e a rubrica. Porta do módulo, escritório alheio (404), recusas por situação (422), e-mail exigido, nível
 * não Simples, link nulo sem segredo, lembrete uma vez por hora e a prévia sem gravar nada.
 */
#[CoversClass(DocumentosDaAssinaturaController::class)]
#[CoversClass(AssinaturaEletronicaController::class)]
#[CoversClass(ArquivosDaAssinaturaController::class)]
#[CoversClass(EnviarDocumentoUseCase::class)]
#[CoversClass(EnviarLembreteUseCase::class)]
#[CoversClass(CancelarEnvioUseCase::class)]
#[CoversClass(ObterPreviaDoSignatarioUseCase::class)]
#[CoversClass(ApresentacaoDoDocumento::class)]
final class EnvioDaAssinaturaControllerTest extends JusPrimeWebTestCase
{
    use NaTelaDeAssinatura;
    use NaAssinaturaEnviada;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        ArmazenamentoEmMemoriaNoContainer::instalarEm(static::getContainer());
        $this->csrfPrevisivel();
    }

    /** @return iterable<string, array{string, string}> */
    public static function rotas(): iterable
    {
        yield 'enviar' => ['POST', '/assinatura/documentos/1/enviar'];
        yield 'lembrete' => ['POST', '/assinatura/documentos/1/lembrete'];
        yield 'cancelar' => ['POST', '/assinatura/documentos/1/cancelar'];
        yield 'prévia' => ['GET', '/assinatura/documentos/1/previa/0'];
        yield 'rubrica' => ['GET', '/assinatura/documentos/1/rubrica/0'];
    }

    #[DataProvider('rotas')]
    #[TestDox('sem o módulo: 403 em $_dataName')]
    public function testSemModulo(string $metodo, string $url): void
    {
        $this->entrar(false);

        $this->client->request($metodo, $url, [], [], ['HTTP_X_CSRF_TOKEN' => self::CSRF]);

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('documento de outro escritório: 404 em enviar, lembrete, cancelar, prévia e rubrica')]
    public function testOutroEscritorio(): void
    {
        $this->entrar(true);
        $id = $this->rascunho();
        $this->entrar(true);

        foreach (['enviar', 'lembrete', 'cancelar'] as $acao) {
            $this->client->request('POST', '/assinatura/documentos/' . $id . '/' . $acao, [], [], ['HTTP_X_CSRF_TOKEN' => self::CSRF]);
            self::assertResponseStatusCodeSame(404, $acao);
        }
        foreach (['previa', 'rubrica'] as $rota) {
            $this->client->request('GET', '/assinatura/documentos/' . $id . '/' . $rota . '/0');
            self::assertResponseStatusCodeSame(404, $rota);
        }
        self::assertSame(['rascunho_salvo'], $this->trilha($id));
    }

    #[TestDox('enviar: congela o conteúdo, guarda só o sha256 do token e convida os dois por e-mail')]
    public function testEnviar(): void
    {
        $this->entrar(true);
        $id = $this->rascunho();

        $this->client->request('POST', '/assinatura/documentos/' . $id . '/enviar', [], [], ['HTTP_X_CSRF_TOKEN' => self::CSRF]);

        self::assertResponseIsSuccessful();
        self::assertEmailCount(2);
        $convite = self::getMailerMessage();
        self::assertNotNull($convite);
        self::assertSame('Documento para assinatura: Contrato de honorários', $convite->getSubject());
        self::assertStringEndsWith(' via BlueJus', (string) $convite->getFrom()[0]->getName());
        self::assertStringContainsString('Leia com atenção.', (string) $convite->getHtmlBody());

        $detalhe = $this->json()['documento'];
        self::assertSame('aguardando', $detalhe['situacao']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $detalhe['hashEnviado']);
        self::assertContains('Enviado para assinatura. Hash ' . substr((string) $detalhe['hashEnviado'], 0, 16) . '...', array_column($detalhe['eventos'], 'texto'), 'o texto do desenho, L375');
        self::assertNotNull($detalhe['enviadoEm']);
        self::assertNull($detalhe['concluidoEm']);
        $tokens = self::tokens($detalhe);
        self::assertCount(2, array_filter($tokens));
        self::assertStringContainsString('/assinar/' . $tokens[0], (string) $convite->getHtmlBody());
        self::assertStringContainsString('/assinar/' . $tokens[1], (string) self::getMailerMessage(1)?->getHtmlBody());

        $guardados = $this->conexao()->fetchFirstColumn('SELECT token_hash FROM assinatura_eletronica_signatario s JOIN assinatura_eletronica_documento d ON d.id = s.documento_id WHERE d.id = ? ORDER BY s.ordem', [$id]);
        self::assertSame([hash('sha256', (string) $tokens[0]), hash('sha256', (string) $tokens[1])], $guardados);
        self::assertSame(['rascunho_salvo', 'documento_enviado', 'convite_enviado', 'convite_enviado'], $this->trilha($id));
        self::assertStringNotContainsString((string) $tokens[0], (string) json_encode($this->conexao()->fetchFirstColumn('SELECT dados FROM assinatura_eletronica_evento WHERE documento_id = ?', [$id])));

        $this->client->request('POST', '/assinatura/documentos/' . $id . '/enviar', [], [], ['HTTP_X_CSRF_TOKEN' => self::CSRF]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Este documento já foi enviado.', $this->json()['erro']);
    }

    #[TestDox('com "em ordem", o convite vai só ao primeiro')]
    public function testEnviarEmOrdem(): void
    {
        $this->entrar(true);
        $id = $this->rascunho(emOrdem: true);

        $this->enviar($id);

        self::assertEmailCount(1);
        self::assertSame('ana@exemplo.com', self::getMailerMessage()?->getTo()[0]->getAddress());
    }

    /** @return iterable<string, array{list<array<string, mixed>>, string}> */
    public static function signatariosRecusados(): iterable
    {
        yield 'sem e-mail' => [[['nome' => 'Ana Lima', 'email' => 'ana@exemplo.com'], ['nome' => 'Bia Souza']], 'Informe o e-mail de Bia Souza para enviar o código de assinatura.'];
        yield 'gov.br sem o validador de cadeia' => [[['nome' => 'Ana Lima', 'email' => 'ana@exemplo.com', 'nivel' => 'govbr']], 'A assinatura de Ana Lima ainda não está disponível. Use a assinatura Simples.'];
        yield 'sem nome' => [[['nome' => '', 'email' => 'ana@exemplo.com']], 'Informe o nome de todos os signatários.'];
        yield 'CPF errado' => [[['nome' => 'Ana Lima', 'email' => 'ana@exemplo.com', 'cpf' => '52998224724']], 'Confira o CPF informado.'];
        yield 'sem signatários' => [[], 'Adicione ao menos um signatário para enviar.'];
    }

    /** @param list<array<string, mixed>> $signatarios */
    #[DataProvider('signatariosRecusados')]
    #[TestDox('enviar recusa: $_dataName')]
    public function testEnviarRecusado(array $signatarios, string $erro): void
    {
        $this->entrar(true);
        $id = $this->rascunho($signatarios);

        $this->client->request('POST', '/assinatura/documentos/' . $id . '/enviar', [], [], ['HTTP_X_CSRF_TOKEN' => self::CSRF]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($erro, $this->json()['erro']);
        self::assertSame('rascunho', $this->detalhe($id)['situacao']);
        self::assertEmailCount(0);
    }

    #[TestDox('sem o segredo configurado: o envio recusa e o link do detalhe vem nulo')]
    public function testSemSegredo(): void
    {
        static::getContainer()->set(ChaveDaAssinatura::class, new ChaveDaAssinatura(''));
        $this->entrar(true);
        $id = $this->rascunho();

        $this->client->request('POST', '/assinatura/documentos/' . $id . '/enviar', [], [], ['HTTP_X_CSRF_TOKEN' => self::CSRF]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Assinatura eletrônica sem chave configurada. Fale com o suporte.', $this->json()['erro']);

        $this->conexao()->executeStatement("UPDATE assinatura_eletronica_documento SET situacao = 'aguardando', enviado_em = NOW(), vence_em = NOW() + INTERVAL '7 days' WHERE id = ?", [$id]);
        $this->situacao($id, 'aguardando');
        self::assertSame([null, null], array_column($this->detalhe($id)['signatarios'], 'link'));
    }

    #[TestDox('lembrete: ao primeiro pendente, uma vez por hora por documento; fora do envio, 422')]
    public function testLembrete(): void
    {
        $this->entrar(true);
        $rascunho = $this->rascunho();
        $this->client->request('POST', '/assinatura/documentos/' . $rascunho . '/lembrete', [], [], ['HTTP_X_CSRF_TOKEN' => self::CSRF]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Só documentos aguardando assinatura, dentro do prazo, recebem lembrete.', $this->json()['erro']);

        [$id] = $this->documentoEnviado();
        $this->client->request('POST', '/assinatura/documentos/' . $id . '/lembrete', [], [], ['HTTP_X_CSRF_TOKEN' => self::CSRF]);
        self::assertResponseIsSuccessful();
        self::assertSame(['ok' => true, 'para' => 'Ana Lima'], $this->json());
        self::assertEmailCount(1);
        self::assertSame('Lembrete de assinatura: Contrato de honorários', self::getMailerMessage()?->getSubject());

        $this->client->request('POST', '/assinatura/documentos/' . $id . '/lembrete', [], [], ['HTTP_X_CSRF_TOKEN' => self::CSRF]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Um lembrete já foi enviado na última hora. Tente mais tarde.', $this->json()['erro']);

        $this->conexao()->executeStatement("UPDATE assinatura_eletronica_evento SET criado_em = criado_em - INTERVAL '61 minutes' WHERE documento_id = ? AND tipo = 'lembrete_enviado'", [$id]);
        $this->client->request('POST', '/assinatura/documentos/' . $id . '/lembrete', [], [], ['HTTP_X_CSRF_TOKEN' => self::CSRF]);
        self::assertResponseIsSuccessful();
        self::assertSame(2, array_count_values($this->trilha($id))['lembrete_enviado']);
        $textos = array_column($this->detalhe($id)['eventos'], 'texto');
        self::assertContains('Lembrete enviado a Ana Lima', $textos);
        self::assertContains('Convite enviado por e-mail a Bia Souza', $textos);
    }

    #[TestDox('segredo trocado depois do envio: o link enviado ainda abre, o detalhe fica sem link e o lembrete não sai')]
    public function testSegredoTrocadoDepoisDoEnvio(): void
    {
        $this->entrar(true);
        [$id, $tokens] = $this->documentoEnviado();
        // O hash guardado no envio da Ana passa a ser o de um token que o segredo de agora não gera (o efeito da troca).
        $enviado = str_repeat('e', 64);
        $this->conexao()->executeStatement('UPDATE assinatura_eletronica_signatario SET token_hash = ? WHERE documento_id = ? AND ordem = 0', [hash('sha256', $enviado), $id]);
        $this->esquecer();

        $links = array_column($this->detalhe($id)['signatarios'], 'link');
        self::assertNull($links[0], 'a Ana, sem link conferido: a tela mostra o aviso de sem chave');
        self::assertSame('http://localhost/assinar/' . $tokens[1], $links[1], 'a Bia, que bate com o segredo, segue com o link');
        $this->client->request('POST', '/assinatura/documentos/' . $id . '/lembrete', [], [], ['HTTP_X_CSRF_TOKEN' => self::CSRF]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Assinatura eletrônica sem chave configurada. Fale com o suporte.', $this->json()['erro']);
        self::assertEmailCount(0);
        $trilha = $this->trilha($id);
        self::assertSame('email_nao_enviado', end($trilha));
        self::assertNotContains('lembrete_enviado', $trilha);

        $this->sair($this->client);
        self::assertSame('pronto', $this->abrir($enviado)['estado'], 'o link que o signatário recebeu abre pelo hash guardado');
    }

    #[TestDox('cancelar envio: só aguardando; os links somem do detalhe e a página mostra "Envio cancelado"')]
    public function testCancelar(): void
    {
        $this->entrar(true);
        [$id, $tokens] = $this->documentoEnviado();

        $this->client->request('POST', '/assinatura/documentos/' . $id . '/cancelar', [], [], ['HTTP_X_CSRF_TOKEN' => self::CSRF]);

        self::assertResponseIsSuccessful();
        $detalhe = $this->json()['documento'];
        self::assertSame('cancelado', $detalhe['situacao']);
        self::assertSame([null, null], array_column($detalhe['signatarios'], 'link'));
        self::assertSame('Envio cancelado. Documento e evidências preservados', $detalhe['eventos'][0]['texto']);
        self::assertSame('cancelado', $this->abrir((string) $tokens[0])['estado']);

        $this->client->request('POST', '/assinatura/documentos/' . $id . '/cancelar', [], [], ['HTTP_X_CSRF_TOKEN' => self::CSRF]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Só documentos aguardando assinatura podem ter o envio cancelado.', $this->json()['erro']);
    }

    #[TestDox('prévia: a página pública só de leitura, sem URL de POST nem CSRF, e nada gravado')]
    public function testPrevia(): void
    {
        $this->entrar(true);
        [$id] = $this->documentoEnviado(emOrdem: true);
        $trilhaAntes = $this->trilha($id);

        $this->client->request('GET', '/assinatura/documentos/' . $id . '/previa/1');

        self::assertResponseIsSuccessful();
        self::assertCabecalhosPublicos($this->client->getResponse());
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $html);
        foreach (['data-csrf', 'data-url-codigo', 'data-url-assinar', 'data-url-recusa'] as $atributo) {
            self::assertStringNotContainsString($atributo, $html, $atributo);
        }
        $publico = $this->publicoDaPagina();
        self::assertTrue($publico['previa']);
        self::assertSame('aguardando_anterior', $publico['estado']);
        self::assertSame('Ana Lima', $publico['anterior']);
        self::assertSame('Bia Souza', $publico['eu']['nome']);
        self::assertSame($trilhaAntes, $this->trilha($id));
        self::assertSame(0, (int) $this->conexao()->fetchOne('SELECT count(*) FROM assinatura_eletronica_signatario WHERE documento_id = ? AND codigo_hash IS NOT NULL', [$id]));

        $rascunho = $this->rascunho();
        $this->client->request('GET', '/assinatura/documentos/' . $rascunho . '/previa/0');
        self::assertResponseIsSuccessful();
        self::assertSame('pronto', $this->publicoDaPagina()['estado']);
        $this->client->request('GET', '/assinatura/documentos/' . $rascunho . '/previa/9');
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('rubrica interna: 404 de quem ainda não assinou')]
    public function testRubricaDeQuemNaoAssinou(): void
    {
        $this->entrar(true);
        [$id] = $this->documentoEnviado();

        $this->client->request('GET', '/assinatura/documentos/' . $id . '/rubrica/0');

        self::assertResponseStatusCodeSame(404);
    }
}
