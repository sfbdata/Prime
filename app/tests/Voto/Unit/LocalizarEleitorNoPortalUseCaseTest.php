<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Entity\Tenant\Tenant;
use App\Voto\DTO\SolicitarCodigoDaAssembleiaInput;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Enum\PesoDoVoto;
use App\Voto\Repository\UnidadeDaAssembleiaRepository;
use App\Voto\UseCase\LocalizarEleitorNoPortalUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

#[CoversClass(LocalizarEleitorNoPortalUseCase::class)]
#[CoversClass(SolicitarCodigoDaAssembleiaInput::class)]
final class LocalizarEleitorNoPortalUseCaseTest extends TestCase
{
    #[TestDox('Casando nome e CPF devolve a unidade e o bloco do cadastro; não casando devolve null')]
    public function testDevolveUnidadeEBlocoOuNull(): void
    {
        $assembleia = new Assembleia(new Tenant(), 'AGO', PesoDoVoto::Unidade, 'Gestora');
        $unidade = new UnidadeDaAssembleia($assembleia, '101', str_repeat('a', 32), str_repeat('b', 64), 'B', responsavel: 'Ana', cpf: '52998224725');
        $repo = $this->createMock(UnidadeDaAssembleiaRepository::class);
        $repo->method('localizarPorNomeECpf')->willReturnCallback(static fn (Assembleia $a, string $nome, string $cpf): ?UnidadeDaAssembleia => 'Ana' === $nome && '52998224725' === $cpf ? $unidade : null);
        $caso = new LocalizarEleitorNoPortalUseCase($repo);

        self::assertSame(['unidade' => '101', 'bloco' => 'B'], $caso->executar($assembleia, 'Ana', '52998224725'));
        self::assertNull($caso->executar($assembleia, 'Ana', '11144477735'));
    }

    #[TestDox('O input com CPF exige CPF válido e dispensa a unidade; sem CPF exige a unidade')]
    public function testValidacaoDoInput(): void
    {
        $validador = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $desafio = str_repeat('a', 32);

        self::assertCount(0, $validador->validate(new SolicitarCodigoDaAssembleiaInput('Ana', '', '', 'a@b.com', $desafio, '52998224725')));
        self::assertCount(1, $validador->validate(new SolicitarCodigoDaAssembleiaInput('Ana', '', '', 'a@b.com', $desafio, '52998224726')));
        self::assertCount(1, $validador->validate(new SolicitarCodigoDaAssembleiaInput('Ana', '', '', 'a@b.com', $desafio)));
        self::assertCount(0, $validador->validate(new SolicitarCodigoDaAssembleiaInput('Ana', '101', '', 'a@b.com', $desafio)));
    }
}
