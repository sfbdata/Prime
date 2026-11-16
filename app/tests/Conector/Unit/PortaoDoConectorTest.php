<?php

declare(strict_types=1);

namespace App\Tests\Conector\Unit;

use App\Conector\DTO\ContextoDoConector;
use App\Conector\Exception\AcessoDoConectorRecusado;
use App\Conector\Service\FiltroDoEscritorio;
use App\Conector\Service\ModoDoConector;
use App\Conector\Service\PortaoDoConector;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Repository\TenantRepository;
use App\Repository\UserRepository;
use App\Repository\UserTenantRepository;
use App\Service\Tenant\TenantContext;
use App\Tenant\Enum\SituacaoNaEquipe;
use App\Tenant\Repository\PedidoDeExclusaoDeContaRepository;
use App\Tenant\Service\AvisoDeContaSuspensa;
use App\Tenant\Service\BarreirasDoEscritorio;
use App\Tenant\Service\BloqueioPorPedidoDeExclusao;
use App\Tenant\Service\RegraDosPrimarios;
use App\Termo\Repository\AceiteTermoRepository;
use App\Termo\TermoVigente;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * O portão do conector (spec mcp-remoto-oauth 4, 4.1 e 12, itens 2, 3 e 18): cada barreira recusa; nunca devolve
 * contexto com o filtro de escritório desligado; só depois de tudo conferido fixa o escritório no TenantContext e liga o
 * modo; abrir de novo na mesma requisição recusa.
 */
#[CoversClass(PortaoDoConector::class)]
#[CoversClass(AcessoDoConectorRecusado::class)]
#[CoversClass(ContextoDoConector::class)]
final class PortaoDoConectorTest extends TestCase
{
    private const PESSOA = 10;
    private const ESCRITORIO = 7;

    private User $pessoa;
    private Tenant $escritorio;
    private ?UserTenant $vinculo;
    private bool $comPedido = false;
    private bool $aceitou = true;
    private ModoDoConector $modo;
    private TenantContext $tenantContext;

    protected function setUp(): void
    {
        $this->pessoa = (new User())->setIsActive(true)->setFullName('Pessoa')->setEmail('pessoa@teste.com')->setRoles(['ROLE_USER']);
        (new \ReflectionProperty(User::class, 'id'))->setValue($this->pessoa, self::PESSOA);
        $this->escritorio = (new Tenant())->setName('Escritório A');
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($this->escritorio, self::ESCRITORIO);
        $this->vinculo = (new UserTenant($this->pessoa, $this->escritorio))->setTenantRole((new TenantRole())->setIsSystem(false));
        (new \ReflectionProperty(UserTenant::class, 'id'))->setValue($this->vinculo, 30);
        $this->modo = new ModoDoConector();
        // Sem sessão (RequestStack vazia), como no `/mcp`: só o escritório fixado pelo portão vale.
        $this->tenantContext = new TenantContext(
            new RequestStack(),
            $this->createStub(Security::class),
            $this->createStub(EntityManagerInterface::class),
            $this->bloqueio(),
        );
    }

    #[TestDox('tudo em ordem: devolve o contexto, com o filtro ligado, o escritório do token no TenantContext (sem sessão) e o modo')]
    public function testAbreComTudoEmOrdem(): void
    {
        $filtro = $this->createMock(FiltroDoEscritorio::class);
        $filtro->expects(self::once())->method('ligar')->with(self::ESCRITORIO);
        $filtro->method('estaLigadoEm')->with(self::ESCRITORIO)->willReturn(true);

        $contexto = $this->portao($filtro)->abrir(self::PESSOA, self::ESCRITORIO);

        self::assertSame($this->pessoa, $contexto->pessoa);
        self::assertSame($this->escritorio, $contexto->escritorio);
        self::assertSame($this->vinculo, $contexto->vinculo);
        self::assertTrue($this->modo->valePara($this->pessoa));
        // Qual escritório o TenantContext devolve (relido pelo EntityManager) se prova no PortaoDoConectorNoBancoTest.
        self::assertTrue($this->tenantContext->hasCurrentTenant());
    }

    #[TestDox('conferir (a tela de autorização): as mesmas conferências, sem ligar filtro, escritório nem modo')]
    public function testConferirNaoTrancaARequisicao(): void
    {
        $filtro = $this->createMock(FiltroDoEscritorio::class);
        $filtro->expects(self::never())->method('ligar');

        $this->portao($filtro)->conferir(self::PESSOA, self::ESCRITORIO);

        self::assertFalse($this->modo->estaLigado());
        self::assertFalse($this->tenantContext->hasCurrentTenant());
    }

    #[TestDox('pessoa desativada ou inexistente: recusa')]
    public function testPessoa(): void
    {
        $this->pessoa->setIsActive(false);
        $this->assertRecusa(AcessoDoConectorRecusado::PESSOA_INDISPONIVEL, AcessoDoConectorRecusado::MOTIVO_PESSOA);

        $this->assertRecusa(AcessoDoConectorRecusado::PESSOA_INDISPONIVEL, AcessoDoConectorRecusado::MOTIVO_PESSOA, userId: 999);
    }

