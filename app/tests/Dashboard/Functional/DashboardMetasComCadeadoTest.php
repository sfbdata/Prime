<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Functional;

use App\Dashboard\Controller\DashboardController;
use App\Dashboard\Repository\MetricasExtrasDoDashboardRepository;
use App\Dashboard\UseCase\ObterDadosDashboardUseCase;
use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\NivelDeAcesso;
use App\Pasta\Entity\Pasta;
use App\Pasta\Service\AcessoAPasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\CriaCenarioDoCadeadoFuncionalTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Dashboard da equipe com cadeado (spec pasta-cadeado, D1 e D3, "Acompanhamento depois dos merges"): as metas
 * de pasta Oculta para quem olha saem dos cards e das colunas de metas, como da lista /tarefas/equipe a que os
 * números levam. O primário e quem tem nível na pasta continuam contando a meta.
 */
#[CoversClass(DashboardController::class)]
#[CoversClass(ObterDadosDashboardUseCase::class)]
#[CoversClass(MetricasExtrasDoDashboardRepository::class)]
final class DashboardMetasComCadeadoTest extends JusPrimeWebTestCase
{
    use CriaCenarioDoCadeadoFuncionalTrait;

    private const PERMISSOES_GESTOR = ['modules.expediente.view', 'resources.pasta.view', 'modules.bi.view'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCliente();
    }

    /**
     * Ana responde por uma meta numa pasta com cadeado e por outra numa pasta livre, as duas vencidas. A gestora
     * fica fora da grade do cadeado (Oculto); o gestor livre tem Editar.
     *
     * @return array{primario: User, tenant: Tenant, gestora: User, gestorLivre: User, ana: User, oculta: Pasta}
     */
    private function cenario(string $statusDasMetas = Tarefa::STATUS_PENDENTE): array
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $gestora     = $this->criarColaborador($tenant, 'Gestora Oculta', self::PERMISSOES_GESTOR);
        $gestorLivre = $this->criarColaborador($tenant, 'Gestor Livre', self::PERMISSOES_GESTOR);
        $ana         = $this->criarColaborador($tenant, 'Ana Equipe');
        $oculta      = $this->criarPasta($tenant);
        $livre       = $this->criarPasta($tenant);
        foreach ([[$oculta, 'Meta dashboard trancada'], [$livre, 'Meta dashboard livre']] as [$pasta, $titulo]) {
            $meta = new Tarefa();
            $meta->setTitulo($titulo);
            $meta->setDescricao('Descrição');
            $meta->setPasta($pasta);
            $meta->setTenant($tenant);
            $meta->setCriadoPor($primario);
            $meta->addResponsavel($ana);
            $meta->setPrazo(new \DateTimeImmutable('-2 days'));
            $meta->setStatus($statusDasMetas);
            $this->em()->persist($meta);
        }
        $this->em()->flush();
        $this->trancar($oculta, $primario, [[$ana, NivelDeAcesso::Editar], [$gestorLivre, NivelDeAcesso::Editar]]);

        return ['primario' => $primario, 'tenant' => $tenant, 'gestora' => $gestora, 'gestorLivre' => $gestorLivre, 'ana' => $ana, 'oculta' => $oculta];
    }

    /** @return array{card: string, total: string, ativas: string, vencidas: string, html: string} */
    private function painelVistoPor(User $quem, Tenant $tenant): array
    {
        $this->entrar($quem, $tenant);
        $c = $this->client->request('GET', '/dashboard');
        self::assertResponseIsSuccessful();

        return [
            'card'     => (string) $c->filter('.db-stat-num[data-card="metas"]')->attr('data-alvo'),
            'total'    => trim($c->filter('a[title="Abrir: Metas de Ana Equipe"]')->first()->text()),
            'ativas'   => trim($c->filter('a[title="Abrir: Metas ativas de Ana Equipe"]')->first()->text()),
            'vencidas' => trim($c->filter('a[title="Abrir: Metas vencidas de Ana Equipe"]')->first()->text()),
            'html'     => (string) $this->client->getResponse()->getContent(),
        ];
    }

    #[TestDox('gestor Oculto na pasta: o card e as colunas de metas não contam a meta dela, e a lista a que levam não mostra o título')]
    public function testGestorOcultoNaoContaNemVeAMetaDaPastaComCadeado(): void
    {
        $c = $this->cenario();

        $painel = $this->painelVistoPor($c['gestora'], $c['tenant']);

        self::assertSame('1', $painel['card'], 'Metas ativas: só a da pasta livre');
        self::assertSame('1', $painel['total']);
        self::assertSame('1', $painel['ativas']);
        self::assertSame('1', $painel['vencidas']);
        self::assertStringNotContainsString('Meta dashboard trancada', $painel['html']);

        // O número leva a /tarefas/equipe: a lista bate com ele e não mostra o título da meta da pasta oculta.
        $this->client->request('GET', '/tarefas/equipe?status=todas&responsavel=' . $c['ana']->getId());
        self::assertResponseIsSuccessful();
        $lista = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Meta dashboard livre', $lista);
        self::assertStringNotContainsString('Meta dashboard trancada', $lista);
    }

    #[TestDox('o primário e o gestor com nível na pasta continuam contando as duas metas')]
    public function testPrimarioEGestorSemRestricaoContinuamVendo(): void
    {
        $c = $this->cenario();

        foreach (['primario' => $c['primario'], 'gestorLivre' => $c['gestorLivre']] as $quem => $usuario) {
            $painel = $this->painelVistoPor($usuario, $c['tenant']);

            self::assertSame('2', $painel['card'], $quem);
            self::assertSame('2', $painel['total'], $quem);
            self::assertSame('2', $painel['ativas'], $quem);
            self::assertSame('2', $painel['vencidas'], $quem);
        }
    }

    #[TestDox('colunas extras de metas (em revisão e tempo médio) também deixam de contar a meta da pasta oculta')]
    public function testColunasExtrasDeMetas(): void
    {
        $c       = $this->cenario(Tarefa::STATUS_EM_REVISAO);
        $ocultas = static::getContainer()->get(AcessoAPasta::class)->idsOcultosPara($c['gestora'], (int) $c['tenant']->getId());
        $extras  = static::getContainer()->get(MetricasExtrasDoDashboardRepository::class);
        $anaId   = (int) $c['ana']->getId();

        self::assertSame([(int) $c['oculta']->getId()], $ocultas);
        self::assertSame(1, $extras->contarEmRevisaoPorResponsavel($c['tenant'], [], $ocultas)[$anaId] ?? 0);
        self::assertSame(2, $extras->contarEmRevisaoPorResponsavel($c['tenant'])[$anaId] ?? 0, 'sem o cadeado seriam duas');
        self::assertSame([], static::getContainer()->get(AcessoAPasta::class)->idsOcultosPara($c['primario'], (int) $c['tenant']->getId()));

        foreach ($this->em()->getRepository(Tarefa::class)->findBy(['tenant' => $c['tenant']]) as $meta) {
            $meta->setStatus(Tarefa::STATUS_CONCLUIDA);
            $meta->setDataConclusao(new \DateTimeImmutable());
        }
        $this->em()->flush();

        self::assertSame(1, $extras->tempoDeConclusaoPorResponsavel($c['tenant'], [], $ocultas)[$anaId]['metas'] ?? 0);
        self::assertSame(2, $extras->tempoDeConclusaoPorResponsavel($c['tenant'])[$anaId]['metas'] ?? 0);
    }
}
