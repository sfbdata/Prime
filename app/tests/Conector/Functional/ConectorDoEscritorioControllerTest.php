<?php

declare(strict_types=1);

namespace App\Tests\Conector\Functional;

use App\Conector\Controller\ConectorDoEscritorioController;
use App\Conector\Entity\EscritorioDoConector;
use App\Conector\Enum\VersaoDoAceiteDoConector;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tests\Conector\Support\CenarioDoServidorDeAutorizacaoTrait;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * O administrador liga (com o aceite) ou desliga o conector de I.A. no cartão da tela "BlueJus IA" (spec
 * mcp-remoto-oauth 14.a nível 3; PR 8). Guarda `admin.inteligencia.manage`, CSRF `_token`, escritório sempre o da
 * sessão, flash e volta para a tela. O token CSRF vem do próprio formulário da tela (armazém real).
 */
#[CoversClass(ConectorDoEscritorioController::class)]
final class ConectorDoEscritorioControllerTest extends JusPrimeWebTestCase
{
    use CenarioDoServidorDeAutorizacaoTrait;

    private const PERMISSAO = 'admin.inteligencia.manage';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    #[TestDox('sem admin.inteligencia.manage: 403 no POST e nada muda')]
    public function testSemPermissao403(): void
    {
        $tenant = $this->escritorio();
        $this->liberadoSemLigar($tenant);
        $pessoa = $this->pessoa();
        $this->vinculo($pessoa, $tenant, permissoes: ['modules.inteligencia.view']);
        $this->logarComTenant($this->client, $pessoa, $tenant);

        $this->client->request('POST', '/admin/inteligencia/conector', ['acao' => 'ligar', 'aceite' => '1', '_token' => 'qualquer']);

        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->linhaNoBanco($tenant)?->isLigado());
    }

    #[TestDox('escritório alheio: sessão nele sem vínculo não chega ao controller, com vínculo sem a permissão é 403, e o corpo não escolhe escritório; o alheio não muda')]
    public function testEscritorioAlheioImpossivel(): void
    {
        $meu = $this->escritorio();
        $alheio = $this->escritorio();
        $this->liberadoSemLigar($alheio);
        $admin = $this->administrador($meu);

        // Sem vínculo: o listener do escritório devolve à escolha do escritório antes do controller.
        $this->logarComTenant($this->client, $admin, $alheio);
        $this->client->request('POST', '/admin/inteligencia/conector', ['acao' => 'ligar', 'aceite' => '1', '_token' => 'qualquer']);
        self::assertResponseRedirects('/escritorio/selecionar');

        // Com vínculo de equipe, sem a permissão de administrar a I.A. no alheio: 403.
        $this->vinculo($admin, $alheio, permissoes: ['modules.inteligencia.view']);
        $this->logarComTenant($this->client, $admin, $alheio);
        $this->client->request('POST', '/admin/inteligencia/conector', ['acao' => 'ligar', 'aceite' => '1', '_token' => 'qualquer']);
        self::assertResponseStatusCodeSame(403);

        $this->logarComTenant($this->client, $admin, $meu);
        $token = $this->tokenDaTela();
        $this->client->request('POST', '/admin/inteligencia/conector', ['acao' => 'ligar', 'aceite' => '1', '_token' => $token, 'tenantId' => (string) $alheio->getId(), 'tenant' => (string) $alheio->getId()]);

        self::assertResponseRedirects('/admin/inteligencia');
        self::assertStringContainsString('ainda não foi liberado pelo Master', $this->flashDepoisDoRedirect());
        self::assertFalse($this->linhaNoBanco($alheio)?->isLigado(), 'o alheio continua desligado');
        self::assertNull($this->linhaNoBanco($alheio)?->getAceiteEm());
        self::assertNull($this->linhaNoBanco($meu), 'o meu não estava liberado: nenhuma linha nasce');
    }

    #[TestDox('CSRF inválido: flash de erro, volta para a tela e nada muda')]
    public function testCsrfInvalido(): void
    {
        $tenant = $this->escritorio();
        $this->liberadoSemLigar($tenant);
        $this->logarComTenant($this->client, $this->administrador($tenant), $tenant);

        $this->client->request('POST', '/admin/inteligencia/conector', ['acao' => 'ligar', 'aceite' => '1', '_token' => 'errado']);

        self::assertResponseRedirects('/admin/inteligencia');
        self::assertStringContainsString('Token CSRF inválido. Recarregue a página.', $this->flashDepoisDoRedirect());
        self::assertFalse($this->linhaNoBanco($tenant)?->isLigado());
    }

    #[TestDox('sem liberação do Master: o cartão mostra "aguardando", a caixa e o botão Ligar desabilitados e o link do passo a passo')]
    public function testAguardandoLiberacao(): void
    {
        $tenant = $this->escritorio();
        $this->logarComTenant($this->client, $this->administrador($tenant), $tenant);

        $crawler = $this->client->request('GET', '/admin/inteligencia');

        self::assertResponseIsSuccessful();
        $card = $this->card($crawler);
        self::assertSame('aguardando_master', $card->filter('[data-conector-estado]')->attr('data-conector-estado'));
        self::assertStringContainsString('Aguardando a liberação do Master do BlueJus', $card->text());
        self::assertNotNull($card->filter('#conector-ligar')->attr('disabled'));
        self::assertNotNull($card->filter('#conector-aceite')->attr('disabled'));
        self::assertCount(0, $card->filter('#conector-desligar'));
        self::assertSame('/perfil/conexoes-de-ia/como-conectar', $card->filter('#conector-guia')->attr('href'), 'o passo a passo para repassar à equipe');
    }

    #[TestDox('sem marcar "Li e aceito": flash de erro e continua desligado')]
    public function testSemAceite(): void
    {
        $tenant = $this->escritorio();
        $this->liberadoSemLigar($tenant);
        $this->logarComTenant($this->client, $this->administrador($tenant), $tenant);

        $this->client->request('POST', '/admin/inteligencia/conector', ['acao' => 'ligar', '_token' => $this->tokenDaTela()]);

        self::assertResponseRedirects('/admin/inteligencia');
        self::assertStringContainsString('Marque "Li e aceito" para ligar o conector.', $this->flashDepoisDoRedirect());
        self::assertFalse($this->linhaNoBanco($tenant)?->isLigado());
    }

    #[TestDox('caminho feliz: liberado, o admin liga com o aceite (versão vigente e autor), vê "ligado" e desliga sem perder o aceite')]
    public function testLigarEDesligar(): void
    {
        $tenant = $this->escritorio();
        $this->liberadoSemLigar($tenant);
        $admin = $this->administrador($tenant);
        $this->logarComTenant($this->client, $admin, $tenant);

        $crawler = $this->client->request('GET', '/admin/inteligencia');
        $card = $this->card($crawler);
        self::assertSame('desligado', $card->filter('[data-conector-estado]')->attr('data-conector-estado'));
        self::assertNull($card->filter('#conector-ligar')->attr('disabled'));
        self::assertNotNull($card->filter('#conector-aceite')->attr('required'));
        self::assertStringContainsString('Li e aceito', $card->text());
        self::assertDoesNotMatchRegularExpression('/[—–]/u', $card->text());

        $this->client->submit($card->filter('#conector-form-ligar')->form(['aceite' => '1']));

        self::assertResponseRedirects('/admin/inteligencia');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Conector de I.A. ligado neste escritório.', $crawler->filter('.alert-success')->text());
        $linha = $this->linhaNoBanco($tenant);
        self::assertTrue($linha?->isLigado());
        self::assertSame(VersaoDoAceiteDoConector::atual()->value, $linha?->getAceiteVersao());
        self::assertSame($admin->getId(), $linha?->getAceitoPor()?->getId());
        $card = $this->card($crawler);
        self::assertSame('ligado', $card->filter('[data-conector-estado]')->attr('data-conector-estado'));
        self::assertCount(1, $card->filter('#conector-aceite-registrado'));
        self::assertCount(0, $card->filter('#conector-form-ligar'));

        $this->client->submit($card->filter('#conector-form-desligar')->form());

        self::assertResponseRedirects('/admin/inteligencia');
        self::assertStringContainsString('Conector de I.A. desligado', $this->flashDepoisDoRedirect());
        $linha = $this->linhaNoBanco($tenant);
        self::assertFalse($linha?->isLigado());
        self::assertNotNull($linha?->getAceiteEm(), 'desligar não apaga o aceite');
    }

    #[TestDox('bloqueado pelo Master depois do aceite: o cartão diz que o Master bloqueou e o admin ainda pode desligar')]
    public function testBloqueadoPeloMaster(): void
    {
        $tenant = $this->escritorio();
        $admin = $this->administrador($tenant);
        $linha = $this->liberar($tenant, $admin);
        $linha->bloquearPeloMaster();
        $this->em()->flush();
        $this->logarComTenant($this->client, $admin, $tenant);

        $card = $this->card($this->client->request('GET', '/admin/inteligencia'));

        self::assertSame('bloqueado_master', $card->filter('[data-conector-estado]')->attr('data-conector-estado'));
        self::assertNotNull($card->filter('#conector-ligar')->attr('disabled'));
        self::assertCount(1, $card->filter('#conector-desligar'));
    }

    #[TestDox('ligado e liberado com o aceite de versão antiga: o cartão pede um novo aceite (caixa e Ligar habilitados) e mostra o Desligar, nunca "desligado"')]
    public function testAceiteDeVersaoAntigaPedeNovoAceite(): void
    {
        $tenant = $this->escritorio();
        $admin = $this->administrador($tenant);
        $linha = $this->liberar($tenant, $admin);
        (new \ReflectionProperty(EscritorioDoConector::class, 'aceiteVersao'))->setValue($linha, 0);
        $this->em()->flush();
        $this->logarComTenant($this->client, $admin, $tenant);

        $card = $this->card($this->client->request('GET', '/admin/inteligencia'));

        self::assertSame('novo_aceite', $card->filter('[data-conector-estado]')->attr('data-conector-estado'));
        self::assertStringContainsString('O texto do aceite mudou: o conector está suspenso', $card->text());
        self::assertStringNotContainsString('Conector desligado neste escritório.', $card->text());
        self::assertCount(1, $card->filter('#conector-aceite-antigo'));
        self::assertNull($card->filter('#conector-aceite')->attr('disabled'));
        self::assertNull($card->filter('#conector-ligar')->attr('disabled'));
        self::assertCount(1, $card->filter('#conector-desligar'));
    }

    private function administrador(Tenant $tenant): User
    {
        $admin = $this->pessoa();
        $this->vinculo($admin, $tenant, permissoes: [self::PERMISSAO]);

        return $admin;
    }

    private function liberadoSemLigar(Tenant $tenant): EscritorioDoConector
    {
        $linha = new EscritorioDoConector($tenant);
        $linha->liberarPeloMaster();
        $this->em()->persist($linha);
        $this->em()->flush();

        return $linha;
    }

    private function card(Crawler $crawler): Crawler
    {
        $card = $crawler->filter('section.content > div.container-fluid > div.bjp-painel#conector-escritorio');
        self::assertCount(1, $card, 'o cartão do conector é um bloco da tela, depois da configuração');

        return $card;
    }

    private function tokenDaTela(): string
    {
        $crawler = $this->client->request('GET', '/admin/inteligencia');

        return (string) $crawler->filter('#conector-form-ligar input[name="_token"]')->attr('value');
    }

    private function flashDepoisDoRedirect(): string
    {
        return $this->client->followRedirect()->filter('.alert')->text();
    }

    /** A linha como está no banco, sem o `TenantFilter` que a última requisição deixou ligado no escritório da sessão. */
    private function linhaNoBanco(Tenant $tenant): ?EscritorioDoConector
    {
        $this->em()->clear();
        if ($this->em()->getFilters()->isEnabled('tenant')) {
            $this->em()->getFilters()->disable('tenant');
        }

        return $this->em()->getRepository(EscritorioDoConector::class)->findOneBy(['tenant' => $tenant->getId()]);
    }
}
