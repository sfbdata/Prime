<?php

declare(strict_types=1);

namespace App\Tests\Profile\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Master\DTO\DadosDoConvenio;
use App\Master\Entity\Convenio;
use App\Master\Entity\ConvenioVinculo;
use App\Master\Enum\AbrangenciaDoConvenio;
use App\Master\Enum\BeneficioDoConvenio;
use App\Master\Enum\OrigemDoVinculo;
use App\Master\Repository\ConvenioVinculoRepository;
use App\Master\Service\ConvenioDoCadastro;
use App\Master\Twig\ConvenioNoPerfilExtension;
use App\Master\UseCase\AplicarCodigoDeConvenioUseCase;
use App\Profile\Controller\ConvenioDoPerfilController;
use App\Tests\Auth\Doubles\RateLimiterFactoryEspiao;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * "Tenho um código de convênio" no Meu Perfil e o `?convenio=` do link de cadastro (spec docs/specs/convenios.md,
 * §5.3, PR 5).
 */
#[CoversClass(ConvenioDoPerfilController::class)]
#[CoversClass(ConvenioNoPerfilExtension::class)]
#[CoversClass(ConvenioDoCadastro::class)]
#[CoversClass(AplicarCodigoDeConvenioUseCase::class)]
final class ConvenioDoPerfilControllerTest extends JusPrimeWebTestCase
{
    private const CSRF_CADASTRO = 'token-de-teste-do-cadastro';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    #[TestDox('primário vê o bloco, aplica o código e o escritório entra no convênio por código')]
    public function testPrimarioAplica(): void
    {
        $convenio = $this->convenio();
        [$tenant, $primario] = $this->escritorio();
        $this->logarComTenant($this->client, $primario, $tenant);

        $this->enviar((string) $convenio->getCodigoConvite());

        self::assertResponseRedirects('/perfil');
        self::assertSame(['Pronto: o escritório entrou no convênio ' . $convenio->getNome() . '.'], $this->flash('success'));
        $abertos = $this->abertos($tenant);
        self::assertCount(1, $abertos);
        self::assertSame(OrigemDoVinculo::Codigo, $abertos[0]->getOrigem());
    }

    #[TestDox('código inválido volta com o aviso e não cria nada')]
    public function testRecusa(): void
    {
        [$tenant, $primario] = $this->escritorio();
        $this->logarComTenant($this->client, $primario, $tenant);

        $this->enviar('ZZZZ9999');

        self::assertResponseRedirects('/perfil');
        self::assertSame([AplicarCodigoDeConvenioUseCase::RECUSA], $this->flash('error'));
        self::assertSame([], $this->abertos($tenant));
    }

    #[TestDox('cota de tentativas do escritório estourada: avisa e nem confere o código')]
    public function testLimite(): void
    {
        $convenio = $this->convenio();
        [$tenant, $primario] = $this->escritorio();
        $this->client->disableReboot(); // o espião só vale enquanto o kernel não reinicia
        $espiao = new RateLimiterFactoryEspiao(aceita: false);
        static::getContainer()->set('limiter.convenio_codigo', $espiao);
        $this->logarComTenant($this->client, $primario, $tenant);

        $this->enviar((string) $convenio->getCodigoConvite());

        self::assertSame(['Muitas tentativas. Tente de novo daqui a uma hora.'], $this->flash('error'));
        self::assertSame(['escritorio-' . $tenant->getId()], $espiao->chavesConsumidas);
        self::assertSame([], $this->abertos($tenant));
    }

    #[TestDox('secundário não vê o bloco e o POST dele é 403; sem login vai para o login; token errado 403')]
    public function testAcesso(): void
    {
        $convenio = $this->convenio();
        $this->client->request('POST', '/perfil/convenio/codigo', ['codigo' => 'ABCD2345']);
        self::assertResponseRedirects('/login');

        [$tenant, $primario] = $this->escritorio();
        $secundario = $this->usuario();
        $this->em->persist(new UserTenant($secundario, $tenant));
        $this->em->flush();
        $this->logarComTenant($this->client, $secundario, $tenant);

        $pagina = $this->client->request('GET', '/perfil');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $pagina->filter('[data-convenio-perfil]'));
        $this->client->request('POST', '/perfil/convenio/codigo', ['_token' => $this->tokenNaSessao(), 'codigo' => $convenio->getCodigoConvite()]);
        self::assertResponseStatusCodeSame(403);

