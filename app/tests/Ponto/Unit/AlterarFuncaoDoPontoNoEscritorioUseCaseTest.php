<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Master\Entity\RecursoEstado;
use App\Master\Repository\ContasDoSistemaRepository;
use App\Master\Repository\RecursoEstadoRepository;
use App\Master\Service\RecursosDoSistema;
use App\Master\Service\RegraDosMasters;
use App\Ponto\DTO\AlterarFuncaoDoPontoInput;
use App\Ponto\Entity\ConfiguracaoDoPonto;
use App\Ponto\Exception\FuncaoDoPontoRecusadaException;
use App\Ponto\Repository\ConfiguracaoDoPontoRepository;
use App\Ponto\Service\FuncoesDoPonto;
use App\Ponto\UseCase\AlterarFuncaoDoPontoNoEscritorioUseCase;
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
 * A chave "Escritório" de uma função do Ponto (E7, P67): recusa sem a permissão, chave sem a camada e Global
 * desligado, sempre ANTES da transação; grava com quem e quando; a linha só nasce no primeiro salvamento que muda algo.
 */
#[CoversClass(AlterarFuncaoDoPontoNoEscritorioUseCase::class)]
#[CoversClass(AlterarFuncaoDoPontoInput::class)]
#[Group('ponto')]
final class AlterarFuncaoDoPontoNoEscritorioUseCaseTest extends TestCase
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

    #[TestDox('Sem a permissão de administrar o ponto do escritório: recusa (403) e não abre transação')]
    public function testSemPermissao(): void
    {
        $this->configuracoes->expects(self::never())->method('emTransacao');

        $this->recusa(FuncaoDoPontoRecusadaException::SEM_PERMISSAO, fn () => $this->casoDeUso(false)->executar($this->pedido('ponto-geo', false)));
    }

    #[TestDox('Chave sem a camada Escritório (a tela, ou uma função de outra etapa): recusa (422)')]
    public function testChaveDesconhecida(): void
    {
        $this->configuracoes->expects(self::never())->method('emTransacao');

        foreach (['ponto', 'ponto-facial', 'voto'] as $chave) {
            $this->recusa(FuncaoDoPontoRecusadaException::DESCONHECIDA, fn () => $this->casoDeUso()->executar($this->pedido($chave, false)));
        }
    }

    #[TestDox('Global desligado pelo Master: a chave do escritório fica travada (422) e nada grava')]
    public function testGlobalDesligadoTrava(): void
    {
        $this->configuracoes->expects(self::never())->method('emTransacao');
        $this->configuracoes->expects(self::never())->method('salvar');

        $this->recusa(
            FuncaoDoPontoRecusadaException::TRAVADA_PELO_MASTER,
            fn () => $this->casoDeUso(true, globalLigado: false)->executar($this->pedido('ponto-geo', true)),
        );
    }

    #[TestDox('Primeiro salvamento: cria a linha do escritório da sessão com a chave, quem e quando, e grava')]
    public function testPrimeiroSalvamentoCriaALinha(): void
    {
        $this->emTransacaoRoda();
        $this->configuracoes->method('doTenantTravado')->with($this->tenant)->willReturn(null);
        $gravada = null;
        $this->configuracoes->expects(self::once())->method('salvar')
            ->with(self::callback(static function (ConfiguracaoDoPonto $c) use (&$gravada): bool {
                $gravada = $c;

                return true;
            }), true);

        $saida = $this->casoDeUso()->executar($this->pedido('ponto-geo', false));

        self::assertInstanceOf(ConfiguracaoDoPonto::class, $gravada);
        self::assertSame($this->tenant, $gravada->getTenant());
        self::assertSame(['ponto-geo' => false], $gravada->getChaves());
        self::assertSame($this->quem, $gravada->getAtualizadoPor());
        self::assertSame('2026-11-09 10:00:00', $gravada->getAtualizadoEm()->format('Y-m-d H:i:s'));
        self::assertSame(['ponto-geo', true, false], [$saida->chave, $saida->global, $saida->escritorio]);
        self::assertSame('Geolocalização', $saida->titulo);
    }

    #[TestDox('Linha que já existe: muda só a chave pedida, na linha travada')]
    public function testAlteraALinhaQueExiste(): void
    {
        $this->emTransacaoRoda();
        $existente = new ConfiguracaoDoPonto($this->tenant);
        $existente->definir('ponto-geo', false, new User(), new \DateTimeImmutable('2026-11-01'));
        $this->configuracoes->method('doTenantTravado')->willReturn($existente);
        $this->configuracoes->expects(self::once())->method('salvar')->with($existente, true);

        $saida = $this->casoDeUso()->executar($this->pedido('ponto-geo', true));

        self::assertSame(['ponto-geo' => true], $existente->getChaves());
        self::assertSame($this->quem, $existente->getAtualizadoPor());
        self::assertTrue($saida->escritorio);
    }

    #[TestDox('Ligar o que já vale ligado (sem linha) ou repetir o valor guardado: nada grava')]
    public function testSemMudancaNaoGrava(): void
    {
        $this->emTransacaoRoda();
        $existente = new ConfiguracaoDoPonto($this->tenant);
        $existente->definir('ponto-geo', false, new User(), new \DateTimeImmutable('2026-11-01'));
        $this->configuracoes->method('doTenantTravado')->willReturnOnConsecutiveCalls(null, $existente);
        $this->configuracoes->expects(self::never())->method('salvar');

        self::assertTrue($this->casoDeUso()->executar($this->pedido('ponto-geo', true))->escritorio);
        self::assertFalse($this->casoDeUso()->executar($this->pedido('ponto-geo', false))->escritorio);
    }

    #[TestDox('O corpo JSON precisa de `ligada` booleano')]
    public function testCorpoDoPedido(): void
    {
        self::assertNull(AlterarFuncaoDoPontoInput::doJson($this->quem, $this->tenant, 'ponto-geo', []));
        self::assertNull(AlterarFuncaoDoPontoInput::doJson($this->quem, $this->tenant, 'ponto-geo', ['ligada' => 'false']));
        self::assertNull(AlterarFuncaoDoPontoInput::doJson($this->quem, $this->tenant, 'ponto-geo', ['ligada' => 0]));
        $pedido = AlterarFuncaoDoPontoInput::doJson($this->quem, $this->tenant, 'ponto-geo', ['ligada' => false, 'tenant' => 99]);
        self::assertNotNull($pedido);
        self::assertSame($this->tenant, $pedido->tenant, 'o escritório é o da sessão; o corpo não escolhe');
        self::assertFalse($pedido->ligada);
    }

    private function emTransacaoRoda(): void
    {
        $this->configuracoes->method('emTransacao')->willReturnCallback(static fn (\Closure $trabalho): mixed => $trabalho());
    }

    private function pedido(string $chave, bool $ligada): AlterarFuncaoDoPontoInput
    {
        return new AlterarFuncaoDoPontoInput($this->quem, $this->tenant, $chave, $ligada);
    }

    private function casoDeUso(bool $podeConfigurar = true, bool $globalLigado = true): AlterarFuncaoDoPontoNoEscritorioUseCase
    {
        $estados = $this->createStub(RecursoEstadoRepository::class);
        $gravados = [];
        if (!$globalLigado) {
            $gravados['ponto-geo'] = new RecursoEstado('ponto-geo');
            $gravados['ponto-geo']->desativar(null, null, null, new \DateTimeImmutable('2026-11-01'));
        }
        $estados->method('todosPorChave')->willReturn($gravados);
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

        return new AlterarFuncaoDoPontoNoEscritorioUseCase(new FuncoesDoPonto($recursos, $this->configuracoes, $permissoes), $this->configuracoes, $relogio);
    }

    private function recusa(string $motivo, \Closure $acao): void
    {
        try {
            $acao();
            self::fail('esperava a recusa ' . $motivo);
        } catch (FuncaoDoPontoRecusadaException $e) {
            self::assertSame($motivo, $e->motivo);
        }
    }
}
