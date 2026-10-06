<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Functional;

use App\Dashboard\Controller\DashboardController;
use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Tests\Factory\Pasta\PastaFactory;
use App\Tests\Factory\Tarefa\TarefaFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * Painel BlueJus Intelligence na TELA do Dashboard: onde mora (filho direto da região
 * que recarrega), como se apresenta (nome do produto + legenda honesta, sem se vender
 * como inteligência artificial), o Modo avançado expansível sem persistência, a leitura
 * com dados reais (e no XHR) e o isolamento entre escritórios.
 *
 * Arranjo sempre por filho direto (`A > B`): distingue "o painel está no fragmento"
 * de "existe um painel em algum lugar da página".
 */
#[CoversClass(DashboardController::class)]
#[Group('dashboard')]
final class DashboardInteligenciaTelaTest extends DashboardWebTestCase
{
    use Factories;

    private const PERIODO = 'data_de=2024-02-01&data_ate=2024-02-29';

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** Pasta só para hospedar metas, aberta fora de qualquer período testado. */
    private function pastaHospedeira(Tenant $tenant): Pasta
    {
        return PastaFactory::createOne([
            'tenant'       => $tenant,
            'dataAbertura' => new \DateTimeImmutable('2023-06-01 10:00'),
        ])->_real();
    }

    /** Meta pendente com `dataCriacao` forçada (a entidade não tem setter: nasce "agora"). */
    private function criarMeta(Pasta $pasta, User $responsavel, string $criadaEm, ?\DateTimeImmutable $prazo = null): void
    {
        $tarefa = TarefaFactory::createOne([
            'pasta'  => $pasta,
            'status' => Tarefa::STATUS_PENDENTE,
            'prazo'  => $prazo,
        ])->_real();
        $tarefa->addResponsavel($responsavel);
        (new \ReflectionProperty(Tarefa::class, 'dataCriacao'))->setValue($tarefa, new \DateTimeImmutable($criadaEm));
        $this->em()->flush();
    }

    private function painel(Crawler $crawler): Crawler
    {
        $painel = $crawler->filter('.db-page [data-filtro-root] > [data-filtro-resultado] > .db-ia > aside.db-ia-painel');
        self::assertCount(1, $painel, 'O painel é filho direto do bloco .db-ia, que é filho direto da região que recarrega');

        return $painel;
    }

    private function textoLimpo(Crawler $no): string
    {
        return (string) preg_replace('/\s+/u', ' ', trim($no->text()));
    }

    #[TestDox('Botão e painel moram no fragmento trocável; o painel é um popover aberto pelo botão (sem JS)')]
    public function testBotaoEPainelNoFragmento(): void
    {
        $client = static::createClient();
        $this->criarGestorLogado($client);

        $crawler = $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.db-page [data-filtro-root] > [data-filtro-resultado] > .db-ia'));

        $botao = $crawler->filter('[data-filtro-resultado] > .db-ia > button.db-ia-abrir');
        self::assertCount(1, $botao);
        self::assertSame('dbIaPainel', $botao->attr('popovertarget'), 'abre o painel pelo Popover API nativo');
        self::assertSame('button', $botao->attr('type'));
        self::assertStringContainsString('Intelligence', $botao->text());
        // sem alerta: balão neutro, sem ponto, com a leitura do período
        $dica = $crawler->filter('[data-filtro-resultado] > .db-ia > button.db-ia-abrir + span.db-ia-dica[role="tooltip"]');
        self::assertCount(1, $dica);
        self::assertStringNotContainsString('is-alerta', (string) $dica->attr('class'));
        self::assertCount(0, $dica->filter('.db-ia-dica-ponto'));
        self::assertSame('Leitura do período', trim($dica->text()));

        $painel = $this->painel($crawler);
        self::assertSame('dbIaPainel', $painel->attr('id'));
        self::assertNotNull($painel->attr('popover'));
        self::assertSame('dialog', $painel->attr('role'));
        self::assertCount(1, $painel->filter('.db-ia-cab > button.db-ia-fechar[popovertarget="dbIaPainel"][popovertargetaction="hide"]'));
        self::assertCount(0, $painel->filter('script'), 'nada de script no painel: sem localStorage, sem fetch');
    }

    #[TestDox('O painel se apresenta como BlueJus Intelligence com a legenda honesta: leitura por regras fixas')]
    public function testLegendaHonesta(): void
    {
        $client = static::createClient();
        $this->criarGestorLogado($client);

        $painel = $this->painel($client->request('GET', '/dashboard'));

        $cab = $painel->filter('aside.db-ia-painel > .db-ia-cab > .db-ia-cab-texto');
        self::assertCount(1, $cab);
        self::assertSame('BlueJus Intelligence', $this->textoLimpo($cab->filter('.db-ia-cab-marca')));
        self::assertSame(
            'Leitura automática dos números do período, por regras fixas',
            $this->textoLimpo($cab->filter('.db-ia-cab-legenda')),
        );
        self::assertMatchesRegularExpression('/^Atualizada hoje às \d{2}:\d{2} · sem período definido$/u', $this->textoLimpo($cab->filter('.db-ia-cab-quando')));
    }

