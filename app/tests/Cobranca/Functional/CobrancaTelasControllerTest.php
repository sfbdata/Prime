<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\CarteiraController;
use App\Cobranca\Controller\CasoController;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CasoCobranca;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Tests\Factory\Cobranca\CarteiraFactory;
use App\Tests\Factory\Cobranca\CasoCobrancaFactory;
use App\Tests\Factory\Cobranca\ObjetoCobrancaFactory;
use App\Tests\Factory\Cobranca\PessoaFactory;
use App\Tests\Factory\Cliente\ClientePFFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * Telas operacionais da Gestão de Cobranças (Etapa 8): rotas de LEITURA de Carteiras e Casos.
 * Cobre o gate de módulo (`cobrancas`), o isolamento de tenant / anti-IDOR (404 cross-tenant),
 * o render com Output DTOs (badge de estado + saldo derivado) e o fragmento XHR do filtro
 * reutilizável. Grafo semeado no MESMO tenant do usuário logado (invariável 1/23).
 */
#[CoversClass(CarteiraController::class)]
#[CoversClass(CasoController::class)]
final class CobrancaTelasControllerTest extends JusPrimeWebTestCase
{
    use Factories;

    /**
     * Cria usuário admin (papel isSystem → passa o gate de módulo) já logado no tenant.
     *
     * @return array{0: User, 1: Tenant}
     */
    private function criarAdminLogado(KernelBrowser $client): array
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Cobrança ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('cobranca_admin_' . uniqid() . '@test.com');
        $user->setFullName('Gestor Cobrança');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Admin ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        $userTenant = new UserTenant($user, $tenant);
        $userTenant->setTenantRole($role);
        $em->persist($userTenant);

        $em->flush();

        $this->logarComTenant($client, $user, $tenant);

