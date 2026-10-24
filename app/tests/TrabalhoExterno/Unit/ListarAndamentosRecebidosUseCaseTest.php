<?php

declare(strict_types=1);

namespace App\Tests\TrabalhoExterno\Unit;

use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\Repository\AvisosDeAndamentoRepository;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\UseCase\ListarAndamentosRecebidosUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** O que a tela aberta recebe para o toque e o toast "Andamento · {ID}" (D10 do desenho 24.10.26). */
#[CoversClass(ListarAndamentosRecebidosUseCase::class)]
final class ListarAndamentosRecebidosUseCaseTest extends TestCase
{
    #[TestDox('sem cursor: só o cursor, para o que já estava lá não tocar')]
    public function testSemCursor(): void
    {
        $repo = $this->createMock(AvisosDeAndamentoRepository::class);
        $repo->expects(self::once())->method('ultimoId')->willReturn(41);
        $repo->expects(self::never())->method('depoisDe');

        self::assertSame(['cursor' => 41, 'andamentos' => []], (new ListarAndamentosRecebidosUseCase($repo))->executar(Apoio::contexto(Perfil::Solicitante), null));
    }

    #[TestDox('com cursor: os novos com código, título, texto e hora HH:MM; o cursor avança até o último, mesmo o de link estranho')]
    public function testComCursor(): void
    {
        $repo = $this->createMock(AvisosDeAndamentoRepository::class);
        $repo->method('depoisDe')->willReturnCallback(static fn ($u, $t, int $desde, int $limite): array => $desde === 41 && $limite === ListarAndamentosRecebidosUseCase::LIMITE ? [
            ['id' => 42, 'titulo' => 'TE-0007 · Audiência', 'mensagem' => 'Andamento: Em análise', 'url' => '/trabalho-externo?te=TE-0007', 'criada_em' => '2026-10-24 14:05:09'],
            ['id' => 43, 'titulo' => 'x', 'mensagem' => null, 'url' => '/outra-coisa', 'criada_em' => '2026-10-24 14:06:00'],
        ] : []);

        $r = (new ListarAndamentosRecebidosUseCase($repo))->executar(Apoio::contexto(Perfil::Convidado), 41);
        self::assertSame(43, $r['cursor']);
        self::assertSame([['codigo' => 'TE-0007', 'titulo' => 'TE-0007 · Audiência', 'texto' => 'Andamento: Em análise', 'hora' => '14:05']], $r['andamentos']);
    }

    #[TestDox('a pré-visualização do Master não recebe nada')]
    public function testPrevia(): void
    {
        $repo = $this->createMock(AvisosDeAndamentoRepository::class);
        $repo->expects(self::never())->method('ultimoId');
        $repo->expects(self::never())->method('depoisDe');
        $ctx = new Contexto(Apoio::usuario(1, ['ROLE_SUPER_ADMIN']), Apoio::tenant(1), Perfil::Solicitante, new \App\TrabalhoExterno\Entity\ConfiguracaoCentral(), previa: true);

        self::assertSame(['cursor' => 0, 'andamentos' => []], (new ListarAndamentosRecebidosUseCase($repo))->executar($ctx, 5));
    }
}
