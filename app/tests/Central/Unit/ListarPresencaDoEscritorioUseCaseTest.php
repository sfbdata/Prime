<?php

declare(strict_types=1);

namespace App\Tests\Central\Unit;

use App\Central\Exception\SemAcessoACentralException;
use App\Central\Repository\ParticipanteRepository;
use App\Central\Entity\Presenca;
use App\Central\Repository\ConfiguracaoDePresencaRepository;
use App\Central\Repository\PresencaRepository;
use App\Central\Service\AcessoACentral;
use App\Central\UseCase\ListarPresencaDoEscritorioUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\UserRepository;
use App\Repository\UserTenantRepository;
use App\Tests\Inteligencia\Support\DefineId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/** Quem está on-line no escritório, para o selo do cabeçalho (spec `presenca-e-ponto-no-cabecalho.md` §2). */
#[CoversClass(ListarPresencaDoEscritorioUseCase::class)]
final class ListarPresencaDoEscritorioUseCaseTest extends TestCase
{
    private const AGORA = '2026-10-19 10:00:00';

    private function usuario(int $id): User
    {
        $u = new User();
        $u->setFullName('Pessoa ' . $id);
        DefineId::em($u, $id);

        return $u;
    }

    /** @param array<int, array{ultimaAtividade: \DateTimeImmutable, status: string, statusDesde: \DateTimeImmutable}> $presencas */
    private function casoDeUso(array $presencas, bool $comVinculo = true): ListarPresencaDoEscritorioUseCase
    {
        $vinculos = $this->createStub(UserTenantRepository::class);
        $vinculos->method('existeVinculoAtivo')->willReturn($comVinculo);
        $usuarios = $this->createStub(UserRepository::class);
        $usuarios->method('findColaboradoresAtivosPorTenant')->willReturn([$this->usuario(1), $this->usuario(2), $this->usuario(3), $this->usuario(4)]);
        $repo = $this->createStub(PresencaRepository::class);
        $repo->method('doEscritorio')->willReturn($presencas);
        // Sem escolha do primário, o limite é o padrão do desenho (60): o "65" deste teste continua valendo.
        $configuracoes = $this->createStub(ConfiguracaoDePresencaRepository::class);
        $configuracoes->method('minutosAusente')->willReturn(Presenca::INATIVO_APOS_MINUTOS);
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable(self::AGORA));

        return new ListarPresencaDoEscritorioUseCase(
            new AcessoACentral($vinculos, $this->createStub(ParticipanteRepository::class), $usuarios),
            $usuarios,
            $repo,
            $clock,
            $configuracoes,
        );
    }

    private static function presenca(string $batimento, string $status, string $desde): array
    {
        return ['ultimaAtividade' => new \DateTimeImmutable($batimento), 'status' => $status, 'statusDesde' => new \DateTimeImmutable($desde)];
    }

    #[TestDox('Só quem bateu nos últimos 60 s; semAtividadeMin só no inativo (minutos desde a troca + o limite de 60)')]
    public function testOnlineESemAtividade(): void
    {
        $lista = $this->casoDeUso([
            1 => self::presenca('2026-10-19 09:59:40', 'disponivel', '2026-10-19 08:00:00'),
            2 => self::presenca('2026-10-19 09:59:50', 'inativo', '2026-10-19 09:45:10'),
            3 => self::presenca('2026-10-19 09:58:30', 'ocupado', '2026-10-19 09:00:00'),   // 90 s: desligado
            4 => self::presenca('2026-10-19 09:59:55', 'ausente', '2026-10-19 09:30:00'),
        ])->executar($this->usuario(1), new Tenant());

        $porId = [];
        foreach ($lista as $p) {
            $porId[$p->id] = [$p->status, $p->semAtividadeMin];
        }
        self::assertSame([1 => ['disponivel', null], 2 => ['inativo', 74], 4 => ['ausente', null]], $porId);
    }

    #[TestDox('Sem vínculo ativo no escritório: recusado')]
    public function testSemVinculo(): void
    {
        $this->expectException(SemAcessoACentralException::class);
        $this->casoDeUso([], comVinculo: false)->executar($this->usuario(1), new Tenant());
    }
}
