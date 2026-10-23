<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Functional;

use App\Dashboard\Controller\ZeramentoDoDashboardController;
use App\Dashboard\UseCase\PedirZeramentoDoDashboardUseCase;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\CriaCenarioDoCadeadoFuncionalTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\DomCrawler\Crawler;

/**
 * POST /dashboard/zerar, /desfazer e /pedir (DASH-014). Zerar grava o corte, não apaga nada; o painel
 * passa a contar a coluna desde o corte e o desfazer devolve a contagem. Só o Master ou um primário
 * ativo zera e desfaz (403); pessoa de outro escritório dá 404; CSRF no header (403).
 */
#[CoversClass(ZeramentoDoDashboardController::class)]
final class ZeramentoDoDashboardControllerTest extends JusPrimeWebTestCase
{
    use CriaCenarioDoCadeadoFuncionalTrait;

    private const PERMISSOES_GESTOR = ['modules.expediente.view', 'resources.pasta.view', 'modules.bi.view'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCliente();
    }

    /** @return array{primario: User, tenant: Tenant, gestor: User, ana: User, pasta: Pasta} Ana com 2 metas criadas há 1 hora */
    private function cenario(): array
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $gestor = $this->criarColaborador($tenant, 'Gestor Secundário', self::PERMISSOES_GESTOR);
        $ana    = $this->criarColaborador($tenant, 'Ana Equipe');
        $pasta  = $this->criarPasta($tenant);
        $c = ['primario' => $primario, 'tenant' => $tenant, 'gestor' => $gestor, 'ana' => $ana, 'pasta' => $pasta];
        $this->novaMetaDaAna($c, '-1 hour');
        $this->novaMetaDaAna($c, '-1 hour');

