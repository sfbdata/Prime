<?php

declare(strict_types=1);

namespace App\Tests\Processo\Functional;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\NivelDeAcesso;
use App\Pasta\Entity\Pasta;
use App\Processo\Controller\ProcessoController;
use App\Processo\Entity\Processo;
use App\Tarefa\Repository\TarefaRepository;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\CriaCenarioDoCadeadoFuncionalTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\DomCrawler\Crawler;

/**
 * A tela do processo (GET /processos/{id}) lê de uma vez os responsáveis de todas as metas da aba Tarefas (PR 4d da
 * revisão de queries). Trava duas coisas: o número de consultas não cresce com o número de metas, e a tela mostra
 * exatamente o mesmo de antes para o primário, para o colaborador e para quem o cadeado restringe.
 */
#[CoversClass(ProcessoController::class)]
#[CoversClass(TarefaRepository::class)]
final class ProcessoShowEmLoteTest extends JusPrimeWebTestCase
{
    use CriaCenarioDoCadeadoFuncionalTrait;

    private const PERMISSOES = [
        'modules.expediente.view', 'modules.pastas.view', 'modules.tarefas.view', 'modules.processos.view',
        'resources.pasta.view', 'resources.pasta.edit', 'resources.processo.view',
    ];

    #[TestDox('a tela do processo faz o mesmo número de consultas com 1 e com 8 metas')]
    public function testConsultasNaoCrescemComAsMetas(): void
    {
        $this->client = static::createClient();

        // Dois escritórios iguais, um com 1 meta no processo e outro com 8: mesmas pessoas, mesmo papel.
        [$primarioUm, $tenantUm, $anaUm, $processoUm]       = $this->escritorioComMetas(1);
        [$primarioOito, $tenantOito, $anaOito, $processoOito] = $this->escritorioComMetas(8);

        foreach ([[$primarioUm, $primarioOito], [$anaUm, $anaOito]] as [$quemUm, $quemOito]) {
            // Aquecimento: a primeira requisição de cada pessoa guarda na sessão o que é de uma vez só.
            $this->contarConsultas($quemUm, $tenantUm, $processoUm);
            $this->contarConsultas($quemOito, $tenantOito, $processoOito);

            $comUma  = $this->contarConsultas($quemUm, $tenantUm, $processoUm);
            $comOito = $this->contarConsultas($quemOito, $tenantOito, $processoOito);

            self::assertSame(1, $comUma['metas']);
            self::assertSame(8, $comOito['metas']);
            self::assertSame($comUma['consultas'], $comOito['consultas'], 'A tela do processo voltou a consultar por meta.');
        }
    }

    #[TestDox('a aba Tarefas mostra as mesmas metas e responsáveis, e o cadeado continua tirando as metas da pasta oculta')]
    public function testTelaMostraOMesmoConteudo(): void
    {
        $this->client = static::createClient();
        [$primario, $tenant] = $this->criarEscritorio();
        $ana   = $this->criarColaborador($tenant, 'Ana Equipe', self::PERMISSOES);
        $bruno = $this->criarColaborador($tenant, 'Bruno Leitor', self::PERMISSOES);

        $processo = $this->novoProcesso($tenant);
        $pastaA   = $this->pastaDoProcesso($tenant, $processo, 'CLIENTE A');
        $pastaB   = $this->pastaDoProcesso($tenant, $processo, 'CLIENTE B');

        // Responsáveis gravados fora da ordem do id (Ana antes de Paula; Bruno antes de Ana).
        $this->meta($pastaA, $tenant, $primario, 'Petição inicial', [$ana, $primario], '2026-12-10', '2026-10-01 09:00');
        $this->meta($pastaA, $tenant, $ana, 'Nota sem responsável', [], null, '2026-10-02 09:00');
        $concluida = $this->meta($pastaA, $tenant, $primario, 'Meta concluída', [$bruno], '2026-10-20', '2026-10-03 09:00');
        $concluida->setStatus(Tarefa::STATUS_CONCLUIDA);
        $concluida->setDataConclusao(new \DateTimeImmutable('2026-10-19 15:00'));
        $this->meta($pastaB, $tenant, $primario, 'Meta da pasta B', [$bruno, $ana], '2026-12-20', '2026-10-04 09:00');
        $this->em()->flush();

        $esperado = $this->esperado();
        self::assertSame($esperado['livre'], $this->tela($primario, $tenant, $processo), 'primário');
        self::assertSame($esperado['livre'], $this->tela($ana, $tenant, $processo), 'colaborador');

        // Cadeado na pasta B só com o Bruno: a Ana deixa de ver a meta dela; o primário e o Bruno continuam vendo.
        $pastaB   = $this->em()->find(Pasta::class, $pastaB->getId());
        $primario = $this->em()->find(User::class, $primario->getId());
        $bruno    = $this->em()->find(User::class, $bruno->getId());
        $this->trancar($pastaB, $primario, [[$bruno, NivelDeAcesso::Visualizar]]);
        self::assertSame($esperado['livre'], $this->tela($primario, $tenant, $processo), 'primário com a pasta B trancada');
        self::assertSame($esperado['livre'], $this->tela($bruno, $tenant, $processo), 'colaborador na lista do cadeado');
        self::assertSame($esperado['semPastaB'], $this->tela($ana, $tenant, $processo), 'colaborador fora do cadeado da pasta B');

        // As duas pastas ocultas para a Ana: a tela do processo recusa, como hoje.
        $pastaA   = $this->em()->find(Pasta::class, $pastaA->getId());
        $primario = $this->em()->find(User::class, $primario->getId());
        $this->trancar($pastaA, $primario);
        self::assertSame(['status' => 403], $this->tela($ana, $tenant, $processo), 'colaborador com todas as pastas ocultas');

        // Outro escritório: o processo não abre.
        [$outroPrimario, $outroTenant] = $this->criarEscritorio();
        self::assertSame($esperado['outroEscritorio'], $this->tela($outroPrimario, $outroTenant, $processo), 'outro escritório');
    }

