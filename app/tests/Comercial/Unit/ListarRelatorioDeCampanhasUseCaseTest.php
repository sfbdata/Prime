<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Unit;

use App\Comercial\DTO\LinhaDoRelatorio;
use App\Comercial\DTO\PedidoDoRelatorio;
use App\Comercial\DTO\RelatorioDeCampanhas;
use App\Comercial\Repository\RelatorioDeCampanhasRepository;
use App\Comercial\Service\RastreioDaLeitura;
use App\Comercial\UseCase\ListarRelatorioDeCampanhasUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ListarRelatorioDeCampanhasUseCase::class)]
#[CoversClass(PedidoDoRelatorio::class)]
#[CoversClass(RelatorioDeCampanhas::class)]
#[CoversClass(LinhaDoRelatorio::class)]
final class ListarRelatorioDeCampanhasUseCaseTest extends TestCase
{
    #[TestDox('Pedido: aba fora da lista vira Enviadas (est e arq valem, F3e), a busca é aparada e cortada, página inválida vira 1')]
    public function testPedido(): void
    {
        $p = PedidoDoRelatorio::deQuery(['aba' => 'lixo', 'q' => '  natal  ', 'p' => '0']);
        self::assertSame(['env', 'natal', 1], [$p->aba, $p->busca, $p->pagina]);
        self::assertSame(['est', 'arq'], [PedidoDoRelatorio::deQuery(['aba' => 'est'])->aba, PedidoDoRelatorio::deQuery(['aba' => 'arq'])->aba]);
        $p = PedidoDoRelatorio::deQuery(['aba' => 'rec', 'q' => str_repeat('a', 300), 'p' => '3']);
        self::assertSame(['rec', 120, 3], [$p->aba, mb_strlen($p->busca), $p->pagina]);
        self::assertSame(1, PedidoDoRelatorio::deQuery(['p' => ['x']])->pagina);
    }

    #[TestDox('Abas, busca e paginação: a página vai ao SQL (50 por página, deslocamento), e a que passa do fim volta à última')]
    public function testPaginacao(): void
    {
        $repo = $this->createMock(RelatorioDeCampanhasRepository::class);
        $abas = ['env' => 3, 'ag' => 120, 'rec' => 0, 'est' => 1, 'arq' => 2];
        $repo->expects(self::once())->method('contarPorAba')->with(7)->willReturn($abas);
        $repo->expects(self::once())->method('contar')->with(7, 'ag', 'natal')->willReturn(120);
        $repo->expects(self::once())->method('pagina')->with(7, 'ag', 'natal', 50, 100)->willReturn([self::campanha(9)]);
        $repo->expects(self::once())->method('metricas')->with([9])->willReturn([]);

        $saida = (new ListarRelatorioDeCampanhasUseCase($repo, new RastreioDaLeitura(true, true)))->executar(new PedidoDoRelatorio('ag', 'natal', 9), 7);

        self::assertSame([3, 3, '101 a 120 de 120'], [$saida->pagina, $saida->paginas(), $saida->faixa()]);
        self::assertSame($abas, $saida->paraArray()['abas']);
    }

    #[TestDox('Aba vazia: nenhuma página é pedida e a faixa é "0 de 0"')]
    public function testVazia(): void
    {
        $repo = $this->createMock(RelatorioDeCampanhasRepository::class);
        $repo->method('contarPorAba')->willReturn(['env' => 0, 'ag' => 0, 'rec' => 0, 'est' => 0, 'arq' => 0]);
        $repo->method('contar')->willReturn(0);
        $repo->expects(self::never())->method('pagina');
        $repo->method('metricas')->willReturn([]);

        $saida = (new ListarRelatorioDeCampanhasUseCase($repo, new RastreioDaLeitura(true, true)))->executar(new PedidoDoRelatorio(), 7);

        self::assertSame(['0 de 0', [], 1], [$saida->faixa(), $saida->linhas, $saida->pagina]);
    }

    #[TestDox('A linha: selo "Teste" (estado da 5d), subtítulo com a frequência, público pelo rótulo, data agendada e métricas em lote')]
    public function testLinha(): void
    {
        $repo = $this->createMock(RelatorioDeCampanhasRepository::class);
        $repo->method('contarPorAba')->willReturn(['env' => 2, 'ag' => 0, 'rec' => 0, 'est' => 1, 'arq' => 0]);
        $repo->method('contar')->willReturn(2);
        $repo->method('pagina')->willReturn([
            self::campanha(5, ['estado' => 'teste', 'frequencia' => 'mensal', 'agendada_para' => '2031-04-05 08:30:00', 'estrela' => true]),
            self::campanha(4, ['execucoes' => 0, 'total' => 12]),
        ]);
        $repo->expects(self::once())->method('metricas')->with([5, 4])->willReturn([
            5 => ['destinatarios' => 4, 'na_fila' => 0, 'enviados' => 4, 'entregues' => 4, 'lidos' => 1, 'rejeitados' => 0, 'clicaram' => 0],
        ]);

        [$teste, $naFila] = (new ListarRelatorioDeCampanhasUseCase($repo, new RastreioDaLeitura(true, true)))->executar(new PedidoDoRelatorio(), 7)->linhas;

        self::assertSame(['Teste', 'Convite · mensal', 'Individual', '2031-04-05T08:30:00', '25%'], [$teste->rotulo, $teste->sub, $teste->publico, $teste->data, $teste->metricas->taxaDeLeitura()]);
        self::assertSame(['Na fila', 'Convite', 12, 12], [$naFila->rotulo, $naFila->sub, $naFila->metricas->destinatarios, $naFila->metricas->naFila]);
        self::assertSame([true, false], [$teste->paraArray()['estrela'], $naFila->paraArray()['estrela']], 'a estrela do Master (F3e)');
    }

    /**
     * @param array<string, mixed> $troca
     *
     * @return array{id: int, nome: string, assunto: string, titulo: string, modelo_nome: string, publico: string, frequencia: string, estado: string, total: int, execucoes: int, agendada_para: ?string, criada_em: string, estrela: bool}
     */
    private static function campanha(int $id, array $troca = []): array
    {
        /** @var array{id: int, nome: string, assunto: string, titulo: string, modelo_nome: string, publico: string, frequencia: string, estado: string, total: int, execucoes: int, agendada_para: ?string, criada_em: string, estrela: bool} */
        return array_merge([
            'id' => $id, 'nome' => 'Assunto ' . $id, 'assunto' => 'Assunto ' . $id, 'titulo' => 'Título', 'modelo_nome' => 'Convite',
            'publico' => 'indiv', 'frequencia' => 'unica', 'estado' => 'na_fila', 'total' => 4, 'execucoes' => 1,
            'agendada_para' => null, 'criada_em' => '2026-10-21 10:00:00', 'rastrear_leitura' => true, 'estrela' => false,
        ], $troca);
    }
}