        return $c;
    }

    /** @param array{primario: User, tenant: Tenant, ana: User, pasta: Pasta} $c */
    private function novaMetaDaAna(array $c, string $criadaEm): void
    {
        // As referências valem depois de um request (o EntityManager pode ter sido limpo).
        $em   = $this->em();
        $meta = new Tarefa();
        $meta->setTitulo('Meta da Ana');
        $meta->setDescricao('Descrição');
        $meta->setPasta($em->getReference(Pasta::class, $c['pasta']->getId()));
        $meta->setTenant($em->getReference(Tenant::class, $c['tenant']->getId()));
        $meta->setCriadoPor($em->getReference(User::class, $c['primario']->getId()));
        $meta->addResponsavel($em->getReference(User::class, $c['ana']->getId()));
        $meta->setPrazo(new \DateTimeImmutable('+2 days'));
        $meta->setStatus(Tarefa::STATUS_PENDENTE);
        $this->em()->persist($meta);
        $this->em()->flush();
        $this->em()->getConnection()->executeStatement(
            'UPDATE tarefa SET data_criacao = :quando WHERE id = :id',
            ['quando' => (new \DateTimeImmutable($criadaEm))->format('Y-m-d H:i:s'), 'id' => $meta->getId()],
        );
    }

    /** @param array<string, mixed> $corpo */
    private function postar(string $rota, array $corpo, string $token = 'TOKEN_ajax'): void
    {
        $this->client->request(
            'POST',
            '/dashboard/zerar' . $rota,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token],
            json_encode($corpo, JSON_THROW_ON_ERROR),
        );
    }

    /** @return array<string, mixed> */
    private function resposta(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function zeramentosNoBanco(Tenant $tenant, bool $ativos = true): int
    {
        return (int) $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM dashboard_zeramento WHERE tenant_id = :t AND desfeito_em IS ' . ($ativos ? 'NULL' : 'NOT NULL'),
            ['t' => $tenant->getId()],
        );
    }

    private function totalMetasDaAna(): string
    {
        $tela = new Crawler((string) $this->client->getResponse()->getContent());
        $linha = $tela->filter('table.db-table > tbody > tr')->reduce(
            static fn (Crawler $tr): bool => str_contains($tr->filter('.db-colab-nome')->attr('title') ?? '', 'Ana Equipe'),
        );
        self::assertCount(1, $linha);

        return trim($linha->filter('tr > td[data-coluna="metas"] .db-num')->text());
    }

    #[TestDox('Primário zera Total metas de Ana: o painel conta desde o corte, sem link; desfazer devolve a contagem')]
    public function testZerarEDesfazer(): void
    {
        $c = $this->cenario();
        $this->entrar($c['primario'], $c['tenant']);
        $this->client->request('GET', '/dashboard');
        self::assertSame('2', $this->totalMetasDaAna(), 'antes do corte');

        $this->postar('', ['coluna' => 'metas', 'pessoa' => $c['ana']->getId()]);
        self::assertResponseIsSuccessful();
        self::assertSame('metas', $this->resposta()['coluna']);
        self::assertSame($c['ana']->getId(), $this->resposta()['pessoa']);

        $tela = $this->client->request('GET', '/dashboard');
        self::assertSame('0', $this->totalMetasDaAna(), 'as 2 metas são de antes do corte');
        $cortes = json_decode((string) $tela->filter('table.db-table')->attr('data-db-zeramentos'), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey((string) $c['ana']->getId(), $cortes['metas']['pessoas']);
        self::assertSame(2, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM tarefa WHERE tenant_id = :t', ['t' => $c['tenant']->getId()]), 'nada se apaga');

        $this->novaMetaDaAna($c, '+1 minute');
        $this->client->request('GET', '/dashboard');
        self::assertSame('1', $this->totalMetasDaAna(), 'a meta criada depois do corte conta (sem link)');

        $this->postar('/desfazer', ['coluna' => 'metas']);
        self::assertResponseIsSuccessful();
        self::assertSame(['desfeitos' => 1], $this->resposta());
        self::assertSame(1, $this->zeramentosNoBanco($c['tenant'], false));

        $this->client->request('GET', '/dashboard');
        self::assertSame('3', $this->totalMetasDaAna(), 'a contagem volta');
    }

    #[TestDox('Quem não é primário (mesmo com o Dashboard) recebe 403 ao zerar e ao desfazer, e nada é gravado')]
    public function testNaoPrimario403(): void
    {
        $c = $this->cenario();
        $this->entrar($c['gestor'], $c['tenant']);

        $this->postar('', ['coluna' => 'metas', 'pessoa' => null]);
        self::assertResponseStatusCodeSame(403);
        $this->postar('/desfazer', ['coluna' => 'metas']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->zeramentosNoBanco($c['tenant']));
    }

    #[TestDox('Pessoa de outro escritório dá 404, no zerar e no pedir, e nada é gravado')]
    public function testPessoaDeOutroEscritorio404(): void
    {
        $c = $this->cenario();
        [, $outro] = $this->criarEscritorio();
        $deFora = $this->criarColaborador($outro, 'Pessoa de Fora');
        $this->entrar($c['primario'], $c['tenant']);

        $this->postar('', ['coluna' => 'metas', 'pessoa' => $deFora->getId()]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->zeramentosNoBanco($c['tenant']));
        self::assertSame(0, $this->zeramentosNoBanco($outro));

        $this->entrar($c['gestor'], $c['tenant']);
        $this->postar('/pedir', ['coluna' => 'metas', 'pessoa' => $deFora->getId()]);
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('CSRF inválido dá 403 e nada é gravado; coluna fora da tabela dá 400')]
    public function testCsrfEColuna(): void
    {
        $c = $this->cenario();
        $this->entrar($c['primario'], $c['tenant']);

        $this->postar('', ['coluna' => 'metas'], 'TOKEN_errado');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->zeramentosNoBanco($c['tenant']));

        $this->postar('', ['coluna' => 'senha']);
        self::assertResponseStatusCodeSame(400);
    }

    #[TestDox('Pedir para zerar avisa o primário e a Controladoria pela Central; o mesmo pedido no dia volta repetido')]
    public function testPedir(): void
    {
        $c = $this->cenario();
        $contas = $this->criarColaborador($c['tenant'], 'Davi Contas');
        $vinculo = $this->em()->getRepository(UserTenant::class)->findOneBy(['user' => $contas, 'tenant' => $c['tenant']]);
        $vinculo?->setControladoria(true);
        $this->em()->flush();
        $this->entrar($c['gestor'], $c['tenant']);

        $this->postar('/pedir', ['coluna' => 'metas', 'pessoa' => $c['ana']->getId()]);
        self::assertResponseIsSuccessful();
        self::assertSame(['avisados' => 2, 'controladoria' => true, 'repetido' => false], $this->resposta());
        $avisados = $this->em()->getConnection()->fetchFirstColumn(
            'SELECT usuario_id FROM notificacao WHERE tenant_id = :t AND tipo = :tipo ORDER BY usuario_id',
            ['t' => $c['tenant']->getId(), 'tipo' => PedirZeramentoDoDashboardUseCase::TIPO_NOTIFICACAO],
        );
        $esperados = [$c['primario']->getId(), $contas->getId()];
        sort($esperados);
        self::assertSame($esperados, array_map('intval', $avisados));
        self::assertSame(0, $this->zeramentosNoBanco($c['tenant']), 'pedir não muda os números');

        $this->postar('/pedir', ['coluna' => 'metas', 'pessoa' => $c['ana']->getId()]);
        self::assertTrue($this->resposta()['repetido']);
    }
}
