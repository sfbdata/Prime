<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Cargo;
use App\Entity\Tenant\Tenant;
use App\Repository\UserTenantRepository;
use App\Tarefa\DTO\EsteiraDaPastaOutput;
use App\Tarefa\Entity\CaixaDaEsteira;
use App\Tarefa\Enum\EtapaDaPasta;
use App\Tarefa\Repository\CaixaDaEsteiraRepository;
use App\Tarefa\Repository\TarefaRepository;
use App\Tarefa\Service\QuemConfiguraACaixa;
use App\Tarefa\UseCase\MontarEsteiraDaPastaUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(MontarEsteiraDaPastaUseCase::class)]
#[Group('tarefa')]
final class MontarEsteiraDaPastaUseCaseTest extends TestCase
{
    private Tenant $escritorio;
    private User $usuario;
    private \DateTimeImmutable $hoje;

    protected function setUp(): void
    {
        $this->escritorio = new Tenant();
        $this->usuario    = $this->usuario(1, 'Quem olha');
        $this->hoje       = new \DateTimeImmutable('2026-10-22');
    }

    #[TestDox('Monta as 8 caixas na ordem, com zero onde a contagem não trouxe a etapa')]
    public function testOitoCaixasNaOrdem(): void
    {
        $saida = $this->montar(['juridico' => ['total' => 4, 'atrasadas' => 0]]);

        self::assertCount(8, $saida->caixas);
        self::assertSame(EtapaDaPasta::cases(), array_map(static fn ($c) => $c->etapa, $saida->caixas));
        self::assertSame(4, $saida->caixa(EtapaDaPasta::Juridico)?->total);
        self::assertSame(0, $saida->caixa(EtapaDaPasta::Comercial)?->total);
        self::assertSame(4, $saida->totalDeAbertas());
    }

    #[TestDox('GARGALO é a caixa com mais atrasadas')]
    public function testGargaloEhAMaisAtrasada(): void
    {
        $saida = $this->montar([
            'comercial' => ['total' => 5, 'atrasadas' => 1],
            'protocolo' => ['total' => 3, 'atrasadas' => 3],
            'juridico'  => ['total' => 9, 'atrasadas' => 2],
        ]);

        self::assertSame([EtapaDaPasta::Protocolo], $this->gargalos($saida));
        self::assertSame(6, $saida->totalDeAtrasadas());
    }

    #[TestDox('No empate, o GARGALO fica com a primeira caixa da esteira')]
    public function testEmpateFicaComAPrimeira(): void
    {
        $saida = $this->montar([
            'encerramento'  => ['total' => 2, 'atrasadas' => 2],
            'controladoria' => ['total' => 2, 'atrasadas' => 2],
        ]);

        self::assertSame([EtapaDaPasta::Controladoria], $this->gargalos($saida));
    }

    #[TestDox('Sem nenhuma atrasada não há GARGALO')]
    public function testSemAtrasadaSemGargalo(): void
    {
        $saida = $this->montar(['juridico' => ['total' => 7, 'atrasadas' => 0]]);

        self::assertSame([], $this->gargalos($saida));
    }

    #[TestDox('Quem não pode configurar não recebe a equipe (o repositório nem é consultado)')]
    public function testSemPermissaoNaoCarregaMembros(): void
    {
        $vinculos = $this->createMock(UserTenantRepository::class);
        $vinculos->expects(self::never())->method('findAtivosComCargoELotacaoPorTenant');

        $saida = $this->montar([], [], false, $vinculos);

        self::assertFalse($saida->podeConfigurar);
        self::assertSame([], $saida->membros);
    }

