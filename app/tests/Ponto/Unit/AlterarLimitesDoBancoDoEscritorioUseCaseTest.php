<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Master\Repository\ContasDoSistemaRepository;
use App\Master\Repository\RecursoEstadoRepository;
use App\Master\Service\RecursosDoSistema;
use App\Master\Service\RegraDosMasters;
use App\Ponto\DTO\AlterarLimitesDoBancoInput;
use App\Ponto\DTO\LimitesDoBancoOutput;
use App\Ponto\Entity\ConfiguracaoDoPonto;
use App\Ponto\Exception\FuncaoDoPontoRecusadaException;
use App\Ponto\Exception\LimitesDoBancoRecusadosException;
use App\Ponto\Repository\ConfiguracaoDoPontoRepository;
use App\Ponto\Service\FuncoesDoPonto;
use App\Ponto\Service\LimitesDoBancoDeHorasDoEscritorio;
use App\Ponto\Service\TrilhaDoPonto;
use App\Ponto\UseCase\AlterarLimitesDoBancoDoEscritorioUseCase;
use App\Service\PermissionChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * "Limite do banco (horas)" e "Prazo de compensação (meses)" do escritório (E17, P73; desenho 16 L250 e L251): recusa
 * sem a permissão e fora da faixa antes da transação; grava com quem e quando na linha que o repositório garantiu e
 * travou; o que não vem no pedido fica como está; repetir o valor não grava. A trilha é o `audit_log` (o flush da
 * entidade Auditavel); a trilha encadeada do Ponto não recebe o limite nem o prazo (D5).
 */
#[CoversClass(AlterarLimitesDoBancoDoEscritorioUseCase::class)]
#[CoversClass(AlterarLimitesDoBancoInput::class)]
#[CoversClass(LimitesDoBancoOutput::class)]
#[CoversClass(LimitesDoBancoRecusadosException::class)]
#[Group('ponto')]
final class AlterarLimitesDoBancoDoEscritorioUseCaseTest extends TestCase
{
    private Tenant $tenant;
    private User $quem;
    private ConfiguracaoDoPontoRepository&MockObject $configuracoes;

    protected function setUp(): void
    {
        $tenant = $this->createStub(Tenant::class);
        $tenant->method('getId')->willReturn(7);
        $this->tenant = $tenant;
        $this->quem = new User();
        $this->configuracoes = $this->createMock(ConfiguracaoDoPontoRepository::class);
    }

    #[TestDox('Sem a permissão de administrar o ponto do escritório: recusa e não abre transação')]
    public function testSemPermissao(): void
    {
        $this->configuracoes->expects(self::never())->method('emTransacao');

        try {
            $this->casoDeUso(false)->executar(new AlterarLimitesDoBancoInput($this->quem, $this->tenant, 150, null));
            self::fail('esperava a recusa');
        } catch (FuncaoDoPontoRecusadaException $e) {
            self::assertSame(FuncaoDoPontoRecusadaException::SEM_PERMISSAO, $e->motivo);
        }
    }

    /** @return iterable<string, array{?int, ?int, string}> */
    public static function foraDaFaixa(): iterable
    {
        yield 'limite 0' => [0, null, 'O limite do banco precisa ser de pelo menos 1 hora.'];
        yield 'limite negativo' => [-10, null, 'O limite do banco precisa ser de pelo menos 1 hora.'];
        yield 'limite acima de 9999' => [10000, null, 'O limite do banco pode ser de até 9999 horas.'];
        yield 'prazo 0' => [null, 0, 'O prazo de compensação precisa ser de 1 a 24 meses.'];
        yield 'prazo 25' => [null, 25, 'O prazo de compensação precisa ser de 1 a 24 meses.'];
        yield 'limite bom e prazo fora' => [150, 25, 'O prazo de compensação precisa ser de 1 a 24 meses.'];
    }