    /** @return array{0: User, 1: Tenant, 2: User, 3: Processo} */
    private function escritorioComMetas(int $quantas): array
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $ana      = $this->criarColaborador($tenant, 'Ana Equipe', self::PERMISSOES);
        $bruno    = $this->criarColaborador($tenant, 'Bruno Leitor', self::PERMISSOES);
        $processo = $this->novoProcesso($tenant);
        $pasta    = $this->pastaDoProcesso($tenant, $processo, 'CLIENTE CONTAGEM');
        for ($i = 0; $i < $quantas; ++$i) {
            // Cada meta com responsáveis próprios fora da identidade carregada (o Bruno não é quem olha).
            $this->meta($pasta, $tenant, $primario, 'Meta ' . $i, [$bruno, $primario, $ana], null, sprintf('2026-10-%02d 09:00', $i + 1));
        }
        $this->em()->flush();

        return [$primario, $tenant, $ana, $processo];
    }

    private function novoProcesso(Tenant $tenant): Processo
    {
        $processo = new Processo();
        $processo->setTenant($tenant);
        $processo->setNumeroProcesso(sprintf('07%05d-10.2026.8.07.0001', random_int(0, 99999)));
        $processo->setAssuntoProcessual('Indenização');
        $this->em()->persist($processo);
        $this->em()->flush();

        return $processo;
    }

    private function pastaDoProcesso(Tenant $tenant, Processo $processo, string $cliente): Pasta
    {
        $pasta = $this->criarPasta($tenant, $cliente);
        $pasta->vincularProcesso($processo);
        $this->em()->flush();

        return $pasta;
    }

    /**
     * @param list<User> $responsaveis
     */
    private function meta(Pasta $pasta, Tenant $tenant, User $criador, string $titulo, array $responsaveis, ?string $prazo, string $criadaEm): Tarefa
    {
        $meta = new Tarefa();
        $meta->setTitulo($titulo);
        $meta->setDescricao('Descrição de ' . $titulo);
        $meta->setPasta($pasta);
        $meta->setTenant($tenant);
        $meta->setCriadoPor($criador);
        $meta->setPrazo($prazo !== null ? new \DateTimeImmutable($prazo) : null);
        // Data de criação fixa: a aba ordena por ela e a mostra.
        (new \ReflectionProperty(Tarefa::class, 'dataCriacao'))->setValue($meta, new \DateTimeImmutable($criadaEm));
        foreach ($responsaveis as $responsavel) {
            $meta->addResponsavel($responsavel);
        }
        $this->em()->persist($meta);
        $this->em()->flush();

        return $meta;
    }

    /** @return array{consultas: int, metas: int} */
    private function contarConsultas(User $quem, Tenant $tenant, Processo $processo): array
    {
        $this->logarComTenant($this->client, $quem, $tenant);
        $this->em()->clear();
        $this->client->enableProfiler();
        $crawler = $this->client->request('GET', '/processos/' . $processo->getId());
        self::assertResponseIsSuccessful();

        $perfil = $this->client->getProfile();
        self::assertNotFalse($perfil);
        /** @var \Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector $db */
        $db = $perfil->getCollector('db');

        return ['consultas' => $db->getQueryCount(), 'metas' => $crawler->filter('#tarefas .tarefa-card')->count()];
    }

    /**
     * O que a aba Tarefas mostra, sem os ids (trocados pela posição implícita da lista).
     *
     * @return array<string, mixed>
     */
    private function tela(User $quem, Tenant $tenant, Processo $processo): array
    {
        $this->logarComTenant($this->client, $quem, $tenant);
        $this->em()->clear();
        $crawler = $this->client->request('GET', '/processos/' . $processo->getId());
        $status  = $this->client->getResponse()->getStatusCode();
        if ($status !== 200) {
            return ['status' => $status];
        }

        return [
            'status' => $status,
            'badge'  => trim($crawler->filter('#tarefas-tab .badge')->count() > 0 ? $crawler->filter('#tarefas-tab .badge')->text() : ''),
            'metas'  => $crawler->filter('#tarefas .tarefa-card')->each(static fn (Crawler $c): array => [
                'titulo'    => (string) $c->attr('data-tarefa-titulo'),
                'descricao' => (string) $c->attr('data-tarefa-descricao'),
                'status'    => (string) $c->attr('data-tarefa-status'),
                'usuarios'  => (string) $c->attr('data-tarefa-usuarios'),
                'prazo'     => (string) $c->attr('data-tarefa-prazo'),
                'criacao'   => (string) $c->attr('data-tarefa-criacao'),
                'conclusao' => (string) $c->attr('data-tarefa-conclusao'),
                'linha'     => preg_replace('/\s+/', ' ', trim($c->filter('.card-body')->text())),
            ]),
        ];
    }

    /**
     * O retrato tirado do código de ANTES da leitura em lote (este mesmo teste, rodado no master): a tela tem de
     * continuar igual.
     *
     * Uma exceção, a ordem dos responsáveis gravados fora da ordem do id ("Petição inicial" e "Meta da pasta B"). A
     * coleção não tem ORDER BY. A leitura antiga devolvia a ordem física da tabela quando ela é pequena (a de
     * inserção, como no banco de teste: "Ana Equipe, Paula Primária" e "Bruno Leitor, Ana Equipe") e a do índice da
     * chave (tarefa_id, user_id) quando ela é grande, como em produção. A leitura em lote ordena pelo id do usuário,
     * a ordem que produção já mostrava. O retrato segue essa ordem: Paula, depois Ana, depois Bruno.
     *
     * @return array<string, array<string, mixed>>
     */
    private function esperado(): array
    {
        $pastaB = [
            'titulo'    => 'Meta da pasta B',
            'descricao' => 'Descrição de Meta da pasta B',
            'status'    => 'pendente',
            'usuarios'  => 'Ana Equipe, Bruno Leitor',
            'prazo'     => '20/12/2026 00:00',
            'criacao'   => '04/10/2026 09:00',
            'conclusao' => '-',
            'linha'     => 'Meta da pasta B PENDENTE Descrição de Meta da pasta B Ana Equipe, Bruno Leitor 04/10/2026 09:00 Prazo: 20/12/2026',
        ];
        $daPastaA = [
            [
                'titulo'    => 'Meta concluída',
                'descricao' => 'Descrição de Meta concluída',
                'status'    => 'concluida',
                'usuarios'  => 'Bruno Leitor',
                'prazo'     => '20/10/2026 00:00',
                'criacao'   => '03/10/2026 09:00',
                'conclusao' => '19/10/2026 15:00',
                'linha'     => 'Meta concluída CONCLUIDA Descrição de Meta concluída Bruno Leitor 03/10/2026 09:00 Prazo: 20/10/2026',
            ],
            [
                'titulo'    => 'Nota sem responsável',
                'descricao' => 'Descrição de Nota sem responsável',
                'status'    => 'pendente',
                'usuarios'  => '-',
                'prazo'     => '-',
                'criacao'   => '02/10/2026 09:00',
                'conclusao' => '-',
                'linha'     => 'Nota sem responsável PENDENTE Descrição de Nota sem responsável - 02/10/2026 09:00',
            ],
            [
                'titulo'    => 'Petição inicial',
                'descricao' => 'Descrição de Petição inicial',
                'status'    => 'pendente',
                'usuarios'  => 'Paula Primária, Ana Equipe',
                'prazo'     => '10/12/2026 00:00',
                'criacao'   => '01/10/2026 09:00',
                'conclusao' => '-',
                'linha'     => 'Petição inicial PENDENTE Descrição de Petição inicial Paula Primária, Ana Equipe 01/10/2026 09:00 Prazo: 10/12/2026',
            ],
        ];

        return [
            'livre'           => ['status' => 200, 'badge' => '4', 'metas' => [$pastaB, ...$daPastaA]],
            'semPastaB'       => ['status' => 200, 'badge' => '3', 'metas' => $daPastaA],
            'outroEscritorio' => ['status' => 404],
        ];
    }
}
