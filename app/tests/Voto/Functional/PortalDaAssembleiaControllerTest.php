<?php

declare(strict_types=1);

namespace App\Tests\Voto\Functional;

use App\Master\Entity\RecursoEstado;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\TenantRole;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Auth\Doubles\RateLimiterFactoryEspiao;
use App\Tests\Factory\Tenant\TenantFactory;
use App\Tests\Factory\Voto\AssembleiaFactory;
use App\Tests\Factory\Voto\UnidadeDaAssembleiaFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Voto\Controller\PortalDaAssembleiaController;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\LiberacaoDoVoto;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Zenstruck\Foundry\Test\Factories;

#[CoversClass(PortalDaAssembleiaController::class)]
final class PortalDaAssembleiaControllerTest extends JusPrimeWebTestCase
{
    use Factories;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Assembleia $assembleia;
    private LiberacaoDoVoto $liberacao;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $recurso = $this->em->getRepository(RecursoEstado::class)->findOneBy(['chave' => 'voto']);
        if ($recurso !== null) {
            $recurso->reativar(null, new \DateTimeImmutable());
        }
        $tenant = TenantFactory::createOne()->_real();
        $this->liberacao = new LiberacaoDoVoto($tenant);
        $this->liberacao->liberar();
        $this->em->persist($this->liberacao);
        $this->em->flush();
        $this->assembleia = AssembleiaFactory::new()->publicada()->create(['tenant' => $tenant, 'titulo' => 'AGO Ipê'])->_real();
    }

    public function testPortalAnonimoNaoExibeDadosNemLinkPessoal(): void
    {
        $unidade = UnidadeDaAssembleiaFactory::createOne(['assembleia' => $this->assembleia, 'responsavel' => 'Nome Reservado', 'email' => 'reservado@example.com'])->_real();
        $crawler = $this->client->request('GET', $this->link());

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('body > .vcPagina > header.vcCab > b.vcPortal'));
        self::assertStringContainsString('AGO Ipê', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('Nome Reservado', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('reservado@example.com', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString($unidade->getSemente(), (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('/votar/', (string) $this->client->getResponse()->getContent());
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    public function testRascunhoNaoFicaPublico(): void
    {
        $rascunho = AssembleiaFactory::createOne(['tenant' => $this->assembleia->getTenant()])->_real();
        $this->client->request('GET', '/voto/v/' . $rascunho->getCodigoPublico());
        self::assertResponseStatusCodeSame(404);
    }

    public function testEscritorioBloqueadoNaoFicaPublico(): void
    {
        $this->liberacao->bloquear();
        $this->em->flush();
        $this->client->request('GET', $this->link());
        self::assertResponseStatusCodeSame(404);
    }

    public function testEscritorioInativoNaoFicaPublico(): void
    {
        $this->assembleia->getTenant()->setIsActive(false);
        $this->em->flush();
        $this->client->request('GET', $this->link());
        self::assertResponseStatusCodeSame(404);
    }

    public function testCodigoPorEmailAbreCedulaUmaUnicaVez(): void
    {
        $unidade = UnidadeDaAssembleiaFactory::createOne([
            'assembleia' => $this->assembleia, 'identificacao' => '101', 'bloco' => 'A',
            'responsavel' => 'Ana Silva', 'email' => 'ana@example.com',
        ])->_real();
        $this->localizar();
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Confirme sua identidade', $crawler->text());
        $this->client->submit($crawler->selectButton('Receber código por e-mail')->form(['email' => 'ana@example.com']));
        self::assertResponseRedirects($this->link(), 303);
        self::assertEmailCount(1);
        $email = self::getMailerMessage(0);
        self::assertNotNull($email);
        self::assertEmailHeaderSame($email, 'To', 'ana@example.com');
        preg_match('/letter-spacing:6px">([0-9]{6})</', (string) $email->getHtmlBody(), $m);
        self::assertArrayHasKey(1, $m);
        $crawler = $this->client->followRedirect();
        self::assertStringNotContainsString($m[1], $crawler->text());
        $form = $crawler->selectButton('Confirmar código')->form(['codigo_acesso' => $m[1]]);
        $this->client->submit($form);
        $cedula = '/votar/' . UnidadeDaAssembleiaFactory::tokenDe($unidade);
        self::assertResponseRedirects($cedula, 303);
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
    }

    public function testEmailDiferenteNaoRecebeCodigoENaoRevelaCadastro(): void
    {
        UnidadeDaAssembleiaFactory::createOne([
            'assembleia' => $this->assembleia, 'identificacao' => '101', 'bloco' => 'A',
            'responsavel' => 'Ana Silva', 'email' => 'ana@example.com',
        ]);
        $this->localizar();
        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->selectButton('Receber código por e-mail')->form(['email' => 'outro@example.com']));
        self::assertResponseRedirects($this->link(), 303);
        self::assertEmailCount(0);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Se os dados informados correspondem ao cadastro', $crawler->text());
        self::assertStringNotContainsString('ana@example.com', $crawler->text());
    }

    public function testPostSemCsrfNaoEnviaCodigo(): void
    {
        $this->client->request('POST', $this->link() . '/codigo', ['email' => 'outro@example.com']);
        self::assertResponseStatusCodeSame(400);
        self::assertEmailCount(0);
    }

    public function testQuintoErroBloqueiaCodigoNoBanco(): void
    {
        UnidadeDaAssembleiaFactory::createOne([
            'assembleia' => $this->assembleia, 'identificacao' => '101', 'bloco' => 'A',
            'responsavel' => 'Ana Silva', 'email' => 'ana@example.com',
        ]);
        $this->localizar();
        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->selectButton('Receber código por e-mail')->form(['email' => 'ana@example.com']));
        $email = self::getMailerMessage(0);
        preg_match('/letter-spacing:6px">([0-9]{6})</', (string) $email?->getHtmlBody(), $m);
        $crawler = $this->client->followRedirect();
        $errado = ($m[1] ?? '') === '000000' ? '999999' : '000000';
        for ($i = 0; $i < 5; ++$i) {
            $crawler = $this->client->submit($crawler->selectButton('Confirmar código')->form(['codigo_acesso' => $errado]));
            self::assertResponseStatusCodeSame(422);
        }
        $this->client->submit($crawler->selectButton('Confirmar código')->form(['codigo_acesso' => $m[1]]));
        self::assertResponseStatusCodeSame(422);
        $linha = $this->em->getConnection()->fetchAssociative('SELECT codigo_tentativas, codigo_hash FROM voto.assembleia_unidade WHERE assembleia_id = ?', [$this->assembleia->getId()]);
        self::assertSame(5, (int) $linha['codigo_tentativas']);
        self::assertNotSame($m[1], $linha['codigo_hash']);
    }

    public function testLogadoEmOutroEscritorioTambemEntraNoPortal(): void
    {
        $outro = TenantFactory::createOne()->_real();
        $usuario = UserFactory::createOne(['roles' => ['ROLE_USER']])->_real();
        $papel = (new TenantRole())->setTenant($outro)->setName('Perfil ' . uniqid())->setIsSystem(true);
        $this->em->persist($papel);
        $this->em->persist((new UserTenant($usuario, $outro))->setTenantRole($papel));
        $this->em->flush();
        $this->logarComTenant($this->client, $usuario, $outro);
        $this->client->request('GET', $this->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.vpContexto', 'AGO Ipê');
    }

    public function testLimiteDoIpRecusaEnvio(): void
    {
        static::getContainer()->set('limiter.voto_portal_envio', new RateLimiterFactoryEspiao(false));
        $this->localizar();
        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->selectButton('Receber código por e-mail')->form(['email' => 'ana@example.com']));
        self::assertResponseStatusCodeSame(429);
        self::assertEmailCount(0);
    }

    public function testDivulgacaoBaixaQrECartazSemCredencialDaUnidade(): void
    {
        $usuario = UserFactory::createOne(['roles' => ['ROLE_USER']])->_real();
        $tenant = $this->assembleia->getTenant();
        $papel = (new TenantRole())->setTenant($tenant)->setName('Gestor')->setIsSystem(true);
        $this->em->persist($papel);
        $this->em->persist((new UserTenant($usuario, $tenant))->setTenantRole($papel));
        $this->em->flush();
        $this->logarComTenant($this->client, $usuario, $tenant);
        $base = '/voto/assembleias/' . $this->assembleia->getId() . '/divulgacao';
        $crawler = $this->client->request('GET', $base);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.vqModal > .vqJanela[role="dialog"] > .vqCorpo > img.vqQr'));
        self::assertStringNotContainsString('/votar/', (string) $this->client->getResponse()->getContent());
        foreach (['png' => [1000, 1000], 'cartaz' => [1000, 1414]] as $formato => $dimensoes) {
            $this->client->request('GET', $base . '/arquivo/' . $formato);
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('Content-Type', 'image/png');
            $info = getimagesizefromstring((string) $this->client->getResponse()->getContent());
            self::assertNotFalse($info);
            self::assertSame($dimensoes, [$info[0], $info[1]]);
        }
        $this->client->request('GET', $base . '/arquivo/svg');
        self::assertResponseHeaderSame('Content-Type', 'image/svg+xml');
        self::assertNotFalse(simplexml_load_string((string) $this->client->getResponse()->getContent()));
    }

    public function testOutroGestorNaoBaixaQrDaAssembleia(): void
    {
        $outro = TenantFactory::createOne()->_real();
        $liberacao = new LiberacaoDoVoto($outro);
        $liberacao->liberar();
        $usuario = UserFactory::createOne(['roles' => ['ROLE_USER']])->_real();
        $papel = (new TenantRole())->setTenant($outro)->setName('Outro gestor')->setIsSystem(true);
        $this->em->persist($liberacao);
        $this->em->persist($papel);
        $this->em->persist((new UserTenant($usuario, $outro))->setTenantRole($papel));
        $this->em->flush();
        $this->logarComTenant($this->client, $usuario, $outro);
        $this->client->request('GET', '/voto/assembleias/' . $this->assembleia->getId() . '/divulgacao/arquivo/png');
        self::assertResponseStatusCodeSame(404);
    }

    public function testCodigoDoCartazApareceNoPortal(): void
    {
        $crawler = $this->client->request('GET', $this->link());
        $codigo = $this->assembleia->getCodigoPublico();
        self::assertStringContainsString(strtoupper(substr($codigo, 0, 4) . '-' . substr($codigo, -4)), $crawler->filter('.vpContexto')->text());
    }

    public function testConfirmacaoTambemTemLimitePorIp(): void
    {
        static::getContainer()->set('limiter.voto_portal_confirmacao', new RateLimiterFactoryEspiao(false));
        $this->localizar();
        $crawler = $this->client->followRedirect();
        $token = $crawler->filter('input[name="_token"]')->first()->attr('value');
        $this->client->request('POST', $this->link() . '/confirmar', ['_token' => $token, 'codigo_acesso' => '123456']);
        self::assertResponseStatusCodeSame(429);
    }

    public function testTerminalEncerraAtendimentoEImpedeVoltarACedulaAnterior(): void
    {
        $unidade = UnidadeDaAssembleiaFactory::createOne([
            'assembleia' => $this->assembleia, 'identificacao' => '101', 'bloco' => 'A',
            'responsavel' => 'Ana Silva', 'email' => 'ana@example.com',
        ])->_real();
        $crawler = $this->client->request('GET', $this->link() . '?presencial=1');
        self::assertStringContainsString('Terminal presencial', $crawler->filter('header.vcCab')->text());
        $this->localizar();
        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->selectButton('Receber código por e-mail')->form(['email' => 'ana@example.com']));
        $email = self::getMailerMessage(0);
        preg_match('/letter-spacing:6px">([0-9]{6})</', (string) $email?->getHtmlBody(), $m);
        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->selectButton('Confirmar código')->form(['codigo_acesso' => $m[1]]));
        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Terminal presencial', $crawler->filter('header.vcCab')->text());
        $this->client->submit($crawler->selectButton('Próximo participante')->form());
        self::assertResponseRedirects($this->link(), 303);
        $this->client->request('GET', '/votar/' . UnidadeDaAssembleiaFactory::tokenDe($unidade));
        self::assertResponseRedirects($this->link(), 303);
        $crawler = $this->client->followRedirect();
        self::assertStringNotContainsString('Ana Silva', $crawler->text());
        self::assertStringNotContainsString('ana@example.com', $crawler->text());
        $this->client->submit($crawler->selectButton('Sair do terminal')->form());
        $crawler = $this->client->followRedirect();
        self::assertStringNotContainsString('Terminal presencial', $crawler->filter('header.vcCab')->text());
        $this->client->request('GET', '/votar/' . UnidadeDaAssembleiaFactory::tokenDe($unidade));
        self::assertResponseIsSuccessful();
    }

    private function localizar(): void
    {
        $crawler = $this->client->request('GET', $this->link());
        $this->client->submit($crawler->selectButton('Continuar')->form(['nome' => 'Ana Silva', 'unidade' => '101', 'bloco' => 'A']));
        self::assertResponseRedirects($this->link(), 303);
    }

    private function link(): string
    {
        return '/voto/v/' . $this->assembleia->getCodigoPublico();
    }
}
