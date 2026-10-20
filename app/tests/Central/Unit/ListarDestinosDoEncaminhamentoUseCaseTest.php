<?php

declare(strict_types=1);

namespace App\Tests\Central\Unit;

use App\Central\DTO\DestinoDoEncaminhamentoOutput;
use App\Central\Entity\Conversa;
use App\Central\Entity\Participante;
use App\Central\Exception\ItemEncaminhadoNaoEncontradoException;
use App\Central\Repository\ParticipanteRepository;
use App\Central\Service\GuardaDoEncaminhamento;
use App\Central\UseCase\ListarDestinosDoEncaminhamentoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Tests\Central\Support\MontaEncaminhamentoTrait;
use App\Tests\Inteligencia\Support\DefineId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** "Para quem?": minhas conversas e os colegas sem conversa, só quem vê a pasta; grupo com quem não vê vem travado. */
#[CoversClass(ListarDestinosDoEncaminhamentoUseCase::class)]
#[CoversClass(GuardaDoEncaminhamento::class)]
#[CoversClass(DestinoDoEncaminhamentoOutput::class)]
final class ListarDestinosDoEncaminhamentoUseCaseTest extends TestCase
{
    use MontaEncaminhamentoTrait;

    private Tenant $tenant;
    private Pasta $pasta;
    private User $eu;
    private User $bruno;
    private User $carla;
    private User $davi;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        $this->pasta = $this->pastaDe($this->tenant);
        $this->eu = $this->pessoa(1, 'Ana Lima');
        $this->bruno = $this->pessoa(2, 'Bruno Reis');
        $this->carla = $this->pessoa(3, 'Carla Dias');
        $this->davi = $this->pessoa(4, 'Davi Melo');
    }

    #[TestDox('Conversa 1:1 com quem vê entra; com quem não vê some; grupo travado leva o motivo; colegas sem conversa por último')]
    public function testListaCompleta(): void
    {
        $agora = new \DateTimeImmutable('2026-10-19 09:00');
        $comBruno = Conversa::individual($this->tenant, $this->eu, $this->bruno, $agora);
        $comCarla = Conversa::individual($this->tenant, $this->carla, $this->eu, $agora);
        $grupoAberto = Conversa::grupo($this->tenant, $this->eu, 'Só quem vê', $agora);
        $grupoTravado = Conversa::grupo($this->tenant, $this->eu, 'Com a Carla', $agora);
        foreach ([[$comBruno, 10], [$comCarla, 11], [$grupoAberto, 12], [$grupoTravado, 13]] as [$c, $id]) {
            DefineId::em($c, $id);
        }
        $membros = [10 => [$this->eu, $this->bruno], 11 => [$this->carla, $this->eu], 12 => [$this->eu, $this->bruno], 13 => [$this->eu, $this->bruno, $this->carla]];

        $destinos = $this->listar([1, 2, 4], [$comBruno, $comCarla, $grupoAberto, $grupoTravado], $membros);

        self::assertSame([
            ['tipo' => 'conversa', 'conversaId' => 10, 'usuarioId' => 2, 'nome' => 'Bruno Reis', 'grupo' => false, 'disponivel' => true, 'motivo' => null],
            ['tipo' => 'conversa', 'conversaId' => 12, 'usuarioId' => null, 'nome' => 'Só quem vê', 'grupo' => true, 'disponivel' => true, 'motivo' => null],
            ['tipo' => 'conversa', 'conversaId' => 13, 'usuarioId' => null, 'nome' => 'Com a Carla', 'grupo' => true, 'disponivel' => false, 'motivo' => 'Nem todos deste grupo veem esta pasta.'],
            ['tipo' => 'colega', 'conversaId' => null, 'usuarioId' => 4, 'nome' => 'Davi Melo', 'grupo' => false, 'disponivel' => true, 'motivo' => null],
        ], array_map(static fn (DestinoDoEncaminhamentoOutput $d): array => $d->toArray(), $destinos));
    }

    #[TestDox('Colega que saiu do escritório: a 1:1 com ele some da lista')]
    public function testConversaComQuemSaiu(): void
    {
        $comBruno = Conversa::individual($this->tenant, $this->eu, $this->bruno, new \DateTimeImmutable());
        DefineId::em($comBruno, 10);

        $destinos = $this->listar([1, 2], [$comBruno], [10 => [$this->eu, $this->bruno]], ativos: [$this->eu]);

        self::assertSame([], $destinos);
    }

    #[TestDox('Grupo em que só eu sigo no escritório: fica na lista, indisponível, com o motivo (ninguém para receber)')]
    public function testGrupoSemNinguemQueReceba(): void
    {
        $grupo = Conversa::grupo($this->tenant, $this->eu, 'Esvaziado', new \DateTimeImmutable());
        DefineId::em($grupo, 30);

        $destinos = $this->listar([1, 2], [$grupo], [30 => [$this->eu, $this->bruno]], ativos: [$this->eu]);

        self::assertCount(1, $destinos);
        self::assertFalse($destinos[0]->disponivel);
        self::assertSame('Ninguém mais nesta conversa pode receber.', $destinos[0]->motivo);
    }

    #[TestDox('Pasta de outro escritório ou que eu não vejo: 404, sem lista')]
    public function testPastaQueNaoVejo(): void
    {
        $this->expectException(ItemEncaminhadoNaoEncontradoException::class);
        $this->listar([2, 3], [], []);
    }

    #[TestDox('Pasta excluída (lápide): 404, mesmo para quem a via')]
    public function testPastaExcluida(): void
    {
        $this->pasta->marcarExcluida($this->bruno, new \DateTimeImmutable());

        $this->expectException(ItemEncaminhadoNaoEncontradoException::class);
        $this->listar([1, 2], [], []);
    }

    #[TestDox('"Vê a pasta?" é perguntado uma vez por pessoa, mesmo com ela em vários grupos')]
    public function testPodeVerUmaVezPorPessoa(): void
    {
        $agora = new \DateTimeImmutable();
        $g1 = Conversa::grupo($this->tenant, $this->eu, 'G1', $agora);
        $g2 = Conversa::grupo($this->tenant, $this->eu, 'G2', $agora);
        DefineId::em($g1, 20);
        DefineId::em($g2, 21);
        $perguntas = [];
        $checker = $this->createStub(\App\Service\PermissionChecker::class);
        $checker->method('canAccessResource')->willReturnCallback(static function (User $u) use (&$perguntas): bool {
            $perguntas[] = (int) $u->getId();

            return true;
        });
        $participantes = $this->createStub(ParticipanteRepository::class);
        $participantes->method('doUsuario')->willReturn([
            new Participante($this->tenant, $g1, $this->eu, $agora),
            new Participante($this->tenant, $g2, $this->eu, $agora),
        ]);
        $participantes->method('daConversaInteira')->willReturnCallback(
            fn (Conversa $c): array => $this->participacoes($this->tenant, $c, [$this->eu, $this->bruno]),
        );
        (new ListarDestinosDoEncaminhamentoUseCase(
            $this->acessoACentral([$this->eu, $this->bruno], $participantes),
            $this->guarda($this->pasta, new \App\Pasta\Service\AcessoAPasta((new \ReflectionClass(\App\Pasta\Service\NivelNoCadeado::class))->newInstanceWithoutConstructor(), $checker), $participantes),
            $participantes,
        ))->executar($this->eu, $this->tenant, 50);

        sort($perguntas);
        self::assertSame([1, 2], $perguntas, 'eu (na guarda da pasta) e o Bruno uma vez só');
    }

    #[TestDox('Pasta que não existe neste escritório: 404')]
    public function testPastaDeOutroEscritorio(): void
    {
        $participantes = $this->createStub(ParticipanteRepository::class);
        $useCase = new ListarDestinosDoEncaminhamentoUseCase(
            $this->acessoACentral([$this->eu], $participantes),
            $this->guarda(null, $this->acessoAPasta([1]), $participantes),
            $participantes,
        );

        $this->expectException(ItemEncaminhadoNaoEncontradoException::class);
        $useCase->executar($this->eu, $this->tenant, 50);
    }

    /**
     * @param list<int>              $quemVe
     * @param list<Conversa>         $minhas
     * @param array<int, list<User>> $membros
     * @param list<User>|null        $ativos
     *
     * @return list<DestinoDoEncaminhamentoOutput>
     */
    private function listar(array $quemVe, array $minhas, array $membros, ?array $ativos = null): array
    {
        $participantes = $this->createStub(ParticipanteRepository::class);
        $participantes->method('doUsuario')->willReturn(array_map(
            fn (Conversa $c): Participante => new Participante($this->tenant, $c, $this->eu, new \DateTimeImmutable()),
            $minhas,
        ));
        $participantes->method('daConversaInteira')->willReturnCallback(
            fn (Conversa $c): array => $this->participacoes($this->tenant, $c, $membros[(int) $c->getId()]),
        );

        return (new ListarDestinosDoEncaminhamentoUseCase(
            $this->acessoACentral($ativos ?? [$this->eu, $this->bruno, $this->carla, $this->davi], $participantes),
            $this->guarda($this->pasta, $this->acessoAPasta($quemVe), $participantes),
            $participantes,
        ))->executar($this->eu, $this->tenant, 50);
    }
}
