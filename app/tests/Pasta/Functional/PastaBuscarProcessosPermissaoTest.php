<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Entity\Permission\ResourceAccess;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\NivelDeAcesso;
use App\Pasta\Entity\Pasta;
use App\Processo\Entity\Processo;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Revisão dos achados 2.01 e 3.04 da auditoria: a lista "Processo já cadastrado" (`pasta_buscar_processos`) só
 * serve para VINCULAR e percorre os processos do escritório. A tela só a oferece a quem edita a pasta não excluída,
 * então ela exige a guarda do vincular (EDIT, com o cadeado), recusa a pasta excluída com 403 e responde 404 para
 * pasta de outro escritório.
 */
#[CoversClass(PastaController::class)]
final class PastaBuscarProcessosPermissaoTest extends JusPrimeWebTestCase
{
    use CriaCenarioDoCadeadoFuncionalTrait;

    private const NUMERO = '07077777720268070001';

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCliente();
    }

    /** @return array{0: \App\Entity\Auth\User, 1: Tenant, 2: Pasta} primário, escritório e a pasta */
    private function cenario(): array
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $pasta = $this->criarPasta($tenant);
        $processo = new Processo();
        $processo->setTenant($tenant);
        $processo->setNumeroProcesso(self::NUMERO);
        $processo->setClasseProcessual('CLASSE DA BUSCA');
        $this->em()->persist($processo);
        $this->em()->flush();

        return [$primario, $tenant, $pasta];
    }

    private function buscar(Pasta $pasta): int
    {
        $this->client->request('GET', '/pasta/' . $pasta->getId() . '/processo/buscar', [], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);

        return $this->client->getResponse()->getStatusCode();
    }

    private function semDados(): void
    {
        self::assertStringNotContainsString(self::NUMERO, (string) $this->client->getResponse()->getContent());
    }

    #[TestDox('Só VIEW na pasta pelo perfil (sem edit): 403, sem processos')]
    public function testSoViewPeloPerfil(): void
    {
        [, $tenant, $pasta] = $this->cenario();
        $this->entrar($this->criarColaborador($tenant, 'Leitor Perfil', ['resources.pasta.view']), $tenant);

        self::assertSame(403, $this->buscar($pasta));
        $this->semDados();
    }

    #[TestDox('Só VIEW na pasta por ResourceAccess: 403')]
    public function testSoViewPorResourceAccess(): void
    {
        [, $tenant, $pasta] = $this->cenario();
        $leitor = $this->criarColaborador($tenant, 'Leitor Item', []);
        $acesso = new ResourceAccess();
        $acesso->setUser($leitor);
        $acesso->setTenant($tenant);
        $acesso->setResourceType(ResourceAccess::RESOURCE_PASTA);
        $acesso->setResourceId((int) $pasta->getId());
        $acesso->setCanView(true);
        $this->em()->persist($acesso);
        $this->em()->flush();
        $this->entrar($leitor, $tenant);

        self::assertSame(403, $this->buscar($pasta));
        $this->semDados();
    }

    #[TestDox('Visualizar no cadeado (perfil com edit): 403')]
    public function testVisualizarNoCadeado(): void
    {
        [$primario, $tenant, $pasta] = $this->cenario();
        $ana = $this->criarColaborador($tenant);
        $this->trancar($pasta, $primario, [[$ana, NivelDeAcesso::Visualizar]]);
        $this->entrar($ana, $tenant);

        self::assertSame(403, $this->buscar($pasta));
        $this->semDados();
    }

    #[TestDox('Com EDIT na pasta, a lista segue')]
    public function testComEditSegue(): void
    {
        [, $tenant, $pasta] = $this->cenario();
        $this->entrar($this->criarColaborador($tenant), $tenant);

        self::assertSame(200, $this->buscar($pasta));
        self::assertStringContainsString(self::NUMERO, (string) $this->client->getResponse()->getContent());
    }

    #[TestDox('Pasta excluída (lápide): 403, mesmo para o primário')]
    public function testPastaExcluida(): void
    {
        [$primario, $tenant, $pasta] = $this->cenario();
        $pasta->marcarExcluida($primario, new \DateTimeImmutable());
        $this->em()->flush();
        $this->entrar($primario, $tenant);

        self::assertSame(403, $this->buscar($pasta));
        $this->semDados();
    }

    #[TestDox('Pasta de outro escritório: 404')]
    public function testOutroEscritorio(): void
    {
        [, , $pasta] = $this->cenario();
        [$outroPrimario, $outro] = $this->criarEscritorio();
        $this->entrar($outroPrimario, $outro);

        self::assertSame(404, $this->buscar($pasta));
        $this->semDados();
    }
}
