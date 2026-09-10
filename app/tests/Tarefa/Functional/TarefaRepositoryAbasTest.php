<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Functional;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Tarefa\Enum\AbaMetas;
use App\Tarefa\Repository\TarefaRepository;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Factory\Pasta\PastaFactory;
use App\Tests\Factory\Tarefa\TarefaFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * As abas de "Minhas Metas" — cada uma é um PAPEL, e o ponto da frente é que os papéis
 * parem de se misturar numa lista só. Spec: `docs/specs/minhas-metas-abas.md`.
 */
#[CoversClass(TarefaRepository::class)]
#[Group('tarefa')]
final class TarefaRepositoryAbasTest extends KernelTestCase
{
    use Factories;

    private TarefaRepository $repo;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->repo = static::getContainer()->get(TarefaRepository::class);
        $this->em   = static::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * @param User[] $responsaveis
     */
    private function meta(
        Tenant $tenant,
        string $titulo,
        ?User $criador,
        array $responsaveis = [],
        string $status = Tarefa::STATUS_PENDENTE,
    ): Tarefa {
        $pasta  = PastaFactory::createOne(['tenant' => $tenant])->_real();
        $tarefa = TarefaFactory::createOne(['pasta' => $pasta, 'titulo' => $titulo, 'status' => $status])->_real();

        if ($criador !== null) {
            $tarefa->setCriadoPor($criador);
        }
        foreach ($responsaveis as $r) {
            $tarefa->addResponsavel($r);
        }
        $this->em->flush();

        return $tarefa;
    }

    /**
     * @param Tarefa[] $resultado
     * @return string[]
     */
    private function titulos(array $resultado): array
    {
        return array_map(static fn (Tarefa $t): string => $t->getTitulo(), $resultado);
    }

    #[TestDox('Aba "Sou responsável" NÃO traz a meta que o usuário criou para outra pessoa')]
    public function testAbaResponsavelNaoTrazOQueDeleguei(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $eu     = UserFactory::createOne()->_real();
        $colega = UserFactory::createOne()->_real();

        $this->meta($tenant, 'Deleguei ao colega', $eu, [$colega]);
        $this->meta($tenant, 'Está comigo', $colega, [$eu]);

        $titulos = $this->titulos($this->repo->findParaMinhasMetas($eu, AbaMetas::RESPONSAVEL, []));

        self::assertContains('Está comigo', $titulos);
        self::assertNotContains('Deleguei ao colega', $titulos, 'A aba do responsável não pode trazer o que foi delegado a terceiro.');
    }

    #[TestDox('Aba "Criei" traz o que deleguei e NÃO traz o que só me foi atribuído')]
    public function testAbaCrieiTrazSoOQueCriei(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $eu     = UserFactory::createOne()->_real();
        $colega = UserFactory::createOne()->_real();

        $this->meta($tenant, 'Deleguei ao colega', $eu, [$colega]);
        $this->meta($tenant, 'Só me atribuíram', $colega, [$eu]);

        $titulos = $this->titulos($this->repo->findParaMinhasMetas($eu, AbaMetas::CRIEI, []));

        self::assertContains('Deleguei ao colega', $titulos);
        self::assertNotContains('Só me atribuíram', $titulos);
    }

    #[TestDox('Meta que criei para mim mesmo aparece nas DUAS abas — sou os dois papéis')]
    public function testMetaAutoAtribuidaApareceNasDuasAbas(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $eu     = UserFactory::createOne()->_real();

        $this->meta($tenant, 'Criei para mim', $eu, [$eu]);

        self::assertContains('Criei para mim', $this->titulos($this->repo->findParaMinhasMetas($eu, AbaMetas::RESPONSAVEL, [])));
        self::assertContains('Criei para mim', $this->titulos($this->repo->findParaMinhasMetas($eu, AbaMetas::CRIEI, [])));
    }

