<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit\Central;

use App\Entity\Auth\User;
use App\Master\DTO\MarcarNaCentralInput;
use App\Master\Entity\CentralMasterEstado;
use App\Master\Notificacoes\OrigensDaCentral;
use App\Master\Repository\CentralMasterEstadoRepository;
use App\Master\UseCase\MarcarNaCentralUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(MarcarNaCentralUseCase::class)]
#[CoversClass(MarcarNaCentralInput::class)]
#[CoversClass(CentralMasterEstado::class)]
final class MarcarNaCentralUseCaseTest extends TestCase
{
    #[TestDox('Marca em lote só as mensagens que existem na origem, com o tenant da origem na linha nova')]
    public function testMarcaEmLoteSoOQueExiste(): void
    {
        $master = new User();
        $estados = $this->createMock(CentralMasterEstadoRepository::class);
        $novas = [];
        $estados->method('daMensagemOuNova')->willReturnCallback(static function (User $m, $msg) use (&$novas): CentralMasterEstado {
            return $novas[] = new CentralMasterEstado($m, $msg->origem, $msg->idOrigem, $msg->tenantId);
        });
        $estados->expects(self::atLeastOnce())->method('salvar');

        $input = MarcarNaCentralInput::doCorpo(['acao' => 'lida', 'chaves' => ['sugestao:1', 'sugestao:99', 'outra:1']]);
        self::assertNotNull($input);
        $n = $this->useCase($estados)->executar($master, $input);

        self::assertSame(1, $n);
        self::assertCount(1, $novas);
        self::assertTrue($novas[0]->isLida());
        self::assertSame(7, $novas[0]->getTenantId());
    }

    #[TestDox('Cada ação muda só o que diz: lida, não lida, estrela, sem estrela, arquivar e desarquivar')]
    #[DataProvider('acoes')]
    public function testAcoes(string $acao, bool $lida, bool $estrela, bool $arquivada): void
    {
        $master = new User();
        $estado = new CentralMasterEstado($master, 'sugestao', 1);
        $estado->marcarLida(new \DateTimeImmutable());
        $estado->arquivar(new \DateTimeImmutable());
        $estados = $this->createStub(CentralMasterEstadoRepository::class);
        $estados->method('daMensagemOuNova')->willReturn($estado);

        $this->useCase($estados)->executar($master, new MarcarNaCentralInput($acao, [['sugestao', 1]]));

        self::assertSame([$lida, $estrela, $arquivada], [$estado->isLida(), $estado->temEstrela(), $estado->isArquivada()]);
    }

    /** @return iterable<string, array{string, bool, bool, bool}> */
    public static function acoes(): iterable
    {
        yield 'lida' => ['lida', true, false, true];
        yield 'não lida' => ['nao_lida', false, false, true];
        yield 'estrela' => ['estrela', true, true, true];
        yield 'sem estrela' => ['sem_estrela', true, false, true];
        yield 'arquivar' => ['arquivar', true, false, true];
        yield 'desarquivar' => ['desarquivar', true, false, false];
    }

    #[TestDox('Corpo inválido não vira entrada: ação desconhecida, sem chaves, chave mal formada ou seleção grande demais')]
    public function testCorpoInvalido(): void
    {
        self::assertNull(MarcarNaCentralInput::doCorpo(['acao' => 'apagar', 'chaves' => ['sugestao:1']]));
        self::assertNull(MarcarNaCentralInput::doCorpo(['acao' => 'lida', 'chaves' => []]));
        self::assertNull(MarcarNaCentralInput::doCorpo(['acao' => 'lida', 'chaves' => ['sugestao:abc']]));
        self::assertNull(MarcarNaCentralInput::doCorpo(['acao' => 'lida', 'chaves' => array_fill(0, 201, 'sugestao:1')]));
        self::assertSame([['sugestao', 1]], MarcarNaCentralInput::doCorpo(['acao' => 'lida', 'chaves' => ['sugestao:1', 'sugestao:1']])?->mensagens);
    }

    private function useCase(CentralMasterEstadoRepository $estados): MarcarNaCentralUseCase
    {
        $origens = new OrigensDaCentral([MensagensDeTeste::origem('sugestao', [MensagensDeTeste::mensagem('sugestao', 1)])]);

        return new MarcarNaCentralUseCase($origens, $estados, new MockClock('2026-10-21 10:00'));
    }
}
