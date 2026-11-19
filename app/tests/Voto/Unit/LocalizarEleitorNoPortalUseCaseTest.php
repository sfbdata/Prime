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
    #[TestDox('Devolve a lista de unidades que casam com nome e CPF (uma, várias) ou lista vazia')]
    public function testDevolveListaDeUnidades(): void
    {
        $assembleia = new Assembleia(new Tenant(), 'AGO', PesoDoVoto::Unidade, 'Gestora');
        $u1 = new UnidadeDaAssembleia($assembleia, '101', str_repeat('a', 32), str_repeat('b', 64), 'B', responsavel: 'Ana', cpf: '52998224725');
        $u2 = new UnidadeDaAssembleia($assembleia, '202', str_repeat('c', 32), str_repeat('d', 64), 'C', responsavel: 'Ana', cpf: '52998224725');
        $repo = $this->createMock(UnidadeDaAssembleiaRepository::class);
        $repo->method('localizarPorNomeECpf')->willReturnCallback(static fn (Assembleia $a, string $nome, string $cpf): array => match ($cpf) {
            '52998224725' => [$u1, $u2],
            '11144477735' => [$u1],
            default => [],
        });
        $caso = new LocalizarEleitorNoPortalUseCase($repo);

        self::assertSame([['unidade' => '101', 'bloco' => 'B'], ['unidade' => '202', 'bloco' => 'C']], $caso->executar($assembleia, 'Ana', '52998224725'));
        self::assertSame([['unidade' => '101', 'bloco' => 'B']], $caso->executar($assembleia, 'Ana', '11144477735'));
        self::assertSame([], $caso->executar($assembleia, 'Ana', '00000000000'));
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
