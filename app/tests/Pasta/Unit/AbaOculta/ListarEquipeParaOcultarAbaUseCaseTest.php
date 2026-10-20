<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit\AbaOculta;

use App\Pasta\DTO\EquipeParaOcultarAbaOutput;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Service\EquipeQuePodeTerAbaOculta;
use App\Pasta\UseCase\ListarEquipeParaOcultarAbaUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ListarEquipeParaOcultarAbaUseCase::class)]
#[CoversClass(EquipeParaOcultarAbaOutput::class)]
#[CoversClass(EquipeQuePodeTerAbaOculta::class)]
#[Group('pasta')]
final class ListarEquipeParaOcultarAbaUseCaseTest extends TestCase
{
    use CriaCenarioDeAbaOcultaTrait;

    protected function setUp(): void
    {
        $this->montarCenario();
    }

    #[TestDox('Lista quem pode ter a aba oculta (sem primário nem Master), com "função · setor" e a marca da regra atual')]
    public function testLista(): void
    {
        $this->regras->method('daAba')->willReturn($this->regraPara(AbaDaPasta::Push, [4]));

        $saida = (new ListarEquipeParaOcultarAbaUseCase($this->regras, $this->equipe()))
            ->executar($this->pasta, AbaDaPasta::Push, $this->tenant)
            ->paraJson();

        self::assertSame([
            'aba'     => 'push',
            'rotulo'  => 'Push Processual',
            'total'   => 2,
            'pessoas' => [
                ['id' => 3, 'nome' => 'Ana Advogada', 'funcao' => 'Advogada · Contencioso', 'oculta' => false],
                ['id' => 4, 'nome' => 'Beto Estagiário', 'funcao' => 'Estagiário', 'oculta' => true],
            ],
        ], $saida);
    }

    #[TestDox('"Toda a equipe": todos marcados como ocultos; sem regra, ninguém')]
    public function testParaTodosESemRegra(): void
    {
        $this->regras->method('daAba')->willReturnOnConsecutiveCalls($this->regraParaTodos(AbaDaPasta::Push), null);
        $useCase = new ListarEquipeParaOcultarAbaUseCase($this->regras, $this->equipe());

        $todos = $useCase->executar($this->pasta, AbaDaPasta::Push, $this->tenant)->pessoas;
        $nenhum = $useCase->executar($this->pasta, AbaDaPasta::Push, $this->tenant)->pessoas;

        self::assertSame([true, true], array_column($todos, 'oculta'));
        self::assertSame([false, false], array_column($nenhum, 'oculta'));
    }
}
