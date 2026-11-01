<?php

declare(strict_types=1);

namespace App\Tests\Profile\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Profile\Controller\GeneroDoPerfilController;
use App\Profile\Entity\UserProfile;
use App\Profile\Enum\Genero;
use App\Profile\Twig\JanelaDoGeneroExtension;
use App\Tenant\Enum\SituacaoNaEquipe;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * A janela "Como devemos tratar você?" (spec genero-no-cadastro, regra 3 e D1): aparece para quem não tem o gênero
 * preenchido (sem perfil, nulo ou o legado `nao_informado`), some depois de salvar, volta no próximo login depois de
 * fechada, não aparece para quem tem; os POSTs exigem CSRF e só gravam o próprio usuário.
 */
#[CoversClass(GeneroDoPerfilController::class)]
#[CoversClass(JanelaDoGeneroExtension::class)]
final class JanelaDoGeneroTest extends JusPrimeWebTestCase
{
    private const JANELA = 'body > [data-janela-genero]';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $tenant;
    private string $token = '';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = static::getContainer()->get(EntityManagerInterface::class);
        $this->tenant = (new Tenant())->setName('Escritório Gênero ' . uniqid());
        $this->em->persist($this->tenant);
    }

    #[TestDox('Sem gênero: a janela aparece com o título, o texto, só Feminino e Masculino (nada marcado) e o Salvar')]
    public function testAparecePraQuemNaoTemGenero(): void
    {
        $crawler = $this->abrir($this->usuario(null));

        $janela = $crawler->filter(self::JANELA);
        self::assertCount(1, $janela);
        self::assertSame('Como devemos tratar você?', trim($janela->filter('.jg-titulo')->text()));
        self::assertSame('Usamos essa informação para escrever o seu nome corretamente no sistema (por exemplo, Dr. ou Dra.).', trim($janela->filter('.jg-texto')->text()));
        $opcoes = $janela->filter('form > .jg-opcoes > label.jg-opcao > input[type="radio"][name="genero"]');
        self::assertSame(['feminino', 'masculino'], $opcoes->each(static fn (Crawler $o): string => (string) $o->attr('value')));
        self::assertSame(['Feminino', 'Masculino'], $janela->filter('.jg-opcao')->each(static fn (Crawler $o): string => trim($o->text())));
        self::assertCount(0, $opcoes->filter('[checked]'));
        self::assertSame('Salvar', trim($janela->filter('form button[type="submit"]')->text()));
        self::assertCount(1, $janela->filter('button[data-fechar-janela-genero]'));
        self::assertStringNotContainsString('Prefiro não informar', $janela->html());
    }

    #[TestDox('Perfil existente com gênero nulo também vê a janela')]
    public function testPerfilComGeneroNulo(): void
    {
        $user = $this->usuario(null);
        $this->em->persist(new UserProfile($user));
        $this->em->flush();

        self::assertCount(1, $this->abrir($user)->filter(self::JANELA));
    }

    #[TestDox('O legado "nao_informado" conta como não preenchido: a janela aparece e o Meu Perfil abre o campo vazio, só com as duas opções')]
    public function testLegadoContaComoNaoPreenchido(): void
    {
        $crawler = $this->abrir($this->usuario(Genero::NaoInformado));

        self::assertCount(1, $crawler->filter(self::JANELA));
        $select = $crawler->filter('#formDadosPessoais select[name="dados_pessoais[genero]"]');
        self::assertSame(['', 'feminino', 'masculino'], $select->filter('option')->each(static fn (Crawler $o): string => (string) $o->attr('value')));
        self::assertCount(0, $select->filter('option[selected]'));
    }

    #[TestDox('Quem tem o gênero preenchido não vê a janela')]
    public function testNaoAparecePraQuemTem(): void
    {
        self::assertCount(0, $this->abrir($this->usuario(Genero::Feminino))->filter('[data-janela-genero]'));
        self::assertCount(0, $this->abrir($this->usuario(Genero::Masculino))->filter('[data-janela-genero]'));
    }

    #[TestDox('Salvar grava o gênero no próprio perfil e a janela some')]
    public function testSalvarGravaESome(): void
    {
        $user = $this->usuario(null);
        $this->abrir($user);

        $this->postar('/perfil/genero', ['genero' => 'masculino', '_token' => $this->csrf()]);

        self::assertResponseIsSuccessful();
        self::assertSame(['ok' => true, 'genero' => 'masculino'], json_decode((string) $this->client->getResponse()->getContent(), true));
        self::assertSame(Genero::Masculino, $this->generoDe($user));
        self::assertCount(0, $this->abrir($user, logar: false)->filter('[data-janela-genero]'));
    }

    #[TestDox('Salvar sem escolha, com valor desconhecido ou com o legado é recusado com "Informe o gênero."')]
    public function testSalvarRecusaSemGenero(): void
    {
        $user = $this->usuario(null);
        $this->abrir($user);

        foreach (['', 'outro', 'nao_informado'] as $genero) {
            $this->postar('/perfil/genero', ['genero' => $genero, '_token' => $this->csrf()]);

            self::assertResponseStatusCodeSame(422);
            self::assertSame(['erro' => 'Informe o gênero.'], json_decode((string) $this->client->getResponse()->getContent(), true));
        }
        self::assertNull($this->generoDe($user));
    }

    #[TestDox('Os dois POSTs exigem CSRF: sem o token, nada grava nem fecha')]
    public function testExigeCsrf(): void
    {
        $user = $this->usuario(null);
        $this->abrir($user);

        $this->postar('/perfil/genero', ['genero' => 'feminino', '_token' => 'errado']);
        self::assertResponseStatusCodeSame(403);
        $this->postar('/perfil/genero/fechar', ['_token' => 'errado']);
        self::assertResponseStatusCodeSame(403);

        self::assertNull($this->generoDe($user));
        self::assertCount(1, $this->abrir($user, logar: false)->filter(self::JANELA));
    }

    #[TestDox('Só grava o próprio usuário: um id de outra pessoa no pedido é ignorado')]
    public function testSoGravaOProprioUsuario(): void
    {
        $outro = $this->usuario(null);
        $eu    = $this->usuario(null);
        $this->abrir($eu);

        $this->postar('/perfil/genero', ['genero' => 'feminino', 'user' => (string) $outro->getId(), 'user_id' => (string) $outro->getId(), '_token' => $this->csrf()]);

        self::assertResponseIsSuccessful();
        self::assertSame(Genero::Feminino, $this->generoDe($eu));
        self::assertNull($this->generoDe($outro));
    }

    #[TestDox('Sem login, os POSTs da janela não gravam nada')]
    public function testSemLoginNaoGrava(): void
    {
        $this->client->request('POST', '/perfil/genero', ['genero' => 'feminino', '_token' => 'qualquer']);

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    #[TestDox('Fechar vale só para a sessão: a janela some e volta no próximo login')]
    public function testFecharVoltaNoProximoLogin(): void
    {
        $user = $this->usuario(null);
        $this->abrir($user);

        $this->postar('/perfil/genero/fechar', ['_token' => $this->csrf()]);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $this->abrir($user, logar: false)->filter('[data-janela-genero]'), 'fechada nesta sessão');
        self::assertNull($this->generoDe($user), 'fechar não grava nada');

        // Próximo login: sessão nova.
        $this->client->getCookieJar()->clear();
        self::assertCount(1, $this->abrir($user)->filter(self::JANELA), 'volta no próximo login');
    }

    #[TestDox('Sem JavaScript: o formulário grava e volta para a tela inicial')]
    public function testSemJavascriptRedireciona(): void
    {
        $user = $this->usuario(null);
        $this->abrir($user);

        $this->client->request('POST', '/perfil/genero', ['genero' => 'feminino', '_token' => $this->csrf()]);

        self::assertResponseRedirects('/inicio');
        self::assertSame(Genero::Feminino, $this->generoDe($user));
    }

    #[TestDox('Sem escritório na sessão (mais de um escritório, nada escolhido): salvar grava e fechar marca a sessão, sem desvio para a seleção')]
    public function testSemEscritorioNaSessao(): void
    {
        $user  = $this->usuario(null);
        $outro = (new Tenant())->setName('Outro Escritório ' . uniqid());
        $this->em->persist($outro);
        $this->em->persist(new UserTenant($user, $outro));
        $this->em->flush();

        $this->provarSalvarEFecharSemEscritorio($user);
    }

    #[TestDox('Convidado do Trabalho Externo (conta sem escritório): salvar grava e fechar marca a sessão')]
    public function testConvidadoSemEscritorio(): void
    {
        $user = (new User())->setEmail(uniqid('convidado_') . '@test.com')->setFullName('Convidado Externo')->setRoles(['ROLE_USER'])->setPassword('x');
        $user->setIsActive(true);
        $this->em->persist($user);
        $this->em->flush();

        $this->provarSalvarEFecharSemEscritorio($user);
    }

    #[TestDox('Master com escritório e sem seleção: salvar grava e fechar marca a sessão')]
    public function testMasterSemSelecao(): void
    {
        $user = $this->usuario(null);
        $user->setRoles(['ROLE_USER', 'ROLE_SUPER_ADMIN']);
        $this->em->flush();

        // Com um escritório só, a seleção escolheria por ele: o Master fica no painel dele, sem escritório na sessão.
        $this->provarSalvarEFecharSemEscritorio($user, '/master');
    }

    #[TestDox('Com escritório na sessão, o POST da janela passa pelas barreiras de sempre: vínculo suspenso, vínculo removido ou conta suspensa vão para a seleção, sem gravar')]
    public function testComEscritorioAsBarreirasValem(): void
    {
        foreach (['vinculo_suspenso', 'vinculo_removido', 'conta_suspensa'] as $caso) {
            $this->client->getCookieJar()->clear();
            // Escritório novo a cada caso: o generoDe() limpa o EntityManager.
            $this->tenant = (new Tenant())->setName('Escritório Barreira ' . uniqid());
            $this->em->persist($this->tenant);
            $user = $this->usuario(null);
            $this->abrir($user);
            $token = $this->csrf();

            $vinculo = $this->em->getRepository(UserTenant::class)->findOneBy(['user' => $user->getId(), 'tenant' => $this->tenant->getId()]);
            self::assertNotNull($vinculo);
            match ($caso) {
                'vinculo_suspenso' => $vinculo->alterarSituacao(SituacaoNaEquipe::Suspenso),
                'vinculo_removido' => $this->em->remove($vinculo),
                'conta_suspensa'   => $vinculo->getTenant()->setIsActive(false),
            };
            $this->em->flush();

            $this->postar('/perfil/genero', ['genero' => 'feminino', '_token' => $token]);
            self::assertResponseRedirects('/escritorio/selecionar', null, $caso);
            self::assertNull($this->generoDe($user), $caso);
        }
    }

    #[TestDox('No gate dos Termos (/termos/aceitar), quem não tem gênero não vê a janela')]
    public function testNaoApareceNoGateDosTermos(): void
    {
        $user = $this->usuario(null);
        $this->client->loginUser($user);

        $this->client->request('GET', '/termos/aceitar');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-janela-genero]');
        self::assertStringNotContainsString('Como devemos tratar você?', (string) $this->client->getResponse()->getContent());
    }

    /** Login sem escritório na sessão; o token vem da janela de uma tela com o layout (a seleção ou o painel). */
    private function provarSalvarEFecharSemEscritorio(User $user, string $tela = '/escritorio/selecionar'): void
    {
        $this->client->loginUser($user);
        $this->marcarTermosAceitos($this->client);
        $crawler = $this->client->request('GET', $tela);
        self::assertResponseIsSuccessful();
        $token = (string) $crawler->filter('[data-janela-genero] input[name="_token"]')->attr('value');

        $this->postar('/perfil/genero/fechar', ['_token' => $token]);
        self::assertResponseIsSuccessful();
        self::assertSame(['ok' => true], json_decode((string) $this->client->getResponse()->getContent(), true));
        self::assertNull($this->client->getRequest()->getSession()->get('current_tenant_id'));
        $this->client->request('GET', $tela);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-janela-genero]', 'fechada nesta sessão');

        $this->postar('/perfil/genero', ['genero' => 'feminino', '_token' => $token]);
        self::assertResponseIsSuccessful();
        self::assertSame(['ok' => true, 'genero' => 'feminino'], json_decode((string) $this->client->getResponse()->getContent(), true));
        self::assertSame(Genero::Feminino, $this->generoDe($user));
    }

    private function abrir(User $user, bool $logar = true): Crawler
    {
        if ($logar) {
            $this->logarComTenant($this->client, $user, $this->tenant);
        }
        $crawler = $this->client->request('GET', '/perfil');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('[data-janela-genero] input[name="_token"]');
        if ($token->count() === 1) {
            $this->token = (string) $token->attr('value');
        }

        return $crawler;
    }

    /** O token CSRF que a própria janela entregou (intenção GeneroDoPerfilController::CSRF, guardado na sessão). */
    private function csrf(): string
    {
        self::assertNotSame('', $this->token, 'a janela não entregou o token');

        return $this->token;
    }

    /** @param array<string, string> $dados */
    private function postar(string $url, array $dados): void
    {
        $this->client->request('POST', $url, $dados, [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json']);
    }

    private function generoDe(User $user): ?Genero
    {
        $this->em->clear();

        return $this->em->getRepository(UserProfile::class)->findOneBy(['user' => $user->getId()])?->getGenero();
    }

    private function usuario(?Genero $genero): User
    {
        $user = (new User())->setEmail(uniqid('genero_') . '@test.com')->setFullName('Pessoa Gênero')->setRoles(['ROLE_USER'])->setPassword('x');
        $user->setIsActive(true);
        $this->em->persist($user);
        if ($genero !== null) {
            $perfil = new UserProfile($user);
            $perfil->setGenero($genero);
            $user->setProfile($perfil);
            $this->em->persist($perfil);
        }
        $papel = (new TenantRole())->setTenant($this->tenant)->setName('Admin ' . uniqid())->setIsSystem(true);
        $this->em->persist($papel);
        $vinculo = new UserTenant($user, $this->tenant);
        $vinculo->setTenantRole($papel);
        $this->em->persist($vinculo);
        $this->em->flush();

        return $user;
    }
}
