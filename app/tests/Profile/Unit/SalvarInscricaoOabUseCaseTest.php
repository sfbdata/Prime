<?php

declare(strict_types=1);

namespace App\Tests\Profile\Unit;

use App\Auth\Entity\InscricaoOab;
use App\Auth\Enum\StatusOab;
use App\Auth\Enum\TipoInscricaoOab;
use App\Auth\Repository\InscricaoOabRepository;
use App\Auth\Service\EspelhoDaOabPrincipal;
use App\Entity\Auth\User;
use App\Profile\DTO\InscricaoOabInput;
use App\Profile\UseCase\RemoverInscricaoOabUseCase;
use App\Profile\UseCase\SalvarInscricaoOabUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(SalvarInscricaoOabUseCase::class)]
#[CoversClass(RemoverInscricaoOabUseCase::class)]
#[CoversClass(EspelhoDaOabPrincipal::class)]
#[CoversClass(InscricaoOab::class)]
final class SalvarInscricaoOabUseCaseTest extends TestCase
{
    #[TestDox('primeira inscrição nasce principal e Pendente e vai para o espelho, mesmo pedida como suplementar')]
    public function testPrimeira(): void
    {
        $user = new User();
        [$sut] = $this->sut($user, []);

        self::assertTrue($sut->executar($user, new InscricaoOabInput(null, '49.613', 'df', TipoInscricaoOab::Suplementar)));
        self::assertSame(['49613', 'DF', StatusOab::NaoVerificada], [$user->getOabNumero(), $user->getOabUf(), $user->getOabStatus()]);
    }

    #[TestDox('nova principal rebaixa a antiga; o espelho passa a ser a nova')]
    public function testNovaPrincipal(): void
    {
        $user = new User();
        $df   = $this->inscricao($user, 1, '49613', 'DF', TipoInscricaoOab::Principal, StatusOab::Confirmada);
        [$sut] = $this->sut($user, [$df]);

        $sut->executar($user, new InscricaoOabInput(null, '700', 'GO', TipoInscricaoOab::Principal));

        self::assertFalse($df->ehPrincipal());
        self::assertSame(StatusOab::Confirmada, $df->getStatus(), 'rebaixar não mexe na situação');
        self::assertSame('GO', $user->getOabUf());
    }

    #[TestDox('mesmo número e UF: situação e data ficam; devolve "não pendente"')]
    public function testSemMudanca(): void
    {
        $user = new User();
        $df   = $this->inscricao($user, 1, '49613', 'DF', TipoInscricaoOab::Principal, StatusOab::Confirmada);
        [$sut] = $this->sut($user, [$df]);

        self::assertFalse($sut->executar($user, new InscricaoOabInput(1, '49613', 'DF', TipoInscricaoOab::Principal)));
        self::assertSame(StatusOab::Confirmada, $df->getStatus());
        self::assertNotNull($df->getVerificadaEm());
    }

    /** @return iterable<string, array{0: string, 1: string, 2: string, 3: TipoInscricaoOab, 4: string}> */
    public static function recusas(): iterable
    {
        yield 'letra no número' => ['12A', 'SP', '', TipoInscricaoOab::Suplementar, 'somente números'];
        yield 'UF inexistente' => ['123', 'XX', '', TipoInscricaoOab::Suplementar, 'Escolha a UF'];
        yield 'UF repetida' => ['123', 'DF', '', TipoInscricaoOab::Suplementar, 'Já existe uma inscrição na OAB/DF'];
        yield 'sem principal' => ['49613', 'DF', '1', TipoInscricaoOab::Suplementar, 'É preciso ter uma inscrição principal'];
        yield 'id de outra pessoa' => ['123', 'SP', '99', TipoInscricaoOab::Suplementar, 'Inscrição não encontrada'];
    }

    #[DataProvider('recusas')]
    #[TestDox('recusa com a mensagem do desenho: $_dataName')]
    public function testRecusas(string $numero, string $uf, string $id, TipoInscricaoOab $tipo, string $mensagem): void
    {
        $user = new User();
        $df   = $this->inscricao($user, 1, '49613', 'DF', TipoInscricaoOab::Principal, StatusOab::Confirmada);
        [$sut, $repo] = $this->sut($user, [$df]);
        $repo->expects(self::never())->method('salvar');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage($mensagem);
        $sut->executar($user, new InscricaoOabInput($id === '' ? null : (int) $id, $numero, $uf, $tipo));
    }

    #[TestDox('remover: suplementar sai; principal é recusada')]
    public function testRemover(): void
    {
        $user = new User();
        $df   = $this->inscricao($user, 1, '49613', 'DF', TipoInscricaoOab::Principal, StatusOab::Confirmada);
        $go   = $this->inscricao($user, 2, '700', 'GO', TipoInscricaoOab::Suplementar, StatusOab::NaoVerificada);
        $repo = $this->createMock(InscricaoOabRepository::class);
        $repo->method('daPessoa')->willReturnCallback(static fn (int $id): ?InscricaoOab => [1 => $df, 2 => $go][$id] ?? null);
        $repo->expects(self::once())->method('remover')->with($go, true);
        $sut = new RemoverInscricaoOabUseCase($repo);

        self::assertSame('GO', $sut->executar($user, 2));
        $this->expectExceptionMessage('principal não pode ser removida');
        $sut->executar($user, 1);
    }

    /**
     * @param list<InscricaoOab> $existentes
     *
     * @return array{0: SalvarInscricaoOabUseCase, 1: InscricaoOabRepository&\PHPUnit\Framework\MockObject\MockObject}
     */
    private function sut(User $user, array $existentes): array
    {
        $repo = $this->createMock(InscricaoOabRepository::class);
        $repo->method('doUsuario')->willReturn($existentes);

        return [new SalvarInscricaoOabUseCase($repo, new EspelhoDaOabPrincipal($repo)), $repo];
    }

    private function inscricao(User $user, int $id, string $numero, string $uf, TipoInscricaoOab $tipo, StatusOab $status): InscricaoOab
    {
        $i = new InscricaoOab($user, $numero, $uf, $tipo);
        $i->registrarVerificacao($status, null);
        (new \ReflectionProperty(InscricaoOab::class, 'id'))->setValue($i, $id);

        return $i;
    }
}
