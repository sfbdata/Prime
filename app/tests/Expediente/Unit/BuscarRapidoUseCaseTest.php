<?php

declare(strict_types=1);

namespace App\Tests\Expediente\Unit;

use App\Entity\Auth\User;
use App\Entity\Permission\ResourceAccess;
use App\Entity\Tenant\Tenant;
use App\Expediente\DTO\ResultadoDaBuscaRapidaOutput;
use App\Expediente\UseCase\BuscarRapidoUseCase;
use App\Pasta\DTO\OcorrenciaDaBuscaRapida;
use App\Pasta\Repository\PastaRepository;
use App\Repository\ResourceAccessRepository;
use App\Service\PermissionChecker;
use App\Twig\DocumentoBrExtension;
use App\Twig\NumeroProcessoExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(BuscarRapidoUseCase::class)]
final class BuscarRapidoUseCaseTest extends TestCase
{
    private PastaRepository&MockObject $pastas;
    private PermissionChecker&MockObject $checker;
    private ResourceAccessRepository&MockObject $acessos;
    private User $user;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->pastas  = $this->createMock(PastaRepository::class);
        $this->checker = $this->createMock(PermissionChecker::class);
        $this->acessos = $this->createMock(ResourceAccessRepository::class);
        $this->user    = new User();
        $this->tenant  = new Tenant();
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

    #[TestDox('pede ao repositório o termo aparado, até 100 caracteres, e o limite de 8')]
    public function testTermoAparadoELimite(): void
    {
        $this->veTodas();
        $this->pastas->expects(self::once())
            ->method('buscarParaBuscaRapida')
            ->with(str_repeat('a', 100), $this->tenant, BuscarRapidoUseCase::LIMITE, null)
            ->willReturn([]);

        $saida = $this->useCase()->executar('  ' . str_repeat('a', 150) . '  ', $this->user, $this->tenant);

        self::assertSame([], $saida->resultados);
    }

    #[TestDox('quem tem resources.pasta.view busca sem restrição, sem consultar acesso por pasta')]
    public function testPermissaoGeralNaoRestringe(): void
    {
        $this->checker->expects(self::once())->method('hasPermission')
            ->with($this->user, $this->tenant, 'resources.pasta.view')->willReturn(true);
        $this->acessos->expects(self::never())->method('findBy');
        $this->pastas->expects(self::once())->method('buscarParaBuscaRapida')
            ->with('', $this->tenant, BuscarRapidoUseCase::LIMITE, null)
            ->willReturn([new OcorrenciaDaBuscaRapida(1, 'Pasta', '1', false)]);

        self::assertCount(1, $this->useCase()->executar('', $this->user, $this->tenant)->resultados);
    }

    #[TestDox('sem a permissão geral, o repositório recebe só os ids com acesso próprio que permite ver (uma consulta)')]
    public function testSemPermissaoGeralRestringeAosIdsComAcessoProprio(): void
    {
        $this->checker->method('hasPermission')->willReturn(false);
        $this->acessos->expects(self::once())->method('findBy')
            ->with(['user' => $this->user, 'resourceType' => 'pasta'])
            ->willReturn([$this->acesso(2, true), $this->acesso(3, false), $this->acesso(2, true), $this->acesso(9, true)]);
        $this->pastas->expects(self::once())->method('buscarParaBuscaRapida')
            ->with('x', $this->tenant, BuscarRapidoUseCase::LIMITE, [2, 9])
            ->willReturn([]);

        $this->useCase()->executar('x', $this->user, $this->tenant);
    }

    #[TestDox('sem a permissão geral e sem acesso próprio, o repositório recebe lista vazia')]
    public function testSemNenhumAcessoPassaListaVazia(): void
    {
        $this->checker->method('hasPermission')->willReturn(false);
        $this->acessos->method('findBy')->willReturn([$this->acesso(3, false)]);
        $this->pastas->expects(self::once())->method('buscarParaBuscaRapida')
            ->with('', $this->tenant, BuscarRapidoUseCase::LIMITE, [])
            ->willReturn([]);

        self::assertSame([], $this->useCase()->executar('', $this->user, $this->tenant)->resultados);
    }

    #[TestDox('trancada passa adiante; abreTrancada vem de ser administrador do escritório; restrito é sempre false hoje')]
    public function testTrancadaEAbreTrancada(): void
    {
        $this->veTodas();
        $this->checker->method('isAdministradorDoEscritorio')->with($this->user, $this->tenant)->willReturn(false);
        $this->devolve([new OcorrenciaDaBuscaRapida(5, 'Pasta', '5', true)]);

        $saida = $this->useCase()->executar('', $this->user, $this->tenant);

        self::assertTrue($saida->resultados[0]->trancada);
        self::assertFalse($saida->resultados[0]->restrito);
        self::assertFalse($saida->abreTrancada);
    }

    // ----------------------------------------------------------------- helpers

    private function useCase(): BuscarRapidoUseCase
    {
        return new BuscarRapidoUseCase($this->pastas, $this->checker, $this->acessos, new DocumentoBrExtension(), new NumeroProcessoExtension());
    }

    private function veTodas(): void
    {
        $this->checker->method('hasPermission')->willReturn(true);
    }

    /** @param list<OcorrenciaDaBuscaRapida> $ocorrencias */
    private function devolve(array $ocorrencias): void
    {
        $this->pastas->method('buscarParaBuscaRapida')->willReturn($ocorrencias);
    }

    private function acesso(int $pastaId, bool $podeVer): ResourceAccess
    {
        return (new ResourceAccess())->setResourceType('pasta')->setResourceId($pastaId)->setCanView($podeVer);
    }
}
