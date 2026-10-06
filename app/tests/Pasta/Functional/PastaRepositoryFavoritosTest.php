<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Cliente\Entity\ClientePF;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Expediente\Entity\Marcador;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaFavorita;
use App\Pasta\Repository\PastaFavoritaRepository;
use App\Pasta\Repository\PastaRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Favoritas sobem para o topo da listagem do Expediente (`PastaRepository::aplicarOrdenacao`
 * com `$favoritosDe`), e só para quem as fixou.
 *
 * O caso que mais importa é o NEGATIVO: sem favorito nenhum — ou com favoritos só de colegas,
 * ou de outro escritório — a ordem tem de ser IDÊNTICA à de antes, em todos os ramos de ordenação.
 */
#[CoversClass(PastaRepository::class)]
#[CoversClass(PastaFavoritaRepository::class)]
final class PastaRepositoryFavoritosTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PastaRepository $repo;
    private PastaFavoritaRepository $favoritas;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em        = static::getContainer()->get(EntityManagerInterface::class);
        $this->repo      = static::getContainer()->get(PastaRepository::class);
        $this->favoritas = static::getContainer()->get(PastaFavoritaRepository::class);
    }

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant FAV ' . uniqid());
        $this->em->persist($tenant);

        return $tenant;
    }

    private function criarUser(string $nome): User
    {
        $user = new User();
        $user->setEmail('fav_' . uniqid() . '@test.com');
        $user->setFullName($nome);
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('dummy');
        $this->em->persist($user);

        return $user;
    }

    private function criarPasta(Tenant $tenant, string $nup, ?User $responsavel = null): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup($nup);
        $pasta->setTenant($tenant);
        if ($responsavel !== null) {
            $pasta->setResponsavel($responsavel);
        }
        $this->em->persist($pasta);

        return $pasta;
    }

    private function criarClientePF(Tenant $tenant, string $nome): ClientePF
    {
        $cliente = new ClientePF();
        $cliente->setEmail('fav_' . uniqid() . '@test.com');
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

    private function favoritar(Tenant $tenant, User $user, Pasta $pasta): void
    {
        $this->em->persist(new PastaFavorita($tenant, $user, $pasta));
    }

    /** @return list<string> */
    private function nups(Tenant $tenant, string $ordenar = '', string $direcao = 'desc', ?User $favoritosDe = null): array
    {
        return array_map(
            static fn (Pasta $p): string => (string) $p->getNup(),
            $this->repo->findByFilters([], $tenant, 1, 50, $ordenar, $direcao, $favoritosDe),
        );
    }

    /**
     * Acervo com o que cada ramo de ordenação usa: responsável, clientes (dois numa pasta, para o
     * JOIN de coleção multiplicar linhas) e números que empatam no prefixo.
     *
     * @return array{Tenant, User, array<string, Pasta>}
     */
    private function acervo(): array
    {
        $tenant = $this->criarTenant();
        $eu     = $this->criarUser('Eu Mesmo');
        $resp   = $this->criarUser('Bruno Responsável');

        $pastas = [
            '10'  => $this->criarPasta($tenant, '10', $resp),
            '20'  => $this->criarPasta($tenant, '20'),
            '30'  => $this->criarPasta($tenant, '30', $eu),
            '30A' => $this->criarPasta($tenant, '30A'),
            '40'  => $this->criarPasta($tenant, '40'),
        ];
        $pastas['20']->addCliente($this->criarClientePF($tenant, 'Ana Cliente'));
        $pastas['20']->addCliente($this->criarClientePF($tenant, 'Zeca Cliente'));
        $pastas['40']->addCliente($this->criarClientePF($tenant, 'Carla Cliente'));
        $this->em->flush();

        return [$tenant, $eu, $pastas];
    }

    /** @return iterable<string, array{string, string}> */
    public static function ramosDeOrdenacao(): iterable
    {
        yield 'padrão'             => ['', 'desc'];
        yield 'nup asc'            => ['nup', 'asc'];
        yield 'nup desc'           => ['nup', 'desc'];
        yield 'cliente asc'        => ['cliente', 'asc'];
        yield 'responsável asc'    => ['responsavel', 'asc'];
        yield 'prioridade desc'    => ['prioridade', 'desc'];
        yield 'situação asc'       => ['situacao', 'asc'];
        yield 'ação asc'           => ['acao', 'asc'];
        yield 'marcadores asc'     => ['marcadores', 'asc'];
    }

    #[DataProvider('ramosDeOrdenacao')]
    #[TestDox('Sem favorito nenhum, passar o usuário não muda a ordem ($ordenar $direcao)')]
    public function testSemFavoritoAOrdemEhIdentica(string $ordenar, string $direcao): void
    {
        [$tenant, $eu] = $this->acervo();

        self::assertSame(
            $this->nups($tenant, $ordenar, $direcao),
            $this->nups($tenant, $ordenar, $direcao, $eu),
        );
    }

    #[DataProvider('ramosDeOrdenacao')]
    #[TestDox('Favoritos só de um COLEGA não mudam a minha ordem ($ordenar $direcao)')]
    public function testFavoritoDeOutroUsuarioNaoMudaMinhaOrdem(string $ordenar, string $direcao): void
    {
        [$tenant, $eu, $pastas] = $this->acervo();
        $colega                 = $this->criarUser('Colega');
        $this->favoritar($tenant, $colega, $pastas['10']);
        $this->favoritar($tenant, $colega, $pastas['20']);
        $this->em->flush();

        self::assertSame(
            $this->nups($tenant, $ordenar, $direcao),
            $this->nups($tenant, $ordenar, $direcao, $eu),
        );
    }

    #[DataProvider('ramosDeOrdenacao')]
    #[TestDox('A favorita sobe para o topo e o resto mantém a ordem escolhida ($ordenar $direcao)')]
    public function testFavoritaSobeParaOTopo(string $ordenar, string $direcao): void
    {
        [$tenant, $eu, $pastas] = $this->acervo();
        $semFavorito            = $this->nups($tenant, $ordenar, $direcao);

        // A pasta 20 tem DOIS clientes: no ramo `cliente` o JOIN de coleção duplica a linha dela
        // antes do GROUP BY — é o caso que um COUNT do favorito ordenaria errado.
        $this->favoritar($tenant, $eu, $pastas['20']);
        $this->em->flush();

        $esperado = array_values(array_merge(['20'], array_diff($semFavorito, ['20'])));
        self::assertSame($esperado, $this->nups($tenant, $ordenar, $direcao, $eu));
    }

    #[TestDox('Entre várias favoritas vale a ordem escolhida; abaixo delas, as demais na mesma ordem')]
    public function testVariasFavoritasRespeitamAOrdemEscolhida(): void
    {
        [$tenant, $eu, $pastas] = $this->acervo();
        $this->favoritar($tenant, $eu, $pastas['10']);
        $this->favoritar($tenant, $eu, $pastas['30']);
        $this->em->flush();

        self::assertSame(['30', '10', '40', '30A', '20'], $this->nups($tenant, '', 'desc', $eu));
        self::assertSame(['10', '30', '20', '30A', '40'], $this->nups($tenant, 'nup', 'asc', $eu));
    }

    #[TestDox('Sem o usuário (outras listagens), a favorita não sobe: o critério só existe com $favoritosDe')]
    public function testSemUsuarioNadaSobe(): void
    {
        [$tenant, $eu, $pastas] = $this->acervo();
        $antes                  = $this->nups($tenant);
        $this->favoritar($tenant, $eu, $pastas['10']);
        $this->em->flush();

        self::assertSame($antes, $this->nups($tenant));
    }

    #[TestDox('Outro escritório: meu favorito lá não entra na listagem daqui, nem a muda')]
    public function testFavoritoEmOutroEscritorio(): void
    {
        [$tenantA, $eu] = $this->acervo();
        $tenantB        = $this->criarTenant();
        $pastaB         = $this->criarPasta($tenantB, '99');
        $this->favoritar($tenantB, $eu, $pastaB);
        $this->em->flush();

        $comUsuario = $this->nups($tenantA, '', 'desc', $eu);
        self::assertSame($this->nups($tenantA), $comUsuario);
        self::assertNotContains('99', $comUsuario, 'a pasta do outro escritório não vaza pela junção do favorito');
        self::assertSame(['99'], $this->nups($tenantB, '', 'desc', $eu));
    }

    #[TestDox('Painel de marcador: a favorita sobe também, e só entre as pastas do marcador')]
    public function testPainelDeMarcador(): void
    {
        [$tenant, $eu, $pastas] = $this->acervo();
        $marcador               = new Marcador('Trabalhista', $tenant, $eu);
        $this->em->persist($marcador);
        $pastas['10']->addMarcador($marcador);
        $pastas['30']->addMarcador($marcador);
        $this->favoritar($tenant, $eu, $pastas['10']);
        $this->favoritar($tenant, $eu, $pastas['40']); // favorita FORA do marcador
        $this->em->flush();

        $nups = array_map(
            static fn (Pasta $p): string => (string) $p->getNup(),
            $this->repo->findPorMarcador($marcador, $tenant, 1, 50, '', 'desc', $eu),
        );

        self::assertSame(['10', '30'], $nups);
    }

    #[TestDox('idsDasPastasFavoritas devolve só as MINHAS, só deste escritório')]
    public function testIdsDasPastasFavoritas(): void
    {
        [$tenantA, $eu, $pastas] = $this->acervo();
        $colega                  = $this->criarUser('Colega');
        $tenantB                 = $this->criarTenant();
        $pastaB                  = $this->criarPasta($tenantB, '99');
        $this->favoritar($tenantA, $eu, $pastas['10']);
        $this->favoritar($tenantA, $eu, $pastas['30A']);
        $this->favoritar($tenantA, $colega, $pastas['20']);
        $this->favoritar($tenantB, $eu, $pastaB);
        $this->em->flush();

        $ids = $this->favoritas->idsDasPastasFavoritas($eu, $tenantA);
        ksort($ids);
        $esperado = [(int) $pastas['10']->getId() => true, (int) $pastas['30A']->getId() => true];
        ksort($esperado);

        self::assertSame($esperado, $ids);
        self::assertSame([], $this->favoritas->idsDasPastasFavoritas($colega, $tenantB));
        self::assertTrue($this->favoritas->ehFavorita($pastas['10'], $eu, $tenantA));
        self::assertFalse($this->favoritas->ehFavorita($pastas['20'], $eu, $tenantA), 'a do colega não é minha');
    }
}
