<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Functional;

use App\Dashboard\Controller\PerfilDaPessoaDoDashboardController;
use App\Dashboard\Entity\AcompanhamentoDaPessoa;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\CriaCenarioDoCadeadoFuncionalTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\DomCrawler\Crawler;

/**
 * "Criar acompanhamento" do perfil individual (spec painel-acompanhamento-redistribuir, fatia A): criar,
 * renovar e encerrar por POST JSON com CSRF no header. Só o Master ou um primário ativo (403); pessoa de outro
 * escritório dá 404; só um acompanhamento aberto por pessoa (409); o chip "Em acompanhamento" aparece no perfil.
 */
#[CoversClass(PerfilDaPessoaDoDashboardController::class)]
#[Group('dashboard')]
final class AcompanhamentoDaPessoaControllerTest extends JusPrimeWebTestCase
{
    use CriaCenarioDoCadeadoFuncionalTrait;

    private const PERMISSOES_GESTOR = ['modules.expediente.view', 'resources.pasta.view', 'modules.bi.view'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCliente();
    }

    /** @return array{primario: User, tenant: Tenant, gestor: User, ana: User} */
    private function cenario(): array
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $gestor = $this->criarColaborador($tenant, 'Gestor Secundário', self::PERMISSOES_GESTOR);
        $ana    = $this->criarColaborador($tenant, 'Ana Equipe');
        $this->em()->flush();