    #[TestDox('Aba "Em acompanhamento" traz só o que EU marquei; marcação de colega não vaza')]
    public function testAbaAcompanhandoEhPessoal(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $eu     = UserFactory::createOne()->_real();
        $colega = UserFactory::createOne()->_real();

        $minha = $this->meta($tenant, 'Marquei esta', $colega, [$colega]);
        $dele  = $this->meta($tenant, 'Ele marcou aquela', $colega, [$colega]);

        $minha->alternarAcompanhamento($eu);
        $dele->alternarAcompanhamento($colega);
        $this->em->flush();

        $titulos = $this->titulos($this->repo->findParaMinhasMetas($eu, AbaMetas::ACOMPANHANDO, []));

        self::assertContains('Marquei esta', $titulos);
        self::assertNotContains('Ele marcou aquela', $titulos, 'Acompanhamento é pessoal: o do colega não pode aparecer na minha aba.');
    }

    #[TestDox('Aba "Todas" une os três papéis SEM duplicar a meta que criei para mim')]
    public function testAbaTodasUneSemDuplicar(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $eu     = UserFactory::createOne()->_real();
        $colega = UserFactory::createOne()->_real();

        $this->meta($tenant, 'Criei para mim', $eu, [$eu]);
        $this->meta($tenant, 'Deleguei', $eu, [$colega]);
        $this->meta($tenant, 'Me atribuíram', $colega, [$eu]);
        $marcada = $this->meta($tenant, 'Só acompanho', $colega, [$colega]);
        $marcada->alternarAcompanhamento($eu);
        $this->em->flush();

        $titulos = $this->titulos($this->repo->findParaMinhasMetas($eu, AbaMetas::TODAS, []));

        self::assertContains('Criei para mim', $titulos);
        self::assertContains('Deleguei', $titulos);
        self::assertContains('Me atribuíram', $titulos);
        self::assertContains('Só acompanho', $titulos);
        self::assertCount(
            1,
            array_keys($titulos, 'Criei para mim', true),
            'A meta em que sou criador E responsável não pode vir duplicada pelo OR.',
        );
    }

    #[TestDox('Concluída há mais de 30 dias fica fora; concluída ontem entra')]
    public function testConcluidasCortadasAos30Dias(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $eu     = UserFactory::createOne()->_real();

        $velha = $this->meta($tenant, 'Concluída há muito tempo', $eu, [$eu], Tarefa::STATUS_CONCLUIDA);
        $velha->setDataConclusao(new \DateTimeImmutable('-40 days'));
        $nova = $this->meta($tenant, 'Concluída ontem', $eu, [$eu], Tarefa::STATUS_CONCLUIDA);
        $nova->setDataConclusao(new \DateTimeImmutable('-1 day'));
        $this->em->flush();

        $titulos = $this->titulos($this->repo->findParaMinhasMetas($eu, AbaMetas::RESPONSAVEL, []));

        self::assertContains('Concluída ontem', $titulos);
        self::assertNotContains('Concluída há muito tempo', $titulos);
    }

    #[TestDox('Concluída SEM dataConclusao cai no fallback da dataAlteracao, em vez de sumir')]
    public function testConcluidaSemDataConclusaoUsaDataAlteracao(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $eu     = UserFactory::createOne()->_real();

        $legado = $this->meta($tenant, 'Concluída no tempo do legado', $eu, [$eu], Tarefa::STATUS_CONCLUIDA);
        $this->em->flush();

        // dataConclusao nula + dataAlteracao recente: é o retrato das metas concluídas antes
        // de a coluna existir. Vai por SQL porque dataAlteracao só é escrita pelo PreUpdate.
        $this->em->getConnection()->executeStatement(
            'UPDATE tarefa SET data_conclusao = NULL, data_alteracao = :quando WHERE id = :id',
            ['quando' => (new \DateTimeImmutable('-2 days'))->format('Y-m-d H:i:s'), 'id' => $legado->getId()],
        );
        $this->em->clear();

        $titulos = $this->titulos($this->repo->findParaMinhasMetas($eu, AbaMetas::RESPONSAVEL, []));

        self::assertContains('Concluída no tempo do legado', $titulos);
    }