    #[TestDox('Nenhuma ocorrência de "inteligência artificial" nem "IA" no painel (texto e atributos)')]
    public function testNaoSeVendeComoInteligenciaArtificial(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarGestorLogado($client);
        // com dados, para o painel estar cheio de frases (alertas, ações, riscos, perguntas)
        $pasta = $this->pastaHospedeira($tenant);
        $bruno = $this->criarColaborador($tenant, 'Bruno Melo');
        $this->criarMeta($pasta, $bruno, '2024-02-10 09:00', new \DateTimeImmutable('2024-02-20'));
        $this->criarMeta($pasta, $bruno, '2024-02-12 09:00');

        $crawler = $client->request('GET', '/dashboard?' . self::PERIODO);
        self::assertResponseIsSuccessful();

        $html = $crawler->filter('[data-filtro-resultado] > .db-ia')->outerHtml();
        self::assertStringContainsStringIgnoringCase('Intelligence', $html, 'o nome do produto está lá (irmã que prova o alvo certo)');
        self::assertDoesNotMatchRegularExpression('/intelig[êe]ncia\s+artificial/iu', $html);
        self::assertDoesNotMatchRegularExpression('/\bI\.?A\b/u', $html, 'nem a sigla, nem "I.A"');
        self::assertDoesNotMatchRegularExpression('/\banalisou\b/iu', $html, 'o painel não "analisou" nada: ele soma e compara');
    }

    #[TestDox('Modo avançado é um <details> fechado por padrão, sem persistência, com a Central de Inteligência dentro')]
    public function testModoAvancadoExpansivelSemPersistencia(): void
    {
        $client = static::createClient();
        $this->criarGestorLogado($client);

        $painel = $this->painel($client->request('GET', '/dashboard'));

        $av = $painel->filter('.db-ia-corpo > details.db-ia-av');
        self::assertCount(1, $av, 'o interruptor do desenho virou um details na cabeça do corpo rolável');
        self::assertNull($av->attr('open'), 'fechado ao carregar: não há preferência por usuário no sistema');
        self::assertCount(1, $av->filter('details.db-ia-av > summary.db-ia-av-toggle'));
        self::assertStringContainsString('Modo avançado', $av->filter('summary')->text());
        self::assertCount(1, $av->filter('details.db-ia-av > .db-ia-av-corpo > .db-ia-central'));
        self::assertStringContainsString('Central de Inteligência', $av->filter('.db-ia-central')->text());
        self::assertCount(5, $av->filter('.db-ia-central > .db-ia-contagem > .db-ia-chip'), 'um chip por nível');
        self::assertCount(5, $av->filter('.db-ia-av-corpo > .db-ia-cockpit > .db-ia-kpi'), 'cockpit sem "Produtividade"');
        self::assertStringNotContainsString('localStorage', $painel->outerHtml());
    }

    #[TestDox('Nada fake: sem "Criar tarefa", "Resolvido", "Aumentar o nível", simular dia nem Inteligência de Desempenho')]
    public function testElementosSemFuncaoNaoSaoRenderizados(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarGestorLogado($client);
        $pasta = $this->pastaHospedeira($tenant);
        $bruno = $this->criarColaborador($tenant, 'Bruno Melo');
        $this->criarMeta($pasta, $bruno, '2024-02-10 09:00', new \DateTimeImmutable('2024-02-20'));

        $painel = $this->painel($client->request('GET', '/dashboard?' . self::PERIODO));
        $html   = $painel->outerHtml();

        self::assertCount(0, $painel->filter('a'), 'nenhum link: não há destino real para nenhum');
        self::assertCount(1, $painel->filter('button'), 'dentro do painel só o X de fechar (o botão de abrir é irmão, fora do aside)');
        foreach (['Criar tarefa', 'Resolvido', 'Ignorar', 'Aumentar o nível', 'simular dia', 'Inteligência de Desempenho', 'Não se aplica'] as $proibido) {
            self::assertStringNotContainsString($proibido, $html, $proibido);
        }
    }

