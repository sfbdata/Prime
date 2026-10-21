<?php

declare(strict_types=1);

namespace App\Tests\Expediente\Unit;

use App\Entity\Auth\User;
use App\Expediente\DTO\ResultadoDaBuscaRapidaOutput;
use App\Expediente\UseCase\BuscarRapidoUseCase;
use App\Pasta\DTO\OcorrenciaDaBuscaRapida;
use App\Pasta\Repository\PastaRepository;
use App\Repository\ResourceAccessRepository;
use App\Tests\Pasta\Unit\Cadeado\CriaCenarioDoCadeadoTrait;
use App\Twig\DocumentoBrExtension;
use App\Twig\NumeroProcessoExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * O `AcessoAPasta` é final: entra o REAL, montado sobre os dublês do cenário do cadeado
 * (`CriaCenarioDoCadeadoTrait`); as pastas ocultas vêm de `CadeadoDaPastaRepository::idsDasPastasOcultasPara`.
 */
#[CoversClass(BuscarRapidoUseCase::class)]
final class BuscarRapidoUseCaseTest extends TestCase
{
    use CriaCenarioDoCadeadoTrait;

    private PastaRepository&MockObject $pastas;
    private ResourceAccessRepository&MockObject $acessos;
    private User $user;

    protected function setUp(): void
    {
        $this->montarCenario();
        $this->pastas  = $this->createMock(PastaRepository::class);
        $this->acessos = $this->createMock(ResourceAccessRepository::class);
        $this->user    = $this->pessoa('Ana Equipe');
        $this->cadeadoRepository->method('escritorioPorId')->willReturn($this->tenant);
    }

    #[TestDox('monta os três grupos com os textos do desenho')]
    public function testMontaOsTresGrupos(): void
    {
        $this->veTodas();
        $this->devolve([
            new OcorrenciaDaBuscaRapida(1176, 'Pasta', '1176', false, nomeAcao: 'Indenização por danos morais'),
            new OcorrenciaDaBuscaRapida(1176, 'Cliente', '1176', false, nome: 'Mariana Alves Duarte', documento: '31877490215'),
            new OcorrenciaDaBuscaRapida(1176, 'Processo', '1176', false, nomeAcao: 'Indenização por danos morais', numeroProcesso: '07122841920258070001'),
        ]);

        $saida = $this->useCase()->executar('1176', $this->user, $this->tenant);

        self::assertEquals([
            new ResultadoDaBuscaRapidaOutput('Pasta', 'Pasta 1176', 'Indenização por danos morais', 1176, '1176', false),
            new ResultadoDaBuscaRapidaOutput('Cliente', 'Mariana Alves Duarte', 'CPF 318.774.902-15 · Pasta 1176', 1176, '1176', false),
            new ResultadoDaBuscaRapidaOutput('Processo', '0712284-19.2025.8.07.0001', 'Indenização por danos morais · Pasta 1176', 1176, '1176', false),
        ], $saida->resultados);
    }

    #[TestDox('sem ação, o subtítulo da pasta é o cliente; processo sem ação usa a classe; cliente sem documento só leva a pasta')]
    public function testTextosAlternativos(): void
    {
        $this->veTodas();
        $this->devolve([
            new OcorrenciaDaBuscaRapida(7, 'Pasta', '7', false, nomeDoClientePrincipal: 'Helena Prado Lima'),
            new OcorrenciaDaBuscaRapida(8, 'Pasta', '8', false, nomeClienteDaPasta: 'NOME ESCRITO'),
            new OcorrenciaDaBuscaRapida(8, 'Cliente', '8', false, nome: 'NOME ESCRITO'),
            new OcorrenciaDaBuscaRapida(9, 'Processo', '9', false, numeroProcesso: '123', classeProcessual: 'Inventário'),
            new OcorrenciaDaBuscaRapida(10, 'Cliente', '10', false, nome: 'Construtora', documento: '12408331000107'),
        ]);

        $r = $this->useCase()->executar('', $this->user, $this->tenant)->resultados;

        self::assertSame('Helena Prado Lima', $r[0]->subtitulo);
        self::assertSame('NOME ESCRITO', $r[1]->subtitulo);
        self::assertSame('Pasta 8', $r[2]->subtitulo);
        self::assertSame('123', $r[3]->titulo, 'número fora do padrão CNJ volta como veio');
        self::assertSame('Inventário · Pasta 9', $r[3]->subtitulo);
        self::assertSame('CNPJ 12.408.331/0001-07 · Pasta 10', $r[4]->subtitulo);
    }

    #[TestDox('corta em 8 resultados')]
    public function testCortaEmOito(): void
    {
        $this->veTodas();
        $this->devolve(array_map(
            static fn (int $id): OcorrenciaDaBuscaRapida => new OcorrenciaDaBuscaRapida($id, 'Pasta', (string) $id, false),
            range(1, 12),
        ));

        self::assertCount(8, $this->useCase()->executar('', $this->user, $this->tenant)->resultados);
    }

    #[TestDox('pede ao repositório o termo aparado, até 100 caracteres, o limite de 8, sem escopo e sem ocultas')]
    public function testTermoAparadoELimite(): void
    {
        $this->veTodas();
        $this->pastas->expects(self::once())
            ->method('buscarParaBuscaRapida')
            ->with(str_repeat('a', 100), $this->tenant, BuscarRapidoUseCase::LIMITE, null, [])
            ->willReturn([]);

        $saida = $this->useCase()->executar('  ' . str_repeat('a', 150) . '  ', $this->user, $this->tenant);

        self::assertSame([], $saida->resultados);
    }

