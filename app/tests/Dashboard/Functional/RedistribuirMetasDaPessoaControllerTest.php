<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Functional;

use App\Dashboard\Controller\PerfilDaPessoaDoDashboardController;
use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\NivelDeAcesso;
use App\Pasta\Entity\Pasta;
use App\Tarefa\Enum\AcaoDoDespacho;
use App\Tarefa\Service\AuditoriaDaRedistribuicao;
use App\Tarefa\UseCase\RedistribuirMetasDaPessoaUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\CriaCenarioDoCadeadoFuncionalTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\DomCrawler\Crawler;

/**
 * "Redistribuir" do perfil individual (spec painel-acompanhamento-redistribuir, fatia B): GET com as metas ativas da
 * pessoa (cadeado aplicado, tudo desmarcado, destino sugerido) e POST JSON com CSRF no header. Só o Master ou um
 * primário ativo (403); pessoa, meta ou destino de outro escritório não vazam (404 e 400); sem migration.
 */
#[CoversClass(PerfilDaPessoaDoDashboardController::class)]
#[CoversClass(RedistribuirMetasDaPessoaUseCase::class)]
#[Group('dashboard')]
final class RedistribuirMetasDaPessoaControllerTest extends JusPrimeWebTestCase
{
    use CriaCenarioDoCadeadoFuncionalTrait;

    private const PERMISSOES_GESTOR = ['modules.expediente.view', 'resources.pasta.view', 'modules.bi.view'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCliente();
    }

    /**
     * Ana tem 3 metas ativas (duas em pasta livre, uma em pasta trancada que Bia não vê), 1 concluída; Bia é
     * responsável por uma meta em que Ana é só criadora.
     *
     * @return array{primario: User, tenant: Tenant, gestor: User, ana: User, bia: User, caio: User, metas: array<string, Tarefa>}
     */
    private function cenario(): array
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $gestor = $this->criarColaborador($tenant, 'Gestor Secundário', self::PERMISSOES_GESTOR);
        $ana    = $this->criarColaborador($tenant, 'Ana Equipe');
        $bia    = $this->criarColaborador($tenant, 'Bia Destino');
        $caio   = $this->criarColaborador($tenant, 'Caio Destino');
        $livre  = $this->criarPasta($tenant, 'CLIENTE LIVRE');
        $trancada = $this->criarPasta($tenant, 'CLIENTE TRANCADO');
        $this->trancar($trancada, $primario, [[$ana, NivelDeAcesso::Editar], [$caio, NivelDeAcesso::Editar]]);

        $metas = [
            'm1' => $this->criarMeta($tenant, $livre, 'Meta um', $ana),
            'm2' => $this->criarMeta($tenant, $livre, 'Meta dois', $ana),
            'm3' => $this->criarMeta($tenant, $trancada, 'Meta trancada', $ana),
            'concluida' => $this->criarMeta($tenant, $livre, 'Meta concluída', $ana, Tarefa::STATUS_CONCLUIDA),
            'dela' => $this->criarMeta($tenant, $livre, 'Meta da Bia', $bia, Tarefa::STATUS_PENDENTE, $ana),
        ];
        $this->em()->flush();

