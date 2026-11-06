<?php

declare(strict_types=1);

namespace App\Tests\Notificacao\Functional;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Service\LeituraDasPastasDosAvisos;
use App\Pasta\Service\NivelNoCadeado;
use App\Profile\Preferencia\CatalogoDePreferenciasDoPerfil;
use App\Profile\Service\SilencioDoSininho;
use App\Profile\UseCase\SalvarPreferenciaDoPerfilUseCase;
use App\Repository\NotificacaoRepository;
use App\Service\NotificacaoService;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\CriaCenarioDoCadeadoFuncionalTrait;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * O sino do topo contava em três consultas (selo, aba "Minhas" e aba "Gestão") em toda página. Agora conta numa só
 * ({@see NotificacaoService::contarNaoLidasDoSino()}). Os números têm de ser os mesmos das três contagens antigas
 * ({@see NotificacaoService::contarNaoLidas()} sem categoria, pessoal e gestão), com os mesmos filtros: escritório,
 * cadeado, silêncio do sininho e cortes da leitura da pasta.
 */
#[CoversClass(NotificacaoService::class)]
#[CoversClass(NotificacaoRepository::class)]
final class SinoNumaContagemTest extends JusPrimeWebTestCase
{
    use CriaCenarioDoCadeadoFuncionalTrait;

    protected function setUp(): void
    {
        $this->prepararCliente();
    }

    #[TestDox('primário: lidas não contam, cada categoria conta a sua, o outro escritório e outra pessoa ficam de fora')]
    public function testPrimarioComLidasENaoLidas(): void
    {
        [$primario, $tenantA] = $this->criarEscritorio();
        [, $tenantB] = $this->criarEscritorio();
        $this->vincular($primario, $tenantB, false, ['modules.expediente.view']);
        $colega = $this->criarColaborador($tenantA, 'Colega');

        $this->aviso($primario, $tenantA, Notificacao::TIPO_TAREFA_CRIADA);
        $this->aviso($primario, $tenantA, Notificacao::TIPO_DJEN_PUBLICACAO);
        $this->aviso($primario, $tenantA, Notificacao::TIPO_TAREFA_CRIADA, lida: true);
        $this->aviso($primario, $tenantA, Notificacao::TIPO_PASTA_PEDIDO_ACESSO);
        $this->aviso($primario, $tenantA, Notificacao::TIPO_SERVICEDESK_NOVO, lida: true);
        $this->aviso($primario, $tenantB, Notificacao::TIPO_TAREFA_CRIADA);
        $this->aviso($primario, $tenantB, Notificacao::TIPO_SERVICEDESK_NOVO);
        $this->aviso($colega, $tenantA, Notificacao::TIPO_SERVICEDESK_NOVO);

        self::assertSame(['total' => 3, 'pessoal' => 2, 'gestao' => 1], $this->comparar($primario, $tenantA));
        self::assertSame(['total' => 2, 'pessoal' => 1, 'gestao' => 1], $this->comparar($primario, $tenantB));
    }

    #[TestDox('sem nenhum aviso: tudo zero, como as três contagens antigas')]
    public function testSemAviso(): void
    {
        [$primario, $tenant] = $this->criarEscritorio();

        self::assertSame(['total' => 0, 'pessoal' => 0, 'gestao' => 0], $this->comparar($primario, $tenant));
    }

    #[TestDox('colaborador fora do cadeado: o aviso da meta e o do link da pasta trancada não contam; a resposta ao pedido conta')]
    public function testColaboradorForaDoCadeado(): void
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $ana = $this->criarColaborador($tenant);
        $pasta = $this->criarPasta($tenant);
        $meta = $this->meta($pasta->getTenant(), $pasta, $primario);
        $this->aviso($ana, $tenant, Notificacao::TIPO_TAREFA_CRIADA, null, $meta);
        $this->aviso($ana, $tenant, Notificacao::TIPO_PASTA_MENCAO_REGISTRO, '/pasta/' . $pasta->getId() . '#pasta-msg-1');
        $this->aviso($ana, $tenant, Notificacao::TIPO_PASTA_ACESSO_DECIDIDO, '/pasta/' . $pasta->getId());
        $this->aviso($ana, $tenant, Notificacao::TIPO_PASTA_PEDIDO_ACESSO, '/pasta/' . $pasta->getId());
        $this->aviso($ana, $tenant, Notificacao::TIPO_TAREFA_CRIADA, '/agenda');
        $this->trancar($pasta, $primario);

