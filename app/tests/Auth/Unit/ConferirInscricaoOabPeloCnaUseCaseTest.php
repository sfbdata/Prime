<?php

declare(strict_types=1);

namespace App\Tests\Auth\Unit;

use App\Auth\Entity\InscricaoOab;
use App\Auth\Enum\StatusOab;
use App\Auth\Enum\TipoInscricaoOab;
use App\Auth\Repository\InscricaoOabRepository;
use App\Auth\Service\ValidadorOab;
use App\Auth\UseCase\ConferirInscricaoOabPeloCnaUseCase;
use App\Entity\Auth\User;
use App\Tests\Auth\Doubles\OabWebServiceClientFake;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConferirInscricaoOabPeloCnaUseCase::class)]
final class ConferirInscricaoOabPeloCnaUseCaseTest extends TestCase
{
    #[TestDox('nome do CNA que bate (tolerante a acento e nome do meio) confirma e espelha a principal no User')]
    public function testNomeQueBateConfirma(): void
    {
        [$user, $i] = $this->inscricao(TipoInscricaoOab::Principal);

        $this->sut($i)->executar(1, '  FARLEI   ROCHA ');

        self::assertSame([StatusOab::Confirmada, 'FARLEI ROCHA'], [$i->getStatus(), $i->getNomeOficial()]);
        self::assertNotNull($i->getVerificadaEm());
        self::assertSame(StatusOab::Confirmada, $user->getOabStatus());
    }

    #[TestDox('nome diferente vai para Divergente com o nome lido; suplementar não mexe no espelho')]
    public function testNomeDiferenteDiverge(): void
    {
        [$user, $i] = $this->inscricao(TipoInscricaoOab::Suplementar);

        $this->sut($i)->executar(1, 'Outra Pessoa');

        self::assertSame([StatusOab::Divergente, 'Outra Pessoa'], [$i->getStatus(), $i->getNomeOficial()]);
        self::assertNull($user->getOabStatus());
    }

    #[TestDox('nome em branco (não localizado no CNA) registra "não encontrada": Divergente, sem nome, com data; a principal espelha no User')]
    public function testEmBrancoRegistraNaoEncontrada(): void
    {
        [$user, $i] = $this->inscricao(TipoInscricaoOab::Principal);
        $i->registrarVerificacao(StatusOab::Divergente, 'Antigo');

        $this->sut($i)->executar(1, '   ');

        self::assertSame([StatusOab::Divergente, null], [$i->getStatus(), $i->getNomeOficial()]);
        self::assertNotNull($i->getVerificadaEm(), 'resultado registrado, diferente de "ainda não consultada"');
        self::assertSame([StatusOab::Divergente, null], [$user->getOabStatus(), $user->getOabNomeOficial()]);
        self::assertNotNull($user->getOabVerificadaEm());
    }

    #[TestDox('inscrição ainda não consultada fica Não verificada e sem data: o "não encontrada" é outro resultado')]
    public function testNaoConsultadaFicaSemRegistro(): void
    {
        [, $i] = $this->inscricao(TipoInscricaoOab::Suplementar);

        self::assertSame([StatusOab::NaoVerificada, null], [$i->getStatus(), $i->getVerificadaEm()]);
    }

    #[TestDox('id inexistente é "Inscrição não encontrada"')]
    public function testInexistente(): void
    {
        [, $i] = $this->inscricao(TipoInscricaoOab::Suplementar);

        $this->expectExceptionMessage('Inscrição não encontrada');
        $this->sut($i)->executar(99, 'Farlei');
    }

    private function sut(InscricaoOab $i): ConferirInscricaoOabPeloCnaUseCase
    {
        $repo = $this->createStub(InscricaoOabRepository::class);
        $repo->method('find')->willReturnCallback(static fn (mixed $id): ?InscricaoOab => (int) $id === 1 ? $i : null);

        return new ConferirInscricaoOabPeloCnaUseCase($repo, new ValidadorOab((new OabWebServiceClientFake())->indisponivel()));
    }

    /** @return array{User, InscricaoOab} */
    private function inscricao(TipoInscricaoOab $tipo): array
    {
        $user = (new User())->setFullName('Farlei Assis da Rôcha');

        return [$user, new InscricaoOab($user, '700', 'DF', $tipo)];
    }
}