    #[TestDox('Concluída sem dataConclusao E alterada há muito tempo continua fora')]
    public function testConcluidaSemDataConclusaoEAntigaFicaFora(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $eu     = UserFactory::createOne()->_real();

        $legado = $this->meta($tenant, 'Legado antigo', $eu, [$eu], Tarefa::STATUS_CONCLUIDA);
        $this->em->flush();

        $this->em->getConnection()->executeStatement(
            'UPDATE tarefa SET data_conclusao = NULL, data_alteracao = :quando WHERE id = :id',
            ['quando' => (new \DateTimeImmutable('-90 days'))->format('Y-m-d H:i:s'), 'id' => $legado->getId()],
        );
        $this->em->clear();

        self::assertNotContains(
            'Legado antigo',
            $this->titulos($this->repo->findParaMinhasMetas($eu, AbaMetas::RESPONSAVEL, [])),
        );
    }

    #[TestDox('Os filtros de busca e status continuam valendo DENTRO da aba escolhida')]
    public function testFiltrosValemDentroDaAba(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $eu     = UserFactory::createOne()->_real();
        $colega = UserFactory::createOne()->_real();

        $this->meta($tenant, 'Protocolar recurso', $colega, [$eu]);
        $this->meta($tenant, 'Agendar audiência', $colega, [$eu]);
        $this->meta($tenant, 'Protocolar petição delegada', $eu, [$colega]);

        $titulos = $this->titulos($this->repo->findParaMinhasMetas($eu, AbaMetas::RESPONSAVEL, ['busca' => 'protocolar']));

        self::assertContains('Protocolar recurso', $titulos);
        self::assertNotContains('Agendar audiência', $titulos);
        self::assertNotContains('Protocolar petição delegada', $titulos, 'O filtro não pode furar o escopo da aba.');
    }

    #[TestDox('A contagem de cada aba conta só metas EM ABERTO e bate com a lista')]
    public function testContagemPorAbaIgnoraConcluidas(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $eu     = UserFactory::createOne()->_real();
        $colega = UserFactory::createOne()->_real();

        $this->meta($tenant, 'Aberta comigo', $colega, [$eu]);
        $concluida = $this->meta($tenant, 'Concluída comigo', $colega, [$eu], Tarefa::STATUS_CONCLUIDA);
        $concluida->setDataConclusao(new \DateTimeImmutable('-1 day'));
        $this->meta($tenant, 'Deleguei uma', $eu, [$colega]);
        $this->em->flush();

        $contagens = $this->repo->contarPorAba($eu);

        self::assertSame(1, $contagens[AbaMetas::RESPONSAVEL->value], 'A concluída não pode entrar na contagem da aba.');
        self::assertSame(1, $contagens[AbaMetas::CRIEI->value]);
        self::assertSame(0, $contagens[AbaMetas::ACOMPANHANDO->value]);
        self::assertSame(2, $contagens[AbaMetas::TODAS->value], 'Todas = união em aberto, sem duplicar.');
    }

    #[TestDox('Os KPIs contam por urgência e batem com o que a lista mostra')]
    public function testKpisPorUrgencia(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $eu     = UserFactory::createOne()->_real();
        $colega = UserFactory::createOne()->_real();

        $atrasada = $this->meta($tenant, 'Atrasada', $colega, [$eu]);
        $atrasada->setPrazo(new \DateTimeImmutable('-3 days'));
        $proxima = $this->meta($tenant, 'Vence em 2 dias', $colega, [$eu]);
        $proxima->setPrazo(new \DateTimeImmutable('+2 days'));
        $longe = $this->meta($tenant, 'Vence longe', $colega, [$eu]);
        $longe->setPrazo(new \DateTimeImmutable('+40 days'));
        $this->meta($tenant, 'Sem prazo', $colega, [$eu]);
        $this->meta($tenant, 'Criei e está em revisão', $eu, [$colega], Tarefa::STATUS_EM_REVISAO);
        $this->em->flush();

        $kpis = $this->repo->contarPainelMinhasMetas($eu);

        self::assertSame(1, $kpis['atrasadas']);
        self::assertSame(1, $kpis['proximas']);
        self::assertSame(1, $kpis['sem_prazo']);
        self::assertSame(1, $kpis['aguardando_revisao'], 'É o que EU criei e voltou para a minha revisão.');
    }

