<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Cliente\Entity\ClientePF;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Expediente\Entity\Marcador;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PrioridadePasta;
use App\Pasta\Repository\PastaRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A ordenação por coluna é feita no banco (não só na página atual): a listagem
 * é ordenada e só então paginada, então clicar num cabeçalho ordena o acervo inteiro.
 */
#[CoversClass(PastaRepository::class)]
final class PastaRepositoryOrdenacaoTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PastaRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em   = static::getContainer()->get(EntityManagerInterface::class);
        $this->repo = static::getContainer()->get(PastaRepository::class);
    }

    /** @return string[] */
    private function nupsOrdenados(Tenant $t, string $ordenar, string $direcao): array
    {
        return array_map(fn(Pasta $p) => $p->getNup(), $this->repo->findByFilters([], $t, 1, 50, $ordenar, $direcao));
    }

    /** @param string[] $lista */
    private function vemAntes(string $a, string $b, array $lista): bool
    {
        $ia = array_search($a, $lista, true);
        $ib = array_search($b, $lista, true);

        return $ia !== false && $ib !== false && $ia < $ib;
    }

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant ORD ' . uniqid());
        $this->em->persist($tenant);

        return $tenant;
    }

    private function criarPasta(Tenant $tenant, string $nup): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup($nup);
        $pasta->setTenant($tenant);
        $this->em->persist($pasta);

        return $pasta;
    }

    private function criarUser(string $nome): User
    {
        $user = new User();
        $user->setEmail('ord_' . uniqid() . '@test.com');
        $user->setFullName($nome);
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('dummy');
        $this->em->persist($user);

        return $user;
    }

    private function criarClientePF(Tenant $tenant, string $nome): ClientePF
    {
        $cpf = substr(str_replace('.', '', uniqid('', true)), 0, 14);
        $cliente = new ClientePF();
        $cliente->setEmail('ord_' . uniqid() . '@test.com');
        $cliente->setCep('01310-100');
        $cliente->setEndereco('Av. Paulista, 1000');
        $cliente->setCidade('São Paulo');
        $cliente->setEstado('SP');
        $cliente->setCpf($cpf);
        $cliente->setRg('00.000.000-0');
        $cliente->setRgOrgaoExpedidor('SSP');
        $cliente->setNomeCompleto($nome);
        $cliente->setTenant($tenant);
        $this->em->persist($cliente);

        return $cliente;
    }

    #[TestDox('ordena por NUP numérico (asc e desc), não lexicográfico')]
    public function testOrdenaPorNup(): void
    {
        $t = $this->criarTenant();
        $p50  = $this->criarPasta($t, 'ORDNUP-50');   // guardado em maiúsculas
        $p100 = $this->criarPasta($t, 'ORDNUP-100');
        $p200 = $this->criarPasta($t, 'ORDNUP-200');
        $this->em->flush();

        // Como o NUP guarda o sufixo textual, uso pastas com NUP puramente numérico:
        $n50  = $this->criarPasta($t, '50');
        $n100 = $this->criarPasta($t, '100');
        $n200 = $this->criarPasta($t, '200');
        $this->em->flush();

        $asc = $this->nupsOrdenados($t, 'nup', 'asc');
        self::assertTrue($this->vemAntes('50', '100', $asc));
        self::assertTrue($this->vemAntes('100', '200', $asc));

        $desc = $this->nupsOrdenados($t, 'nup', 'desc');
        self::assertTrue($this->vemAntes('200', '100', $desc));
        self::assertTrue($this->vemAntes('100', '50', $desc));
    }

    #[TestDox('findByFilters ordena NUP com sufixo de letra de forma natural (10A/10B junto do 10, não no fim)')]
    public function testOrdenacaoNaturalComSufixoDeLetra(): void
    {
        $t = $this->criarTenant();
        foreach (['9', '10', '10A', '10B', '11'] as $nup) {
            $this->criarPasta($t, $nup);
        }
        $this->em->flush();

        // Direção padrão (DESC): número maior primeiro; dentro do mesmo número, a letra
        // fica adjacente (10B > 10A > 10), nunca jogada para o fim da lista.
        $nups = $this->nupsOrdenados($t, '', 'desc');
        self::assertSame(['11', '10B', '10A', '10', '9'], $nups);
    }

    #[TestDox('ordena por prioridade (desc = urgente primeiro)')]
    public function testOrdenaPorPrioridade(): void
    {
        $t = $this->criarTenant();
        $normal = $this->criarPasta($t, 'PRI-NOR');
        $normal->setPrioridade(PrioridadePasta::Normal);
        $urgente = $this->criarPasta($t, 'PRI-URG');
        $urgente->setPrioridade(PrioridadePasta::Urgente);
        $this->em->flush();

        $desc = $this->nupsOrdenados($t, 'prioridade', 'desc');
        self::assertTrue($this->vemAntes($urgente->getNup(), $normal->getNup(), $desc));

        $asc = $this->nupsOrdenados($t, 'prioridade', 'asc');
        self::assertTrue($this->vemAntes($normal->getNup(), $urgente->getNup(), $asc));
    }

    #[TestDox('ordena por responsável (nome, asc)')]
    public function testOrdenaPorResponsavel(): void
    {
        $t = $this->criarTenant();
        $ana   = $this->criarPasta($t, 'RESP-ANA');
        $ana->setResponsavel($this->criarUser('Ana Ordenacao'));
        $bruno = $this->criarPasta($t, 'RESP-BRU');
        $bruno->setResponsavel($this->criarUser('Bruno Ordenacao'));
        $this->em->flush();

        $asc = $this->nupsOrdenados($t, 'responsavel', 'asc');
        self::assertTrue($this->vemAntes($ana->getNup(), $bruno->getNup(), $asc));
    }

    #[TestDox('ordena por cliente vinculado (nome, asc)')]
    public function testOrdenaPorCliente(): void
    {
        $t = $this->criarTenant();
        $pa = $this->criarPasta($t, 'CLI-ORD-A');
        $pa->addCliente($this->criarClientePF($t, 'Aaa Cliente Ordenacao'));
        $pz = $this->criarPasta($t, 'CLI-ORD-Z');
        $pz->addCliente($this->criarClientePF($t, 'Zzz Cliente Ordenacao'));
        $this->em->flush();

        $asc = $this->nupsOrdenados($t, 'cliente', 'asc');
        self::assertTrue($this->vemAntes($pa->getNup(), $pz->getNup(), $asc));
    }

    #[TestDox('ordena por cliente seguindo o NOME DA PASTA, não o do cliente cadastrado')]
    public function testOrdenaPorClientePrefereONomeDaPasta(): void
    {
        $t = $this->criarTenant();

        // Cruzado de propósito: o nome da PASTA e o do CLIENTE CADASTRADO ordenam em sentidos
        // opostos. É o único arranjo que distingue as duas precedências — com pastas que só têm
        // cliente cadastrado (como no teste acima) as duas dão o mesmo resultado.
        $primeira = $this->criarPasta($t, 'CLI-PREC-A');
        $primeira->setNomeCliente('Aaa Pasta Precedencia');
        $primeira->addCliente($this->criarClientePF($t, 'Zzz Cadastro Precedencia'));

        $segunda = $this->criarPasta($t, 'CLI-PREC-Z');
        $segunda->setNomeCliente('Zzz Pasta Precedencia');
        $segunda->addCliente($this->criarClientePF($t, 'Aaa Cadastro Precedencia'));

        $this->em->flush();

        $asc = $this->nupsOrdenados($t, 'cliente', 'asc');

        self::assertTrue(
            $this->vemAntes($primeira->getNup(), $segunda->getNup(), $asc),
            'a ordem tem de seguir a coluna que o usuário vê, que é o nome da pasta',
        );
    }

    #[TestDox('ordena por nome da ação (asc)')]
    public function testOrdenaPorAcao(): void
    {
        $t = $this->criarTenant();
        $pa = $this->criarPasta($t, 'ACAO-ORD-A');
        $pa->setNomeAcao('Aaa Acao Ordenacao');
        $pz = $this->criarPasta($t, 'ACAO-ORD-Z');
        $pz->setNomeAcao('Zzz Acao Ordenacao');
        $this->em->flush();

        $asc = $this->nupsOrdenados($t, 'acao', 'asc');
        self::assertTrue($this->vemAntes($pa->getNup(), $pz->getNup(), $asc));
    }

    #[TestDox('ordena por situação (asc = arquivado antes de ativo)')]
    public function testOrdenaPorSituacao(): void
    {
        $t = $this->criarTenant();
        $ativo = $this->criarPasta($t, 'SIT-ATI');
        $ativo->setSituacao(Pasta::SITUACAO_ATIVA);
        $arquivado = $this->criarPasta($t, 'SIT-ARQ');
        $arquivado->setSituacao(Pasta::SITUACAO_ARQUIVADA);
        $this->em->flush();

        $asc = $this->nupsOrdenados($t, 'situacao', 'asc');
        self::assertTrue($this->vemAntes($arquivado->getNup(), $ativo->getNup(), $asc));
    }

    #[TestDox('ordenar por cliente não vaza pastas de outro tenant (JOIN de ordenação isolado)')]
    public function testOrdenacaoNaoVazaOutroTenant(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();

        $pa = $this->criarPasta($tenantA, 'ORDISO-A');
        $pa->addCliente($this->criarClientePF($tenantA, 'Aaa Iso Ordenacao'));
        $pb = $this->criarPasta($tenantB, 'ORDISO-B');
        $pb->addCliente($this->criarClientePF($tenantB, 'Aaa Iso Ordenacao'));
        $this->em->flush();

        // Mesmo ordenando por cliente (que faz LEFT JOIN em pasta_cliente/PF), o tenant
        // ativo não pode enxergar a pasta homônima do outro escritório.
        $nups = $this->nupsOrdenados($tenantA, 'cliente', 'asc');

        self::assertContains($pa->getNup(), $nups);
        self::assertNotContains($pb->getNup(), $nups);
    }

    #[TestDox('ordena por marcador (primeiro nome, asc)')]
    public function testOrdenaPorMarcadores(): void
    {
        $t = $this->criarTenant();
        $autor = $this->criarUser('Autor Marcador');
        $pAlpha = $this->criarPasta($t, 'MARC-ALP');
        $pAlpha->addMarcador(new Marcador('Alpha Ordenacao', $t, $autor));
        $pBeta = $this->criarPasta($t, 'MARC-BET');
        $pBeta->addMarcador(new Marcador('Beta Ordenacao', $t, $autor));
        foreach ($pAlpha->getMarcadores() as $m) {
            $this->em->persist($m);
        }
        foreach ($pBeta->getMarcadores() as $m) {
            $this->em->persist($m);
        }
        $this->em->flush();

        $asc = $this->nupsOrdenados($t, 'marcadores', 'asc');
        self::assertTrue($this->vemAntes($pAlpha->getNup(), $pBeta->getNup(), $asc));
    }
}