    #[TestDox('Com período e 2 metas vencidas, a leitura usa os números reais: ação, risco, "o que está acontecendo" e Central')]
    public function testLeituraComDadosReais(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarGestorLogado($client);
        $pasta = $this->pastaHospedeira($tenant);
        $bruno = $this->criarColaborador($tenant, 'Bruno Melo');
        // duas metas abertas em fev/2024 com prazo já vencido; nenhuma concluída
        $this->criarMeta($pasta, $bruno, '2024-02-10 09:00', new \DateTimeImmutable('2024-02-20'));
        $this->criarMeta($pasta, $bruno, '2024-02-12 09:00', new \DateTimeImmutable('2024-02-25'));

        $crawler = $client->request('GET', '/dashboard?' . self::PERIODO);
        $painel  = $this->painel($crawler);

        // fev/2024 já encerrou: a fase diz isso, e os números são os da tabela (2 abertas, 0 concluídas, 2 ativas)
        $oQue = $painel->filter('.db-ia-leitura .db-ia-q-texto[data-tipo="fase"]');
        self::assertCount(1, $oQue);
        self::assertSame(
            'A equipe encerrou o período com 0 metas concluídas. 2 metas abertas no período, 0 concluídas e 2 ativas.',
            $this->textoLimpo($oQue),
        );
        // "dia X de Y" no topo do bloco de ritmo, à direita (dc L1282-1286), não no subcabeçalho.
        self::assertSame('dia 29 de 29', $this->textoLimpo($painel->filter('.db-ia-corpo > .db-ia-ritmo > .db-ia-ritmo-topo:first-child > .db-ia-dia')));
        self::assertCount(0, $painel->filter('.db-ia-sub .db-ia-dia'));

        $acoes = $painel->filter('.db-ia-acoes > .db-ia-acao');
        self::assertGreaterThanOrEqual(2, $acoes->count());
        self::assertSame('Priorizar 2 metas vencidas.', $this->textoLimpo($acoes->first()->filter('.db-ia-acao-texto')));
        self::assertSame('Atenção imediata', $this->textoLimpo($acoes->first()->filter('.db-ia-acao-grupo')));
        self::assertSame('{"vencidas":2}', $acoes->first()->attr('data-numeros'), 'os números que sustentam a frase vão no atributo');
        self::assertSame('Reavaliar o resultado em 24 horas.', $this->textoLimpo($acoes->last()->filter('.db-ia-acao-texto')));

        $riscos = $painel->filter('.db-ia-riscos > .db-ia-risco');
        self::assertCount(1, $riscos);
        self::assertSame('2 metas vencidas', $this->textoLimpo($riscos->first()));
        self::assertSame('vencidas', $riscos->first()->attr('data-tipo'));

        // "Por que": só o que os números sustentam — das criadas no período, quantas seguem
        // abertas; e a contagem de vencidas (relativas a hoje), sem razão entre as duas bases
        self::assertSame(
            '2 das 2 metas criadas no período seguem abertas (100%).',
            $this->textoLimpo($painel->filter('.db-ia-leitura [data-tipo="abertas_no_periodo"]')),
        );
        self::assertSame(
            '2 metas com prazo já vencido continuam abertas.',
            $this->textoLimpo($painel->filter('.db-ia-leitura [data-tipo="vencidas"]')),
        );

        // Central: 2 vencidas (< 10) é "monitorar" pela contagem, nomeia quem as tem e não
        // calcula percentual sobre a fila do período (bases diferentes)
        $alerta = $painel->filter('.db-ia-alertas > details.db-ia-alerta[data-alerta="vencidas"]');
        self::assertCount(1, $alerta);
        self::assertStringContainsString('db-ia-nivel--monitorar', (string) $alerta->attr('class'));
        self::assertStringContainsString('Bruno tem 2.', $alerta->text());
        self::assertStringNotContainsString('%', $alerta->text());
        self::assertCount(0, $painel->filter('.db-ia-alertas > details.db-ia-alerta[data-alerta="entrada"]'), 'alerta de entrada × saída não existe');

        // botão sem ponto (dc L197-206); a contagem de riscos vai no balão irmão, com o
        // ponto âmbar DENTRO dele, e não no title nativo
        $botao = $crawler->filter('[data-filtro-resultado] > .db-ia > button.db-ia-abrir');
        self::assertCount(1, $botao);
        self::assertStringContainsString('is-alerta', (string) $botao->attr('class'));
        self::assertCount(0, $botao->filter('.db-ia-abrir-ponto, .db-ia-dica-ponto'), 'o botão não leva ponto');
        self::assertNull($botao->attr('title'), 'a dica é o balão próprio, não o title nativo');
        self::assertSame('dbIaDica', $botao->attr('aria-describedby'));
        $dica = $crawler->filter('[data-filtro-resultado] > .db-ia > button.db-ia-abrir + span.db-ia-dica#dbIaDica[role="tooltip"]');
        self::assertCount(1, $dica);
        self::assertStringContainsString('is-alerta', (string) $dica->attr('class'));
        self::assertCount(1, $dica->filter('.db-ia-dica > .db-ia-dica-ponto'));
        self::assertSame('1 ponto de atenção', trim($dica->text()));
    }

