<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Cliente\Entity\ClientePF;
use App\Cliente\Entity\ClientePJ;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\OcorrenciaDaBuscaRapida;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
use App\Processo\Entity\Processo;
use App\Tests\Factory\Cliente\ClientePFFactory;
use App\Tests\Factory\Cliente\ClientePJFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * `PastaRepository::buscarParaBuscaRapida` (Busca rápida, Ctrl + K): o SQL nativo, no banco de verdade.
 * Cada teste usa um termo único do próprio teste, para não casar com dado de outro.
 */
#[CoversClass(PastaRepository::class)]
final class PastaRepositoryBuscaRapidaTest extends KernelTestCase
{
    use Factories;

    private EntityManagerInterface $em;
    private PastaRepository $repo;
    private int $seq = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em   = static::getContainer()->get(EntityManagerInterface::class);
        $this->repo = static::getContainer()->get(PastaRepository::class);
    }

    #[TestDox('casa o nome do cliente sem acento e sem caixa')]
    public function testNomeDoClienteSemAcentoESemCaixa(): void
    {
        $tenant = $this->criarTenant();
        $pasta  = $this->criarPasta($tenant);
        $pasta->addCliente($this->clientePF($tenant, 'Joséfina Ávila Brandão', '111.222.333-44'));
        $this->em->flush();

        $achadas = $this->repo->buscarParaBuscaRapida('JOSEFINA avila', $tenant);

        self::assertCount(1, $achadas);
        self::assertSame(OcorrenciaDaBuscaRapida::GRUPO_CLIENTE, $achadas[0]->grupo);
        self::assertSame($pasta->getId(), $achadas[0]->pastaId);
        // O cadastro grava o nome em maiúsculas; a asserção segue o dado.
        self::assertSame('JOSÉFINA ÁVILA BRANDÃO', $achadas[0]->nome);
        self::assertSame('111.222.333-44', $achadas[0]->documento);
    }

    #[TestDox('CPF gravado com máscara casa pelos dígitos; CNPJ cru casa pelo texto mascarado')]
    public function testCpfECnpjPorDigitos(): void
    {
        $tenant = $this->criarTenant();
        $comCpf = $this->criarPasta($tenant);
        $comCpf->addCliente($this->clientePF($tenant, 'Cliente Cpf Teste', '318.774.902-15'));
        $comCnpj = $this->criarPasta($tenant);
        $comCnpj->addCliente($this->clientePJ($tenant, 'Construtora Teste Ltda', '12408331000107'));
        $this->em->flush();

        $porCpf = $this->repo->buscarParaBuscaRapida('31877490215', $tenant);
        self::assertSame([$comCpf->getId()], $this->idsDoGrupo($porCpf, OcorrenciaDaBuscaRapida::GRUPO_CLIENTE));

        $porCnpj = $this->repo->buscarParaBuscaRapida('12.408.331/0001', $tenant);
        self::assertSame([$comCnpj->getId()], $this->idsDoGrupo($porCnpj, OcorrenciaDaBuscaRapida::GRUPO_CLIENTE));
    }

    #[TestDox('menos de 3 dígitos não compara por dígitos')]
    public function testPoucosDigitosNaoComparaPorDigitos(): void
    {
        $tenant = $this->criarTenant();
        $pasta  = $this->criarPasta($tenant);
        $pasta->addCliente($this->clientePF($tenant, 'Cliente Dois Digitos', '987.654.321-00'));
        $this->em->flush();

        self::assertSame([], $this->idsDoGrupo($this->repo->buscarParaBuscaRapida('-87', $tenant), OcorrenciaDaBuscaRapida::GRUPO_CLIENTE));
    }

    #[TestDox('acha o processo que NÃO é o principal, pelos dígitos e pelo número gravado')]
    public function testProcessoNaoPrincipal(): void
    {
        $tenant = $this->criarTenant();
        $pasta  = $this->criarPasta($tenant, nomeAcao: 'Indenização por danos morais');
        $pasta->vincularProcesso($this->criarProcesso($tenant, '07122841920258070001'));
        $pasta->vincularProcesso($this->criarProcesso($tenant, '08099315520248070016'));
        $this->em->flush();

        $achadas = $this->repo->buscarParaBuscaRapida('0809931-55.2024', $tenant);

        self::assertCount(1, $achadas);
        self::assertSame(OcorrenciaDaBuscaRapida::GRUPO_PROCESSO, $achadas[0]->grupo);
        self::assertSame('08099315520248070016', $achadas[0]->numeroProcesso);
        self::assertSame('INDENIZAÇÃO POR DANOS MORAIS', $achadas[0]->nomeAcao);
        self::assertCount(1, $this->repo->buscarParaBuscaRapida('080993155', $tenant));
    }

    #[TestDox('grupo Pasta casa pelo número e por "Pasta <número>", não pela ação (desenho L6470-6472)')]
    public function testPastaPorNumeroENaoPelaAcao(): void
    {
        $tenant = $this->criarTenant();
        $pasta  = $this->criarPasta($tenant, nomeAcao: 'Usucapião Extraordinária Zétima');

        $porNumero = $this->repo->buscarParaBuscaRapida(mb_strtolower((string) $pasta->getNup()), $tenant);
        self::assertSame([$pasta->getId()], $this->idsDoGrupo($porNumero, OcorrenciaDaBuscaRapida::GRUPO_PASTA));
        self::assertFalse($porNumero[0]->trancada);
        self::assertSame('USUCAPIÃO EXTRAORDINÁRIA ZÉTIMA', $porNumero[0]->nomeAcao, 'a ação segue como dado do subtítulo');

        $comPrefixo = $this->repo->buscarParaBuscaRapida('Pasta ' . $pasta->getNup(), $tenant);
        self::assertSame([$pasta->getId()], $this->idsDoGrupo($comPrefixo, OcorrenciaDaBuscaRapida::GRUPO_PASTA));

        self::assertSame([], $this->repo->buscarParaBuscaRapida('usucapiao extraordinaria zetima', $tenant));
    }

    #[TestDox('pasta sem cliente cadastrado casa pelo nome escrito na pasta')]
    public function testNomeEscritoNaPasta(): void
    {
        $tenant = $this->criarTenant();
        $pasta  = $this->criarPasta($tenant, nomeCliente: 'Gleisson Bruno Teste');

        $achadas = $this->repo->buscarParaBuscaRapida('gleisson bruno teste', $tenant);

        self::assertSame([$pasta->getId()], $this->idsDoGrupo($achadas, OcorrenciaDaBuscaRapida::GRUPO_CLIENTE));
        self::assertSame('GLEISSON BRUNO TESTE', $achadas[0]->nome);
    }

    #[TestDox('pasta de outro escritório nunca aparece, nem por número, cliente ou processo')]
    public function testTenantAlheioNaoAparece(): void
    {
        $meu    = $this->criarTenant();
        $alheio = $this->criarTenant();
        $dele   = $this->criarPasta($alheio);
        $dele->addCliente($this->clientePF($alheio, 'Cliente Exclusivo Alheio', '555.444.333-22'));
        $dele->vincularProcesso($this->criarProcesso($alheio, '55544433322201234567'));
        $this->em->flush();

        self::assertSame([], $this->repo->buscarParaBuscaRapida((string) $dele->getNup(), $meu));
        self::assertSame([], $this->repo->buscarParaBuscaRapida('exclusivo alheio', $meu));
        self::assertSame([], $this->repo->buscarParaBuscaRapida('55544433322', $meu));
        self::assertSame([], $this->repo->buscarParaBuscaRapida('', $meu));
        self::assertNotSame([], $this->repo->buscarParaBuscaRapida((string) $dele->getNup(), $alheio));
    }

    #[TestDox('pasta minha ligada a cliente e processo de OUTRO escritório não traz esse cliente nem esse processo')]
    public function testGuardasDeTenantDoClienteEDoProcesso(): void
    {
        $meu    = $this->criarTenant();
        $alheio = $this->criarTenant();
        $minha  = $this->criarPasta($meu);
        $minha->addCliente($this->clientePF($alheio, 'Cliente Infiltrado Alheio', '777.666.555-44'));
        $minha->vincularProcesso($this->criarProcesso($alheio, '77766655544401234567'));
        $this->em->flush();

        self::assertSame([], $this->repo->buscarParaBuscaRapida('infiltrado alheio', $meu));
        self::assertSame([], $this->repo->buscarParaBuscaRapida('77766655544', $meu));

        $pelaPasta = $this->repo->buscarParaBuscaRapida((string) $minha->getNup(), $meu);
        self::assertCount(1, $pelaPasta);
        self::assertNull($pelaPasta[0]->nomeDoClientePrincipal, 'o cliente principal de outro escritório não vira subtítulo');
    }

    #[TestDox('somenteIds restringe dentro do SQL, antes do limite; lista vazia não traz nada')]
    public function testSomenteIdsRestringeAntesDoLimite(): void
    {
        $tenant = $this->criarTenant();
        $antiga = $this->criarPasta($tenant);
        for ($i = 0; $i < 12; ++$i) {
            $this->criarPasta($tenant);
        }

        $achadas = $this->repo->buscarParaBuscaRapida('', $tenant, 8, [(int) $antiga->getId()]);
        self::assertSame([$antiga->getId()], array_map(static fn (OcorrenciaDaBuscaRapida $o): int => $o->pastaId, $achadas));
        self::assertSame([], $this->repo->buscarParaBuscaRapida('', $tenant, 8, []));
    }

    #[TestDox('pasta excluída (lápide) não aparece')]
    public function testLapideNaoAparece(): void
    {
        $tenant = $this->criarTenant();
        $viva   = $this->criarPasta($tenant, nomeCliente: 'Lapide Teste Nome');
        $morta  = $this->criarPasta($tenant, nomeCliente: 'Lapide Teste Nome');
        $morta->marcarExcluida($this->criarUsuario(), new \DateTimeImmutable());
        $this->em->flush();

        self::assertSame([$viva->getId()], $this->idsDoGrupo($this->repo->buscarParaBuscaRapida('lapide teste', $tenant), OcorrenciaDaBuscaRapida::GRUPO_CLIENTE));
        self::assertSame([$viva->getId()], $this->idsDoGrupo($this->repo->buscarParaBuscaRapida('', $tenant), OcorrenciaDaBuscaRapida::GRUPO_PASTA));
        self::assertSame([], $this->repo->buscarParaBuscaRapida((string) $morta->getNup(), $tenant));
    }

    #[TestDox('termo vazio: só pastas, das mais recentes, até o limite')]
    public function testTermoVazioRespeitaOLimite(): void
    {
        $tenant = $this->criarTenant();
        $ids    = [];
        for ($i = 0; $i < 10; ++$i) {
            $ids[] = (int) $this->criarPasta($tenant)->getId();
        }

        $achadas = $this->repo->buscarParaBuscaRapida('', $tenant, 8);

        self::assertCount(8, $achadas);
        rsort($ids);
        self::assertSame(array_slice($ids, 0, 8), array_map(static fn (OcorrenciaDaBuscaRapida $o): int => $o->pastaId, $achadas));
        foreach ($achadas as $o) {
            self::assertSame(OcorrenciaDaBuscaRapida::GRUPO_PASTA, $o->grupo);
        }
    }

    #[TestDox('% e _ digitados são texto, não curinga')]
    public function testCuringaDigitadoEhTexto(): void
    {
        $tenant = $this->criarTenant();
        $this->criarPasta($tenant, nomeAcao: 'Acao Comum');

        self::assertSame([], $this->repo->buscarParaBuscaRapida('%', $tenant));
        self::assertSame([], $this->repo->buscarParaBuscaRapida('_', $tenant));
        // Sem o escape, a barra no fim do padrão faria o PostgreSQL recusar o LIKE.
        self::assertSame([], $this->repo->buscarParaBuscaRapida('\\', $tenant));
        self::assertSame([], $this->repo->buscarParaBuscaRapida('a\\', $tenant));
    }

    #[TestDox('pasta trancada vem marcada')]
    public function testTrancadaVemMarcada(): void
    {
        $tenant = $this->criarTenant();
        $pasta  = $this->criarPasta($tenant);
        $pasta->trancar($this->criarUsuario(), new \DateTimeImmutable());
        $this->em->flush();

        $achadas = $this->repo->buscarParaBuscaRapida((string) $pasta->getNup(), $tenant);

        self::assertCount(1, $achadas);
        self::assertTrue($achadas[0]->trancada);
    }

    // ----------------------------------------------------------------- helpers

    /**
     * @param list<OcorrenciaDaBuscaRapida> $ocorrencias
     *
     * @return list<int>
     */
    private function idsDoGrupo(array $ocorrencias, string $grupo): array
    {
        return array_values(array_map(
            static fn (OcorrenciaDaBuscaRapida $o): int => $o->pastaId,
            array_filter($ocorrencias, static fn (OcorrenciaDaBuscaRapida $o): bool => $o->grupo === $grupo),
        ));
    }

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant BR ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function criarUsuario(): User
    {
        $user = new User();
        $user->setEmail('br_' . uniqid() . '@test.com');
        $user->setFullName('Usuario Busca Rapida');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('x');
        $this->em->persist($user);

        return $user;
    }

    private function criarPasta(Tenant $tenant, ?string $nomeAcao = null, ?string $nomeCliente = null): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup('BR' . (++$this->seq) . 'X' . strtoupper(substr(uniqid(), -7)));
        $pasta->setTenant($tenant);
        $pasta->setNomeAcao($nomeAcao);
        $pasta->setNomeCliente($nomeCliente);
        $this->em->persist($pasta);
        $this->em->flush();

        return $pasta;
    }

    private function criarProcesso(Tenant $tenant, string $numero): Processo
    {
        $processo = new Processo();
        $processo->setNumeroProcesso($numero);
        $processo->setSiglaTribunal('TJDFT');
        $processo->setOrgaoJulgador('1a Vara');
        $processo->setClasseProcessual('Procedimento comum');
        $processo->setAssuntoProcessual('Teste');
        $processo->setSituacaoProcesso('Em Andamento');
        $processo->setInstancia('1a Instância');
        $processo->setTenant($tenant);
        $this->em->persist($processo);

        return $processo;
    }

    private function clientePF(Tenant $tenant, string $nome, string $cpf): ClientePF
    {
        return ClientePFFactory::createOne(['tenant' => $tenant, 'nomeCompleto' => $nome, 'cpf' => $cpf])->_real();
    }

    private function clientePJ(Tenant $tenant, string $razao, string $cnpj): ClientePJ
    {
        return ClientePJFactory::createOne(['tenant' => $tenant, 'razaoSocial' => $razao, 'cnpj' => $cnpj])->_real();
    }
}