        return ['primario' => $primario, 'tenant' => $tenant, 'gestor' => $gestor, 'ana' => $ana, 'bia' => $bia, 'caio' => $caio, 'metas' => $metas];
    }

    private function criarMeta(Tenant $tenant, Pasta $pasta, string $titulo, User $responsavel, string $status = Tarefa::STATUS_PENDENTE, ?User $criador = null): Tarefa
    {
        $meta = new Tarefa();
        $meta->setTitulo($titulo);
        $meta->setDescricao('Descrição');
        $meta->setPasta($pasta);
        $meta->setTenant($tenant);
        $meta->setCriadoPor($criador ?? $responsavel);
        $meta->addResponsavel($responsavel);
        $meta->setStatus($status);
        $this->em()->persist($meta);

        return $meta;
    }

    /** @param list<array{0: Tarefa|int, 1: User|int}> $pares meta e destino */
    private function movimentos(array $pares): array
    {
        return ['movimentos' => array_map(
            static fn (array $p): array => ['meta_id' => $p[0] instanceof Tarefa ? $p[0]->getId() : $p[0], 'destino_id' => $p[1] instanceof User ? $p[1]->getId() : $p[1]],
            $pares,
        )];
    }

    /** @param array<string, mixed>|null $corpo */
    private function confirmar(User $origem, ?array $corpo, string $token = 'TOKEN_ajax'): void
    {
        $this->client->request('POST', '/dashboard/inteligencia/pessoa/' . $origem->getId() . '/redistribuir', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], json_encode($corpo ?? [], JSON_THROW_ON_ERROR));
    }

    private function lista(User $pessoa): Crawler
    {
        return $this->client->request('GET', '/dashboard/inteligencia/pessoa/' . $pessoa->getId() . '/redistribuir');
    }

    /** @return array<string, mixed> */
    private function resposta(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 8, JSON_THROW_ON_ERROR);
    }

    /** @return list<int> */
    private function responsaveis(Tarefa $meta): array
    {
        $ids = $this->em()->getConnection()->fetchFirstColumn('SELECT user_id FROM tarefa_responsaveis WHERE tarefa_id = :id ORDER BY user_id', ['id' => $meta->getId()]);

        return array_map('intval', $ids);
    }

    private function contar(string $sql, array $parametros): int
    {
        return (int) $this->em()->getConnection()->fetchOne($sql, $parametros);
    }

    private function auditorias(Tenant $tenant, string $acao): int
    {
        return $this->contar('SELECT COUNT(*) FROM audit_log WHERE tenant_id = :t AND action = :a', ['t' => $tenant->getId(), 'a' => $acao]);
    }

    #[TestDox('A lista traz só as metas ativas da pessoa (sem concluída nem meta em que ela é só criadora), TUDO desmarcado, com destino sugerido')]
    public function testListaTudoDesmarcado(): void
    {
        $c = $this->cenario();
        $this->entrar($c['primario'], $c['tenant']);

        $tela = $this->lista($c['ana']);

        self::assertResponseIsSuccessful();
        $titulos = $tela->filter('[data-pf-red-linha] b')->each(static fn (Crawler $b): string => $b->text());
        self::assertSame(['Meta um', 'Meta dois', 'Meta trancada'], $titulos);
        self::assertCount(3, $tela->filter('[data-pf-red-marca]'));
        self::assertCount(0, $tela->filter('[data-pf-red-marca][checked]'), 'nada vem marcado');
        self::assertCount(3, $tela->filter('[data-pf-red-destino] option[selected]'), 'cada meta tem destino sugerido');
        self::assertStringContainsString('Sugestão por regras fixas: quem tem menos metas ativas. Sem IA, sem avaliação de desempenho.', $tela->filter('body')->text());
        self::assertStringContainsString('pode diferir do número do card se ele foi zerado', $tela->filter('body')->text());
        self::assertStringNotContainsString('Ana Equipe (', $tela->filter('[data-pf-red-lista]')->text(), 'a origem não é destino');
    }

    #[TestDox('Cadeado: quem não vê a pasta trancada não é destino possível da meta dela')]
    public function testCadeadoNoDestino(): void
    {
        $c = $this->cenario();
        $this->entrar($c['primario'], $c['tenant']);

        $tela = $this->lista($c['ana']);

        $livre = $tela->filter('[data-meta="' . $c['metas']['m1']->getId() . '"] option')->each(static fn (Crawler $o): string => $o->text());
        $trancada = $tela->filter('[data-meta="' . $c['metas']['m3']->getId() . '"] option')->each(static fn (Crawler $o): string => $o->text());
        self::assertCount(4, $livre, 'primária, secundário, Bia e Caio veem a pasta livre; a origem fica fora');
        self::assertStringContainsString('Bia', implode('|', $livre));
        self::assertStringNotContainsString('Bia', implode('|', $trancada));
        self::assertStringNotContainsString('Gestor', implode('|', $trancada));
        self::assertStringContainsString('Caio', implode('|', $trancada));
    }

    #[TestDox('Pessoa sem meta ativa: a lista vem vazia com "Nenhuma meta ativa para redistribuir."')]
    public function testPessoaSemMeta(): void
    {
        $c = $this->cenario();
        $this->entrar($c['primario'], $c['tenant']);

        $tela = $this->lista($c['caio']);

        self::assertResponseIsSuccessful();
        self::assertSame('Nenhuma meta ativa para redistribuir.', trim($tela->filter('[data-pf-red-vazio]')->text()));
    }

    #[TestDox('O botão "Redistribuir" só aparece para quem tem meta ativa e está acima da média do escritório; o dialog vem junto')]
    public function testBotaoPorDadoReal(): void
    {
        $c = $this->cenario();
        $this->entrar($c['primario'], $c['tenant']);

        $ana = $this->client->request('GET', '/dashboard/inteligencia/pessoa/' . $c['ana']->getId());
        $caio = $this->client->request('GET', '/dashboard/inteligencia/pessoa/' . $c['caio']->getId());

        self::assertSame('Redistribuir', trim($ana->filter('[data-pf-red-abrir]')->text()));
        self::assertCount(1, $ana->filter('dialog[data-pf-red-dlg] [data-pf-red-confirmar][disabled]'), 'confirmar nasce desabilitado');
        self::assertCount(0, $caio->filter('[data-pf-red-abrir]'));
        self::assertCount(0, $caio->filter('dialog[data-pf-red-dlg]'));
    }

    #[TestDox('Confirmar troca o responsável, grava histórico e auditoria (sem nomes) e avisa uma vez por pessoa; nenhuma meta fica sem responsável')]
    public function testConfirmaRedistribuicao(): void
    {
        $c = $this->cenario();
        $this->entrar($c['primario'], $c['tenant']);
        $m = $c['metas'];

        $this->confirmar($c['ana'], $this->movimentos([[$m['m1'], $c['bia']], [$m['m2'], $c['caio']], [$m['m3'], $c['caio']]]));

        self::assertResponseIsSuccessful();
        self::assertSame(['passadas' => 3, 'puladas' => 0, 'total' => 3], $this->resposta());
        self::assertSame([$c['bia']->getId()], $this->responsaveis($m['m1']));
        self::assertSame([$c['caio']->getId()], $this->responsaveis($m['m2']));
        self::assertSame([$c['caio']->getId()], $this->responsaveis($m['m3']));
        self::assertSame([$c['ana']->getId()], $this->responsaveis($m['concluida']), 'concluída nunca muda');
        self::assertSame([$c['bia']->getId()], $this->responsaveis($m['dela']));

        self::assertSame(3, $this->contar("SELECT COUNT(*) FROM tarefa_mensagem WHERE tenant_id = :t AND acao = 'redistribuicao'", ['t' => $c['tenant']->getId()]));
        $texto = (string) $this->em()->getConnection()->fetchOne("SELECT mensagem FROM tarefa_mensagem WHERE tarefa_id = :id AND acao = 'redistribuicao'", ['id' => $m['m1']->getId()]);
        self::assertSame('<p>Responsável alterado de Ana Equipe para Bia Destino por Paula Primária.</p>', $texto);

        self::assertSame(3, $this->auditorias($c['tenant'], 'meta_redis'));
        self::assertSame(1, $this->auditorias($c['tenant'], 'ia_redis'));
        $log = $this->em()->getConnection()->fetchAssociative("SELECT entity_class, entity_id, changes FROM audit_log WHERE action = 'meta_redis' AND entity_id = :id", ['id' => (string) $m['m1']->getId()]);
        self::assertSame(Tarefa::class, $log['entity_class']);
        self::assertSame(['origem_id' => $c['ana']->getId(), 'destino_id' => $c['bia']->getId()], json_decode((string) $log['changes'], true));
        $operacao = (string) $this->em()->getConnection()->fetchOne("SELECT changes FROM audit_log WHERE action = 'ia_redis' AND tenant_id = :t", ['t' => $c['tenant']->getId()]);
        self::assertSame(['pessoa_id' => $c['ana']->getId(), 'total' => 3], json_decode($operacao, true));
        self::assertStringNotContainsString('Ana', $operacao);

        $avisos = $this->em()->getConnection()->fetchAllKeyValue('SELECT usuario_id, mensagem FROM notificacao WHERE tenant_id = :t AND tipo = :tipo', ['t' => $c['tenant']->getId(), 'tipo' => RedistribuirMetasDaPessoaUseCase::TIPO_NOTIFICACAO]);
        self::assertSame([
            $c['ana']->getId() => '3 metas suas passaram para outras pessoas.',
            $c['bia']->getId() => '1 meta passou para você.',
            $c['caio']->getId() => '2 metas passaram para você.',
        ], $this->ordenar($avisos));
    }

    /** @param array<int|string, string> $avisos @return array<int, string> */
    private function ordenar(array $avisos): array
    {
        $porId = [];
        foreach ($avisos as $id => $texto) {
            $porId[(int) $id] = $texto;
        }
        ksort($porId);

        return $porId;
    }

    #[TestDox('Meta concluída no pedido é pulada e dita ("mudou antes da confirmação"); nada é gravado nem avisado sobre ela')]
    public function testMetaQueMudouEPulada(): void
    {
        $c = $this->cenario();
        $this->entrar($c['primario'], $c['tenant']);

        $this->confirmar($c['ana'], $this->movimentos([[$c['metas']['concluida'], $c['bia']]]));

        self::assertResponseIsSuccessful();
        self::assertSame(['passadas' => 0, 'puladas' => 1, 'total' => 1], $this->resposta());
        self::assertSame([$c['ana']->getId()], $this->responsaveis($c['metas']['concluida']));
        self::assertSame(0, $this->auditorias($c['tenant'], 'ia_redis'));
        self::assertSame(0, $this->contar('SELECT COUNT(*) FROM notificacao WHERE tenant_id = :t AND tipo = :tipo', ['t' => $c['tenant']->getId(), 'tipo' => RedistribuirMetasDaPessoaUseCase::TIPO_NOTIFICACAO]));
    }

    #[TestDox('Cross-tenant: meta de outro escritório dá 404 e o lote inteiro não grava; pessoa de outro escritório dá 404 no GET e no POST')]
    public function testCrossTenant(): void
    {
        $c = $this->cenario();
        [$primarioDeFora, $outro] = $this->criarEscritorio();
        $deFora = $this->criarColaborador($outro, 'Pessoa de Fora');
        $pastaDeFora = $this->criarPasta($outro, 'CLIENTE DE FORA');
        $metaDeFora = $this->criarMeta($outro, $pastaDeFora, 'Meta de fora', $deFora);
        $this->em()->flush();
        $this->entrar($c['primario'], $c['tenant']);

        $this->confirmar($c['ana'], $this->movimentos([[$c['metas']['m1'], $c['bia']], [$metaDeFora, $c['bia']]]));
        self::assertResponseStatusCodeSame(404);
        self::assertSame([$c['ana']->getId()], $this->responsaveis($c['metas']['m1']), 'a primeira meta do lote não foi movida');
        self::assertSame([$deFora->getId()], $this->responsaveis($metaDeFora));

        $this->lista($deFora);
        self::assertResponseStatusCodeSame(404);
        $this->confirmar($deFora, $this->movimentos([[$metaDeFora, $c['bia']]]));
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->auditorias($c['tenant'], 'meta_redis'));
        self::assertSame(0, $this->auditorias($outro, 'meta_redis'));
        self::assertNotNull($primarioDeFora);
    }

    #[TestDox('Destino inválido dá 400 e nada grava: de outro escritório, a própria origem ou quem não vê a pasta (cadeado)')]
    public function testDestinoInvalido(): void
    {
        $c = $this->cenario();
        [, $outro] = $this->criarEscritorio();
        $deFora = $this->criarColaborador($outro, 'Pessoa de Fora');
        $this->em()->flush();
        $this->entrar($c['primario'], $c['tenant']);
        $m = $c['metas'];

        foreach ([[$m['m1'], $deFora], [$m['m1'], $c['ana']], [$m['m3'], $c['bia']]] as $par) {
            $this->confirmar($c['ana'], $this->movimentos([$par]));
            self::assertResponseStatusCodeSame(400);
        }

        self::assertSame([$c['ana']->getId()], $this->responsaveis($m['m1']));
        self::assertSame([$c['ana']->getId()], $this->responsaveis($m['m3']));
        self::assertSame(0, $this->auditorias($c['tenant'], 'ia_redis'));
    }

    #[TestDox('Corpo inválido, lote vazio e mais de 50 metas dão 400; CSRF inválido dá 403; nada grava')]
    public function testCorpoLimiteECsrf(): void
    {
        $c = $this->cenario();
        $this->entrar($c['primario'], $c['tenant']);

        $this->confirmar($c['ana'], $this->movimentos([[$c['metas']['m1'], $c['bia']]]), 'TOKEN_errado');
        self::assertResponseStatusCodeSame(403);

        foreach ([[], ['movimentos' => []], ['movimentos' => [['meta_id' => '1', 'destino_id' => 2]]], ['movimentos' => 'x']] as $corpo) {
            $this->confirmar($c['ana'], $corpo);
            self::assertResponseStatusCodeSame(400, json_encode($corpo));
        }

        $muitas = array_map(static fn (int $i): array => [1000000 + $i, $c['bia']->getId()], range(1, 51));
        $this->confirmar($c['ana'], $this->movimentos($muitas));
        self::assertResponseStatusCodeSame(400);
        self::assertSame([$c['ana']->getId()], $this->responsaveis($c['metas']['m1']));
        self::assertSame(0, $this->auditorias($c['tenant'], 'ia_redis'));
    }

    #[TestDox('Quem tem o Dashboard mas não é primário recebe 403 no GET e no POST; nada é gravado')]
    public function testNaoPrimario403(): void
    {
        $c = $this->cenario();
        $this->entrar($c['gestor'], $c['tenant']);

        $this->lista($c['ana']);
        self::assertResponseStatusCodeSame(403);
        $this->confirmar($c['ana'], $this->movimentos([[$c['metas']['m1'], $c['bia']]]));
        self::assertResponseStatusCodeSame(403);

        self::assertSame([$c['ana']->getId()], $this->responsaveis($c['metas']['m1']));
        self::assertSame(0, $this->auditorias($c['tenant'], 'meta_redis'));
    }

    #[TestDox('As ações de auditoria da redistribuição cabem nos 10 caracteres da coluna')]
    public function testAcoesCabemNaColuna(): void
    {
        foreach ([AuditoriaDaRedistribuicao::ACAO_META, AuditoriaDaRedistribuicao::ACAO_OPERACAO] as $acao) {
            self::assertLessThanOrEqual(10, strlen($acao), $acao);
        }
        self::assertLessThanOrEqual(20, strlen(AcaoDoDespacho::Redistribuicao->value), 'cabe no varchar(20) da coluna acao, sem migration');
    }
}
