<?php

declare(strict_types=1);

namespace App\Tests\Auth\Unit;

use App\Auth\Entity\InscricaoOab;
use App\Auth\Enum\StatusOab;
use App\Auth\Enum\TipoInscricaoOab;
use App\Auth\Repository\InscricaoOabRepository;
use App\Auth\Service\ValidadorOab;
use App\Auth\UseCase\ConfirmarInscricaoOabUseCase;
use App\Auth\UseCase\ReverterInscricaoOabUseCase;
use App\Auth\UseCase\VerificarInscricaoOabUseCase;
use App\Entity\Auth\User;
use App\Tests\Auth\Doubles\OabWebServiceClientFake;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfirmarInscricaoOabUseCase::class)]
#[CoversClass(ReverterInscricaoOabUseCase::class)]
#[CoversClass(VerificarInscricaoOabUseCase::class)]
final class InscricaoOabDoMasterUseCaseTest extends TestCase
{
    #[TestDox('confirmar a principal confirma também o espelho do User; a suplementar não mexe no espelho')]
    public function testConfirmar(): void
    {
        $user = new User();
        [$principal, $suplementar] = [$this->inscricao($user, TipoInscricaoOab::Principal, 'DF'), $this->inscricao($user, TipoInscricaoOab::Suplementar, 'GO')];
        $sut = new ConfirmarInscricaoOabUseCase($this->repo([1 => $principal, 2 => $suplementar]));

        $sut->executar(2);
        self::assertSame(StatusOab::Confirmada, $suplementar->getStatus());
        self::assertNull($user->getOabStatus(), 'suplementar não vai para o espelho');

        $sut->executar(1);
        self::assertSame([StatusOab::Confirmada, 'DF'], [$user->getOabStatus(), $user->getOabUf()]);
        self::assertNotNull($user->getOabVerificadaEm());
    }

    #[TestDox('reverter aceita Pendente ou Divergente; Confirmada é recusada; id inexistente é "não encontrada"')]
    public function testReverter(): void
    {
        $user = new User();
        $i    = $this->inscricao($user, TipoInscricaoOab::Suplementar, 'GO');
        $i->registrarVerificacao(StatusOab::Confirmada, null);
        $sut = new ReverterInscricaoOabUseCase($this->repo([1 => $i]));

        $sut->executar(1, StatusOab::Divergente);
        self::assertSame(StatusOab::Divergente, $i->getStatus());

        try {
            $sut->executar(1, StatusOab::Confirmada);
            self::fail('Confirmada não é destino de reverter');
        } catch (\InvalidArgumentException) {
        }
        $this->expectExceptionMessage('Inscrição não encontrada');
        $sut->executar(99, StatusOab::NaoVerificada);
    }

    #[TestDox('verificar com o CNA dormente deixa a inscrição Pendente, sem data')]
    public function testVerificarDormente(): void
    {
        $user = new User();
        $user->setFullName('Farlei Assis da Rocha');
        $i = $this->inscricao($user, TipoInscricaoOab::Suplementar, 'GO');
        $i->registrarVerificacao(StatusOab::Divergente, null);

        (new VerificarInscricaoOabUseCase($this->repo([1 => $i]), new ValidadorOab((new OabWebServiceClientFake())->indisponivel())))->executar(1);

        self::assertSame(StatusOab::NaoVerificada, $i->getStatus());
        self::assertNull($i->getVerificadaEm());
    }

    /** @param array<int, InscricaoOab> $porId */
    private function repo(array $porId): InscricaoOabRepository
    {
        $repo = $this->createStub(InscricaoOabRepository::class);
        $repo->method('find')->willReturnCallback(static fn (mixed $id): ?InscricaoOab => $porId[(int) $id] ?? null);

        return $repo;
    }

    private function inscricao(User $user, TipoInscricaoOab $tipo, string $uf): InscricaoOab
    {
        return new InscricaoOab($user, '700', $uf, $tipo);
    }
}