    #[TestDox('vínculo removido: recusa com o texto do conector, sem o "Selecione outro escritório" da tela')]
    public function testSemVinculo(): void
    {
        $this->vinculo = null;

        $this->assertRecusa(AcessoDoConectorRecusado::ACESSO_INDISPONIVEL);
        self::assertSame('Seu acesso a este escritório não está mais disponível.', AcessoDoConectorRecusado::ACESSO_INDISPONIVEL);
        self::assertStringNotContainsString('Selecione', AcessoDoConectorRecusado::ACESSO_INDISPONIVEL);
        self::assertStringContainsString('Selecione outro escritório', BarreirasDoEscritorio::ACESSO_INDISPONIVEL, 'o navegador segue com o texto dele');
    }

    #[TestDox('abrir de novo na mesma requisição: recusa (falha fechada), sem mexer no que a primeira abertura fixou')]
    public function testAbrirDeNovoRecusa(): void
    {
        $filtro = $this->createMock(FiltroDoEscritorio::class);
        $filtro->expects(self::once())->method('ligar')->with(self::ESCRITORIO);
        $filtro->method('estaLigadoEm')->willReturn(true);
        $portao = $this->portao($filtro);
        $portao->abrir(self::PESSOA, self::ESCRITORIO);

        try {
            $portao->abrir(self::PESSOA, self::ESCRITORIO);
            self::fail('O portão abriu duas vezes a mesma requisição.');
        } catch (AcessoDoConectorRecusado $recusa) {
            self::assertSame(AcessoDoConectorRecusado::MOTIVO_JA_ABERTO, $recusa->motivo);
            self::assertSame(AcessoDoConectorRecusado::JA_ABERTO, $recusa->getMessage());
        }
        self::assertTrue($this->tenantContext->estaFixadoSemSessao());
        self::assertTrue($this->modo->valePara($this->pessoa));
    }

    #[TestDox('modo já ligado (sem escritório fixado): abrir recusa do mesmo jeito')]
    public function testModoJaLigadoRecusa(): void
    {
        $this->modo->ligar($this->vinculo ?? throw new \LogicException());
        $filtro = $this->createMock(FiltroDoEscritorio::class);
        $filtro->expects(self::never())->method('ligar');

        $this->assertRecusa(AcessoDoConectorRecusado::JA_ABERTO, AcessoDoConectorRecusado::MOTIVO_JA_ABERTO, filtro: $filtro);
        self::assertFalse($this->tenantContext->estaFixadoSemSessao());
    }

    #[TestDox('vínculo inativo (legado): recusa')]
    public function testVinculoInativo(): void
    {
        (new \ReflectionProperty(UserTenant::class, 'isActive'))->setValue($this->vinculo, false);

        $this->assertRecusa(AcessoDoConectorRecusado::ACESSO_INDISPONIVEL);
    }

    #[TestDox('situação suspensa ou em análise na equipe: recusa com a mensagem da situação')]
    public function testSituacao(): void
    {
        $this->vinculo?->alterarSituacao(SituacaoNaEquipe::Suspenso);
        $this->assertRecusa(SituacaoNaEquipe::Suspenso->mensagemDeAcesso('Escritório A'));

        $this->vinculo?->alterarSituacao(SituacaoNaEquipe::EmAnalise);
        $this->assertRecusa(SituacaoNaEquipe::EmAnalise->mensagemDeAcesso('Escritório A'));
    }

    #[TestDox('Master com vínculo em análise ou suspenso: o navegador o deixa passar pela barreira (d); o conector o recusa')]
    public function testMasterComVinculoInativo(): void
    {
        $this->pessoa->setRoles(['ROLE_SUPER_ADMIN']);
        $this->vinculo?->alterarSituacao(SituacaoNaEquipe::EmAnalise);
        $this->assertRecusa(SituacaoNaEquipe::EmAnalise->mensagemDeAcesso('Escritório A'));

        $this->vinculo?->alterarSituacao(SituacaoNaEquipe::Suspenso);
        $this->assertRecusa(SituacaoNaEquipe::Suspenso->mensagemDeAcesso('Escritório A'));
    }

    #[TestDox('vínculo ativo com a situação fora de "aprovado" (estado de legado): recusa, sem exceção')]
    public function testVinculoAtivoSemSituacaoAprovada(): void
    {
        (new \ReflectionProperty(UserTenant::class, 'situacao'))->setValue($this->vinculo, SituacaoNaEquipe::EmAnalise);
        self::assertTrue($this->vinculo?->isActive());

        $this->assertRecusa(AcessoDoConectorRecusado::ACESSO_INDISPONIVEL);
    }

    #[TestDox('escritório suspenso, em quarentena ou inexistente: recusa')]
    public function testEscritorio(): void
    {
        $this->escritorio->setIsActive(false);
        $this->assertRecusa(AvisoDeContaSuspensa::para('Escritório A'));

        $this->escritorio->setExcluidoEm(new \DateTimeImmutable());
        $this->assertRecusa(AvisoDeContaSuspensa::paraExcluida('Escritório A'));

        $this->assertRecusa(AcessoDoConectorRecusado::ACESSO_INDISPONIVEL, tenantId: 999);
    }