    #[TestDox('quem tem resources.pasta.view busca sem restrição de escopo, sem consultar acesso por pasta')]
    public function testPermissaoGeralNaoRestringe(): void
    {
        $this->permissionChecker->expects(self::once())->method('hasPermission')
            ->with($this->user, $this->tenant, 'resources.pasta.view')->willReturn(true);
        $this->acessos->expects(self::never())->method('idsComAcesso');
        $this->pastas->expects(self::once())->method('buscarParaBuscaRapida')
            ->with('', $this->tenant, BuscarRapidoUseCase::LIMITE, null, [])
            ->willReturn([new OcorrenciaDaBuscaRapida(1, 'Pasta', '1', false)]);

        self::assertCount(1, $this->useCase()->executar('', $this->user, $this->tenant)->resultados);
    }

    #[TestDox('sem a permissão geral, o escopo são os ids com acesso próprio de ver neste escritório (uma consulta, sem repetição)')]
    public function testSemPermissaoGeralRestringeAosIdsComAcessoProprio(): void
    {
        $this->permissionChecker->method('hasPermission')->willReturn(false);
        $this->acessos->expects(self::once())->method('idsComAcesso')
            ->with($this->user, $this->tenant, 'pasta', 'view')
            ->willReturn([2, 9, 2]);
        $this->pastas->expects(self::once())->method('buscarParaBuscaRapida')
            ->with('x', $this->tenant, BuscarRapidoUseCase::LIMITE, [2, 9], [])
            ->willReturn([]);

        $this->useCase()->executar('x', $this->user, $this->tenant);
    }

    #[TestDox('sem a permissão geral e sem acesso próprio, nada é buscado')]
    public function testSemNenhumAcessoNaoBusca(): void
    {
        $this->permissionChecker->method('hasPermission')->willReturn(false);
        $this->acessos->method('idsComAcesso')->willReturn([]);
        $this->cadeadoRepository->expects(self::never())->method('idsDasPastasOcultasPara');
        $this->pastas->expects(self::never())->method('buscarParaBuscaRapida');

        self::assertSame([], $this->useCase()->executar('', $this->user, $this->tenant)->resultados);
    }

    #[TestDox('as pastas ocultas da pessoa (uma consulta) vão ao repositório, que decide o que casa delas')]
    public function testOcultasVaoAoRepositorio(): void
    {
        $this->veTodas();
        $this->cadeadoRepository->expects(self::once())->method('idsDasPastasOcultasPara')
            ->with($this->user, $this->tenant)->willReturn([5, 6]);
        $this->pastas->expects(self::once())->method('buscarParaBuscaRapida')
            ->with('joana', $this->tenant, BuscarRapidoUseCase::LIMITE, null, [5, 6])
            ->willReturn([]);

        $this->useCase()->executar('joana', $this->user, $this->tenant);
    }

    #[TestDox('o primário não tem pasta oculta: nem consulta os cadeados')]
    public function testPrimarioNaoTemOcultas(): void
    {
        $primario = $this->pessoa('Paula Primária', primario: true);
        $this->veTodas();
        $this->cadeadoRepository->expects(self::never())->method('idsDasPastasOcultasPara');
        $this->pastas->expects(self::once())->method('buscarParaBuscaRapida')
            ->with('', $this->tenant, BuscarRapidoUseCase::LIMITE, null, [])
            ->willReturn([]);

        $this->useCase()->executar('', $primario, $this->tenant);
    }

    #[TestDox('pasta oculta: restrito=true, o subtítulo é o cliente (nunca a ação) e processo dela não sai')]
    public function testOcultaRestritaSemAcaoESemProcesso(): void
    {
        $this->veTodas();
        $this->devolve([
            new OcorrenciaDaBuscaRapida(5, 'Pasta', '5', true, nomeAcao: 'Ação sigilosa', nomeDoClientePrincipal: 'Joana Sigilo'),
            new OcorrenciaDaBuscaRapida(5, 'Cliente', '5', true, nome: 'Joana Sigilo', documento: '31877490215'),
            new OcorrenciaDaBuscaRapida(5, 'Processo', '5', true, nomeAcao: 'Ação sigilosa', numeroProcesso: '07122841920258070001'),
            new OcorrenciaDaBuscaRapida(6, 'Pasta', '6', false, nomeAcao: 'Ação livre'),
        ]);

        $r = $this->useCase()->executar('', $this->user, $this->tenant)->resultados;

        self::assertEquals([
            new ResultadoDaBuscaRapidaOutput('Pasta', 'Pasta 5', 'Joana Sigilo', 5, '5', true),
            new ResultadoDaBuscaRapidaOutput('Cliente', 'Joana Sigilo', 'CPF 318.774.902-15 · Pasta 5', 5, '5', true),
            new ResultadoDaBuscaRapidaOutput('Pasta', 'Pasta 6', 'Ação livre', 6, '6', false),
        ], $r);
    }

    // ----------------------------------------------------------------- helpers

    private function useCase(): BuscarRapidoUseCase
    {
        return new BuscarRapidoUseCase(
            $this->pastas,
            $this->permissionChecker,
            $this->acessos,
            $this->acessoAPasta(),
            new DocumentoBrExtension(),
            new NumeroProcessoExtension(),
        );
    }

    private function veTodas(): void
    {
        $this->permissionChecker->method('hasPermission')->willReturn(true);
    }

    /** @param list<OcorrenciaDaBuscaRapida> $ocorrencias */
    private function devolve(array $ocorrencias): void
    {
        $this->pastas->method('buscarParaBuscaRapida')->willReturn($ocorrencias);
    }
}
