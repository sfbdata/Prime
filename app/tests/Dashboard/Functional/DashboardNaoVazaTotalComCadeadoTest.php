<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Functional;

use App\Dashboard\Controller\DashboardController;
use App\Dashboard\Entity\DashboardMetaPastas;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\NivelDeAcesso;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\CriaCenarioDoCadeadoFuncionalTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Achado de auditoria interna: com pasta oculta pelo cadeado, nenhum número derivado do escritório inteiro (meta
 * do mês, "Faltam", restante, ritmo) pode sair no HTML do painel, nem em atributo, title, data-* ou JSON.
 * Sentinela: meta 4321 do escritório; a gestora vê 1 pasta criada (o primário vê 2): faltariam 4320 (4319).
 */
#[CoversClass(DashboardController::class)]
final class DashboardNaoVazaTotalComCadeadoTest extends JusPrimeWebTestCase
{
    use CriaCenarioDoCadeadoFuncionalTrait;

    private const PERMISSOES_GESTOR = ['modules.expediente.view', 'resources.pasta.view', 'modules.bi.view'];
    private const META              = 4321;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCliente();
    }

    /** @return array{primario: User, tenant: Tenant, gestora: User, url: string} */
    private function cenario(): array
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $gestora = $this->criarColaborador($tenant, 'Gestora Oculta', self::PERMISSOES_GESTOR);
        $ana     = $this->criarColaborador($tenant, 'Ana Equipe');
        $oculta  = $this->criarPasta($tenant);
        $livre   = $this->criarPasta($tenant);
        foreach ([$oculta, $livre] as $pasta) {
            $pasta->setResponsavel($ana);
            $pasta->setCriadoPor($ana);
        }
        $this->em()->persist(new DashboardMetaPastas($tenant, new \DateTimeImmutable('first day of this month'), self::META, $primario));
        $this->em()->flush();
        $this->trancar($oculta, $primario, [[$ana, NivelDeAcesso::Editar]]);
        $hoje = new \DateTimeImmutable('today');

        return [
            'primario' => $primario, 'tenant' => $tenant, 'gestora' => $gestora,
            'url'      => '/dashboard?data_de=' . $hoje->format('Y-m-01') . '&data_ate=' . $hoje->format('Y-m-t'),
        ];
    }

    #[TestDox('gestora com pasta oculta: página inteira e fragmento XHR sem meta, Faltam nem derivados do total do escritório')]
    public function testNenhumNumeroDoEscritorioInteiroVaza(): void
    {
        $c = $this->cenario();
        $this->entrar($c['gestora'], $c['tenant']);

        $this->client->request('GET', $c['url']);
        self::assertResponseIsSuccessful();
        $pagina = (string) $this->client->getResponse()->getContent();
        $this->client->xmlHttpRequest('GET', $c['url']);
        self::assertResponseIsSuccessful();
        $fragmento = (string) $this->client->getResponse()->getContent();

        foreach (['página' => $pagina, 'fragmento' => $fragmento] as $onde => $html) {
            foreach ([self::META, self::META - 1, self::META - 2] as $numero) {
                self::assertDoesNotMatchRegularExpression('/(?<![\d.])' . $numero . '(?![\d])/', $html, $onde . ': ' . $numero . ' vazou');
            }
            self::assertStringNotContainsString('Faltam', $html, $onde);
            self::assertStringNotContainsString('db-meta-trilho', $html, $onde . ': bloco da meta');
            self::assertStringNotContainsString('data-db-meta-valor', $html, $onde);
        }
    }

    #[TestDox('controle positivo: o primário vê a meta, o "Faltam" e o ritmo')]
    public function testPrimarioVeAMeta(): void
    {
        $c = $this->cenario();
        $this->entrar($c['primario'], $c['tenant']);

        $this->client->request('GET', $c['url']);
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString((string) self::META, $html);
        self::assertStringContainsString('Faltam ' . (self::META - 2), $html);
        self::assertStringContainsString('db-ia-meta-ritmo', $html);
    }
}
