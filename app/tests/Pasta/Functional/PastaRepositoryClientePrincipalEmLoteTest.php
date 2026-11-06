<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Cliente\Entity\ClientePF;
use App\Cliente\Entity\ClientePJ;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Expediente\Entity\Marcador;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaFavorita;
use App\Pasta\Entity\PrioridadePasta;
use App\Pasta\Repository\PastaRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * As listagens paginadas de pasta (`findByFilters`, `findPorMarcador`, `findByFiltrosEMarcador`) buscam os ids da
 * página e depois as pastas com o cliente principal junto. Trava duas coisas: o resultado é o mesmo da busca de
 * antes (mesmas pastas, mesma ordem, mesmas páginas, mesmo total) e o cliente principal, PF ou PJ, não custa
 * uma consulta por pasta.
 *
 * A "busca de antes" é montada aqui com os mesmos construtores privados do repositório (filtros, GROUP BY e
 * ordenação), devolvendo a entidade inteira na mesma consulta, como o repositório fazia.
 */
#[CoversClass(PastaRepository::class)]
final class PastaRepositoryClientePrincipalEmLoteTest extends KernelTestCase
{
    private const POR_PAGINA = 3;

    private EntityManagerInterface $em;
    private PastaRepository $repo;
    private Tenant $tenant;
    private User $eu;
    private Marcador $topo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em   = static::getContainer()->get(EntityManagerInterface::class);
        $this->repo = static::getContainer()->get(PastaRepository::class);
    }

    #[TestDox('findByFilters devolve as mesmas pastas, na mesma ordem e nas mesmas páginas, com os filtros dos chamadores')]
    public function testFindByFiltersEquivaleABuscaDeAntes(): void
    {
        $this->montarAcervo();
        $responsavel = ['responsavel' => (string) $this->eu->getId()];

        $casos = [
            'expediente: padrão com favoritos'      => [[], '', 'desc', $this->eu],
            'agente e chat: NUP crescente'          => [[], 'nup', 'asc', null],
            'busca pelo nome do cliente'            => [['busca' => 'Equivalência'], 'nup', 'asc', null],
            'minhas demandas: por cliente'          => [$responsavel, 'cliente', 'asc', $this->eu],
            'por cliente decrescente'               => [[], 'cliente', 'desc', null],
            'por réu'                               => [[], 'reu', 'asc', null],
            'por ação'                              => [[], 'acao', 'desc', null],
            'por marcadores'                        => [[], 'marcadores', 'asc', null],
            'por responsável'                       => [[], 'responsavel', 'asc', $this->eu],
            'por prioridade'                        => [[], 'prioridade', 'desc', null],
            'por situação'                          => [['prioridade' => 'urgente'], 'situacao', 'asc', null],
        ];

        foreach ($casos as $nome => [$filtros, $ordenar, $direcao, $favoritosDe]) {
            $referencia = $this->referencia('buildQbByFilters', [$filtros, $this->tenant, null], $ordenar, $direcao, $favoritosDe);
            self::assertNotSame([], $referencia, $nome . ': o cenário precisa de pastas.');
            self::assertSame(count($referencia), $this->repo->countByFilters($filtros, $this->tenant), $nome . ': total');

            $this->conferirPaginas(
                $nome,
                $referencia,
                fn (int $pagina, int $porPagina): array => $this->repo->findByFilters($filtros, $this->tenant, $pagina, $porPagina, $ordenar, $direcao, $favoritosDe),
            );
        }
    }

    #[TestDox('o painel de marcador devolve as mesmas pastas, na mesma ordem e nas mesmas páginas')]
    public function testPainelDeMarcadorEquivaleABuscaDeAntes(): void
    {
        $this->montarAcervo();

        $semFiltro = $this->referencia('buildQbPorMarcador', [[], $this->topo, $this->tenant, null], '', 'desc', $this->eu);
        self::assertGreaterThan(self::POR_PAGINA, count($semFiltro));
        self::assertSame(count($semFiltro), $this->repo->countPorMarcador($this->topo, $this->tenant));
        $this->conferirPaginas(
            'marcador sem filtro',
            $semFiltro,
            fn (int $pagina, int $porPagina): array => $this->repo->findPorMarcador($this->topo, $this->tenant, $pagina, $porPagina, '', 'desc', $this->eu),
        );

        $filtros   = ['busca' => 'Equivalência'];
        $comFiltro = $this->referencia('buildQbPorMarcador', [$filtros, $this->topo, $this->tenant, null], 'cliente', 'asc', null);
        self::assertNotSame([], $comFiltro);
        self::assertSame(count($comFiltro), $this->repo->countByFiltrosEMarcador($filtros, $this->topo, $this->tenant));
        $this->conferirPaginas(
            'marcador com busca',
            $comFiltro,
            fn (int $pagina, int $porPagina): array => $this->repo->findByFiltrosEMarcador($filtros, $this->topo, $this->tenant, $pagina, $porPagina, 'cliente', 'asc'),
        );
    }

    #[TestDox('o cliente principal PF e PJ vem junto das pastas: duas consultas com 2 ou com 6 pastas')]
    public function testClientePrincipalPfEPjNaoConsultaPorPasta(): void
    {
        $this->montarAcervo();

        $doisIds = array_slice($this->referencia('buildQbByFilters', [[], $this->tenant, null], 'nup', 'asc', null), 0, 2);
        self::assertCount(2, $doisIds);

        $comDuas = $this->consultasDaListagem(2);
        $comSeis = $this->consultasDaListagem(6);

        self::assertSame(2, $comDuas['consultas'], 'Página de 2 pastas: ids da página e as pastas com o cliente.');
        self::assertSame($comDuas['consultas'], $comSeis['consultas'], 'O cliente principal voltou a ser consultado pasta a pasta.');

        // O cadastro grava o nome em caixa alta.
        $clientes = [];
        foreach ($comSeis['pastas'] as $pasta) {
            $clientes[$pasta->getNup()] = $this->nomeDoClientePrincipal($pasta);
        }
        self::assertSame([
            'EQ-01' => 'PF EQUIVALÊNCIA ANA',
            'EQ-02' => 'PJ EQUIVALÊNCIA LTDA',
            'EQ-03' => null,
            'EQ-04' => 'PF EQUIVALÊNCIA BIA',
            'EQ-05' => 'PJ ZETA COMÉRCIO',
            'EQ-06' => null,
        ], $clientes);
    }

    /**
     * Sete pastas no escritório (PF, PJ, sem cliente; marcadores de topo e filho; um favorito; prioridades,
     * situações e responsáveis variados) e uma pasta de outro escritório que nunca pode aparecer.
     */
    private function montarAcervo(): void
    {
        $this->tenant = $this->criarTenant('EQ');
        $this->eu     = $this->criarUser('Eu Mesmo');
        $outro        = $this->criarUser('Outra Pessoa');
        $this->topo   = new Marcador('Topo ' . uniqid(), $this->tenant, $this->eu);
        $filho        = new Marcador('Filho ' . uniqid(), $this->tenant, $this->eu, $this->topo);
        $this->em->persist($this->topo);
        $this->em->persist($filho);

        $p1 = $this->criarPasta($this->tenant, 'EQ-01', $this->eu);
        $p1->addCliente($this->criarClientePF($this->tenant, 'PF Equivalência Ana'));
        $p1->addMarcador($this->topo);

        $p2 = $this->criarPasta($this->tenant, 'EQ-02', $outro);
        $p2->addCliente($this->criarClientePJ($this->tenant, 'PJ Equivalência Ltda'));
        $p2->addMarcador($filho);
        $p2->setPrioridade(PrioridadePasta::Urgente);

        $p3 = $this->criarPasta($this->tenant, 'EQ-03', $this->eu);
        $p3->setNomeCliente('Texto Livre Equivalência');
        $p3->setNomeAcao('Ação à mão');
        $p3->addMarcador($this->topo);

        $p4 = $this->criarPasta($this->tenant, 'EQ-04', null);
        $p4->addCliente($this->criarClientePF($this->tenant, 'PF Equivalência Bia'));
        $p4->addCliente($this->criarClientePJ($this->tenant, 'PJ Segundo Cliente'));
        $p4->addMarcador($this->topo);
        $p4->addMarcador($filho);
        $p4->setPrioridade(PrioridadePasta::Urgente);
        $p4->setSituacao(Pasta::SITUACAO_SUSPENSA);

        $p5 = $this->criarPasta($this->tenant, 'EQ-05', $this->eu);
        $p5->addCliente($this->criarClientePJ($this->tenant, 'PJ Zeta Comércio'));
        $p5->addMarcador($filho);
        $p5->setPrioridade(PrioridadePasta::Prioridade);

        $p6 = $this->criarPasta($this->tenant, 'EQ-06', $outro);
        $p6->setNomeCliente('Outro Texto Equivalência');

        $p7 = $this->criarPasta($this->tenant, 'EQ-07', $this->eu);
        $p7->addCliente($this->criarClientePF($this->tenant, 'PF Equivalência Caio'));
        $p7->addMarcador($this->topo);

        $this->em->persist(new PastaFavorita($this->tenant, $this->eu, $p5));
        $this->em->persist(new PastaFavorita($this->tenant, $this->eu, $p2));

        $alheio = $this->criarTenant('EQ fora');
        $fora   = $this->criarPasta($alheio, 'EQ-99', $this->eu);
        $fora->addCliente($this->criarClientePF($alheio, 'PF Equivalência De Fora'));

        $this->em->flush();
    }

    /**
     * A busca de antes: os mesmos filtros, GROUP BY e ordenação, com a pasta inteira na mesma consulta e sem página.
     *
     * @param list<mixed> $argumentos
     * @return list<int>
     */
    private function referencia(string $construtor, array $argumentos, string $ordenar, string $direcao, ?User $favoritosDe): array
    {
        $this->em->clear();
        $classe = new \ReflectionClass(PastaRepository::class);
        /** @var QueryBuilder $qb */
        $qb = $classe->getMethod($construtor)->invokeArgs($this->repo, $this->recarregar($argumentos));
        $qb->groupBy('p.id');
        $classe->getMethod('aplicarOrdenacao')->invoke($this->repo, $qb, $ordenar, $direcao, $this->recarregarUm($favoritosDe));

        return array_map(static fn (Pasta $p): int => (int) $p->getId(), $qb->getQuery()->getResult());
    }

    /**
     * @param list<int> $referencia
     * @param callable(int, int): Pasta[] $listar
     */
    private function conferirPaginas(string $nome, array $referencia, callable $listar): void
    {
        $paginas = (int) ceil(count($referencia) / self::POR_PAGINA);
        for ($pagina = 1; $pagina <= $paginas; ++$pagina) {
            $this->em->clear();
            $ids = array_map(static fn (Pasta $p): int => (int) $p->getId(), $listar($pagina, self::POR_PAGINA));
            self::assertSame(
                array_slice($referencia, ($pagina - 1) * self::POR_PAGINA, self::POR_PAGINA),
                $ids,
                sprintf('%s: página %d', $nome, $pagina),
            );
        }

        $this->em->clear();
        $tudo = array_map(static fn (Pasta $p): int => (int) $p->getId(), $listar(1, max(1, count($referencia))));
        self::assertSame($referencia, $tudo, $nome . ': todas de uma vez (exportação)');
    }

    /** @return array{consultas: int, pastas: Pasta[]} */
    private function consultasDaListagem(int $porPagina): array
    {
        $this->em->clear();
        $tenant  = $this->em->find(Tenant::class, $this->tenant->getId());
        $coletor = static::getContainer()->get('doctrine.debug_data_holder');
        $coletor->reset();

        $pastas = $this->repo->findByFilters([], $tenant, 1, $porPagina, 'nup', 'asc');

        return ['consultas' => count($coletor->getData()['default'] ?? []), 'pastas' => $pastas];
    }

    private function nomeDoClientePrincipal(Pasta $pasta): ?string
    {
        $cliente = $pasta->getClientePrincipal();

        return match (true) {
            $cliente instanceof ClientePF => $cliente->getNomeCompleto(),
            $cliente instanceof ClientePJ => $cliente->getRazaoSocial(),
            default                       => null,
        };
    }

    /**
     * Depois do `clear()`, as entidades dos argumentos voltam do banco para a consulta usar as gerenciadas.
     *
     * @param list<mixed> $argumentos
     * @return list<mixed>
     */
    private function recarregar(array $argumentos): array
    {
        return array_map(fn (mixed $a): mixed => is_object($a) ? $this->recarregarUm($a) : $a, $argumentos);
    }

    /**
     * @template T of object
     * @param T|null $entidade
     * @return T|null
     */
    private function recarregarUm(?object $entidade): ?object
    {
        if ($entidade === null) {
            return null;
        }

        return $this->em->find($entidade::class, $entidade->getId());
    }

    private function criarTenant(string $nome): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant ' . $nome . ' ' . uniqid());
        $this->em->persist($tenant);

        return $tenant;
    }

    private function criarUser(string $nome): User
    {
        $user = new User();
        $user->setEmail('eq_' . uniqid() . '@test.com');
        $user->setFullName($nome);
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('dummy');
        $this->em->persist($user);

        return $user;
    }

    private function criarPasta(Tenant $tenant, string $nup, ?User $responsavel): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup($nup);
        $pasta->setTenant($tenant);
        $pasta->setResponsavel($responsavel);
        $this->em->persist($pasta);

        return $pasta;
    }

    private function criarClientePF(Tenant $tenant, string $nome): ClientePF
    {
        $cliente = new ClientePF();
        $cliente->setEmail('eq_pf_' . uniqid() . '@test.com');
        $cliente->setCep('01310-100');
        $cliente->setEndereco('Av. Paulista, 1000');
        $cliente->setCidade('São Paulo');
        $cliente->setEstado('SP');
        $cliente->setCpf(substr(str_replace('.', '', uniqid('', true)), 0, 14));
        $cliente->setRg('00.000.000-0');
        $cliente->setRgOrgaoExpedidor('SSP');
        $cliente->setNomeCompleto($nome);
        $cliente->setTenant($tenant);
        $this->em->persist($cliente);

        return $cliente;
    }

    private function criarClientePJ(Tenant $tenant, string $razaoSocial): ClientePJ
    {
        $cliente = new ClientePJ();
        $cliente->setEmail('eq_pj_' . uniqid() . '@test.com');
        $cliente->setCep('01310-100');
        $cliente->setEndereco('Av. Paulista, 2000');
        $cliente->setCidade('São Paulo');
        $cliente->setEstado('SP');
        $cliente->setCnpj(substr(str_replace('.', '', uniqid('', true)), 0, 14));
        $cliente->setRazaoSocial($razaoSocial);
        $cliente->setEnderecSede('Av. Paulista, 2000, São Paulo');
        $cliente->setRepresentanteLegal('Representante EQ');
        $cliente->setRepresentanteCpf('00000000000000');
        $cliente->setRepresentanteRg('00.000.000-0');
        $cliente->setRepresentanteCargo('Diretor');
        $cliente->setTenant($tenant);
        $this->em->persist($cliente);

        return $cliente;
    }
}