    #[TestDox('Quem pode configurar recebe a equipe com nome e função')]
    public function testComPermissaoCarregaMembros(): void
    {
        $cargo = new Cargo();
        $cargo->setNome('Advogada');
        $vinculo = new UserTenant($this->usuario(7, 'Ana Lima'), $this->escritorio);
        $vinculo->setCargo($cargo);
        $vinculos = $this->createStub(UserTenantRepository::class);
        $vinculos->method('findAtivosComCargoELotacaoPorTenant')->willReturn([$vinculo]);

        $saida = $this->montar([], [], true, $vinculos);

        self::assertTrue($saida->podeConfigurar);
        self::assertCount(1, $saida->membros);
        self::assertSame(7, $saida->membros[0]->id);
        self::assertSame('Ana Lima', $saida->membros[0]->nome);
        self::assertSame('Advogada', $saida->membros[0]->funcao);
    }

    #[TestDox('Os nomes e os avisados que o escritório configurou chegam na caixa; as outras ficam no padrão')]
    public function testNomesConfiguradosChegamNaSaida(): void
    {
        $caixa = new CaixaDaEsteira($this->escritorio, EtapaDaPasta::Protocolo);
        $caixa->configurar('Fila do protocolo', 'Protocolo e distribuição', [$this->usuario(9, 'Bruno')]);

        $saida = $this->montar([], ['protocolo' => $caixa]);

        $protocolo = $saida->caixa(EtapaDaPasta::Protocolo);
        self::assertSame('Fila do protocolo', $protocolo?->nomeDaCaixa);
        self::assertSame('Protocolo e distribuição', $protocolo?->nomeDaEtapa);
        self::assertSame(['Bruno'], $protocolo?->avisados);
        self::assertSame([9], $protocolo?->idsDosAvisados);
        self::assertNull($saida->caixa(EtapaDaPasta::Juridico)?->nomeDaCaixa);
        self::assertSame([], $saida->caixa(EtapaDaPasta::Juridico)?->avisados);
    }

    #[TestDox('As pastas ocultas pelo cadeado seguem para a contagem')]
    public function testRepassaPastasOcultas(): void
    {
        $tarefas = $this->createMock(TarefaRepository::class);
        $tarefas->expects(self::once())->method('contarEsteira')
            ->with($this->escritorio, $this->hoje, [3, 5])->willReturn([]);

        $this->useCase($tarefas, [], false, null)->executar($this->usuario, $this->escritorio, $this->hoje, [3, 5]);
    }

    /**
     * @param array<string, array{total: int, atrasadas: int}> $contagens
     * @param array<string, CaixaDaEsteira>                    $configuradas
     */
    private function montar(array $contagens, array $configuradas = [], bool $pode = false, ?UserTenantRepository $vinculos = null): EsteiraDaPastaOutput
    {
        $tarefas = $this->createStub(TarefaRepository::class);
        $tarefas->method('contarEsteira')->willReturn($contagens);

        return $this->useCase($tarefas, $configuradas, $pode, $vinculos)->executar($this->usuario, $this->escritorio, $this->hoje);
    }

    /** @param array<string, CaixaDaEsteira> $configuradas */
    private function useCase(TarefaRepository $tarefas, array $configuradas, bool $pode, ?UserTenantRepository $vinculos): MontarEsteiraDaPastaUseCase
    {
        $caixas = $this->createStub(CaixaDaEsteiraRepository::class);
        $caixas->method('configuradasDoEscritorio')->willReturn($configuradas);
        $quem = $this->createStub(QuemConfiguraACaixa::class);
        $quem->method('pode')->willReturn($pode);

        return new MontarEsteiraDaPastaUseCase($tarefas, $caixas, $vinculos ?? $this->createStub(UserTenantRepository::class), $quem);
    }

    /** @return list<EtapaDaPasta> */
    private function gargalos(EsteiraDaPastaOutput $saida): array
    {
        return array_values(array_map(
            static fn ($c) => $c->etapa,
            array_filter($saida->caixas, static fn ($c): bool => $c->gargalo),
        ));
    }

    private function usuario(int $id, string $nome): User
    {
        $usuario = new User();
        $usuario->setFullName($nome);
        (new \ReflectionProperty(User::class, 'id'))->setValue($usuario, $id);

        return $usuario;
    }
}