    #[TestDox('O XHR devolve o painel junto com cards e tabela, recalculado para o filtro')]
    public function testXhrDevolveOPainelRecalculado(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarGestorLogado($client);
        $pasta = $this->pastaHospedeira($tenant);
        $bruno = $this->criarColaborador($tenant, 'Bruno Melo');
        $this->criarMeta($pasta, $bruno, '2024-02-10 09:00', new \DateTimeImmutable('2024-02-20'));

        $xhr = $client->xmlHttpRequest('GET', '/dashboard?' . self::PERIODO);
        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('data-filtro-root', $body, 'só o fragmento');
        self::assertCount(1, $xhr->filter('.db-table-card'), 'cards e tabela continuam vindo');
        self::assertCount(1, $xhr->filter('.db-ia > aside.db-ia-painel'));
        self::assertStringContainsString('Priorizar 1 meta vencida.', $body);
        self::assertStringContainsString('dia 29 de 29', $body);

        // filtrando por outro responsável, a leitura muda junto (nenhuma linha → nenhuma vencida)
        $xhrOutro = $client->xmlHttpRequest('GET', '/dashboard?' . self::PERIODO . '&busca=ninguem-com-esse-nome');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $xhrOutro->filter('.db-ia > aside.db-ia-painel'));
        self::assertStringNotContainsString('Priorizar', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('Nenhum gargalo relevante', (string) $client->getResponse()->getContent());
    }

    #[TestDox('Sem período: sem "dia X de Y", sem ritmo por dia, e o limite é declarado no painel')]
    public function testSemPeriodoDeclaraOLimite(): void
    {
        $client = static::createClient();
        $this->criarGestorLogado($client);

        $painel = $this->painel($client->request('GET', '/dashboard'));

        self::assertCount(0, $painel->filter('.db-ia-dia'));
        self::assertCount(0, $painel->filter('.db-ia-ritmo-topo'));
        self::assertCount(0, $painel->filter('.db-ia-ritmo-grade'));
        self::assertCount(0, $painel->filter('.db-ia-q-texto[data-tipo="fase"]'));
        $limites = $painel->filter('.db-ia-limites > ul > li');
        self::assertCount(3, $limites);
        self::assertStringContainsString('não define objetivo', $limites->eq(0)->text());
        self::assertStringContainsString('não mede tendência da fila', $limites->eq(1)->text());
        self::assertStringContainsString('não há dia a contar', $limites->eq(2)->text());
    }

    #[TestDox('Isolamento: as metas vencidas de outro escritório não entram na leitura deste')]
    public function testNaoVazaEntreEscritorios(): void
    {
        $client = static::createClient();

        // Escritório B: colaborador com 2 metas vencidas — o painel DELE fala nelas (prova a barreira certa)
        $tenantB = $this->criarTenant();
        $gestorB = $this->criarUsuarioComPermissaoBi($tenantB);
        $pastaB  = $this->pastaHospedeira($tenantB);
        $colabB  = $this->criarColaborador($tenantB, 'Zuleica Prado');
        $this->criarMeta($pastaB, $colabB, '2024-02-10 09:00', new \DateTimeImmutable('2024-02-20'));
        $this->criarMeta($pastaB, $colabB, '2024-02-11 09:00', new \DateTimeImmutable('2024-02-21'));

        $this->logarComTenant($client, $gestorB, $tenantB);
        $painelB = $this->painel($client->request('GET', '/dashboard?' . self::PERIODO));
        self::assertStringContainsString('Priorizar 2 metas vencidas.', $painelB->text());
        self::assertStringContainsString('Zuleica', $painelB->text());

        // Escritório A: um colaborador com uma meta em dia — nada de vencida, nada de Zuleica
        $tenantA = $this->criarTenant();
        $gestorA = $this->criarUsuarioComPermissaoBi($tenantA);
        $pastaA  = $this->pastaHospedeira($tenantA);
        $colabA  = $this->criarColaborador($tenantA, 'Ana Lima');
        $this->criarMeta($pastaA, $colabA, '2024-02-10 09:00', new \DateTimeImmutable('2099-12-31'));

        $this->logarComTenant($client, $gestorA, $tenantA);
        $painelA = $this->painel($client->request('GET', '/dashboard?' . self::PERIODO));
        $textoA  = $painelA->text();
        self::assertStringNotContainsString('Priorizar', $textoA);
        self::assertStringNotContainsString('Zuleica', $textoA);
        self::assertStringContainsString('Ana', $textoA, 'o próprio colaborador aparece (irmã que prova que a leitura rodou)');
        self::assertCount(0, $painelA->filter('.db-ia-riscos > .db-ia-risco'));
    }
}