    #[TestDox('Meta em revisão sai da contagem de urgência — a bola está com quem criou')]
    public function testEmRevisaoNaoContaComoAtrasadaDoResponsavel(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $eu     = UserFactory::createOne()->_real();
        $colega = UserFactory::createOne()->_real();

        $enviada = $this->meta($tenant, 'Enviei para revisão', $colega, [$eu], Tarefa::STATUS_EM_REVISAO);
        $enviada->setPrazo(new \DateTimeImmutable('-5 days'));
        $this->em->flush();

        $kpis = $this->repo->contarPainelMinhasMetas($eu);

        self::assertSame(0, $kpis['atrasadas'], 'Já entreguei: não conta como minha pendência atrasada.');
    }

    #[TestDox('Trilho da aba "Sou responsável": quem delegou para mim, com abertas e atrasadas')]
    public function testTrilhoListaQuemDelegouParaMim(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $eu     = UserFactory::createOne()->_real();
        $chefe  = UserFactory::createOne(['fullName' => 'Mariana Costa'])->_real();
        $colega = UserFactory::createOne(['fullName' => 'Samuel Freitas'])->_real();

        $atrasada = $this->meta($tenant, 'Do chefe, atrasada', $chefe, [$eu]);
        $atrasada->setPrazo(new \DateTimeImmutable('-3 days'));
        $this->meta($tenant, 'Do chefe, no prazo', $chefe, [$eu]);
        $this->meta($tenant, 'Do colega', $colega, [$eu]);
        $this->meta($tenant, 'Que eu deleguei', $eu, [$colega]);
        $this->em->flush();

        $pessoas = $this->repo->contarPessoasDoTrilho($eu, AbaMetas::RESPONSAVEL);
        $porNome = [];
        foreach ($pessoas as $p) {
            $porNome[$p->nome] = $p;
        }

        self::assertSame(2, $porNome['Mariana Costa']->abertas);
        self::assertSame(1, $porNome['Mariana Costa']->atrasadas);
        self::assertSame(1, $porNome['Samuel Freitas']->abertas);
        self::assertSame(0, $porNome['Samuel Freitas']->atrasadas);
        self::assertArrayNotHasKey(
            $eu->getFullName(),
            $porNome,
            'O trilho desta aba lista quem delegou PARA mim — eu mesmo não entro por ter delegado a outro.',
        );
    }

    #[TestDox('Trilho da aba "Criei": com quem estão as metas que eu deleguei')]
    public function testTrilhoListaComQuemEstao(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $eu     = UserFactory::createOne()->_real();
        $ana    = UserFactory::createOne(['fullName' => 'Ana Lima'])->_real();
        $bruno  = UserFactory::createOne(['fullName' => 'Bruno Reis'])->_real();

        $atrasada = $this->meta($tenant, 'Com a Ana, atrasada', $eu, [$ana]);
        $atrasada->setPrazo(new \DateTimeImmutable('-5 days'));
        $this->meta($tenant, 'Com a Ana e o Bruno', $eu, [$ana, $bruno]);
        $this->meta($tenant, 'Me atribuíram', $ana, [$eu]);
        $this->em->flush();

        $porNome = [];
        foreach ($this->repo->contarPessoasDoTrilho($eu, AbaMetas::CRIEI) as $p) {
            $porNome[$p->nome] = $p;
        }

        self::assertSame(2, $porNome['Ana Lima']->abertas);
        self::assertSame(1, $porNome['Ana Lima']->atrasadas);
        self::assertSame(1, $porNome['Bruno Reis']->abertas, 'Meta com dois responsáveis conta para os dois.');
        self::assertArrayNotHasKey(
            $ana->getFullName() . ' (como criadora)',
            $porNome,
            'O trilho agrupa por responsável, não por quem criou.',
        );
        self::assertSame([], array_diff(array_keys($porNome), ['Ana Lima', 'Bruno Reis']), 'Só os responsáveis das metas que EU criei entram.');
    }

