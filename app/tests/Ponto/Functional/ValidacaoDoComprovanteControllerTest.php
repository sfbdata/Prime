<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Controller\ValidacaoDoComprovanteController;
use App\Ponto\Entity\ComprovanteDoPonto;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Service\SeloDoComprovante;
use App\Ponto\UseCase\RegistrarBatidaDaPessoaUseCase;
use App\Profile\Entity\UserProfile;
use App\Tests\Auth\Doubles\RateLimiterFactoryEspiao;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Ponto\Support\SeloDoComprovanteDeTeste;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * Validação pública do comprovante (E15, P50 e D20): `/ponto/validar`, sem login. "Comprovante autêntico." só quando o
 * código existe e o selo confere; qualquer outro caso recebe a MESMA resposta, "Código não localizado.". Nome inteiro,
 * sem CPF nem localização, `noindex`, e limite por IP.
 */
#[Group('ponto')]
#[CoversClass(ValidacaoDoComprovanteController::class)]
final class ValidacaoDoComprovanteControllerTest extends JusPrimeWebTestCase
{
    use CenarioDeJustificativaTrait;

    private const JSON = ['HTTP_ACCEPT' => 'application/json'];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
    }

    #[TestDox('Autêntico, sem login: nome inteiro, tipo, data e hora e NSR; sem CPF, sem localização (que vai no selo e na imagem), noindex e sem cache')]
    public function testAutenticoSemLogin(): void
    {
        $this->seloPronto();
        $comprovante = $this->emitir();

        $tela = $this->client->request('GET', '/ponto/validar/' . $comprovante->getCodigo());

        self::assertResponseIsSuccessful();
        $ok = $tela->filter('main .pv-ok[role="status"]');
        self::assertCount(1, $ok);
        self::assertSame('Comprovante autêntico.', $ok->filter('b')->text());
        self::assertMatchesRegularExpression('#^Comprovante autêntico\. ?Entrada de Maria da Silva Pereira em \d{2}/\d{2}/\d{4}, \d{2}:\d{2}:\d{2}, NSR 000000001\.$#u', trim($ok->text()));
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('"local":"-23.55000, -46.63000 · precisão 12 m"', $comprovante->getDadosAssinados(), 'o local está no dado selado (P49)');
        foreach (['123.456.789-09', '12345678909', '-23.55', '-46.63', 'precisão', 'Sede Centro'] as $proibido) {
            self::assertStringNotContainsString($proibido, $html);
        }
        $this->assertSemIndiceNemCache();
        self::assertCount(1, $tela->filter('head > meta[name="robots"][content="noindex, nofollow"]'));
    }

    #[TestDox('A janela da tela consulta a mesma rota com Accept JSON: autêntico com o texto do desenho')]
    public function testConsultaDaJanela(): void
    {
        $this->seloPronto();
        $comprovante = $this->emitir();

        $this->client->request('GET', '/ponto/validar/' . strtolower($comprovante->getCodigo()), [], [], self::JSON);

        self::assertResponseIsSuccessful();
        $dados = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($dados['autentico']);
        self::assertStringStartsWith('Entrada de Maria da Silva Pereira em ', $dados['texto']);
        self::assertStringNotContainsString('-23.55', $dados['texto'], 'a janela também não mostra o local (P50)');
        self::assertStringNotContainsString('precisão', $dados['texto']);
        $this->assertSemIndiceNemCache();
    }

    #[TestDox('O gestor apaga a batida: o comprovante fica (registro_ponto_id nulo) e o código continua autêntico')]
    public function testBatidaApagadaPeloGestorContinuaAutentica(): void
    {
        $this->seloPronto();
        $this->instalarCsrfPrevisivel();
        $escritorio = $this->escritorio('Escritório Validação');
        $pessoa = $this->pessoaComPerfil();
        $this->vincularColaborador($pessoa, $escritorio);
        $gestor = $this->pessoa('gestor');
        $this->vincularGestor($gestor, $escritorio);
        $comprovante = $this->emitir($pessoa, $escritorio);
        $batidaId = $comprovante->getRegistro()?->getId();
        $comprovanteId = $comprovante->getId();
        $esperado = [$this->pagina($comprovante->getCodigo()), $this->json($comprovante->getCodigo())];

        $this->logarComTenant($this->client, $gestor, $escritorio);
        $this->client->request('POST', sprintf('/tenant/%d/user/%d/ponto/%d/delete', $escritorio->getId(), $pessoa->getId(), $batidaId), ['_token' => 'TOKEN_delete_ponto_' . $batidaId]);
        self::assertResponseRedirects();
        $this->sair($this->client);
        $this->em()->clear();

        self::assertNull($this->emSemFiltro()->find(RegistroPonto::class, $batidaId), 'a batida saiu');
        $restante = $this->emSemFiltro()->find(ComprovanteDoPonto::class, $comprovanteId);
        self::assertNotNull($restante, 'o comprovante sobrevive (ON DELETE SET NULL)');
        self::assertNull($restante->getRegistro());
        self::assertSame($esperado, [$this->pagina($comprovante->getCodigo()), $this->json($comprovante->getCodigo())]);
        self::assertStringContainsString('"autentico":true', $esperado[1]);
    }

    #[TestDox('Logado sem escritório na sessão (e sem o aceite dos Termos nela): a validação abre, sem redirecionar')]
    public function testLogadoSemEscritorioNaSessao(): void
    {
        $this->seloPronto();
        $comprovante = $this->emitir();
        $visitante = $this->pessoa('sem_escritorio');
        $this->vincularColaborador($visitante, $this->escritorio('Escritório do visitante'));

        $this->client->loginUser($visitante);   // sem current_tenant_id e sem a versão dos Termos na sessão
        $tela = $this->client->request('GET', '/ponto/validar/' . $comprovante->getCodigo());
        self::assertResponseIsSuccessful();
        self::assertSame('Comprovante autêntico.', $tela->filter('main .pv-ok[role="status"] b')->text());
        $this->client->request('GET', '/ponto/validar?codigo=' . $comprovante->getCodigo());
        self::assertResponseIsSuccessful();
        self::assertTrue(json_decode($this->json($comprovante->getCodigo()), true)['autentico']);
    }

    #[TestDox('Selo que não confere e código que não existe recebem a mesma resposta, byte a byte (página e JSON)')]
    public function testErradoEInexistenteIguais(): void
    {
        $this->seloPronto();
        $comprovante = $this->emitir();
        $codigo = $comprovante->getCodigo();
        $this->em()->getConnection()->executeStatement('UPDATE ponto_comprovante SET hmac = :h WHERE id = :id', ['h' => str_repeat('0', 64), 'id' => $comprovante->getId()]);
        $this->em()->clear();   // o banco como está: o HMAC trocado, não o guardado na memória

        $adulterado = [$this->pagina($codigo), $this->json($codigo)];
        $this->em()->getConnection()->executeStatement('DELETE FROM ponto_comprovante WHERE id = :id', ['id' => $comprovante->getId()]);
        $inexistente = [$this->pagina($codigo), $this->json($codigo)];

        self::assertSame($inexistente, $adulterado);
        self::assertStringContainsString('Código não localizado.', $inexistente[0]);
        self::assertStringNotContainsString('Maria', $inexistente[0]);
        self::assertSame('{"autentico":false,"texto":null}', $inexistente[1]);
    }

    #[TestDox('Formato errado também recebe "Código não localizado." (mesma caixa da página, mesmo JSON)')]
    public function testFormatoErrado(): void
    {
        $this->seloPronto();

        self::assertSame('{"autentico":false,"texto":null}', $this->json("BJP-ABCD' OR 1=1"));
        $tela = $this->client->request('GET', '/ponto/validar?codigo=qualquer');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Código não localizado.', $tela->filter('.pv-erro[role="alert"]')->text());
    }

    #[TestDox('Sem o segredo do HMAC (produção da fase A): mesmo o comprovante que existe responde "Código não localizado."')]
    public function testSeloIndisponivel(): void
    {
        $batida = $this->batida($this->pessoaComPerfil(), $this->escritorio('Escritório Validação'));
        $batida->setNsr(1);
        $this->em()->persist($batida);
        $comprovante = new ComprovanteDoPonto($batida->getTenant(), $batida, 1, 'BJP-ABCD-EFGH-JK23', str_repeat('a', 64), 1, new \DateTimeImmutable(), '{}');
        $this->em()->persist($comprovante);
        $this->em()->flush();

        self::assertSame('{"autentico":false,"texto":null}', $this->json('BJP-ABCD-EFGH-JK23'));
        $tela = $this->client->request('GET', '/ponto/validar/BJP-ABCD-EFGH-JK23');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $tela->filter('.pv-erro'));
        self::assertCount(0, $tela->filter('.pv-ok'));
    }

    #[TestDox('O limite configurado: 20 consultas por minuto por chave; a 21ª é recusada')]
    public function testLimiteConfigurado(): void
    {
        /** @var RateLimiterFactoryInterface $fabrica */
        $fabrica = static::getContainer()->get('limiter.ponto_validacao');
        $limitador = $fabrica->create('203.0.113.9');
        for ($i = 0; $i < 20; ++$i) {
            self::assertTrue($limitador->consume()->isAccepted(), 'consulta ' . ($i + 1));
        }

        self::assertFalse($limitador->consume()->isAccepted(), 'a 21ª');
        self::assertTrue($fabrica->create('203.0.113.10')->consume()->isAccepted(), 'outro IP tem a própria cota');
    }

    #[TestDox('Cada consulta gasta a cota do IP de quem pede')]
    public function testLimitePorIp(): void
    {
        $espiao = new RateLimiterFactoryEspiao();
        static::getContainer()->set('limiter.ponto_validacao', $espiao);
        $this->client->request('GET', '/ponto/validar/BJP-ABCD-EFGH-JK23', [], [], self::JSON + ['REMOTE_ADDR' => '203.0.113.9']);
        self::assertResponseIsSuccessful();
        self::assertSame(['203.0.113.9'], $espiao->chavesConsumidas);
    }

    #[TestDox('Cota estourada: 429 com o texto, na página e no JSON, ainda sem índice e sem cache')]
    public function testCotaEstourada(): void
    {
        static::getContainer()->set('limiter.ponto_validacao', new RateLimiterFactoryEspiao(aceita: false));

        $this->client->request('GET', '/ponto/validar/BJP-ABCD-EFGH-JK23', [], [], self::JSON);
        self::assertResponseStatusCodeSame(429);
        self::assertSame(ValidacaoDoComprovanteController::MUITAS_TENTATIVAS, json_decode((string) $this->client->getResponse()->getContent(), true)['erro']);
        $tela = $this->client->request('GET', '/ponto/validar/BJP-ABCD-EFGH-JK23');
        self::assertResponseStatusCodeSame(429);
        self::assertSame(ValidacaoDoComprovanteController::MUITAS_TENTATIVAS, $tela->filter('.pv-aviso')->text());
        $this->assertSemIndiceNemCache();
    }

    #[TestDox('As rotas públicas de validação não começam com "ponto_" (a chave da tela do Ponto, E7, derruba essas)')]
    public function testNomesDasRotasPublicas(): void
    {
        $publicas = [];
        foreach (static::getContainer()->get(RouterInterface::class)->getRouteCollection() as $nome => $rota) {
            if (str_starts_with($rota->getPath(), '/ponto/validar')) {
                $publicas[$nome] = $rota->getDefault('_controller');
            }
        }

        self::assertSame([ValidacaoDoComprovanteController::ROTA, ValidacaoDoComprovanteController::ROTA_DO_CODIGO], array_keys($publicas));
        foreach ($publicas as $nome => $controller) {
            self::assertStringStartsNotWith('ponto_', $nome);
            self::assertStringStartsWith(ValidacaoDoComprovanteController::class, (string) $controller);
        }
    }

    private function assertSemIndiceNemCache(): void
    {
        $cabecalhos = $this->client->getResponse()->headers;
        self::assertSame('noindex, nofollow', $cabecalhos->get('X-Robots-Tag'));
        self::assertStringContainsString('no-store', (string) $cabecalhos->get('Cache-Control'));
        self::assertSame('no-referrer', $cabecalhos->get('Referrer-Policy'));
    }

    private function pagina(string $codigo): string
    {
        $this->client->request('GET', '/ponto/validar/' . $codigo);
        self::assertResponseIsSuccessful();

        return $this->client->getResponse()->getStatusCode() . (string) $this->client->getResponse()->getContent();
    }

    private function json(string $codigo): string
    {
        $this->client->request('GET', '/ponto/validar/' . rawurlencode($codigo), [], [], self::JSON);
        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }

    private function seloPronto(): void
    {
        static::getContainer()->set(SeloDoComprovante::class, new SeloDoComprovanteDeTeste());
    }

    private function emitir(?User $pessoa = null, ?Tenant $escritorio = null): ComprovanteDoPonto
    {
        $batida = $this->batida($pessoa ?? $this->pessoaComPerfil(), $escritorio ?? $this->escritorio('Escritório Validação'));
        $batida->setLatitude('-23.55000000');
        $batida->setLongitude('-46.63000000');
        $batida->setPrecisaoGps('12.40');
        $batida->setSedeNomeSnapshot('Sede Centro');
        $comprovante = static::getContainer()->get(RegistrarBatidaDaPessoaUseCase::class)->executar($batida);
        self::assertNotNull($comprovante);

        return $comprovante;
    }

    /** Pessoa com CPF no perfil: a página não pode mostrá-lo. */
    private function pessoaComPerfil(): User
    {
        $pessoa = $this->pessoa('validacao');
        $pessoa->setFullName('Maria da Silva Pereira');
        $this->em()->persist((new UserProfile($pessoa))->setCpf('123.456.789-09'));
        $this->em()->flush();

        return $pessoa;
    }

    private function batida(User $pessoa, Tenant $escritorio): RegistroPonto
    {
        $batida = new RegistroPonto();
        $batida->setUser($pessoa);
        $batida->setTenant($escritorio);
        $batida->setTipo(RegistroPonto::TIPO_ENTRADA);
        $batida->setDataHora(new \DateTime());

        return $batida;
    }
}
