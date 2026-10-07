<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Controller\PastaTimelineController;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Entity\PastaPagamento;
use App\Pasta\Service\MontadorDaTimelineInteligente;
use App\Pasta\Service\RegrasDaTimelineInteligente;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Timeline inteligente da pasta (`pasta_timeline`, L18): isolamento, ordem, limite, filtros que
 * esvaziam, marcos e "Precisa de atenção" a partir das fontes reais.
 *
 * Isolamento provado com o RECURSO IRMÃO: o que não aparece na pasta A aparece na pasta B pelo
 * mesmo endpoint — sem o par, um vazio por qualquer outro motivo passaria por isolamento.
 */
#[CoversClass(PastaTimelineController::class)]
#[CoversClass(MontadorDaTimelineInteligente::class)]
#[Group('pasta')]
final class PastaTimelineControllerTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private const NUMERO_A = '07011345720258070007';
    private const NUMERO_B = '07099999999999999999';

    private function registrar(Pasta $pasta, User $autor, Tenant $tenant, string $texto, string $quando): PastaMensagem
    {
        $msg = (new PastaMensagem())
            ->setPasta($pasta)
            ->setAutor($autor)
            ->setTenant($tenant)
            ->setConteudo($texto);
        (new \ReflectionProperty(PastaMensagem::class, 'criadaEm'))->setValue($msg, new \DateTimeImmutable($quando));

        $this->em()->persist($msg);
        $this->em()->flush();

        return $msg;
    }

    private function pagamento(Pasta $pasta, Tenant $tenant, string $descricao, string $vencimento, ?string $pagoEm = null): PastaPagamento
    {
        $pag = (new PastaPagamento())
            ->setPasta($pasta)
            ->setTenant($tenant)
            ->setDescricao($descricao)
            ->setValor('150.00')
            ->setVencimento(new \DateTimeImmutable($vencimento));
        if ($pagoEm !== null) {
            $pag->alternarQuitacao(new \DateTimeImmutable($pagoEm));
        }
        $this->em()->persist($pag);
        $this->em()->flush();

        return $pag;
    }

    private function meta(Pasta $pasta, Tenant $tenant, string $titulo, ?string $prazo): Tarefa
    {
        $tarefa = new Tarefa();
        $tarefa->setTitulo($titulo);
        $tarefa->setDescricao('');
        $tarefa->setPasta($pasta);
        $tarefa->setTenant($tenant);
        $tarefa->setPrazo($prazo !== null ? new \DateTimeImmutable($prazo) : null);
        $this->em()->persist($tarefa);
        $this->em()->flush();

        return $tarefa;
    }

    /** @return array<string, mixed> */
    private function timeline(KernelBrowser $client, Pasta $pasta, array $query = []): array
    {
        // Identidade nova: a pasta e as coleções dela vêm do BANCO, não do que o teste deixou em memória.
        $this->em()->clear();
        $client->request('GET', '/pasta/' . $pasta->getId() . '/timeline' . ($query !== [] ? '?' . http_build_query($query) : ''));
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        return json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $dados @return list<string> */
    private function ids(array $dados, string $chave = 'eventos'): array
    {
        return array_map(static fn (array $e) => (string) $e['id'], $dados[$chave]);
    }

    #[TestDox('Pasta A nunca mostra registro, publicação nem pagamento da pasta B (e B mostra os seus)')]
    public function testIsolamentoEntrePastasDoMesmoEscritorio(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();

        $pastaA = $this->criarPasta($tenant);
        $procA  = $this->criarProcesso($tenant, self::NUMERO_A);
        $this->vincular($pastaA, $procA);
        $pubA = $this->criarPublicacao($tenant, '51000001', self::NUMERO_A, '2026-08-20', $procA);
        $this->registrar($pastaA, $user, $tenant, 'REGISTRO-DA-PASTA-A', '2026-08-21 10:00');

        $pastaB = $this->criarPasta($tenant);
        $procB  = $this->criarProcesso($tenant, self::NUMERO_B);
        $this->vincular($pastaB, $procB);
        $pubB = $this->criarPublicacao($tenant, '51000002', self::NUMERO_B, '2026-08-22', $procB);
        $this->registrar($pastaB, $user, $tenant, 'REGISTRO-DA-PASTA-B', '2026-08-23 10:00');
        $pagB = $this->pagamento($pastaB, $tenant, 'PAGAMENTO-DA-PASTA-B', '2026-09-01');

        $this->logarComTenant($client, $user, $tenant);

        $a     = $this->timeline($client, $pastaA);
        $corpo = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('REGISTRO-DA-PASTA-A', $corpo);
        self::assertContains('p' . $pubA->getId(), $this->ids($a));
        self::assertStringNotContainsString('REGISTRO-DA-PASTA-B', $corpo);
        self::assertStringNotContainsString('PAGAMENTO-DA-PASTA-B', $corpo);
        self::assertNotContains('p' . $pubB->getId(), $this->ids($a));
        self::assertNotContains('pl' . $pagB->getId(), $this->ids($a));

        // Recurso irmão: o mesmo endpoint, pela pasta certa, mostra o que faltou acima.
        $b = $this->timeline($client, $pastaB);
        self::assertContains('p' . $pubB->getId(), $this->ids($b));
        self::assertContains('pl' . $pagB->getId(), $this->ids($b));
        self::assertStringContainsString('REGISTRO-DA-PASTA-B', (string) $client->getResponse()->getContent());
    }

    #[TestDox('Vários processos vinculados e autor do pagamento: tudo carregado em lote, nada se perde')]
    public function testProcessosVinculadosEAutorDoPagamentoEmLote(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();

        $pasta = $this->criarPasta($tenant);
        $procA = $this->criarProcesso($tenant, self::NUMERO_A);
        $procB = $this->criarProcesso($tenant, self::NUMERO_B);
        $this->vincular($pasta, $procA);
        $this->vincular($pasta, $procB);
        $pubA = $this->criarPublicacao($tenant, '51000011', self::NUMERO_A, '2026-08-20', $procA);
        $pubB = $this->criarPublicacao($tenant, '51000012', self::NUMERO_B, '2026-08-22', $procB);

        $pag = $this->pagamento($pasta, $tenant, 'PAGAMENTO-COM-AUTOR', '2026-09-01');
        $pag->setAutor($user);
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $dados = $this->timeline($client, $pasta);

        self::assertContains('p' . $pubA->getId(), $this->ids($dados));
        self::assertContains('p' . $pubB->getId(), $this->ids($dados));

        $lancado = array_values(array_filter($dados['eventos'], static fn (array $e) => $e['id'] === 'pl' . $pag->getId()));
        self::assertCount(1, $lancado);
        self::assertSame($user->getFullName(), $lancado[0]['autor']);
    }

    #[TestDox('Sem "Enquanto você estava fora": o servidor ignora `desde` e não devolve `novos`')]
    public function testSemNovosDesdeAUltimaVisita(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->registrar($pasta, $user, $tenant, 'REGISTRO-QUALQUER', '2026-08-21 10:00');

        $this->logarComTenant($client, $user, $tenant);
        $dados = $this->timeline($client, $pasta, ['desde' => '2020-01-01T00:00:00.000Z']);

        self::assertArrayNotHasKey('novos', $dados);
        self::assertArrayNotHasKey('resumoDesde', $dados);
    }

    #[TestDox('Pasta de outro escritório: 404 (a própria responde 200)')]
    public function testOutroEscritorioDa404(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        [, $outro]       = $this->criarAdmin();
        $minha           = $this->criarPasta($tenant);
        $alheia          = $this->criarPasta($outro);
        $this->registrar($alheia, $user, $outro, 'SEGREDO-DO-OUTRO-ESCRITORIO', '2026-08-21 10:00');

        $this->logarComTenant($client, $user, $tenant);

        $this->timeline($client, $minha);

        $this->em()->clear();
        $client->request('GET', '/pasta/' . $alheia->getId() . '/timeline');
        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString('SEGREDO-DO-OUTRO-ESCRITORIO', (string) $client->getResponse()->getContent());
    }

    #[TestDox('Quem não pode ver a pasta recebe 403')]
    public function testSemPermissaoDa403(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [, $tenant]      = $this->criarAdmin();
        $semNada         = $this->criarUsuarioSemNenhumaPermissao($tenant);
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $semNada, $tenant);
        $this->em()->clear();
        $client->request('GET', '/pasta/' . $pasta->getId() . '/timeline');

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('Ordem do mais novo para o mais antigo; marcos de criação e da primeira publicação')]
    public function testOrdemEMarcos(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $proc            = $this->criarProcesso($tenant, self::NUMERO_A);
        $this->vincular($pasta, $proc);
        $pubVelha = $this->criarPublicacao($tenant, '52000001', self::NUMERO_A, '2026-06-01', $proc);
        $this->criarPublicacao($tenant, '52000002', self::NUMERO_A, '2026-07-01', $proc);
        $this->registrar($pasta, $user, $tenant, 'PRIMEIRO', '2026-06-15 09:00');
        $this->registrar($pasta, $user, $tenant, 'SEGUNDO', '2026-07-15 09:00');

        $this->logarComTenant($client, $user, $tenant);
        $dados = $this->timeline($client, $pasta);

        $datas = array_map(static fn (array $e) => new \DateTimeImmutable($e['quando']), $dados['eventos']);
        for ($i = 1, $n = count($datas); $i < $n; ++$i) {
            self::assertGreaterThanOrEqual($datas[$i], $datas[$i - 1], 'A lista precisa vir do mais novo para o mais antigo.');
        }

        $marcos = [];
        foreach ($dados['eventos'] as $e) {
            if ($e['marco'] !== null) {
                $marcos[$e['id']] = $e['marco'];
            }
        }
        self::assertSame(RegrasDaTimelineInteligente::MARCO_PRIMEIRA_PUB, $marcos['p' . $pubVelha->getId()] ?? null);
        self::assertContains(RegrasDaTimelineInteligente::MARCO_CRIACAO, $marcos);
        self::assertCount(1, array_filter($dados['eventos'], static fn (array $e) => $e['tipo'] === 'pasta_criada'));

        // Publicação só tem data: a tela não inventa a hora.
        $pub = array_values(array_filter($dados['eventos'], static fn (array $e) => $e['id'] === 'p' . $pubVelha->getId()))[0];
        self::assertNull($pub['hora']);
        self::assertSame('2026-06-01', $pub['dia']);
    }

    #[TestDox('Limite: devolve os N mais novos do conjunto inteiro e avisa que cortou')]
    public function testLimite(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        for ($i = 1; $i <= 5; ++$i) {
            // No futuro de propósito: os cinco são, sem empate, os mais novos da pasta.
            $this->registrar($pasta, $user, $tenant, 'REGISTRO ' . $i, sprintf('2099-09-0%d 10:00', $i));
        }

        $this->logarComTenant($client, $user, $tenant);
        $inteira = $this->timeline($client, $pasta);
        self::assertFalse($inteira['truncado']);

        $cortada = $this->timeline($client, $pasta, ['limite' => 2]);
        self::assertCount(2, $cortada['eventos']);
        self::assertTrue($cortada['truncado']);
        self::assertSame(2, $cortada['limite']);
        self::assertSame(array_slice($this->ids($inteira), 0, 2), $this->ids($cortada));
    }

    #[TestDox('Filtro válido que não casa com nada devolve lista VAZIA (e os chips seguem contando tudo)')]
    public function testFiltroQueRemoveTudo(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->registrar($pasta, $user, $tenant, 'ALGUM REGISTRO', '2026-09-01 10:00');

        $this->logarComTenant($client, $user, $tenant);

        $semFinanceiro = $this->timeline($client, $pasta, ['categoria' => 'financeiro']);
        self::assertSame([], $semFinanceiro['eventos']);
        self::assertSame(0, $semFinanceiro['totalFiltrado']);
        self::assertFalse($semFinanceiro['truncado']);
        self::assertGreaterThan(0, $semFinanceiro['contagens']['tudo']);

        $busca = $this->timeline($client, $pasta, ['q' => 'xyzwqk']);
        self::assertSame([], $busca['eventos']);

        // Categoria inventada não esvazia: vira "tudo".
        $inventada = $this->timeline($client, $pasta, ['categoria' => 'ia']);
        self::assertNotSame([], $inventada['eventos']);
    }

    #[TestDox('XSS: o registro chega como TEXTO, sem marcação')]
    public function testRegistroChegaSemHtml(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $msg             = $this->registrar($pasta, $user, $tenant, '<p>Oi <b>NEGRITO</b></p><script>alert(1)</script><img src=x onerror=alert(2)>', '2026-09-01 10:00');

        $this->logarComTenant($client, $user, $tenant);
        $dados = $this->timeline($client, $pasta);

        $evento = array_values(array_filter($dados['eventos'], static fn (array $e) => $e['id'] === 'm' . $msg->getId()))[0];
        self::assertStringContainsString('NEGRITO', (string) $evento['texto']);
        self::assertStringNotContainsString('<', (string) $evento['texto']);
        self::assertSame('Registro em Dados da pasta', $evento['titulo']);
    }

    #[TestDox('"Precisa de atenção": pagamento vencido (pendente) e meta vencida há mais de 30 dias (crítico)')]
    public function testPrecisaDeAtencao(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $vencido         = $this->pagamento($pasta, $tenant, 'HONORARIOS', '-10 days');
        $meta            = $this->meta($pasta, $tenant, 'Juntar procuração', '-40 days');
        $emDia           = $this->meta($pasta, $tenant, 'Recurso', '+5 days');

        $this->logarComTenant($client, $user, $tenant);
        $dados = $this->timeline($client, $pasta);

        $atencao = $this->ids($dados, 'atencao');
        self::assertContains('pv' . $vencido->getId(), $atencao);
        self::assertContains('mp' . $meta->getId(), $atencao);
        self::assertNotContains('mp' . $emDia->getId(), $atencao);

        $porId = array_column($dados['eventos'], null, 'id');
        self::assertTrue($porId['pv' . $vencido->getId()]['pendente']);
        self::assertSame('critico', $porId['mp' . $meta->getId()]['prioridade']);
        self::assertSame('info', $porId['mp' . $emDia->getId()]['prioridade']);
        self::assertSame(1, $dados['contagens']['pendente']);
    }

    #[TestDox('Financeiro quitado: a última quitação vira marco só quando todos os pagamentos estão pagos')]
    public function testMarcoDoFinanceiroQuitado(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $primeiro        = $this->pagamento($pasta, $tenant, 'PARCELA 1', '2026-08-01', '2026-08-02');
        $ultimo          = $this->pagamento($pasta, $tenant, 'PARCELA 2', '2026-09-01', '2026-09-03');

        $this->logarComTenant($client, $user, $tenant);
        $dados = $this->timeline($client, $pasta, ['categoria' => 'marco']);

        $porId = array_column($dados['eventos'], null, 'id');
        self::assertSame(RegrasDaTimelineInteligente::MARCO_FINANCEIRO, $porId['pq' . $ultimo->getId()]['marco'] ?? null);
        self::assertArrayNotHasKey('pq' . $primeiro->getId(), $porId);
    }
}