        return [$user, $tenant];
    }

    /**
     * Semeia um grafo consistente Carteira→Objeto→Caso (+ Pessoa cobrada) no tenant dado.
     *
     * @return array{0: Carteira, 1: CasoCobranca}
     */
    private function semearGrafo(Tenant $tenant): array
    {
        $cliente = ClientePFFactory::createOne(['tenant' => $tenant]);
        $carteira = CarteiraFactory::createOne(['tenant' => $tenant, 'cliente' => $cliente]);
        $objeto = ObjetoCobrancaFactory::createOne(['tenant' => $tenant, 'carteira' => $carteira]);
        $pessoa = PessoaFactory::createOne(['tenant' => $tenant]);
        $caso = CasoCobrancaFactory::createOne([
            'tenant' => $tenant,
            'objeto' => $objeto,
            'pessoaCobradaAtual' => $pessoa,
        ]);

        return [$carteira->_real(), $caso->_real()];
    }

    #[TestDox('GET /cobrancas sem autenticação redireciona para login')]
    public function testSemAutenticacaoRedireciona(): void
    {
        $client = static::createClient();
        $client->request('GET', '/cobrancas');

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('GET /cobrancas sem o módulo cobrancas é bloqueado (redirect, não 200)')]
    public function testSemModuloBloqueia(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant SemMod ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('cobranca_semmod_' . uniqid() . '@test.com');
        $user->setFullName('Sem Módulo');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('dummy_hash');
        $em->persist($user);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Comum ' . uniqid());
        $role->setIsSystem(false);
        $em->persist($role);

        $userTenant = new UserTenant($user, $tenant);
        $userTenant->setTenantRole($role);
        $em->persist($userTenant);
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', '/cobrancas');

        self::assertResponseRedirects();
        self::assertStringNotContainsString('/login', (string) $client->getResponse()->headers->get('Location'), 'deve desviar por falta de módulo (homepage), não por falta de login');
    }

    #[TestDox('GET /cobrancas autenticado retorna 200 com a barra de filtro e a carteira semeada')]
    public function testCarteiraIndex200(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [$carteira] = $this->semearGrafo($tenant);

        $client->request('GET', '/cobrancas');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('data-filtro-root', $html);
        self::assertStringContainsString((string) $carteira->getNome(), $html);
    }

    #[TestDox('GET /cobrancas/casos autenticado retorna 200 e mostra a pessoa cobrada')]
    public function testCasoIndex200(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        $client->request('GET', '/cobrancas/casos');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('data-filtro-root', $html);
        self::assertStringContainsString((string) $caso->getPessoaCobradaAtual()->getNome(), $html);
    }

    #[TestDox('XHR em /cobrancas/casos devolve só o fragmento (sem layout nem barra de filtro)')]
    public function testCasoIndexXhrFragmento(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        $client->xmlHttpRequest('GET', '/cobrancas/casos');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString((string) $caso->getObjeto()->getIdentificacao(), $html);
        self::assertStringContainsString('caso(s)', $html);
        self::assertStringNotContainsString('<!DOCTYPE', $html);
        self::assertStringNotContainsString('data-filtro-root', $html);
    }

    #[TestDox('GET visão da carteira retorna 200 com saldo consolidado')]
    public function testCarteiraShow200(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [$carteira] = $this->semearGrafo($tenant);

        $client->request('GET', '/cobrancas/carteiras/' . $carteira->getId());

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString((string) $carteira->getNome(), $html);
        self::assertStringContainsString('Saldo consolidado', $html);
    }

    #[TestDox('GET detalhe do caso retorna 200 com pessoa cobrada e saldo exigível')]
    public function testCasoShow200(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        $client->request('GET', '/cobrancas/casos/' . $caso->getId());

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString((string) $caso->getPessoaCobradaAtual()->getNome(), $html);
        self::assertStringContainsString('Saldo exig', $html);
    }

    #[TestDox('IDOR: carteira de OUTRO tenant devolve 404')]
    public function testCarteiraShowCrossTenant404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);

        // Grafo em um segundo tenant, ao qual o usuário logado NÃO pertence.
        $outroTenant = new Tenant();
        $outroTenant->setName('Outro Tenant ' . uniqid());
        static::getContainer()->get(EntityManagerInterface::class)->persist($outroTenant);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        [$carteiraOutro] = $this->semearGrafo($outroTenant);

        $client->request('GET', '/cobrancas/carteiras/' . $carteiraOutro->getId());

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('IDOR: caso de OUTRO tenant devolve 404')]
    public function testCasoShowCrossTenant404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);

        $outroTenant = new Tenant();
        $outroTenant->setName('Outro Tenant ' . uniqid());
        static::getContainer()->get(EntityManagerInterface::class)->persist($outroTenant);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        [, $casoOutro] = $this->semearGrafo($outroTenant);

        $client->request('GET', '/cobrancas/casos/' . $casoOutro->getId());

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('XHR na lista de Carteiras devolve só o fragmento (sem layout)')]
    public function testCarteiraIndexXhrFragmento(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [$carteira] = $this->semearGrafo($tenant);

        $client->xmlHttpRequest('GET', '/cobrancas');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString((string) $carteira->getNome(), $html);
        self::assertStringContainsString('carteira(s)', $html);
        self::assertStringNotContainsString('<!DOCTYPE', $html);
        self::assertStringNotContainsString('data-filtro-root', $html);
    }

    #[TestDox('Faceta status=encerrado estreita a lista de casos (bind enum + XHR)')]
    public function testCasoFacetaStatus(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);

        $cliente = ClientePFFactory::createOne(['tenant' => $tenant]);
        $carteira = CarteiraFactory::createOne(['tenant' => $tenant, 'cliente' => $cliente]);
        $objetoAtivo = ObjetoCobrancaFactory::createOne(['tenant' => $tenant, 'carteira' => $carteira]);
        $objetoEncerrado = ObjetoCobrancaFactory::createOne(['tenant' => $tenant, 'carteira' => $carteira]);
        $casoAtivo = CasoCobrancaFactory::createOne([
            'tenant' => $tenant, 'objeto' => $objetoAtivo,
            'pessoaCobradaAtual' => PessoaFactory::createOne(['tenant' => $tenant]),
            'status' => \App\Cobranca\Enum\StatusCaso::Ativo,
        ])->_real();
        $casoEncerrado = CasoCobrancaFactory::createOne([
            'tenant' => $tenant, 'objeto' => $objetoEncerrado,
            'pessoaCobradaAtual' => PessoaFactory::createOne(['tenant' => $tenant]),
            'status' => \App\Cobranca\Enum\StatusCaso::Encerrado,
        ])->_real();

        $client->xmlHttpRequest('GET', '/cobrancas/casos', ['status' => 'encerrado']);

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString((string) $casoEncerrado->getObjeto()->getIdentificacao(), $html);
        self::assertStringNotContainsString((string) $casoAtivo->getObjeto()->getIdentificacao(), $html);
    }

    #[TestDox('Faceta carteira estreita a lista de casos (filtro numérico validado)')]
    public function testCasoFacetaCarteira(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);

        $cliente = ClientePFFactory::createOne(['tenant' => $tenant]);
        $carteiraA = CarteiraFactory::createOne(['tenant' => $tenant, 'cliente' => $cliente]);
        $carteiraB = CarteiraFactory::createOne(['tenant' => $tenant, 'cliente' => $cliente]);
        $objetoA = ObjetoCobrancaFactory::createOne(['tenant' => $tenant, 'carteira' => $carteiraA]);
        $objetoB = ObjetoCobrancaFactory::createOne(['tenant' => $tenant, 'carteira' => $carteiraB]);
        $casoA = CasoCobrancaFactory::createOne(['tenant' => $tenant, 'objeto' => $objetoA, 'pessoaCobradaAtual' => PessoaFactory::createOne(['tenant' => $tenant])])->_real();
        $casoB = CasoCobrancaFactory::createOne(['tenant' => $tenant, 'objeto' => $objetoB, 'pessoaCobradaAtual' => PessoaFactory::createOne(['tenant' => $tenant])])->_real();

        $client->xmlHttpRequest('GET', '/cobrancas/casos', ['carteira' => (string) $carteiraA->getId()]);

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString((string) $casoA->getObjeto()->getIdentificacao(), $html);
        self::assertStringNotContainsString((string) $casoB->getObjeto()->getIdentificacao(), $html);
    }

    #[TestDox('Ordenação e paginação não quebram (COALESCE HIDDEN + whitelist)')]
    public function testOrdenacaoEPaginacaoNaoQuebram(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $this->semearGrafo($tenant);

        // Ordenação por criação asc, faceta modo na lista de carteiras, e página 2 — todos os
        // caminhos com ORDER BY COALESCE/whitelist. Nenhum deve retornar 500.
        $client->request('GET', '/cobrancas/casos', ['ordenar' => 'criacao', 'direcao' => 'asc']);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/cobrancas/casos', ['ordenar' => 'atualizacao', 'direcao' => 'desc', 'page' => 2]);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/cobrancas', ['ordenar' => 'atualizacao', 'direcao' => 'desc', 'modo' => 'multiplo']);
        self::assertResponseIsSuccessful();
    }

    #[TestDox('A lista de casos NÃO vaza casos de outro tenant')]
    public function testCasoIndexNaoVazaOutroTenant(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $casoProprio] = $this->semearGrafo($tenant);

        $outroTenant = new Tenant();
        $outroTenant->setName('Outro Tenant ' . uniqid());
        static::getContainer()->get(EntityManagerInterface::class)->persist($outroTenant);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        [, $casoAlheio] = $this->semearGrafo($outroTenant);

        $client->xmlHttpRequest('GET', '/cobrancas/casos');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString((string) $casoProprio->getObjeto()->getIdentificacao(), $html);
        self::assertStringNotContainsString((string) $casoAlheio->getObjeto()->getIdentificacao(), $html);
    }
}
