<?php

declare(strict_types=1);

namespace App\Tests\Conector\Integration;

use App\Conector\Service\ModoDoConector;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\AbaOcultaDaPasta;
use App\Pasta\Entity\CadeadoDaPasta;
use App\Pasta\Entity\ModoDoCadeado;
use App\Pasta\Entity\Pasta;
use App\Pasta\Service\VisibilidadeDasAbasDaPasta;
use App\Service\PermissionChecker;
use App\Tenant\Service\RegraDosPrimarios;
use App\Tests\Conector\Support\CenarioDoConectorTrait;
use App\Tests\Factory\Pasta\PastaFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * O teto do conector (spec mcp-remoto-oauth 6.3, itens 2 e 3; 12.19), com os serviços de verdade do container: ligado
 * para a pessoa do token, Master e primário perdem os passes e as `admin.*`; o perfil de sistema fica com toda
 * permissão que não é `admin.*`; o colega não muda; o `reset()` devolve tudo.
 */
#[CoversClass(ModoDoConector::class)]
#[CoversClass(PermissionChecker::class)]
#[CoversClass(RegraDosPrimarios::class)]
#[CoversClass(VisibilidadeDasAbasDaPasta::class)]
final class ModoDoConectorTest extends KernelTestCase
{
    use CenarioDoConectorTrait;
    use Factories;