    #[TestDox('O trilho ignora metas concluídas — mede carga de hoje, não histórico')]
    public function testTrilhoIgnoraConcluidas(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $eu     = UserFactory::createOne()->_real();
        $chefe  = UserFactory::createOne(['fullName' => 'Chefe Teste'])->_real();

        $this->meta($tenant, 'Aberta', $chefe, [$eu]);
        $concluida = $this->meta($tenant, 'Concluída', $chefe, [$eu], Tarefa::STATUS_CONCLUIDA);
        $concluida->setDataConclusao(new \DateTimeImmutable('-1 day'));
        $this->em->flush();

        $pessoas = $this->repo->contarPessoasDoTrilho($eu, AbaMetas::RESPONSAVEL);

        self::assertCount(1, $pessoas);
        self::assertSame(1, $pessoas[0]->abertas);
    }

    /**
     * O teto que a spec §Testes item 10 pede, e que faltava: o KPI é um ATALHO, então o
     * número que ele mostra e o tamanho da lista que ele abre têm de ser o mesmo.
     *
     * O revisor mediu a divergência em dados de ensaio: 3 metas `em_revisao` com prazo
     * vencido entravam na lista de "Atrasadas" sem entrar na contagem, e metas concluídas
     * sem prazo entravam em "Sem prazo" com o KPI marcando zero.
     */
    #[TestDox('Cada KPI bate exatamente com a lista que ele abre')]
    public function testKpiBateComALista(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $eu     = UserFactory::createOne()->_real();
        $colega = UserFactory::createOne()->_real();

        $atrasada = $this->meta($tenant, 'Atrasada de verdade', $colega, [$eu]);
        $atrasada->setPrazo(new \DateTimeImmutable('-3 days'));

        // Entregue e vencida: sai da fila do responsável, então NÃO pode entrar nem no
        // número nem na lista de atrasadas.
        $entregue = $this->meta($tenant, 'Entregue e vencida', $colega, [$eu], Tarefa::STATUS_EM_REVISAO);
        $entregue->setPrazo(new \DateTimeImmutable('-9 days'));

        $proxima = $this->meta($tenant, 'Vence em 2 dias', $colega, [$eu]);
        $proxima->setPrazo(new \DateTimeImmutable('+2 days'));

        $this->meta($tenant, 'Sem prazo, aberta', $colega, [$eu]);

        // Concluída sem prazo: fora dos dois lados.
        $fechada = $this->meta($tenant, 'Sem prazo, concluída', $colega, [$eu], Tarefa::STATUS_CONCLUIDA);
        $fechada->setDataConclusao(new \DateTimeImmutable('-2 days'));
        $this->em->flush();

        $kpis = $this->repo->contarPainelMinhasMetas($eu);

        foreach ([['atrasadas', 'vencidas'], ['proximas', 'proximas'], ['sem_prazo', 'sem']] as [$kpi, $faceta]) {
            $lista = $this->repo->findParaMinhasMetas($eu, AbaMetas::RESPONSAVEL, ['prazo' => $faceta]);

            self::assertSame(
                $kpis[$kpi],
                count($lista),
                "O KPI '{$kpi}' mostra {$kpis[$kpi]}, mas o filtro '{$faceta}' devolve " . count($lista) . ' meta(s).',
            );
        }
    }
}