    #[DataProvider('foraDaFaixa')]
    #[TestDox('Fora da faixa ($limite, $prazo): recusa com a frase simples e não abre transação')]
    public function testForaDaFaixa(?int $limite, ?int $prazo, string $frase): void
    {
        $this->configuracoes->expects(self::never())->method('emTransacao');

        $this->expectException(LimitesDoBancoRecusadosException::class);
        $this->expectExceptionMessage($frase);

        $this->casoDeUso()->executar(new AlterarLimitesDoBancoInput($this->quem, $this->tenant, $limite, $prazo));
    }

    #[TestDox('As pontas da faixa valem: limite 1 e 9999, prazo 1 e 24')]
    public function testPontasDaFaixa(): void
    {
        $this->emTransacaoRoda();
        $linha = new ConfiguracaoDoPonto($this->tenant);
        $this->configuracoes->method('garantidaETravada')->willReturn($linha);

        self::assertSame(['limiteHoras' => 1, 'prazoMeses' => 1], $this->casoDeUso()->executar(new AlterarLimitesDoBancoInput($this->quem, $this->tenant, 1, 1))->paraJson());
        self::assertSame(['limiteHoras' => 9999, 'prazoMeses' => 24], $this->casoDeUso()->executar(new AlterarLimitesDoBancoInput($this->quem, $this->tenant, 9999, 24))->paraJson());
    }

    #[TestDox('Primeiro salvamento do limite: grava na linha garantida, com quem e quando; o prazo fica no padrão')]
    public function testGravaOLimite(): void
    {
        $this->emTransacaoRoda();
        $garantida = new ConfiguracaoDoPonto($this->tenant);
        $this->configuracoes->expects(self::once())->method('garantidaETravada')
            ->with($this->tenant, self::callback(static fn (\DateTimeImmutable $agora): bool => $agora->format('Y-m-d H:i:s') === '2026-11-10 10:00:00'))
            ->willReturn($garantida);
        $this->configuracoes->expects(self::once())->method('salvar')->with(self::identicalTo($garantida), true);

        $saida = $this->casoDeUso()->executar(new AlterarLimitesDoBancoInput($this->quem, $this->tenant, 150, null));

        self::assertSame(['limiteHoras' => 150, 'prazoMeses' => 6], $saida->paraJson());
        self::assertSame([150, 6], [$garantida->getBancoLimiteHoras(), $garantida->getBancoPrazoMeses()]);
        self::assertSame($this->quem, $garantida->getAtualizadoPor());
        self::assertSame('2026-11-10 10:00:00', $garantida->getAtualizadoEm()->format('Y-m-d H:i:s'));
        self::assertSame([], $garantida->getChaves(), 'as chaves das funções não mudam');
        self::assertTrue($garantida->permiteTrabalhoExterno(), 'o trabalho externo não muda');
    }

    #[TestDox('O prazo grava na linha que existe e o limite gravado antes fica')]
    public function testGravaOPrazo(): void
    {
        $this->emTransacaoRoda();
        $existente = new ConfiguracaoDoPonto($this->tenant);
        $existente->definirLimitesDoBanco(200, 6, new User(), new \DateTimeImmutable('2026-11-01'));
        $this->configuracoes->method('garantidaETravada')->willReturn($existente);
        $this->configuracoes->expects(self::once())->method('salvar')->with($existente, true);

        $saida = $this->casoDeUso()->executar(new AlterarLimitesDoBancoInput($this->quem, $this->tenant, null, 12));

        self::assertSame(['limiteHoras' => 200, 'prazoMeses' => 12], $saida->paraJson());
        self::assertSame($this->quem, $existente->getAtualizadoPor());
    }

    #[TestDox('Repetir o que já vale (o padrão 120 e 6 numa linha vazia): nada grava')]
    public function testSemMudancaNaoGrava(): void
    {
        $this->emTransacaoRoda();
        $vazia = new ConfiguracaoDoPonto($this->tenant);
        $this->configuracoes->method('garantidaETravada')->willReturn($vazia);
        $this->configuracoes->expects(self::never())->method('salvar');

        $saida = $this->casoDeUso()->executar(new AlterarLimitesDoBancoInput($this->quem, $this->tenant, 120, 6));

        self::assertSame(['limiteHoras' => 120, 'prazoMeses' => 6], $saida->paraJson());
        self::assertNull($vazia->getAtualizadoPor());
    }

