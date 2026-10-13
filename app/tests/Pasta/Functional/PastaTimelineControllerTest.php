<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Notificacao;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Service\VisibilidadeDoFinanceiroDaPasta;
use App\Pasta\Controller\PastaTimelineController;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Pasta\Entity\PastaObservacaoFinanceira;
use App\Pasta\Entity\PastaPagamento;
use App\Pasta\Service\MontadorDaTimelineInteligente;
use App\Pasta\Service\RegrasDaTimelineInteligente;
use App\Repository\NotificacaoRepository;
use App\Tarefa\UseCase\AlertarResponsavelDaMetaUseCase;
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
#[CoversClass(NotificacaoRepository::class)]
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

    private function observacao(string $classe, Pasta $pasta, User $autor, Tenant $tenant, string $texto, string $quando): PastaObservacaoDetalhes|PastaObservacaoFinanceira
    {
        /** @var PastaObservacaoDetalhes|PastaObservacaoFinanceira $obs */
        $obs = new $classe();
        $obs->setPasta($pasta)->setAutor($autor)->setTenant($tenant)->setConteudo($texto);
        (new \ReflectionProperty($classe, 'criadaEm'))->setValue($obs, new \DateTimeImmutable($quando));

        $this->em()->persist($obs);
        $this->em()->flush();

        return $obs;
    }

    /** @param array<string, mixed> $dados @return array<string, mixed>|null */
    private function evento(array $dados, string $id): ?array
    {
        foreach ($dados['eventos'] as $e) {
            if ($e['id'] === $id) {
                return $e;
            }
        }

        return null;
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

    private function notificacao(
        User $usuario,
        Tenant $tenant,
        string $titulo,
        ?string $url = null,
        ?Tarefa $tarefa = null,
        string $tipo = Notificacao::TIPO_TAREFA_CRIADA,
        bool $lida = false,
        ?string $mensagem = null,
        string $quando = '2026-08-15 10:00',
    ): Notificacao {
        $n = (new Notificacao())
            ->setUsuario($usuario)
            ->setTenant($tenant)
            ->setTipo($tipo)
            ->setTitulo($titulo)
            ->setMensagem($mensagem)
            ->setTarefa($tarefa)
            ->setUrl($url)
            ->setLida($lida);
        (new \ReflectionProperty(Notificacao::class, 'criadaEm'))->setValue($n, new \DateTimeImmutable($quando));

        $this->em()->persist($n);
        $this->em()->flush();

        return $n;
    }

    /** Outra pessoa (super admin, como o `criarAdmin`), com nome PRÓPRIO e membro do escritório. */
    private function colega(Tenant $tenant, string $nome): User
    {
        [$colega] = $this->criarAdmin();
        $colega->setFullName($nome);
        $this->em()->persist(new UserTenant($colega, $tenant));
        $this->em()->flush();

        return $colega;
    }

    /** @param array<string, mixed> $dados @return list<string> */
    private function idsDeNotificacao(array $dados, string $chave = 'eventos'): array
    {
        return array_values(array_filter($this->ids($dados, $chave), static fn (string $id) => str_starts_with($id, 'n')));
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

    #[TestDox('O `desde` da URL não manda: sem visita guardada no servidor, nada é novo (PAS-043)')]
    public function testSemNovosDesdeAUltimaVisita(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->registrar($pasta, $user, $tenant, 'REGISTRO-QUALQUER', '2026-08-21 10:00');

        $this->logarComTenant($client, $user, $tenant);
        $dados = $this->timeline($client, $pasta, ['desde' => '2020-01-01T00:00:00.000Z']);

        self::assertSame(0, $dados['novos']);
        self::assertNull($dados['foraDesde']);
        self::assertNull($dados['resumoDesde']);
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

    #[TestDox('Observações de Detalhes e do Financeiro entram como "Registro em <aba>", com autor, texto puro, busca e pessoa; as de outra pasta, não')]
    public function testObservacoesDeDetalhesEFinanceiro(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $outra           = $this->criarPasta($tenant);

        $det = $this->observacao(PastaObservacaoDetalhes::class, $pasta, $user, $tenant, '<p>Relato <b>INICIAL-DETALHES</b></p>', '2026-08-10 09:30');
        $fin = $this->observacao(PastaObservacaoFinanceira::class, $pasta, $user, $tenant, 'ACORDO-HONORARIOS combinado', '2026-08-11 14:00');
        $detOutra = $this->observacao(PastaObservacaoDetalhes::class, $outra, $user, $tenant, 'DETALHE-DA-OUTRA', '2026-08-12 10:00');
        $finOutra = $this->observacao(PastaObservacaoFinanceira::class, $outra, $user, $tenant, 'FINANCEIRO-DA-OUTRA', '2026-08-12 11:00');

        $this->logarComTenant($client, $user, $tenant);
        $dados = $this->timeline($client, $pasta);
        $corpo = (string) $client->getResponse()->getContent();

        $eDet = $this->evento($dados, 'od' . $det->getId());
        self::assertNotNull($eDet, 'a observação de Detalhes entra na timeline');
        self::assertSame('Registro em Detalhes', $eDet['titulo']);
        self::assertSame('Detalhes', $eDet['fonte']);
        self::assertSame('registro', $eDet['categoria']);
        self::assertSame('Relato INICIAL-DETALHES', $eDet['texto']);
        self::assertSame($user->getFullName(), $eDet['autor']);
        self::assertSame('09:30', $eDet['hora']);

        $eFin = $this->evento($dados, 'of' . $fin->getId());
        self::assertNotNull($eFin, 'quem vê o financeiro recebe a observação financeira');
        self::assertSame('Registro em Financeiro', $eFin['titulo']);
        self::assertSame('Financeiro', $eFin['fonte']);

        self::assertStringNotContainsString('DETALHE-DA-OUTRA', $corpo);
        self::assertStringNotContainsString('FINANCEIRO-DA-OUTRA', $corpo);
        self::assertContains($user->getFullName(), $dados['pessoas']);
        self::assertGreaterThanOrEqual(2, $dados['contagens']['registro']);

        // Busca e pessoa valem para a fonte nova como para as outras.
        $busca = $this->timeline($client, $pasta, ['q' => 'honorarios']);
        self::assertSame(['of' . $fin->getId()], $this->ids($busca));
        $porPessoa = $this->timeline($client, $pasta, ['pessoa' => $user->getFullName(), 'categoria' => 'registro']);
        self::assertContains('od' . $det->getId(), $this->ids($porPessoa));

        // Recurso irmão: a outra pasta mostra as suas.
        $b = $this->timeline($client, $outra);
        self::assertContains('od' . $detOutra->getId(), $this->ids($b));
        self::assertContains('of' . $finOutra->getId(), $this->ids($b));
    }

    #[TestDox('Quem não vê o financeiro não recebe a observação financeira (nem o pagamento); a de Detalhes continua')]
    public function testObservacaoFinanceiraSoParaQuemVeOFinanceiro(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $det = $this->observacao(PastaObservacaoDetalhes::class, $pasta, $user, $tenant, 'DETALHE-VISIVEL', '2026-08-10 09:30');
        $fin = $this->observacao(PastaObservacaoFinanceira::class, $pasta, $user, $tenant, 'FINANCEIRO-ESCONDIDO', '2026-08-11 14:00');
        $pag = $this->pagamento($pasta, $tenant, 'PAGAMENTO-ESCONDIDO', '2026-09-01');

        // A regra de hoje é a do guarda de leitura da pasta (quem abre a timeline vê o
        // financeiro); o dublê diz "não" sem mexer nesse guarda — é o aperto futuro.
        $naoVe = $this->createStub(VisibilidadeDoFinanceiroDaPasta::class);
        $naoVe->method('podeVer')->willReturn(false);
        static::getContainer()->set(VisibilidadeDoFinanceiroDaPasta::class, $naoVe);

        $this->logarComTenant($client, $user, $tenant);
        $dados = $this->timeline($client, $pasta);
        $corpo = (string) $client->getResponse()->getContent();

        self::assertNotNull($this->evento($dados, 'od' . $det->getId()));
        self::assertNull($this->evento($dados, 'pl' . $pag->getId()), 'o dublê valeu: o pagamento também sumiu');
        self::assertNull($this->evento($dados, 'of' . $fin->getId()));
        self::assertStringNotContainsString('FINANCEIRO-ESCONDIDO', $corpo);
    }

    #[TestDox('PAS-048: observação financeira de AUTOR EXCLUSIVO não vaza para quem não vê o financeiro — nem em eventos, contagens, pessoas, atenção ou busca')]
    public function testObservacaoFinanceiraDeAutorExclusivoNaoVazaEmNenhumCaminho(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        // Autora que SÓ escreveu a observação financeira: se o nome dela aparecer em
        // qualquer lugar da resposta, foi por essa observação.
        $autora = new User();
        $autora->setEmail('autora_fin_' . uniqid() . '@test.com');
        $autora->setFullName('Autora Exclusiva Do Financeiro');
        $autora->setRoles(['ROLE_USER']);
        $autora->setIsActive(true);
        $autora->setPassword('dummy_hash');
        $this->em()->persist($autora);
        $this->em()->flush();

        $this->observacao(PastaObservacaoDetalhes::class, $pasta, $user, $tenant, 'DETALHE-COMUM', '2026-08-10 09:30');
        $fin = $this->observacao(PastaObservacaoFinanceira::class, $pasta, $autora, $tenant, 'SEGREDO-FINANCEIRO-XYZ', '2026-08-11 14:00');

        // Um dublê só, com a resposta trocável: primeiro "vê" (prova que o cenário produz o
        // evento em todos os caminhos), depois "não vê" (prova que ele some de todos).
        $veFinanceiro    = true;
        $duble = $this->createStub(VisibilidadeDoFinanceiroDaPasta::class);
        $duble->method('podeVer')->willReturnCallback(static function () use (&$veFinanceiro): bool {
            return $veFinanceiro;
        });
        static::getContainer()->set(VisibilidadeDoFinanceiroDaPasta::class, $duble);

        $this->logarComTenant($client, $user, $tenant);

        // Quem VÊ: a observação aparece na lista, na contagem, nas pessoas e na busca.
        $comFin = $this->timeline($client, $pasta);
        self::assertNotNull($this->evento($comFin, 'of' . $fin->getId()), 'controle: quem vê recebe');
        self::assertContains('Autora Exclusiva Do Financeiro', $comFin['pessoas']);
        $buscaComFin = $this->timeline($client, $pasta, ['q' => 'SEGREDO-FINANCEIRO-XYZ']);
        self::assertSame(['of' . $fin->getId()], $this->ids($buscaComFin));

        // Quem NÃO vê.
        $veFinanceiro    = false;
        $dados = $this->timeline($client, $pasta);
        $corpo = (string) $client->getResponse()->getContent();

        self::assertNull($this->evento($dados, 'of' . $fin->getId()), 'lista de eventos');
        self::assertSame($comFin['contagens']['registro'] - 1, $dados['contagens']['registro'], 'chip Registros');
        self::assertSame($comFin['contagens']['tudo'] - 1, $dados['contagens']['tudo'], 'chip Tudo');
        self::assertNotContains('Autora Exclusiva Do Financeiro', $dados['pessoas'], 'lista de pessoas');
        self::assertNotContains('of' . $fin->getId(), $this->ids($dados, 'atencao'), '"Precisa de atenção"');
        self::assertStringNotContainsString('SEGREDO-FINANCEIRO-XYZ', $corpo);
        self::assertStringNotContainsString('Autora Exclusiva Do Financeiro', $corpo);

        $busca      = $this->timeline($client, $pasta, ['q' => 'SEGREDO-FINANCEIRO-XYZ']);
        $corpoBusca = (string) $client->getResponse()->getContent();
        self::assertSame([], $busca['eventos'], 'busca pelo texto dela');
        self::assertSame(0, $busca['totalFiltrado']);
        self::assertStringNotContainsString('SEGREDO-FINANCEIRO-XYZ', $corpoBusca);
        self::assertStringNotContainsString('Autora Exclusiva Do Financeiro', $corpoBusca);

        $porPessoa = $this->timeline($client, $pasta, ['pessoa' => 'Autora Exclusiva Do Financeiro']);
        self::assertSame([], $porPessoa['eventos'], 'filtro pela pessoa dela');
    }

    #[TestDox('Observação gravada com OUTRO escritório não entra, mesmo pendurada nesta pasta')]
    public function testObservacaoDeOutroEscritorioNaoEntra(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        [, $outro]       = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $minha  = $this->observacao(PastaObservacaoDetalhes::class, $pasta, $user, $tenant, 'DETALHE-MEU', '2026-08-10 09:30');
        $alheia = $this->observacao(PastaObservacaoDetalhes::class, $pasta, $user, $outro, 'DETALHE-DO-OUTRO-ESCRITORIO', '2026-08-10 10:30');
        $alheiaFin = $this->observacao(PastaObservacaoFinanceira::class, $pasta, $user, $outro, 'FINANCEIRO-DO-OUTRO-ESCRITORIO', '2026-08-10 11:30');

        $this->logarComTenant($client, $user, $tenant);
        $dados = $this->timeline($client, $pasta);
        $corpo = (string) $client->getResponse()->getContent();

        self::assertNotNull($this->evento($dados, 'od' . $minha->getId()));
        self::assertNull($this->evento($dados, 'od' . $alheia->getId()));
        self::assertNull($this->evento($dados, 'of' . $alheiaFin->getId()));
        self::assertStringNotContainsString('OUTRO-ESCRITORIO', $corpo);
    }

    // ── PAS-047i: notificações do usuário logado ligadas à pasta ─────────────────

    #[TestDox('PAS-047i: as notificações DO USUÁRIO ligadas à pasta (pela meta ou pelo link) entram, pendentes enquanto não lidas; as de outra pasta, de prefixo parecido ou sem pasta, não')]
    public function testNotificacoesDoUsuarioLigadasAPasta(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $outra           = $this->criarPasta($tenant);
        $meta            = $this->meta($pasta, $tenant, 'Juntar procuração', null);
        $metaOutra       = $this->meta($outra, $tenant, 'Meta da outra', null);
        $caminho         = '/pasta/' . $pasta->getId();

        $pelaMeta  = $this->notificacao($user, $tenant, 'Nova meta AVISO-PELA-META', tarefa: $meta, mensagem: 'Juntar procuração', quando: '2026-08-15 10:00');
        $peloLink  = $this->notificacao($user, $tenant, 'Ana mencionou você AVISO-PELO-LINK', url: $caminho . '#pasta-msg-9', tipo: Notificacao::TIPO_PASTA_MENCAO_REGISTRO, lida: true, quando: '2026-08-15 11:00');
        $pelaQuery = $this->notificacao($user, $tenant, 'AVISO-PELA-QUERY', url: $caminho . '?meta=1#tarefas', quando: '2026-08-15 12:00');
        $exato     = $this->notificacao($user, $tenant, 'AVISO-LINK-EXATO', url: $caminho, mensagem: 'AVISO-LINK-EXATO', quando: '2026-08-15 13:00');
        $alerta    = $this->notificacao($user, $tenant, 'Alerta AVISO-ALERTA', tarefa: $meta, tipo: AlertarResponsavelDaMetaUseCase::TIPO_NOTIFICACAO, quando: '2026-08-15 14:00');

        $daOutraMeta = $this->notificacao($user, $tenant, 'AVISO-DA-OUTRA-PASTA-META', tarefa: $metaOutra);
        $daOutraLink = $this->notificacao($user, $tenant, 'AVISO-DA-OUTRA-PASTA-LINK', url: '/pasta/' . $outra->getId() . '#pasta-msg-1');
        // `/pasta/<id>0` começa com o caminho desta pasta, mas é OUTRA pasta.
        $prefixo  = $this->notificacao($user, $tenant, 'AVISO-DE-PREFIXO', url: $caminho . '0#pasta-msg-1');
        $semPasta = $this->notificacao($user, $tenant, 'AVISO-SEM-PASTA');

        $minhas = [
            'n' . $pelaMeta->getId(), 'n' . $peloLink->getId(), 'n' . $pelaQuery->getId(),
            'n' . $exato->getId(), 'n' . $alerta->getId(),
        ];

        $this->logarComTenant($client, $user, $tenant);
        $dados = $this->timeline($client, $pasta);
        $corpo = (string) $client->getResponse()->getContent();

        self::assertEqualsCanonicalizing($minhas, $this->idsDeNotificacao($dados), 'lista de eventos');

        $e = $this->evento($dados, 'n' . $pelaMeta->getId());
        self::assertNotNull($e, 'pela meta da pasta');
        self::assertSame('notificacao', $e['tipo']);
        self::assertSame('notificacao', $e['categoria']);
        self::assertSame('Nova meta AVISO-PELA-META', $e['titulo']);
        self::assertSame('Juntar procuração', $e['texto']);
        self::assertTrue($e['pendente'], 'não lida = pendente');
        self::assertSame('info', $e['prioridade']);
        self::assertNull($e['autor'], 'a notificação não guarda quem a causou');
        self::assertNull($e['fonte']);
        self::assertNull($e['link']);
        self::assertSame('10:00', $e['hora']);

        $lida = $this->evento($dados, 'n' . $peloLink->getId());
        self::assertNotNull($lida, 'pelo link da pasta_show, com âncora');
        self::assertFalse($lida['pendente'], 'lida não é pendente');
        self::assertNotNull($this->evento($dados, 'n' . $pelaQuery->getId()), 'pelo link com query');
        self::assertNull($this->evento($dados, 'n' . $exato->getId())['texto'] ?? null, 'texto igual ao título não sai duas vezes');
        self::assertSame('importante', $this->evento($dados, 'n' . $alerta->getId())['prioridade'] ?? null, 'alerta de meta');

        // Chips: contagem própria, rótulo e ícone do desenho, e o filtro devolve só elas.
        self::assertSame(5, $dados['contagens']['notificacao']);
        self::assertSame('Notificações', $dados['categorias']['notificacao']['rotulo']);
        self::assertSame('bi-bell', $dados['categorias']['notificacao']['icone']);
        $chip = $this->timeline($client, $pasta, ['categoria' => 'notificacao']);
        self::assertEqualsCanonicalizing($minhas, $this->ids($chip));

        // "Precisa de atenção" e chip Pendências: as não lidas, não a lida.
        $atencao = $this->ids($dados, 'atencao');
        self::assertContains('n' . $pelaMeta->getId(), $atencao);
        self::assertContains('n' . $alerta->getId(), $atencao);
        self::assertNotContains('n' . $peloLink->getId(), $atencao);
        $pendentes = $this->timeline($client, $pasta, ['categoria' => 'pendente']);
        self::assertContains('n' . $pelaMeta->getId(), $this->ids($pendentes));
        self::assertNotContains('n' . $peloLink->getId(), $this->ids($pendentes));

        // A busca alcança o título.
        $busca = $this->timeline($client, $pasta, ['q' => 'AVISO-PELO-LINK']);
        self::assertSame(['n' . $peloLink->getId()], $this->ids($busca));

        foreach (['AVISO-DA-OUTRA-PASTA', 'AVISO-DE-PREFIXO', 'AVISO-SEM-PASTA'] as $fora) {
            self::assertStringNotContainsString($fora, $corpo);
        }
        self::assertNull($this->evento($dados, 'n' . $semPasta->getId()));

        // Recurso irmão: as da outra pasta aparecem na outra pasta (e as desta, não).
        $b = $this->timeline($client, $outra);
        self::assertEqualsCanonicalizing(
            ['n' . $daOutraMeta->getId(), 'n' . $daOutraLink->getId()],
            $this->idsDeNotificacao($b),
        );
        self::assertNotContains('n' . $prefixo->getId(), $this->ids($b));
    }

    #[TestDox('PAS-047i: notificação de OUTRA PESSOA ou gravada em OUTRO ESCRITÓRIO não vaza — nem em eventos, chips, pessoas, atenção ou busca')]
    public function testNotificacaoDeOutraPessoaOuOutroEscritorioNaoVazaEmNenhumCaminho(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        $colega          = $this->colega($tenant, 'Colega Que Recebeu Aviso');
        [, $outro]       = $this->criarAdmin();
        $this->em()->persist(new UserTenant($user, $outro));
        $this->em()->flush();

        $pasta        = $this->criarPasta($tenant);
        $pastaNoOutro = $this->criarPasta($outro);
        $meta         = $this->meta($pasta, $tenant, 'Meta da pasta', null);
        $caminho      = '/pasta/' . $pasta->getId();

        $minha        = $this->notificacao($user, $tenant, 'AVISO-MEU', url: $caminho . '#pasta-msg-1');
        $doColega     = $this->notificacao($colega, $tenant, 'SEGREDO-DO-COLEGA-XYZ', url: $caminho . '#pasta-msg-2', tipo: AlertarResponsavelDaMetaUseCase::TIPO_NOTIFICACAO);
        $doColegaMeta = $this->notificacao($colega, $tenant, 'SEGREDO-DO-COLEGA-META', tarefa: $meta);
        // Mesma pessoa, OUTRO escritório, link para esta pasta: só o escritório difere da `$minha`.
        $deOutroEscr = $this->notificacao($user, $outro, 'SEGREDO-DE-OUTRO-ESCRITORIO-XYZ', url: $caminho . '#pasta-msg-3', tipo: AlertarResponsavelDaMetaUseCase::TIPO_NOTIFICACAO);
        $noOutroEscr = $this->notificacao($user, $outro, 'AVISO-NO-OUTRO-ESCRITORIO', url: '/pasta/' . $pastaNoOutro->getId() . '#pasta-msg-4');

        $this->logarComTenant($client, $user, $tenant);
        $dados = $this->timeline($client, $pasta);
        $corpo = (string) $client->getResponse()->getContent();

        self::assertSame(['n' . $minha->getId()], $this->idsDeNotificacao($dados), 'lista de eventos');
        self::assertSame(1, $dados['contagens']['notificacao'], 'chip Notificações');
        self::assertSame(1, $dados['contagens']['pendente'], 'chip Pendências: só a minha');
        self::assertSame(['n' . $minha->getId()], $this->idsDeNotificacao($dados, 'atencao'), '"Precisa de atenção"');
        self::assertNotContains('Colega Que Recebeu Aviso', $dados['pessoas'], 'lista de pessoas');
        self::assertStringNotContainsString('SEGREDO', $corpo);
        self::assertStringNotContainsString('Colega Que Recebeu Aviso', $corpo);

        $chip = $this->timeline($client, $pasta, ['categoria' => 'notificacao']);
        self::assertSame(['n' . $minha->getId()], $this->ids($chip), 'filtro do chip');
        $pend = $this->timeline($client, $pasta, ['categoria' => 'pendente']);
        self::assertSame(['n' . $minha->getId()], $this->idsDeNotificacao($pend), 'filtro Pendências');

        foreach (['SEGREDO-DO-COLEGA-XYZ', 'SEGREDO-DO-COLEGA-META', 'SEGREDO-DE-OUTRO-ESCRITORIO-XYZ'] as $termo) {
            $busca = $this->timeline($client, $pasta, ['q' => $termo]);
            self::assertSame([], $busca['eventos'], 'busca por ' . $termo);
            self::assertSame(0, $busca['totalFiltrado']);
            self::assertStringNotContainsString('SEGREDO', (string) $client->getResponse()->getContent());
        }

        // Recurso irmão 1: o colega, na MESMA pasta, recebe as dele (e não a minha).
        $this->logarComTenant($client, $colega, $tenant);
        $doColegaDados = $this->timeline($client, $pasta);
        self::assertEqualsCanonicalizing(
            ['n' . $doColega->getId(), 'n' . $doColegaMeta->getId()],
            $this->idsDeNotificacao($doColegaDados),
        );

        // Recurso irmão 2: no outro escritório, a minha de lá aparece na pasta de lá.
        $this->logarComTenant($client, $user, $outro);
        $noOutro = $this->timeline($client, $pastaNoOutro);
        self::assertSame(['n' . $noOutroEscr->getId()], $this->idsDeNotificacao($noOutro));
        self::assertNotContains('n' . $deOutroEscr->getId(), $this->ids($noOutro));
    }

    #[TestDox('PAS-047i: notificação que aponta para a aba Financeiro não vaza para quem não vê o financeiro — nem em eventos, chips, atenção ou busca')]
    public function testNotificacaoDoFinanceiroNaoVazaEmNenhumCaminho(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $caminho         = '/pasta/' . $pasta->getId();

        $comum  = $this->notificacao($user, $tenant, 'AVISO-COMUM', url: $caminho . '#pasta-msg-1');
        $fin    = $this->notificacao($user, $tenant, 'AVISO-FINANCEIRO-XYZ', url: $caminho . '#financeiro-obs-7');
        $finQry = $this->notificacao($user, $tenant, 'AVISO-FINANCEIRO-QRY', url: $caminho . '?x=1#financeiro');

        // Um dublê só, com a resposta trocável (o mesmo padrão do PAS-048).
        $veFinanceiro = true;
        $duble = $this->createStub(VisibilidadeDoFinanceiroDaPasta::class);
        $duble->method('podeVer')->willReturnCallback(static function () use (&$veFinanceiro): bool {
            return $veFinanceiro;
        });
        static::getContainer()->set(VisibilidadeDoFinanceiroDaPasta::class, $duble);

        $this->logarComTenant($client, $user, $tenant);

        // Controle: quem VÊ recebe as três em todos os caminhos.
        $com = $this->timeline($client, $pasta);
        self::assertEqualsCanonicalizing(
            ['n' . $comum->getId(), 'n' . $fin->getId(), 'n' . $finQry->getId()],
            $this->idsDeNotificacao($com),
        );
        self::assertSame(3, $com['contagens']['notificacao']);
        self::assertContains('n' . $fin->getId(), $this->ids($com, 'atencao'));
        self::assertSame(['n' . $fin->getId()], $this->ids($this->timeline($client, $pasta, ['q' => 'AVISO-FINANCEIRO-XYZ'])));

        // Quem NÃO vê.
        $veFinanceiro = false;
        $dados = $this->timeline($client, $pasta);
        $corpo = (string) $client->getResponse()->getContent();

        self::assertSame(['n' . $comum->getId()], $this->idsDeNotificacao($dados), 'lista de eventos');
        self::assertSame(1, $dados['contagens']['notificacao'], 'chip Notificações');
        self::assertSame($com['contagens']['tudo'] - 2, $dados['contagens']['tudo'], 'chip Tudo');
        self::assertSame($com['contagens']['pendente'] - 2, $dados['contagens']['pendente'], 'chip Pendências');
        self::assertSame(['n' . $comum->getId()], $this->idsDeNotificacao($dados, 'atencao'), '"Precisa de atenção"');
        self::assertStringNotContainsString('AVISO-FINANCEIRO', $corpo);

        $chip = $this->timeline($client, $pasta, ['categoria' => 'notificacao']);
        self::assertSame(['n' . $comum->getId()], $this->ids($chip), 'filtro do chip');

        $busca = $this->timeline($client, $pasta, ['q' => 'AVISO-FINANCEIRO-XYZ']);
        self::assertSame([], $busca['eventos'], 'busca pelo título');
        self::assertSame(0, $busca['totalFiltrado']);
        self::assertStringNotContainsString('AVISO-FINANCEIRO', (string) $client->getResponse()->getContent());
    }

    #[TestDox('PAS-047i: as notificações respeitam o mesmo teto das outras fontes e avisam que cortaram')]
    public function testNotificacoesRespeitamOLimite(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $caminho         = '/pasta/' . $pasta->getId();
        for ($i = 1; $i <= 3; ++$i) {
            // No futuro de propósito: são, sem empate, os mais novos da pasta.
            $this->notificacao($user, $tenant, 'AVISO ' . $i, url: $caminho . '#pasta-msg-' . $i, quando: sprintf('2099-09-0%d 10:00', $i));
        }

        $this->logarComTenant($client, $user, $tenant);
        $cortada = $this->timeline($client, $pasta, ['limite' => 2]);

        self::assertCount(2, $cortada['eventos']);
        self::assertTrue($cortada['truncado']);
        self::assertSame(['AVISO 3', 'AVISO 2'], array_column($cortada['eventos'], 'titulo'));
    }
}
