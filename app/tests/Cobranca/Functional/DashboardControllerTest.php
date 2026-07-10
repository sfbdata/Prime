<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\DashboardController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Tests\Factory\Cobranca\ObrigacaoFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Camada HTTP do Dashboard e da Central de Alertas do escritório (Etapa 9). Cobre o gate de módulo
 * (`cobrancas`), o render das duas telas, os empty states, o anti-IDOR do filtro de carteira (404
 * cross-tenant) e o não-vazamento entre escritórios. Só GET/leitura — sem CSRF.
 */
#[CoversClass(DashboardController::class)]
final class DashboardControllerTest extends CobrancaWebTestCase
{
    private function usuarioSemModulo(KernelBrowser $client): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant SemMod ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('dash_semmod_' . uniqid() . '@test.com');
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
    }

    private function comObrigacaoVencida(Tenant $tenant, \App\Cobranca\Entity\CasoCobranca $caso): void
    {
        ObrigacaoFactory::createOne([
            'tenant' => $tenant,
            'caso' => $caso,
            'valorOriginal' => 100000,
            'encargosReconhecidos' => 0,
            'vencimentoOriginal' => new \DateTimeImmutable('2020-01-01'),
        ]);
    }

    #[TestDox('GET /cobrancas/painel sem autenticação redireciona para login')]
    public function testPainelSemAutenticacao(): void
    {
        $client = static::createClient();
        $client->request('GET', '/cobrancas/painel');

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('GET /cobrancas/painel sem o módulo cobrancas é bloqueado (redirect, não 200)')]
    public function testPainelSemModulo(): void
    {
        $client = static::createClient();
        $this->usuarioSemModulo($client);

        $client->request('GET', '/cobrancas/painel');

        self::assertResponseRedirects();
        self::assertStringNotContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('GET /cobrancas/painel com dados retorna 200 e as três visões')]
    public function testPainelRender(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $this->comObrigacaoVencida($tenant, $caso);

        $client->request('GET', '/cobrancas/painel');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Visão financeira', $html);
        self::assertStringContainsString('Visão operacional', $html);
        self::assertStringContainsString('Taxa de recuperação', $html);
    }

    #[TestDox('GET /cobrancas/painel sem casos mostra empty state')]
    public function testPainelEmptyState(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);

        $client->request('GET', '/cobrancas/painel');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Nenhuma cobrança para exibir', (string) $client->getResponse()->getContent());
    }

    #[TestDox('GET /cobrancas/alertas com alerta retorna 200 e mostra o objeto do caso')]
    public function testAlertasRender(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $this->comObrigacaoVencida($tenant, $caso);

        $client->request('GET', '/cobrancas/alertas');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString(
            htmlspecialchars((string) $caso->getObjeto()->getIdentificacao(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $html,
        );
    }

    #[TestDox('GET /cobrancas/alertas sem alertas mostra empty state')]
    public function testAlertasEmptyState(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);

        $client->request('GET', '/cobrancas/alertas');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Nenhum alerta pendente', (string) $client->getResponse()->getContent());
    }

    #[TestDox('IDOR: filtro por carteira de OUTRO tenant devolve 404 (painel)')]
    public function testPainelIDOR404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        [$carteiraOutro] = $this->semearGrafo($this->tenantAvulso());

        $client->request('GET', '/cobrancas/painel', ['carteira' => (string) $carteiraOutro->getId()]);

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('IDOR: filtro por carteira de OUTRO tenant devolve 404 (alertas)')]
    public function testAlertasIDOR404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        [$carteiraOutro] = $this->semearGrafo($this->tenantAvulso());

        $client->request('GET', '/cobrancas/alertas', ['carteira' => (string) $carteiraOutro->getId()]);

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('A central de alertas NÃO vaza casos de outro tenant')]
    public function testAlertasNaoVazaOutroTenant(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $casoProprio] = $this->semearGrafo($tenant);
        $this->comObrigacaoVencida($tenant, $casoProprio);

        $outroTenant = $this->tenantAvulso();
        [, $casoAlheio] = $this->semearGrafo($outroTenant);
        $this->comObrigacaoVencida($outroTenant, $casoAlheio);

        $client->request('GET', '/cobrancas/alertas');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString(
            htmlspecialchars((string) $casoProprio->getObjeto()->getIdentificacao(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $html,
        );
        self::assertStringNotContainsString(
            htmlspecialchars((string) $casoAlheio->getObjeto()->getIdentificacao(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $html,
        );
    }

    #[TestDox('O painel NÃO vaza valores de outro tenant nos totais (nível HTTP)')]
    public function testPainelNaoVazaValoresDeOutroTenant(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $casoProprio] = $this->semearGrafo($tenant);
        // Saldo próprio distinto: R$ 1.234,00.
        ObrigacaoFactory::createOne([
            'tenant' => $tenant,
            'caso' => $casoProprio,
            'valorOriginal' => 123400,
            'encargosReconhecidos' => 0,
            'vencimentoOriginal' => new \DateTimeImmutable('2020-01-01'),
        ]);

        $outroTenant = $this->tenantAvulso();
        [, $casoAlheio] = $this->semearGrafo($outroTenant);
        // Saldo alheio distinto: R$ 9.876,00 — não pode aparecer no painel do tenant logado.
        ObrigacaoFactory::createOne([
            'tenant' => $outroTenant,
            'caso' => $casoAlheio,
            'valorOriginal' => 987600,
            'encargosReconhecidos' => 0,
            'vencimentoOriginal' => new \DateTimeImmutable('2020-01-01'),
        ]);

        $client->request('GET', '/cobrancas/painel');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('1.234,00', $html);
        self::assertStringNotContainsString('9.876,00', $html);
    }

    #[TestDox('Operador com o módulo (só leitura) acessa o painel')]
    public function testOperadorComModuloAcessa(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarOperadorSemCapacidade($client);
        [, $caso] = $this->semearGrafo($tenant);
        $this->comObrigacaoVencida($tenant, $caso);

        $client->request('GET', '/cobrancas/painel');

        self::assertResponseIsSuccessful();
    }
}
