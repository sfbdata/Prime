<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Master\Repository\ContasDoSistemaRepository;
use App\Master\Repository\RecursoEstadoRepository;
use App\Master\Service\RecursosDoSistema;
use App\Master\Service\RegraDosMasters;
use App\Ponto\DTO\AlterarTrabalhoExternoInput;
use App\Ponto\Entity\ConfiguracaoDoPonto;
use App\Ponto\Exception\FuncaoDoPontoRecusadaException;
use App\Ponto\Repository\ConfiguracaoDoPontoRepository;
use App\Ponto\Service\FuncoesDoPonto;
use App\Ponto\UseCase\AlterarTrabalhoExternoDoEscritorioUseCase;
use App\Service\PermissionChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * "Permitir trabalho externo" do escritório (E12, P74): recusa sem a permissão antes da transação; grava com quem e
 * quando na linha que o repositório garantiu e travou; repetir o valor não grava.
 */
#[CoversClass(AlterarTrabalhoExternoDoEscritorioUseCase::class)]
#[CoversClass(AlterarTrabalhoExternoInput::class)]
#[Group('ponto')]
final class AlterarTrabalhoExternoDoEscritorioUseCaseTest extends TestCase
{
    private Tenant $tenant;
    private User $quem;
    private ConfiguracaoDoPontoRepository&MockObject $configuracoes;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        $this->quem = new User();
        $this->configuracoes = $this->createMock(ConfiguracaoDoPontoRepository::class);
    }

    #[TestDox('Sem a permissão de administrar o ponto do escritório: recusa e não abre transação')]
    public function testSemPermissao(): void
    {
        $this->configuracoes->expects(self::never())->method('emTransacao');

        try {
            $this->casoDeUso(false)->executar(new AlterarTrabalhoExternoInput($this->quem, $this->tenant, false));
            self::fail('esperava a recusa');
        } catch (FuncaoDoPontoRecusadaException $e) {
            self::assertSame(FuncaoDoPontoRecusadaException::SEM_PERMISSAO, $e->motivo);
        }
    }

    #[TestDox('Primeiro salvamento: desliga na linha garantida, com quem e quando')]
    public function testPrimeiroSalvamento(): void
    {
        $this->emTransacaoRoda();
        $garantida = new ConfiguracaoDoPonto($this->tenant);
        $this->configuracoes->expects(self::once())->method('garantidaETravada')
            ->with($this->tenant, self::callback(static fn (\DateTimeImmutable $agora): bool => $agora->format('Y-m-d H:i:s') === '2026-11-09 10:00:00'))
            ->willReturn($garantida);
        $this->configuracoes->expects(self::once())->method('salvar')->with(self::identicalTo($garantida), true);

        self::assertFalse($this->casoDeUso()->executar(new AlterarTrabalhoExternoInput($this->quem, $this->tenant, false)));

        self::assertFalse($garantida->permiteTrabalhoExterno());
        self::assertSame($this->quem, $garantida->getAtualizadoPor());
        self::assertSame('2026-11-09 10:00:00', $garantida->getAtualizadoEm()->format('Y-m-d H:i:s'));
        self::assertSame([], $garantida->getChaves(), 'as chaves das funções não mudam');
    }

    #[TestDox('Religa na linha que existe')]
    public function testReligaALinhaQueExiste(): void
    {
        $this->emTransacaoRoda();
        $existente = new ConfiguracaoDoPonto($this->tenant);
        $existente->definirTrabalhoExterno(false, new User(), new \DateTimeImmutable('2026-11-01'));
        $this->configuracoes->method('garantidaETravada')->willReturn($existente);
        $this->configuracoes->expects(self::once())->method('salvar')->with($existente, true);

        self::assertTrue($this->casoDeUso()->executar(new AlterarTrabalhoExternoInput($this->quem, $this->tenant, true)));

        self::assertTrue($existente->permiteTrabalhoExterno());
        self::assertSame($this->quem, $existente->getAtualizadoPor());
    }

    #[TestDox('Repetir o valor que já vale (o padrão permitido numa linha vazia): nada grava')]
    public function testSemMudancaNaoGrava(): void
    {
        $this->emTransacaoRoda();
        $vazia = new ConfiguracaoDoPonto($this->tenant);
        $this->configuracoes->method('garantidaETravada')->willReturn($vazia);
        $this->configuracoes->expects(self::never())->method('salvar');

        self::assertTrue($this->casoDeUso()->executar(new AlterarTrabalhoExternoInput($this->quem, $this->tenant, true)));
        self::assertNull($vazia->getAtualizadoPor());
    }

    #[TestDox('O corpo JSON precisa de `permitir` booleano; o escritório é o da sessão')]
    public function testCorpoDoPedido(): void
    {
        self::assertNull(AlterarTrabalhoExternoInput::doJson($this->quem, $this->tenant, []));
        self::assertNull(AlterarTrabalhoExternoInput::doJson($this->quem, $this->tenant, ['permitir' => 'true']));
        self::assertNull(AlterarTrabalhoExternoInput::doJson($this->quem, $this->tenant, ['permitir' => 1]));
        $pedido = AlterarTrabalhoExternoInput::doJson($this->quem, $this->tenant, ['permitir' => false, 'tenant' => 99]);
        self::assertNotNull($pedido);
        self::assertSame($this->tenant, $pedido->tenant);
        self::assertFalse($pedido->permitir);
    }

    private function emTransacaoRoda(): void
    {
        $this->configuracoes->method('emTransacao')->willReturnCallback(static fn (\Closure $trabalho): mixed => $trabalho());
    }

    private function casoDeUso(bool $podeConfigurar = true): AlterarTrabalhoExternoDoEscritorioUseCase
    {
        $estados = $this->createStub(RecursoEstadoRepository::class);
        $estados->method('todosPorChave')->willReturn([]);
        $relogio = new MockClock('2026-11-09 10:00:00');
        $recursos = new RecursosDoSistema(
            $estados,
            $relogio,
            $this->createStub(Security::class),
            new RegraDosMasters($this->createStub(ContasDoSistemaRepository::class)),
            new RequestStack(),
            new NullLogger(),
        );
        $permissoes = $this->createStub(PermissionChecker::class);
        $permissoes->method('canAdminister')->willReturn($podeConfigurar);

        return new AlterarTrabalhoExternoDoEscritorioUseCase(new FuncoesDoPonto($recursos, $this->configuracoes, $permissoes), $this->configuracoes, $relogio);
    }
}