    #[TestDox('Depois de gravar, a leitura do mesmo pedido não fica com o valor velho (ela tinha lido "sem linha")')]
    public function testLeituraDoMesmoPedidoVeOValorNovo(): void
    {
        $this->emTransacaoRoda();
        $garantida = new ConfiguracaoDoPonto($this->tenant);
        $this->configuracoes->method('garantidaETravada')->willReturn($garantida);
        $this->configuracoes->method('doTenant')->willReturnOnConsecutiveCalls(null, $garantida);
        $limites = new LimitesDoBancoDeHorasDoEscritorio($this->configuracoes);
        self::assertSame(6, $limites->prazoEmMeses($this->tenant), 'sem linha, o padrão (e a leitura guarda isso)');

        $this->casoDeUso(true, $limites)->executar(new AlterarLimitesDoBancoInput($this->quem, $this->tenant, 40, 3));

        self::assertSame(3, $limites->prazoEmMeses($this->tenant));
        self::assertSame(40 * 60, $limites->limiteEmMinutos($this->tenant));
    }

    #[TestDox('A trilha é o audit_log do flush: o caso de uso não escreve na trilha encadeada do Ponto (D5)')]
    public function testNaoEscreveNaTrilhaEncadeada(): void
    {
        $construtor = (new \ReflectionClass(AlterarLimitesDoBancoDoEscritorioUseCase::class))->getConstructor();
        self::assertNotNull($construtor);
        $tipos = array_map(static fn (\ReflectionParameter $p): string => (string) $p->getType(), $construtor->getParameters());

        self::assertNotContains(TrilhaDoPonto::class, $tipos);
        self::assertContains(ConfiguracaoDoPontoRepository::class, $tipos, 'grava pelo repositório (flush da entidade Auditavel)');
    }

    #[TestDox('O corpo JSON: limiteHoras e/ou prazoMeses inteiros; vazio, texto ou número com casas recusam; o escritório é o da sessão')]
    public function testCorpoDoPedido(): void
    {
        foreach ([[], ['outro' => 1], ['limiteHoras' => null], ['limiteHoras' => '150'], ['limiteHoras' => 12.5], ['prazoMeses' => true], ['limiteHoras' => 150, 'prazoMeses' => '6']] as $corpo) {
            self::assertNull(AlterarLimitesDoBancoInput::doJson($this->quem, $this->tenant, $corpo), (string) json_encode($corpo));
        }

        $pedido = AlterarLimitesDoBancoInput::doJson($this->quem, $this->tenant, ['limiteHoras' => 0, 'tenant' => 99]);
        self::assertNotNull($pedido);
        self::assertSame($this->tenant, $pedido->tenant);
        self::assertSame([0, null], [$pedido->limiteHoras, $pedido->prazoMeses], 'o 0 passa no corpo: quem recusa é a faixa');

        $ambos = AlterarLimitesDoBancoInput::doJson($this->quem, $this->tenant, ['limiteHoras' => 150, 'prazoMeses' => 12]);
        self::assertNotNull($ambos);
        self::assertSame([150, 12], [$ambos->limiteHoras, $ambos->prazoMeses]);
    }

    private function emTransacaoRoda(): void
    {
        $this->configuracoes->method('emTransacao')->willReturnCallback(static fn (\Closure $trabalho): mixed => $trabalho());
    }

    private function casoDeUso(bool $podeConfigurar = true, ?LimitesDoBancoDeHorasDoEscritorio $limites = null): AlterarLimitesDoBancoDoEscritorioUseCase
    {
        $estados = $this->createStub(RecursoEstadoRepository::class);
        $estados->method('todosPorChave')->willReturn([]);
        $relogio = new MockClock('2026-11-10 10:00:00');
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

        return new AlterarLimitesDoBancoDoEscritorioUseCase(
            new FuncoesDoPonto($recursos, $this->configuracoes, $permissoes),
            $this->configuracoes,
            $relogio,
            $limites ?? new LimitesDoBancoDeHorasDoEscritorio($this->configuracoes),
        );
    }
}