        self::assertSame(['total' => 2, 'pessoal' => 2, 'gestao' => 0], $this->comparar($ana, $tenant));
        // O primário vê a pasta trancada: tudo conta.
        $this->aviso($primario, $tenant, Notificacao::TIPO_TAREFA_CRIADA, null, $meta);
        self::assertSame(['total' => 1, 'pessoal' => 1, 'gestao' => 0], $this->comparar($primario, $tenant));
    }

    #[TestDox('colaborador sem leitura da pasta (sem cadeado): o aviso com link da pasta não conta')]
    public function testColaboradorSemLeituraDaPasta(): void
    {
        [, $tenant] = $this->criarEscritorio();
        $beto = $this->criarColaborador($tenant, 'Beto Sem Pasta', ['modules.expediente.view']);
        $x = $this->criarPasta($tenant);
        $this->aviso($beto, $tenant, Notificacao::TIPO_PASTA_MENCAO_REGISTRO, '/pasta/' . $x->getId() . '#detalhes-obs-1');
        $this->aviso($beto, $tenant, Notificacao::TIPO_PASTA_PEDIDO_ACESSO, '/pasta/' . $x->getId());
        $this->aviso($beto, $tenant, Notificacao::TIPO_PASTA_ACESSO_DECIDIDO, '/pasta/' . $x->getId());
        $this->aviso($beto, $tenant, Notificacao::TIPO_SERVICEDESK_NOVO, '/servicedesk');

        self::assertSame(['total' => 2, 'pessoal' => 1, 'gestao' => 1], $this->comparar($beto, $tenant));
    }

    #[TestDox('sininho em silêncio: só os tipos que pedem ação contam, em cada aba')]
    public function testSininhoSilenciado(): void
    {
        [, $tenant] = $this->criarEscritorio();
        $ana = $this->criarColaborador($tenant);
        $this->aviso($ana, $tenant, Notificacao::TIPO_TAREFA_CRIADA);
        $this->aviso($ana, $tenant, Notificacao::TIPO_DJEN_PUBLICACAO);
        $this->aviso($ana, $tenant, Notificacao::TIPO_PASTA_PEDIDO_ACESSO);
        $this->aviso($ana, $tenant, Notificacao::TIPO_SERVICEDESK_NOVO);
        $this->aviso($ana, $tenant, Notificacao::TIPO_DJEN_PUBLICACAO, lida: true);

        self::assertSame(['total' => 4, 'pessoal' => 2, 'gestao' => 2], $this->comparar($ana, $tenant));

        static::getContainer()->get(SalvarPreferenciaDoPerfilUseCase::class)->executar($tenant, $ana, CatalogoDePreferenciasDoPerfil::NOTIFICACOES, false);

        self::assertSame(['total' => 2, 'pessoal' => 1, 'gestao' => 1], $this->comparar($ana, $tenant));
    }

    #[TestDox('sem escritório escolhido: só os avisos do Trabalho Externo dos escritórios com vínculo ativo contam')]
    public function testSemEscritorioEscolhido(): void
    {
        [, $tenantA] = $this->criarEscritorio();
        $ana = $this->criarColaborador($tenantA);
        [, $tenantB] = $this->criarEscritorio();
        $this->vincular($ana, $tenantB, false, ['modules.expediente.view']);
        [, $semVinculo] = $this->criarEscritorio();
        $this->aviso($ana, $tenantA, Notificacao::TIPO_TRABALHO_EXTERNO_DEMANDA_ANDAMENTO, '/trabalho-externo?te=TE-1');
        $this->aviso($ana, $tenantB, Notificacao::TIPO_TRABALHO_EXTERNO_DEMANDA_ANDAMENTO, '/trabalho-externo?te=TE-2', lida: true);
        $this->aviso($ana, $tenantB, Notificacao::TIPO_TAREFA_CRIADA);
        $this->aviso($ana, $tenantA, Notificacao::TIPO_SERVICEDESK_NOVO);
        $this->aviso($ana, $semVinculo, Notificacao::TIPO_TRABALHO_EXTERNO_DEMANDA_ANDAMENTO, '/trabalho-externo?te=TE-3');

        self::assertSame(['total' => 1, 'pessoal' => 1, 'gestao' => 0], $this->comparar($ana, null));
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function consultasDoSino(): iterable
    {
        // Antes: lista, três contagens e a aba Gestão (5); o colaborador soma a leitura dos links de pasta (6).
        yield 'layout, primário' => ['primario', '/perfil', 3];
        yield 'layout, colaborador' => ['colaborador', '/perfil', 4];
        yield 'selo, primário' => ['primario', '/notificacoes/count', 1];
        yield 'selo, colaborador' => ['colaborador', '/notificacoes/count', 2];
    }

    #[DataProvider('consultasDoSino')]
    #[TestDox('$url, como $pessoa: o sino faz $esperadas consultas na tabela de avisos')]
    public function testTravaAsConsultasDoSino(string $pessoa, string $url, int $esperadas): void
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $ana = $this->criarColaborador($tenant);
        $pasta = $this->criarPasta($tenant);
        foreach ([$primario, $ana] as $quem) {
            $this->aviso($quem, $tenant, Notificacao::TIPO_TAREFA_CRIADA, '/pasta/' . $pasta->getId());
            $this->aviso($quem, $tenant, Notificacao::TIPO_SERVICEDESK_NOVO);
        }
        $quem = $pessoa === 'primario' ? $primario : $ana;

        $this->entrar($quem, $tenant);
        $this->em()->clear();
        $this->client->enableProfiler();
        $this->client->request('GET', $url, [], [], str_contains($url, '/count') ? ['HTTP_X-Requested-With' => 'XMLHttpRequest'] : []);
        self::assertResponseIsSuccessful();
        $perfil = $this->client->getProfile();
        self::assertNotFalse($perfil, 'O profiler não está ligado no ambiente de teste.');
        $db = $perfil->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $db);

        $doSino = 0;
        foreach ($db->getQueries() as $consultas) {
            foreach ($consultas as $consulta) {
                if (preg_match('/\bFROM\s+notificacao\b/i', (string) $consulta['sql']) === 1) {
                    ++$doSino;
                }
            }
        }

        self::assertSame($esperadas, $doSino, sprintf('%s, como %s, fez %d consultas na tabela de avisos (esperadas %d).', $url, $pessoa, $doSino, $esperadas));
    }

    /**
     * Roda as três contagens antigas e a nova, no escritório informado (nulo = sem escritório na sessão), confere
     * que dão o mesmo e devolve a nova.
     *
     * @return array{total: int, pessoal: int, gestao: int}
     */
    private function comparar(User $quem, ?Tenant $tenant): array
    {
        $container = static::getContainer();
        $sessao = new Session(new MockArraySessionStorage());
        if ($tenant !== null) {
            $sessao->set('current_tenant_id', (int) $tenant->getId());
        }
        $requisicao = new Request();
        $requisicao->setSession($sessao);
        $pilha = $container->get(RequestStack::class);
        $pilha->push($requisicao);

        try {
            $container->get(SilencioDoSininho::class)->reset();
            $container->get(LeituraDasPastasDosAvisos::class)->reset();
            $container->get(NivelNoCadeado::class)->reset();
            $servico = $container->get(NotificacaoService::class);

            $antigas = [
                'total'   => $servico->contarNaoLidas($quem),
                'pessoal' => $servico->contarNaoLidas($quem, Notificacao::CATEGORIA_PESSOAL),
                'gestao'  => $servico->contarNaoLidas($quem, Notificacao::CATEGORIA_GESTAO),
            ];
            $nova = $servico->contarNaoLidasDoSino($quem);
        } finally {
            $pilha->pop();
        }

        self::assertSame($antigas, $nova, 'a contagem agrupada tem de dar os mesmos números das três contagens antigas');

        return $nova;
    }

    private function meta(Tenant $tenant, Pasta $pasta, User $criador): Tarefa
    {
        $meta = new Tarefa();
        $meta->setTitulo('Meta da pasta trancada');
        $meta->setDescricao('Descrição');
        $meta->setPasta($pasta);
        $meta->setTenant($tenant);
        $meta->setCriadoPor($criador);
        $this->em()->persist($meta);
        $this->em()->flush();

        return $meta;
    }

    private function aviso(User $para, Tenant $tenant, string $tipo, ?string $url = null, ?Tarefa $meta = null, bool $lida = false): void
    {
        $n = new Notificacao();
        $n->setUsuario($para);
        $n->setTenant($tenant);
        $n->setTipo($tipo);
        $n->setTitulo('Aviso ' . $tipo);
        $n->setMensagem('Texto ' . $tipo);
        $n->setUrl($url);
        $n->setTarefa($meta);
        $n->setLida($lida);
        $this->em()->persist($n);
        $this->em()->flush();
    }
}
