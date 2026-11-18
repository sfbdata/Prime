<?php

declare(strict_types=1);

namespace App\Tests\Voto\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Master\Entity\RecursoEstado;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use App\Tests\Factory\Voto\AssembleiaFactory;
use App\Tests\Factory\Voto\PautaDaAssembleiaFactory;
use App\Tests\Factory\Voto\UnidadeDaAssembleiaFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Voto\Controller\AssembleiaController;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\LiberacaoDoVoto;
use App\Voto\Entity\MembroDaEquipe;
use App\Voto\Entity\ParticipacaoNaPauta;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Enum\CenarioDaAssembleia;
use App\Voto\Enum\PapelNaEquipe;
use App\Voto\UseCase\MontarPainelDeUnidadesUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * "Painel de unidades" (botão "Unidades" da aba Acompanhar): a unidade que votou aparece como `votou`, a outra como
 * `nao_votou`, só com a identificação; quem não vê a relação de votantes recebe 403 e não vê o botão; cenário sem lista
 * e assembleia de outro escritório dão 404.
 */
#[CoversClass(AssembleiaController::class)]
#[CoversClass(MontarPainelDeUnidadesUseCase::class)]
#[Group('voto')]
final class PainelDeUnidadesTest extends JusPrimeWebTestCase
{
    use Factories;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $escritorio;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->escritorio = $this->escritorioLiberado();
    }

    #[TestDox('200: a unidade que votou sai como votou e a outra como nao_votou, só com a identificação')]
    public function testQuadradinhosPorSituacao(): void
    {
        [$assembleia, $pauta] = $this->assembleiaComPautaAberta(CenarioDaAssembleia::Condominio);
        $this->unidade($assembleia, 'Ana Responsável', '101', $pauta);
        $this->unidade($assembleia, 'Beto Responsável', '102');
        $this->unidade($assembleia, 'Carla Responsável', '103', apta: false);
        $this->entrar($this->colega());

        $tela = $this->client->request('GET', $this->url($assembleia, $pauta));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $tela->filter('[data-situacao="votou"]'));
        self::assertSame('101', trim($tela->filter('[data-situacao="votou"]')->text()));
        self::assertSame('102', trim($tela->filter('[data-situacao="nao_votou"]')->text()));
        self::assertSame('103', trim($tela->filter('[data-situacao="inapta"]')->text()));
        self::assertSame('101: Votou', $tela->filter('[data-situacao="votou"]')->attr('aria-label'));
        self::assertSame(['101', '102', '103'], $tela->filter('.voto-unidades-grade > [data-situacao]')->each(static fn (Crawler $c): string => trim($c->text())));
        self::assertStringContainsString('1 de 2 votaram', $tela->filter('.voto-unidades-resumo')->text());
        self::assertSame('5', $tela->filter('meta[http-equiv="refresh"]')->attr('content'));
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('Responsável', $html, 'nunca o nome do responsável');
    }

    #[TestDox('403 para quem não vê a relação de votantes (conselho de assembleia com equipe) e o botão some da aba Acompanhar')]
    public function testQuemNaoVeARelacaoRecebe403(): void
    {
        [$assembleia, $pauta] = $this->assembleiaComPautaAberta(CenarioDaAssembleia::Condominio);
        $this->unidade($assembleia, 'Ana', '101', $pauta);
        $comissao = $this->colega();
        $conselho = $this->colega();
        $this->em->persist(new MembroDaEquipe($assembleia, PapelNaEquipe::Comissao, 'Presidência', null, '', $comissao));
        $this->em->persist(new MembroDaEquipe($assembleia, PapelNaEquipe::Conselho, 'Conselho', null, '', $conselho));
        $this->em->flush();

        $this->entrar($conselho);
        $this->client->request('GET', $this->url($assembleia, $pauta));
        self::assertResponseStatusCodeSame(403);
        $aba = $this->client->request('GET', '/voto?aba=aco');
        self::assertCount(0, $aba->filter('[data-voto-unidades]'), 'conselho não vê o botão');

        $this->entrar($comissao);
        $aba = $this->client->request('GET', '/voto?aba=aco');
        self::assertCount(1, $aba->filter('[data-aba="aco"] [data-voto-unidades]'), 'a comissão vê o botão');
        self::assertStringContainsString($this->url($assembleia, $pauta), (string) $aba->filter('[data-voto-unidades]')->attr('href'));
        self::assertSame('Unidades', trim($aba->filter('[data-voto-unidades]')->text()));
        $this->client->request('GET', $this->url($assembleia, $pauta));
        self::assertResponseIsSuccessful();
    }

    #[TestDox('Cenário sem lista de votantes: 404 na rota e sem botão na aba Acompanhar')]
    public function testCenarioSemListaDa404(): void
    {
        [$assembleia, $pauta] = $this->assembleiaComPautaAberta(CenarioDaAssembleia::Classe);
        $this->unidade($assembleia, 'Ana', '101', $pauta);
        $this->entrar($this->colega());

        $this->client->request('GET', $this->url($assembleia, $pauta));
        self::assertResponseStatusCodeSame(404);
        $aba = $this->client->request('GET', '/voto?aba=aco');
        self::assertCount(0, $aba->filter('[data-voto-unidades]'));
    }

    #[TestDox('Assembleia de outro escritório e pauta de outra assembleia dão 404')]
    public function testOutroEscritorioEPautaDeOutraDa404(): void
    {
        [$assembleia, $pauta] = $this->assembleiaComPautaAberta(CenarioDaAssembleia::Condominio);
        [$outra, $pautaDaOutra] = $this->assembleiaComPautaAberta(CenarioDaAssembleia::Condominio);
        $this->entrar($this->colega());

        $this->client->request('GET', '/voto/assembleias/' . $assembleia->getId() . '/pautas/' . $pautaDaOutra->getId() . '/unidades');
        self::assertResponseStatusCodeSame(404);

        $estrangeiro = TenantFactory::createOne(['name' => 'Outro Voto ' . uniqid()])->_real();
        $deFora = AssembleiaFactory::new(['tenant' => $estrangeiro, 'cenario' => CenarioDaAssembleia::Condominio])->publicada()->create()->_real();
        $pautaDeFora = PautaDaAssembleiaFactory::new(['assembleia' => $deFora])->comOpcoesPadrao()->aberta()->create()->_real();
        $this->client->request('GET', '/voto/assembleias/' . $deFora->getId() . '/pautas/' . $pautaDeFora->getId() . '/unidades');
        self::assertResponseStatusCodeSame(404);
        self::assertNotSame($outra->getId(), $assembleia->getId());
    }

    private function url(Assembleia $assembleia, PautaDaAssembleia $pauta): string
    {
        return '/voto/assembleias/' . $assembleia->getId() . '/pautas/' . $pauta->getId() . '/unidades';
    }

    private function escritorioLiberado(): Tenant
    {
        $escritorio = TenantFactory::createOne(['name' => 'Painel do Voto ' . uniqid()])->_real();
        $estado = $this->em->getRepository(RecursoEstado::class)->findOneBy(['chave' => 'voto']);
        if (null !== $estado) {
            $estado->reativar(null, new \DateTimeImmutable());
        }
        $liberacao = new LiberacaoDoVoto($escritorio);
        $liberacao->liberar();
        $this->em->persist($liberacao);
        $this->em->flush();

        return $escritorio;
    }

    /** @return array{0: Assembleia, 1: PautaDaAssembleia} */
    private function assembleiaComPautaAberta(CenarioDaAssembleia $cenario): array
    {
        $assembleia = AssembleiaFactory::new(['tenant' => $this->escritorio, 'cenario' => $cenario, 'titulo' => 'AGO ' . uniqid()])->publicada()->create()->_real();
        $pauta = PautaDaAssembleiaFactory::new(['assembleia' => $assembleia, 'titulo' => 'Contas'])->comOpcoesPadrao()->aberta()->create()->_real();

        return [$assembleia, $pauta];
    }

    private function unidade(Assembleia $assembleia, string $nome, string $identificacao, ?PautaDaAssembleia $votouEm = null, bool $apta = true): UnidadeDaAssembleia
    {
        $fabrica = UnidadeDaAssembleiaFactory::new();
        $unidade = ($apta ? $fabrica : $fabrica->inapta())->create(['assembleia' => $assembleia, 'responsavel' => $nome, 'identificacao' => $identificacao, 'bloco' => 'A'])->_real();
        if (null !== $votouEm) {
            $this->em->persist(new ParticipacaoNaPauta($unidade, $votouEm, new \DateTimeImmutable()));
            $this->em->flush();
        }

        return $unidade;
    }

    private function colega(): User
    {
        $user = UserFactory::createOne(['fullName' => 'Pessoa ' . uniqid(), 'roles' => ['ROLE_USER']])->_real();
        $papel = (new TenantRole())->setTenant($this->escritorio)->setName('Perfil ' . uniqid())->setIsSystem(false);
        $this->em->persist($papel);
        $permissao = $this->em->getRepository(Permission::class)->findOneBy(['code' => 'modules.voto.view']);
        if (null === $permissao) {
            $permissao = (new Permission())->setCode('modules.voto.view')->setDescription('Acesso ao módulo Voto (eleições e votações)')->setGroup('modules');
            $this->em->persist($permissao);
        }
        $concessao = (new TenantRolePermission())->setTenantRole($papel)->setPermission($permissao);
        $this->em->persist($concessao);
        $papel->getTenantRolePermissions()->add($concessao);
        $this->em->persist((new UserTenant($user, $this->escritorio))->setTenantRole($papel));
        $this->em->flush();

        return $user;
    }

    private function entrar(User $user): void
    {
        $this->logarComTenant($this->client, $user, $this->escritorio);
    }
}
