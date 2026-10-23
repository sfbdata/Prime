<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Ponto\Entity\BlocoJornada;
use App\Ponto\Entity\BlocoJornadaColaborador;
use App\Ponto\Entity\JornadaColaborador;
use App\Ponto\Entity\JornadaTenant;
use App\Ponto\Repository\JornadaColaboradorRepository;
use App\Ponto\Repository\JornadaTenantRepository;
use App\Ponto\Service\IntervaloDeTrabalho;
use App\Ponto\Service\IntervalosDeTrabalho;
use App\Ponto\Service\JornadaResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** Os trechos de trabalho da jornada efetiva, pela mesma cascata da folha (spec seguranca-b-jornada §3.2). */
#[CoversClass(IntervalosDeTrabalho::class)]
#[CoversClass(IntervaloDeTrabalho::class)]
final class IntervalosDeTrabalhoTest extends TestCase
{
    private IntervalosDeTrabalho $sut;

    protected function setUp(): void
    {
        $this->sut = new IntervalosDeTrabalho(
            new JornadaResolver(),
            $this->createStub(JornadaColaboradorRepository::class),
            $this->createStub(JornadaTenantRepository::class),
        );
    }

    #[TestDox('Bloco com intervalo vira dois trechos, sem o repouso; bloco corrido vira um')]
    public function testBlocoComIntervaloViraDoisTrechos(): void
    {
        $pessoal = new JornadaColaborador();
        $pessoal->addBloco($this->blocoPessoal([1], '09:00', '12:00', '13:00', '18:00'));
        $pessoal->addBloco($this->blocoPessoal([6], '08:00', null, null, '12:00'));

        $trechos = $this->sut->daJornada($pessoal, null);

        self::assertEquals([
            new IntervaloDeTrabalho(1, 540, 720),
            new IntervaloDeTrabalho(1, 780, 1080),
            new IntervaloDeTrabalho(6, 480, 720),
        ], $trechos);
    }

    #[TestDox('Sem blocos pessoais vale a padrão do escritório; a pessoal em blocos passa por cima dela')]
    public function testCascataPessoalSobreAPadrao(): void
    {
        $padrao = new JornadaTenant();
        $padrao->addBloco((new BlocoJornada())->setDiasSemana([2])->setEntrada('07:00')->setSaida('11:00'));
        $pessoal = new JornadaColaborador();
        $pessoal->addBloco($this->blocoPessoal([3], '14:00', null, null, '16:00'));

        self::assertEquals([new IntervaloDeTrabalho(2, 420, 660)], $this->sut->daJornada(null, $padrao));
        self::assertEquals([new IntervaloDeTrabalho(3, 840, 960)], $this->sut->daJornada($pessoal, $padrao));
    }

    #[TestDox('Sem jornada pessoal e sem padrão não há trecho nenhum')]
    public function testSemJornadaNaoHaTrecho(): void
    {
        self::assertSame([], $this->sut->daJornada(null, null));
    }

    #[TestDox('Sobreposição no mesmo dia; encostar não conta; o instante dentro do trecho exclui as pontas')]
    public function testSobreposicaoEInstante(): void
    {
        $manha = new IntervaloDeTrabalho(1, 480, 720);

        self::assertTrue($manha->sobrepoe(new IntervaloDeTrabalho(1, 600, 840)));
        self::assertFalse($manha->sobrepoe(new IntervaloDeTrabalho(1, 720, 840)), 'acaba às 12:00 e o outro começa às 12:00');
        self::assertFalse($manha->sobrepoe(new IntervaloDeTrabalho(2, 600, 840)), 'outro dia da semana');
        self::assertTrue($manha->contem(1, 9 * 3600));
        self::assertFalse($manha->contem(1, 12 * 3600));
        self::assertSame(['08:00', '12:00'], $manha->horario());
    }

    /** @param list<int> $dias */
    private function blocoPessoal(array $dias, string $entrada, ?string $repouso, ?string $retorno, string $saida): BlocoJornadaColaborador
    {
        return (new BlocoJornadaColaborador())->setDiasSemana($dias)->setEntrada($entrada)
            ->setRepouso($repouso)->setRetorno($retorno)->setSaida($saida);
    }
}
