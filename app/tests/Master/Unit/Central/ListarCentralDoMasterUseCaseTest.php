<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit\Central;

use App\Entity\Auth\User;
use App\Master\DTO\ItemDaCentral;
use App\Master\Entity\CentralMasterEstado;
use App\Master\Notificacoes\OrigensDaCentral;
use App\Master\Repository\CentralMasterEstadoRepository;
use App\Master\Repository\CentralMasterRespostaRepository;
use App\Master\UseCase\ListarCentralDoMasterUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ListarCentralDoMasterUseCase::class)]
#[CoversClass(OrigensDaCentral::class)]
#[CoversClass(ItemDaCentral::class)]
final class ListarCentralDoMasterUseCaseTest extends TestCase
{
    private User $master;

    protected function setUp(): void
    {
        $this->master = new User();
    }

    #[TestDox('Junta as origens, mais novas primeiro, e conta as abas pelo estado deste Master e pelas respostas')]
    public function testJuntaEConta(): void
    {
        $lida = new CentralMasterEstado($this->master, 'suporte', 1);
        $lida->marcarLida(new \DateTimeImmutable());
        $lida->definirEstrela(true);
        $arquivada = new CentralMasterEstado($this->master, 'sugestao', 2);
        $arquivada->arquivar(new \DateTimeImmutable());

        $caixa = $this->useCase(['suporte:1' => $lida, 'sugestao:2' => $arquivada], ['sugestao:1' => true])
            ->executar($this->master, 'rec', '', 1);

        self::assertSame(['sugestao:1', 'suporte:1', 'alerta_indicacao:1'], array_map(static fn (ItemDaCentral $i): string => $i->mensagem->chave(), $caixa->itens));
        self::assertSame(['rec' => 3, 'nl' => 2, 'est' => 1, 'resp' => 1, 'arq' => 1], $caixa->contagens);
        self::assertSame(3, $caixa->total);
        self::assertTrue($caixa->itens[0]->respondida);
        self::assertTrue($caixa->itens[1]->lida);
    }

    #[TestDox('Cada aba filtra como o desenho: Não lidas, Com estrela, Respondidas e Arquivadas')]
    public function testAbas(): void
    {
        $lida = new CentralMasterEstado($this->master, 'suporte', 1);
        $lida->marcarLida(new \DateTimeImmutable());
        $lida->definirEstrela(true);
        $arquivada = new CentralMasterEstado($this->master, 'sugestao', 2);
        $arquivada->arquivar(new \DateTimeImmutable());
        $estados = ['suporte:1' => $lida, 'sugestao:2' => $arquivada];
        $chaves = fn (string $aba): array => array_map(static fn (ItemDaCentral $i): string => $i->mensagem->chave(), $this->useCase($estados, ['sugestao:1' => true])->executar($this->master, $aba, '', 1)->itens);

        self::assertSame(['sugestao:1', 'alerta_indicacao:1'], $chaves('nl'));
        self::assertSame(['suporte:1'], $chaves('est'));
        self::assertSame(['sugestao:1'], $chaves('resp'));
        self::assertSame(['sugestao:2'], $chaves('arq'));
        self::assertSame($chaves('rec'), $chaves('qualquer'), 'aba desconhecida vira Recebidas');
    }

    #[TestDox('A busca olha nome, e-mail, assunto e texto, sem diferenciar maiúsculas; os contadores não levam a busca')]
    public function testBusca(): void
    {
        $caixa = $this->useCase([], [])->executar($this->master, 'rec', 'DISPARO', 1);

        self::assertSame(1, $caixa->total);
        self::assertSame('alerta_indicacao:1', $caixa->itens[0]->mensagem->chave());
        self::assertSame(4, $caixa->contagens['rec'], 'sem estado: as quatro na caixa');
    }

    #[TestDox('Página de 50: a segunda página traz o resto')]
    public function testPaginaDe50(): void
    {
        $muitas = [];
        for ($i = 1; $i <= 60; ++$i) {
            $muitas[] = MensagensDeTeste::mensagem('suporte', $i, '2026-10-01 00:00 +' . $i . ' minutes');
        }
        $estados = $this->createStub(CentralMasterEstadoRepository::class);
        $estados->method('doMaster')->willReturn([]);
        $respostas = $this->createStub(CentralMasterRespostaRepository::class);
        $respostas->method('chavesRespondidas')->willReturn([]);
        $sut = new ListarCentralDoMasterUseCase(new OrigensDaCentral([MensagensDeTeste::origem('suporte', $muitas)]), $estados, $respostas);

        $primeira = $sut->executar($this->master, 'rec', '', 1);
        $segunda = $sut->executar($this->master, 'rec', '', 2);

        self::assertSame([60, 50, 60], [$primeira->total, count($primeira->itens), $primeira->itens[0]->mensagem->idOrigem]);
        self::assertSame([10, 10], [count($segunda->itens), $segunda->itens[0]->mensagem->idOrigem]);
    }

    /**
     * @param array<string, CentralMasterEstado> $estados
     * @param array<string, true>                $respondidas
     */
    private function useCase(array $estados, array $respondidas): ListarCentralDoMasterUseCase
    {
        $repoEstados = $this->createMock(CentralMasterEstadoRepository::class);
        $repoEstados->expects(self::once())->method('doMaster')->with($this->master)->willReturn($estados);
        $repoRespostas = $this->createStub(CentralMasterRespostaRepository::class);
        $repoRespostas->method('chavesRespondidas')->willReturn($respondidas);
        $alerta = new \App\Master\DTO\MensagemDaCentral('alerta_indicacao', 1, 'alerta', 'Alerta', 'Sistema BlueJus', null, '', null, 'Disparo alto de convites', 'Disparo alto de convites: Ana enviou 30', new \DateTimeImmutable('2026-10-18 09:00'), false);

        return new ListarCentralDoMasterUseCase(new OrigensDaCentral([
            MensagensDeTeste::origem('sugestao', [MensagensDeTeste::mensagem('sugestao', 1, '2026-10-21 10:00'), MensagensDeTeste::mensagem('sugestao', 2, '2026-10-17 10:00')]),
            MensagensDeTeste::origem('suporte', [MensagensDeTeste::mensagem('suporte', 1, '2026-10-20 10:00')]),
            MensagensDeTeste::origem('alerta_indicacao', [$alerta]),
        ]), $repoEstados, $repoRespostas);
    }
}
