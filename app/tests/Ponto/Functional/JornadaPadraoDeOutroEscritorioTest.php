<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Tenant\Tenant;
use App\Ponto\Controller\JornadaTenantController;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Ponto\Support\CenarioDeJornadaPorEscritorioTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * A jornada padrão de um escritório, aberta e salva pelo super admin com a sessão em OUTRO escritório. A rota é
 * `/tenant/{id}/jornada` (sem `{tenantId}`): sem fixar o filtro no escritório da URL, a tela vinha vazia e salvar
 * tentava criar uma segunda jornada padrão (achado da revisão da fatia 6, docs/specs/isolamento-multitenant-cobertura.md).
 */
#[CoversClass(JornadaTenantController::class)]
final class JornadaPadraoDeOutroEscritorioTest extends JusPrimeWebTestCase
{
    use CenarioDeJornadaPorEscritorioTrait;

    private const MANHA = [[[1, 2, 3, 4, 5], '08:00', null, null, '12:00']];
    private const TARDE = [[[1, 2, 3, 4, 5], '13:00', null, null, '17:00']];

    private KernelBrowser $client;
    private Tenant $daSessao;
    private Tenant $daUrl;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->instalarCsrfFalso();
        $this->daSessao = $this->escritorio('Escritório da sessão');
        $this->daUrl = $this->escritorio('Escritório da URL');
        $this->jornadaPadrao($this->daUrl, self::MANHA);

        $superAdmin = $this->pessoa('Super admin', ['ROLE_USER', 'ROLE_SUPER_ADMIN']);
        $this->vincular($superAdmin, $this->daSessao, [], true);
        $this->logarComTenant($this->client, $superAdmin, $this->daSessao);
        // A jornada do escritório da URL sai do banco, não da memória do teste.
        $this->em()->clear();
    }

    #[TestDox('Super admin com a sessão em outro escritório vê a jornada padrão gravada do escritório da URL')]
    public function testAbreAJornadaDoEscritorioDaUrl(): void
    {
        $resposta = $this->chamarJson($this->client, 'GET', $this->url());

        self::assertResponseIsSuccessful();
        self::assertSame('08:00', $resposta['blocos'][0]['entrada'] ?? null, 'a tela veio sem a jornada gravada do escritório da URL');
    }

    #[TestDox('Super admin com a sessão em outro escritório altera a jornada padrão do escritório da URL, sem criar outra')]
    public function testSalvaNaMesmaJornada(): void
    {
        $this->chamarJson($this->client, 'POST', $this->url(), $this->corpoDaJornada(self::TARDE) + [
            'validacaoRepousoHabilitada' => true,
            'minimoMinutosRepouso' => 60,
            'validacaoInterjornadaHabilitada' => true,
            'minimoMinutosInterjornada' => 660,
        ]);

        self::assertResponseIsSuccessful();
        $conn = $this->em()->getConnection();
        $idDaUrl = $this->daUrl->getId();
        self::assertSame(1, (int) $conn->fetchOne('SELECT COUNT(*) FROM jornada_tenant WHERE tenant_id = ?', [$idDaUrl]));
        self::assertSame(['13:00'], $conn->fetchFirstColumn(
            'SELECT b.entrada FROM bloco_jornada b JOIN jornada_tenant j ON j.id = b.jornada_tenant_id WHERE j.tenant_id = ?',
            [$idDaUrl],
        ), 'a jornada do escritório da URL não ficou com o horário novo');
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM jornada_tenant WHERE tenant_id = ?', [$this->daSessao->getId()]), 'gravou no escritório da sessão');
    }

    #[TestDox('Administrador comum com a sessão em outro escritório recebe 403 ao abrir ou salvar a jornada padrão do escritório da URL, sem gravar nada')]
    public function testAdministradorComumDeOutroEscritorioContinuaBarrado(): void
    {
        // O setUp limpou o EntityManager: o escritório da sessão volta do banco antes de ganhar vínculo novo.
        $daSessao = $this->em()->find(Tenant::class, $this->daSessao->getId());
        self::assertNotNull($daSessao);
        $admin = $this->pessoa('Administrador do escritório da sessão');
        $this->vincular($admin, $daSessao, ['admin.users.manage', 'modules.ponto.view'], true);
        $this->logarComTenant($this->client, $admin, $daSessao);
        $this->em()->clear();

        // Controle: no próprio escritório ele administra a jornada; o 403 abaixo vem do escritório da URL.
        $this->chamarJson($this->client, 'GET', sprintf('/tenant/%d/jornada', $daSessao->getId()));
        self::assertResponseIsSuccessful();

        $this->chamarJson($this->client, 'GET', $this->url());
        self::assertResponseStatusCodeSame(403);

        $this->chamarJson($this->client, 'POST', $this->url(), $this->corpoDaJornada(self::TARDE));
        self::assertResponseStatusCodeSame(403);

        $conn = $this->em()->getConnection();
        self::assertSame(['08:00'], $conn->fetchFirstColumn(
            'SELECT b.entrada FROM bloco_jornada b JOIN jornada_tenant j ON j.id = b.jornada_tenant_id WHERE j.tenant_id = ?',
            [$this->daUrl->getId()],
        ), 'a jornada do escritório da URL mudou com um pedido barrado');
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM jornada_tenant WHERE tenant_id = ?', [$this->daSessao->getId()]));
    }

    private function url(): string
    {
        return sprintf('/tenant/%d/jornada', $this->daUrl->getId());
    }
}