        $this->logarComTenant($this->client, $primario, $tenant);
        $this->client->request('POST', '/perfil/convenio/codigo', ['_token' => 'errado', 'codigo' => $convenio->getCodigoConvite()]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->abertos($tenant));
    }

    #[TestDox('cadastro pelo link com ?convenio=: o escritório novo nasce no convênio; código ruim não trava a conta')]
    public function testCadastroPeloLink(): void
    {
        $convenio = $this->convenio();

        $this->client->request('GET', '/cadastro?convenio=' . strtolower((string) $convenio->getCodigoConvite()));
        $tenant = $this->cadastrar();
        self::assertCount(1, $this->abertos($tenant));

        $this->client->request('GET', '/cadastro?convenio=ZZZZ9999');
        $semConvenio = $this->cadastrar();
        self::assertSame([], $this->abertos($semConvenio), 'código que não vale: a conta nasce, sem convênio');
    }

    private function cadastrar(): Tenant
    {
        $sessao = $this->client->getRequest()->getSession();
        $sessao->set('_csrf/cadastro_criar', self::CSRF_CADASTRO);
        $sessao->save();
        $email = 'cadastro_' . uniqid() . '@adv.com';
        $this->client->jsonRequest('POST', '/cadastro/criar', [
            'nome' => 'Ana Souza', 'escritorio' => 'Souza Advogados ' . uniqid(), 'email' => $email,
            'genero' => 'feminino', 'aceite' => true, '_token' => self::CSRF_CADASTRO,
        ]);
        self::assertResponseIsSuccessful();
        $this->em->clear();
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user);
        $vinculo = $this->em->getRepository(UserTenant::class)->findOneBy(['user' => $user]);
        self::assertNotNull($vinculo);
        $this->client->getRequest()->getSession()->remove(ConvenioDoCadastro::CHAVE_SESSAO);

        return $vinculo->getTenant();
    }

    private function enviar(string $codigo): void
    {
        $token = (string) $this->client->request('GET', '/perfil')->filter('[data-convenio-perfil] input[name=_token]')->attr('value');
        $this->client->request('POST', '/perfil/convenio/codigo', ['_token' => $token, 'codigo' => $codigo]);
    }

    /** Grava o token do formulário na sessão, como o csrf_token() da página faria (o secundário não tem o bloco). */
    private function tokenNaSessao(): string
    {
        $sessao = $this->client->getRequest()->getSession();
        $sessao->set('_csrf/' . ConvenioDoPerfilController::CSRF, 'token-do-codigo');
        $sessao->save();

        return 'token-do-codigo';
    }

    /** @return list<string> */
    private function flash(string $tipo): array
    {
        return $this->client->getRequest()->getSession()->getFlashBag()->peek($tipo);
    }

    private function convenio(): Convenio
    {
        $hoje = new \DateTimeImmutable('today');
        $dados = new DadosDoConvenio('Convênio ' . uniqid(), 'Caixa de Assistencia', 'caixa-de-assistencia', AbrangenciaDoConvenio::Nacional, null, BeneficioDoConvenio::Gratuidade, 0, 'CONVÊNIO', $hoje->modify('-10 days'), null, null);
        $convenio = Convenio::novoPorCodigo('t-' . substr(uniqid(), -10), $dados, substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 8), null, $hoje);
        $this->em->persist($convenio);
        $this->em->flush();

        return $convenio;
    }

    /** @return array{Tenant, User} */
    private function escritorio(): array
    {
        $tenant = (new Tenant())->setName('Conta do perfil ' . uniqid())->setEstado('SP');
        $this->em->persist($tenant);
        $papel = (new TenantRole())->setTenant($tenant)->setName('Administrador do Escritório')->setIsSystem(true);
        $this->em->persist($papel);
        $primario = $this->usuario();
        $this->em->persist((new UserTenant($primario, $tenant))->setTenantRole($papel));
        $this->em->flush();

        return [$tenant, $primario];
    }

    private function usuario(): User
    {
        $user = (new User())->setEmail('perfil_' . uniqid() . '@adv.com')->setFullName('Pessoa do Perfil')->setRoles(['ROLE_USER'])->setIsActive(true)->setPassword('x');
        $this->em->persist($user);

        return $user;
    }

    /** @return list<ConvenioVinculo> */
    private function abertos(Tenant $tenant): array
    {
        return static::getContainer()->get(ConvenioVinculoRepository::class)->abertosDosTenants([(int) $tenant->getId()])[(int) $tenant->getId()] ?? [];
    }
}