    private Tenant $escritorio;
    private User $master;
    private UserTenant $vinculoDoMaster;
    private User $primario;
    private UserTenant $vinculoDoPrimario;
    private User $colegaPrimario;
    private UserTenant $vinculoDoColega;
    private User $secundario;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->escritorio = $this->escritorio();
        // Master secundário com vínculo comum (docs/AUTORIZACAO.md, 2a): o perfil dele só tem o módulo Pastas.
        $this->master = $this->pessoa(['ROLE_SUPER_ADMIN']);
        $this->vinculoDoMaster = $this->vinculo($this->master, $this->escritorio, permissoes: ['modules.pastas.view']);
        // Perfil de sistema SEM permissão ligada: sem o passe, só a regra do teto dá as não-admin.
        $this->primario = $this->pessoa();
        $this->vinculoDoPrimario = $this->vinculo($this->primario, $this->escritorio, sistema: true);
        $this->colegaPrimario = $this->pessoa();
        $this->vinculoDoColega = $this->vinculo($this->colegaPrimario, $this->escritorio, sistema: true);
        $this->secundario = $this->pessoa();
        $this->vinculo($this->secundario, $this->escritorio, permissoes: ['modules.pastas.view', 'admin.users.manage', 'admin.audit.view']);
    }

    #[TestDox('desligado: Master e primário passam por tudo, como no navegador')]
    public function testDesligadoNadaMuda(): void
    {
        $checker = $this->checker();
        $regra = $this->regra();

        self::assertTrue($checker->canAdminister($this->master, $this->escritorio, 'admin.users.manage'));
        self::assertTrue($checker->canAccessModule($this->master, $this->escritorio, 'voto'));
        self::assertTrue($checker->isAdministradorDoEscritorio($this->primario, $this->escritorio));
        self::assertTrue($checker->canAdminister($this->primario, $this->escritorio, 'admin.audit.view'));
        self::assertTrue($checker->hasPermission($this->secundario, $this->escritorio, 'admin.users.manage'));
        self::assertTrue($regra->ehMaster($this->master));
        self::assertTrue($regra->ehPrimario($this->vinculoDoPrimario));
    }

    #[TestDox('ligado para o Master: sem o passe, conta pelo perfil do vínculo; nenhuma função de administrador')]
    public function testMasterPerdeOPasse(): void
    {
        $this->modo()->ligar($this->vinculoDoMaster);
        $checker = $this->checker();

        self::assertTrue($checker->canAccessModule($this->master, $this->escritorio, 'pastas'), 'o perfil do vínculo tem Pastas');
        self::assertFalse($checker->canAccessModule($this->master, $this->escritorio, 'voto'), 'sem o passe, o que o perfil não tem não abre');
        self::assertFalse($checker->canAdminister($this->master, $this->escritorio, 'admin.users.manage'));
        self::assertFalse($checker->hasPermission($this->master, $this->escritorio, 'admin.audit.view'));
        self::assertFalse($checker->isAdministradorDoEscritorio($this->master, $this->escritorio));
        self::assertFalse($checker->canAccessResource($this->master, $this->escritorio, AccessRequest::RESOURCE_PASTA, 999999, AccessRequest::ACTION_VIEW));
        self::assertFalse($this->regra()->ehMaster($this->master));
        self::assertFalse($this->regra()->podeGerirPrimarios($this->master, $this->escritorio));
    }

    #[TestDox('ligado para o primário: toda permissão não-admin pelo perfil de sistema, nenhuma admin.*, não é primário nem administrador')]
    public function testPrimarioPerdeOPasse(): void
    {
        $this->modo()->ligar($this->vinculoDoPrimario);
        $checker = $this->checker();

        self::assertTrue($checker->canAccessModule($this->primario, $this->escritorio, 'voto'), 'perfil de sistema: módulo sem ligação conta');
        self::assertTrue($checker->hasPermission($this->primario, $this->escritorio, 'resources.pasta.view'));
        self::assertFalse($checker->hasPermission($this->primario, $this->escritorio, 'admin.users.manage'));
        self::assertFalse($checker->canAdminister($this->primario, $this->escritorio, 'admin.audit.view'));
        self::assertFalse($checker->isAdministradorDoEscritorio($this->primario, $this->escritorio));
        self::assertFalse($this->regra()->ehPrimario($this->vinculoDoPrimario));
        self::assertFalse($this->regra()->podeGerirPrimarios($this->primario, $this->escritorio));
    }

    #[TestDox('ligado para o primário: a pasta trancada para todos fica oculta para ele (passa pelo cadeado como qualquer colega)')]
    public function testPrimarioPassaPeloCadeado(): void
    {
        $pasta = $this->pastaTrancadaParaTodos();
        $id = (int) $pasta->getId();
        self::assertTrue($this->checker()->canAccessResource($this->primario, $this->escritorio, AccessRequest::RESOURCE_PASTA, $id, AccessRequest::ACTION_VIEW), 'no navegador, o primário vê');

        $this->modo()->ligar($this->vinculoDoPrimario);

        self::assertFalse($this->checker()->canAccessResource($this->primario, $this->escritorio, AccessRequest::RESOURCE_PASTA, $id, AccessRequest::ACTION_VIEW));
        self::assertSame([], $this->checker()->idsPermitidosComVinculo($this->primario, $this->vinculoDoPrimario, AccessRequest::RESOURCE_PASTA, [$id], AccessRequest::ACTION_VIEW));
    }

    #[TestDox('ligado: a aba oculta para todos some também para o primário e o Master (abasOcultasPara), e o colega primário segue vendo')]
    public function testAbaOcultaParaTodosValeNoModo(): void
    {
        $pasta = PastaFactory::createOne(['tenant' => $this->escritorio])->_real();
        $this->em()->persist(AbaOcultaDaPasta::paraTodaAEquipe($this->escritorio, $pasta, AbaDaPasta::Financeiro, $this->colegaPrimario, new \DateTimeImmutable()));
        $this->em()->flush();
        $abas = static::getContainer()->get(VisibilidadeDasAbasDaPasta::class);
        self::assertSame([], $abas->abasOcultasPara($pasta, $this->primario), 'no navegador, quem configura não tem aba oculta');
        self::assertSame([], $abas->abasOcultasPara($pasta, $this->master));

        $this->modo()->ligar($this->vinculoDoPrimario);
        self::assertSame([AbaDaPasta::Financeiro], $abas->abasOcultasPara($pasta, $this->primario));
        self::assertSame([], $abas->abasOcultasPara($pasta, $this->colegaPrimario), 'o colega primário não muda');

        static::getContainer()->get('services_resetter')->reset();
        $this->modo()->ligar($this->vinculoDoMaster);
        self::assertSame([AbaDaPasta::Financeiro], $abas->abasOcultasPara($pasta, $this->master));
    }

    #[TestDox('ligado para uma pessoa: o colega (outra pessoa) continua primário e administrador')]
    public function testColegaNaoMuda(): void
    {
        $this->modo()->ligar($this->vinculoDoPrimario);

        self::assertTrue($this->regra()->ehPrimario($this->vinculoDoColega));
        self::assertTrue($this->checker()->isAdministradorDoEscritorio($this->colegaPrimario, $this->escritorio));
        self::assertTrue($this->checker()->canAdminister($this->colegaPrimario, $this->escritorio, 'admin.users.manage'));
        self::assertTrue($this->regra()->ehMaster($this->master));
        self::assertTrue($this->checker()->hasPermission($this->secundario, $this->escritorio, 'admin.users.manage'));
    }

    #[TestDox('ligado para o secundário com admin.* no perfil: admin.* nunca; o resto do perfil continua')]
    public function testSecundarioPerdeAsAdmin(): void
    {
        $vinculo = $this->em()->getRepository(UserTenant::class)->findOneBy(['user' => $this->secundario]);
        self::assertInstanceOf(UserTenant::class, $vinculo);
        $this->modo()->ligar($vinculo);

        self::assertFalse($this->checker()->hasPermission($this->secundario, $this->escritorio, 'admin.users.manage'));
        self::assertFalse($this->checker()->canAdminister($this->secundario, $this->escritorio, 'admin.audit.view'));
        self::assertTrue($this->checker()->canAccessModule($this->secundario, $this->escritorio, 'pastas'));
    }

    #[TestDox('reset(): os passes voltam (o serviço zera entre requisições e mensagens do worker)')]
    public function testResetDevolveOsPasses(): void
    {
        $this->modo()->ligar($this->vinculoDoMaster);
        self::assertFalse($this->checker()->canAdminister($this->master, $this->escritorio, 'admin.users.manage'));

        static::getContainer()->get('services_resetter')->reset();

        self::assertFalse($this->modo()->estaLigado());
        self::assertTrue($this->checker()->canAdminister($this->master, $this->escritorio, 'admin.users.manage'));
        self::assertTrue($this->regra()->ehMaster($this->master));
    }

    private function pastaTrancadaParaTodos(): Pasta
    {
        $pasta = PastaFactory::createOne(['tenant' => $this->escritorio])->_real();
        $this->em()->persist(new CadeadoDaPasta($this->escritorio, $pasta, ModoDoCadeado::Todos, null, new \DateTimeImmutable()));
        $this->em()->flush();

        return $pasta;
    }

    private function modo(): ModoDoConector
    {
        return static::getContainer()->get(ModoDoConector::class);
    }

    private function checker(): PermissionChecker
    {
        return static::getContainer()->get(PermissionChecker::class);
    }

    private function regra(): RegraDosPrimarios
    {
        return static::getContainer()->get(RegraDosPrimarios::class);
    }
}