    #[TestDox('pedido de exclusão pendente: a equipe é recusada com a mensagem do pedido')]
    public function testPedidoDeExclusao(): void
    {
        $this->comPedido = true;

        $this->assertRecusa(BloqueioPorPedidoDeExclusao::MENSAGEM);
    }

    #[TestDox('pedido de exclusão pendente: o PRIMÁRIO também é recusado, com a mesma mensagem (o conector não tem Meu Perfil)')]
    public function testPrimarioNaContaSuspensaAPedido(): void
    {
        $this->comPedido = true;
        $this->vinculo?->setTenantRole((new TenantRole())->setIsSystem(true));

        $this->assertRecusa(BloqueioPorPedidoDeExclusao::MENSAGEM);
    }

    #[TestDox('termos de uso da versão vigente não aceitos: recusa com a mensagem fixa')]
    public function testTermos(): void
    {
        $this->aceitou = false;

        $this->assertRecusa(AcessoDoConectorRecusado::TERMOS_NAO_ACEITOS, AcessoDoConectorRecusado::MOTIVO_TERMOS);
    }

    #[TestDox('o filtro não liga (lança): recusa, sem escritório fixado nem modo')]
    public function testFiltroQueLanca(): void
    {
        $filtro = $this->createStub(FiltroDoEscritorio::class);
        $filtro->method('ligar')->willThrowException(new \InvalidArgumentException('Filter "tenant" does not exist.'));
        $filtro->method('estaLigadoEm')->willReturn(true);

        $this->assertRecusa(AcessoDoConectorRecusado::FILTRO_NAO_LIGOU, AcessoDoConectorRecusado::MOTIVO_FILTRO, filtro: $filtro);
        self::assertFalse($this->modo->estaLigado());
        self::assertFalse($this->tenantContext->hasCurrentTenant());
    }

    #[TestDox('o filtro liga sem pegar (desligado ou com outro escritório): recusa, nunca devolve contexto')]
    public function testFiltroQueNaoPega(): void
    {
        $filtro = $this->createStub(FiltroDoEscritorio::class);
        $filtro->method('estaLigadoEm')->willReturn(false);

        $this->assertRecusa(AcessoDoConectorRecusado::FILTRO_NAO_LIGOU, AcessoDoConectorRecusado::MOTIVO_FILTRO, filtro: $filtro);
        self::assertFalse($this->modo->estaLigado());
        self::assertFalse($this->tenantContext->hasCurrentTenant());
    }

    private function assertRecusa(
        string $mensagem,
        string $motivo = AcessoDoConectorRecusado::MOTIVO_ESCRITORIO,
        int $userId = self::PESSOA,
        int $tenantId = self::ESCRITORIO,
        ?FiltroDoEscritorio $filtro = null,
    ): void {
        if ($filtro === null) {
            $filtro = $this->createStub(FiltroDoEscritorio::class);
            $filtro->method('estaLigadoEm')->willReturn(true);
        }

        try {
            $this->portao($filtro)->abrir($userId, $tenantId);
            self::fail('O portão devolveu contexto; esperava a recusa: ' . $mensagem);
        } catch (AcessoDoConectorRecusado $recusa) {
            self::assertSame($mensagem, $recusa->getMessage());
            self::assertSame($motivo, $recusa->motivo);
        }
    }

    private function portao(FiltroDoEscritorio $filtro): PortaoDoConector
    {
        $pessoas = $this->createStub(UserRepository::class);
        $pessoas->method('find')->willReturnCallback(fn (mixed $id): ?User => $id === self::PESSOA ? $this->pessoa : null);
        $escritorios = $this->createStub(TenantRepository::class);
        $escritorios->method('find')->willReturnCallback(fn (mixed $id): ?Tenant => $id === self::ESCRITORIO ? $this->escritorio : null);
        $vinculos = $this->createStub(UserTenantRepository::class);
        $vinculos->method('findPorUserETenant')->willReturnCallback(fn (): ?UserTenant => $this->vinculo);
        $aceites = $this->createStub(AceiteTermoRepository::class);
        $aceites->method('existeAceiteVigente')->willReturnCallback(fn (User $quem, string $versao): bool => $this->aceitou && $versao === TermoVigente::VERSAO);
        $regra = new RegraDosPrimarios($this->createStub(UserTenantRepository::class));

        return new PortaoDoConector(
            $pessoas,
            $escritorios,
            $vinculos,
            new BarreirasDoEscritorio($this->bloqueio(), $regra),
            $aceites,
            new TermoVigente(),
            $filtro,
            $this->modo,
            $this->tenantContext,
        );
    }

    private function bloqueio(): BloqueioPorPedidoDeExclusao
    {
        $pedidos = $this->createStub(PedidoDeExclusaoDeContaRepository::class);
        $pedidos->method('existePendente')->willReturnCallback(fn (): bool => $this->comPedido);

        return new BloqueioPorPedidoDeExclusao($pedidos, new RegraDosPrimarios($this->createStub(UserTenantRepository::class)));
    }
}