        return ['primario' => $primario, 'tenant' => $tenant, 'gestor' => $gestor, 'ana' => $ana];
    }

    /** @param array<string, mixed>|null $corpo */
    private function enviar(string $sufixo, User $pessoa, ?array $corpo = ['prazo_dias' => 7], string $token = 'TOKEN_ajax'): void
    {
        $this->client->request('POST', '/dashboard/inteligencia/pessoa/' . $pessoa->getId() . '/acompanhamento' . $sufixo, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], json_encode($corpo ?? [], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function resposta(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 8, JSON_THROW_ON_ERROR);
    }

    private function linhas(Tenant $tenant, bool $soAbertas = false): int
    {
        $sql = 'SELECT COUNT(*) FROM dashboard_acompanhamento_pessoa WHERE tenant_id = :t' . ($soAbertas ? ' AND encerrado_em IS NULL' : '');

        return (int) $this->em()->getConnection()->fetchOne($sql, ['t' => $tenant->getId()]);
    }

    private function auditorias(Tenant $tenant, string $acao): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM audit_log WHERE tenant_id = :t AND action = :a', ['t' => $tenant->getId(), 'a' => $acao]);
    }

    private function perfil(User $pessoa): Crawler
    {
        return $this->client->request('GET', '/dashboard/inteligencia/pessoa/' . $pessoa->getId());
    }

    #[TestDox('Primário cria o acompanhamento: grava uma linha aberta, audita acomp_cria sem nome e devolve o estado')]
    public function testPrimarioCria(): void
    {
        $c = $this->cenario();
        $this->entrar($c['primario'], $c['tenant']);

        $this->enviar('', $c['ana'], ['prazo_dias' => 2]);

        self::assertResponseIsSuccessful();
        $dados = $this->resposta();
        self::assertTrue($dados['aberto']);
        self::assertSame((new \DateTimeImmutable('today +2 days'))->format('Y-m-d'), $dados['revisar_em']);
        self::assertSame(1, $this->linhas($c['tenant'], true));
        self::assertSame(1, $this->auditorias($c['tenant'], 'acomp_cria'));
        $log = $this->em()->getConnection()->fetchAssociative("SELECT entity_id, actor_user_id, changes FROM audit_log WHERE action = 'acomp_cria' AND tenant_id = :t", ['t' => $c['tenant']->getId()]);
        self::assertSame((string) $c['ana']->getId(), $log['entity_id']);
        self::assertSame($c['primario']->getId(), (int) $log['actor_user_id']);
        $changes = json_decode((string) $log['changes'], true);
        self::assertSame(['evento', 'pessoa_id', 'revisar_em'], array_keys($changes));
        self::assertStringNotContainsString('Ana', (string) $log['changes']);
    }

    #[TestDox('O perfil mostra o chip "Em acompanhamento" (amarelo), "Revisar até dd/mm" e troca o botão por Renovar e Encerrar')]
    public function testChipENoBotao(): void
    {
        $c = $this->cenario();
        $this->entrar($c['primario'], $c['tenant']);

        $antes = $this->perfil($c['ana']);
        self::assertSame('Pendente', trim($antes->filter('[data-pf-status]')->text()));
        self::assertNull($antes->filter('[data-pf-acomp-abrir="criar"]')->attr('hidden'));
        self::assertSame('', $antes->filter('[data-pf-acomp-encerrar]')->attr('hidden'));
        self::assertCount(1, $antes->filter('dialog[data-pf-dlg]'));

        $this->enviar('', $c['ana'], ['prazo_dias' => 7]);
        $depois = $this->perfil($c['ana']);

        self::assertSame('Em acompanhamento', trim($depois->filter('[data-pf-status]')->text()));
        self::assertStringContainsString('db-pf-status--acomp', (string) $depois->filter('[data-pf-status]')->attr('class'));
        self::assertSame('Revisar até ' . (new \DateTimeImmutable('today +7 days'))->format('d/m'), trim($depois->filter('[data-pf-revisao]')->text()));
        self::assertSame('', $depois->filter('[data-pf-acomp-abrir="criar"]')->attr('hidden'));
        self::assertNull($depois->filter('[data-pf-acomp-encerrar]')->attr('hidden'));
        self::assertStringContainsString('Não cria nota, não altera metas e não avisa a pessoa.', $depois->filter('.db-pf-dlg-texto')->text());
        self::assertSame(['1', '2', '7'], $depois->filter('.db-pf-dlg-prazo input[type=radio]')->each(static fn (Crawler $i): string => (string) $i->attr('value')));
    }

    #[TestDox('Já em acompanhamento: o segundo POST dá 409 e continua uma linha só')]
    public function testDuplicadoDa409(): void
    {
        $c = $this->cenario();
        $this->entrar($c['primario'], $c['tenant']);

        $this->enviar('', $c['ana']);
        $this->enviar('', $c['ana']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('Esta pessoa já está em acompanhamento.', $this->resposta()['erro']);
        self::assertSame(1, $this->linhas($c['tenant']));
        self::assertSame(1, $this->auditorias($c['tenant'], 'acomp_cria'));
    }

    #[TestDox('Renovar muda a data na mesma linha e audita acomp_renov; sem aberto dá 409')]
    public function testRenova(): void
    {
        $c = $this->cenario();
        $this->entrar($c['primario'], $c['tenant']);

        $this->enviar('/renovar', $c['ana']);
        self::assertResponseStatusCodeSame(409);

        $this->enviar('', $c['ana'], ['prazo_dias' => 7]);
        $this->enviar('/renovar', $c['ana'], ['prazo_dias' => 1]);

        self::assertResponseIsSuccessful();
        self::assertSame((new \DateTimeImmutable('today +1 day'))->format('Y-m-d'), $this->resposta()['revisar_em']);
        self::assertSame(1, $this->linhas($c['tenant']));
        self::assertSame(1, $this->auditorias($c['tenant'], 'acomp_renov'));
    }

    #[TestDox('Encerrar fecha a linha (que fica para a auditoria), audita acomp_fim, volta o chip e permite criar de novo')]
    public function testEncerra(): void
    {
        $c = $this->cenario();
        $this->entrar($c['primario'], $c['tenant']);
        $this->enviar('', $c['ana']);

        $this->enviar('/encerrar', $c['ana'], []);

        self::assertResponseIsSuccessful();
        self::assertFalse($this->resposta()['aberto']);
        self::assertSame(1, $this->linhas($c['tenant']));
        self::assertSame(0, $this->linhas($c['tenant'], true));
        self::assertSame(1, $this->auditorias($c['tenant'], 'acomp_fim'));
        $linha = $this->em()->getConnection()->fetchAssociative('SELECT encerrado_por_id FROM dashboard_acompanhamento_pessoa WHERE tenant_id = :t', ['t' => $c['tenant']->getId()]);
        self::assertSame($c['primario']->getId(), (int) $linha['encerrado_por_id']);
        self::assertSame('Pendente', trim($this->perfil($c['ana'])->filter('[data-pf-status]')->text()));

        $this->enviar('', $c['ana']);
        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->linhas($c['tenant']));
        self::assertSame(1, $this->linhas($c['tenant'], true));

        $this->enviar('/encerrar', $c['ana'], []);
        $this->enviar('/encerrar', $c['ana'], []);
        self::assertResponseStatusCodeSame(409);
    }

    #[TestDox('Vencida a data de revisão, o chip diz "Revisar acompanhamento" e nada muda sozinho')]
    public function testVencido(): void
    {
        $c = $this->cenario();
        $this->em()->persist(new AcompanhamentoDaPessoa($c['tenant'], $c['ana'], $c['primario'], new \DateTimeImmutable('-10 days'), 1));
        $this->em()->flush();
        $this->entrar($c['primario'], $c['tenant']);

        $perfil = $this->perfil($c['ana']);

        self::assertSame('Revisar acompanhamento', trim($perfil->filter('[data-pf-status]')->text()));
        self::assertSame(1, $this->linhas($c['tenant'], true));
    }

    #[TestDox('Quem tem o Dashboard mas não é primário recebe 403 nas três portas; nada é gravado nem auditado')]
    public function testNaoPrimario403(): void
    {
        $c = $this->cenario();
        $this->entrar($c['gestor'], $c['tenant']);

        foreach (['', '/renovar', '/encerrar'] as $sufixo) {
            $this->enviar($sufixo, $c['ana']);
            self::assertResponseStatusCodeSame(403, $sufixo);
        }

        self::assertSame(0, $this->linhas($c['tenant']));
        self::assertSame(0, $this->auditorias($c['tenant'], 'acomp_cria'));
    }

    #[TestDox('Cross-tenant: pessoa de outro escritório dá 404 nas três portas e nada é gravado')]
    public function testPessoaDeOutroEscritorio404(): void
    {
        $c = $this->cenario();
        [, $outro] = $this->criarEscritorio();
        $deFora = $this->criarColaborador($outro, 'Pessoa de Fora');
        $this->em()->flush();
        $this->entrar($c['primario'], $c['tenant']);

        foreach (['', '/renovar', '/encerrar'] as $sufixo) {
            $this->enviar($sufixo, $deFora);
            self::assertResponseStatusCodeSame(404, $sufixo);
        }

        self::assertSame(0, $this->linhas($c['tenant']));
        self::assertSame(0, $this->linhas($outro));
        self::assertSame(0, $this->auditorias($c['tenant'], 'acomp_cria'));
    }

    #[TestDox('Cross-tenant: o acompanhamento aberto num outro escritório não aparece aqui nem impede criar aqui')]
    public function testAcompanhamentoDeOutroEscritorioNaoVaza(): void
    {
        $c = $this->cenario();
        [$primarioDeFora, $outro] = $this->criarEscritorio();
        $this->vincular($c['ana'], $outro, false, []);
        $this->em()->persist(new AcompanhamentoDaPessoa($outro, $c['ana'], $primarioDeFora, new \DateTimeImmutable(), 7));
        $this->em()->flush();
        $this->entrar($c['primario'], $c['tenant']);

        self::assertSame('Pendente', trim($this->perfil($c['ana'])->filter('[data-pf-status]')->text()));
        $this->enviar('/encerrar', $c['ana'], []);
        self::assertResponseStatusCodeSame(409);

        $this->enviar('', $c['ana']);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->linhas($outro, true), 'o registro do outro escritório fica intacto');
        self::assertSame(1, $this->linhas($c['tenant'], true));
    }

    #[TestDox('CSRF inválido dá 403; prazo fora de 1/2/7, ausente ou corpo inválido dá 400; nada é gravado')]
    public function testCsrfEPrazo(): void
    {
        $c = $this->cenario();
        $this->entrar($c['primario'], $c['tenant']);

        $this->enviar('', $c['ana'], ['prazo_dias' => 7], 'TOKEN_errado');
        self::assertResponseStatusCodeSame(403);
        $this->enviar('/encerrar', $c['ana'], [], 'TOKEN_errado');
        self::assertResponseStatusCodeSame(403);

        foreach ([['prazo_dias' => 3], ['prazo_dias' => '7'], [], ['prazo_dias' => null]] as $corpo) {
            $this->enviar('', $c['ana'], $corpo);
            self::assertResponseStatusCodeSame(400, json_encode($corpo));
        }

        self::assertSame(0, $this->linhas($c['tenant']));
        self::assertSame(0, $this->auditorias($c['tenant'], 'acomp_cria'));
    }

    #[TestDox('O gestor não se coloca em acompanhamento: 400 e nada gravado')]
    public function testNaoSeAcompanhaASiMesmo(): void
    {
        $c = $this->cenario();
        $this->entrar($c['primario'], $c['tenant']);

        $this->enviar('', $c['primario']);

        self::assertResponseStatusCodeSame(400);
        self::assertSame(0, $this->linhas($c['tenant']));
    }

    #[TestDox('As ações de auditoria do acompanhamento cabem nos 10 caracteres da coluna')]
    public function testAcoesCabemNaColuna(): void
    {
        foreach (['acomp_cria', 'acomp_renov', 'acomp_fim'] as $acao) {
            self::assertLessThanOrEqual(10, strlen($acao), $acao);
        }
    }
}
