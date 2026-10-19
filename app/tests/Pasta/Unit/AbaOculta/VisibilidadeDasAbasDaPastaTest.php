<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit\AbaOculta;

use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\AbasDaPastaOutput;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Exception\AbaOcultaRecusadaException;
use App\Pasta\Service\VisibilidadeDasAbasDaPasta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(VisibilidadeDasAbasDaPasta::class)]
#[CoversClass(AbasDaPastaOutput::class)]
#[Group('pasta')]
final class VisibilidadeDasAbasDaPastaTest extends TestCase
{
    use CriaCenarioDeAbaOcultaTrait;

    protected function setUp(): void
    {
        $this->montarCenario();
    }

    #[TestDox('Sem regra: a aba é visível para todos, e o vínculo nem é consultado')]
    public function testSemRegraVisivel(): void
    {
        $this->regras->method('daAba')->willReturn(null);
        $this->vinculos->expects(self::never())->method('findAtivoPorUserETenant');

        self::assertFalse($this->visibilidade()->ocultaPara($this->pasta, AbaDaPasta::Push, $this->ana));
    }

    #[TestDox('Para toda a equipe: oculta para o colaborador comum')]
    public function testParaTodosOcultaParaComum(): void
    {
        $this->regras->method('daAba')->willReturn($this->regraParaTodos(AbaDaPasta::Financeiro));

        self::assertTrue($this->visibilidade()->ocultaPara($this->pasta, AbaDaPasta::Financeiro, $this->ana));
        self::assertTrue($this->visibilidade()->ocultaPara($this->pasta, AbaDaPasta::Financeiro, $this->beto));
    }

    #[TestDox('Lista: oculta só para quem está nela')]
    public function testListaOcultaSoParaQuemEstaNela(): void
    {
        $this->regras->method('daAba')->willReturn($this->regraPara(AbaDaPasta::Push, [3]));

        self::assertTrue($this->visibilidade()->ocultaPara($this->pasta, AbaDaPasta::Push, $this->ana));
        self::assertFalse($this->visibilidade()->ocultaPara($this->pasta, AbaDaPasta::Push, $this->beto));
    }

    #[TestDox('Master e primário nunca são ocultados, nem por "toda a equipe" nem por estarem na lista')]
    public function testMasterEPrimarioNunca(): void
    {
        $this->regras->method('daAba')->willReturnOnConsecutiveCalls(
            $this->regraParaTodos(AbaDaPasta::Push),
            $this->regraParaTodos(AbaDaPasta::Push),
            $this->regraPara(AbaDaPasta::Push, [1, 2]),
            $this->regraPara(AbaDaPasta::Push, [1, 2]),
        );
        $vis = $this->visibilidade();

        self::assertFalse($vis->ocultaPara($this->pasta, AbaDaPasta::Push, $this->primario));
        self::assertFalse($vis->ocultaPara($this->pasta, AbaDaPasta::Push, $this->master));
        self::assertFalse($vis->ocultaPara($this->pasta, AbaDaPasta::Push, $this->primario));
        self::assertFalse($vis->ocultaPara($this->pasta, AbaDaPasta::Push, $this->master));
    }

    #[TestDox('Primário com a conta desativada deixa de configurar e passa a ser alcançado pela regra')]
    public function testPrimarioDesativadoNaoConfigura(): void
    {
        $this->primario->setIsActive(false);
        $this->regras->method('daAba')->willReturn($this->regraParaTodos(AbaDaPasta::Push));

        self::assertTrue($this->visibilidade()->ocultaPara($this->pasta, AbaDaPasta::Push, $this->primario));
    }

    #[TestDox('abasOcultasPara: todas as regras da pasta numa consulta; só as que alcançam a pessoa')]
    public function testAbasOcultasPara(): void
    {
        $this->regras->expects(self::exactly(2))->method('daPasta')->willReturn([
            $this->regraParaTodos(AbaDaPasta::Financeiro),
            $this->regraPara(AbaDaPasta::Push, [4]),
        ]);
        $this->regras->expects(self::never())->method('daAba');

        self::assertSame([AbaDaPasta::Financeiro], $this->visibilidade()->abasOcultasPara($this->pasta, $this->ana));
        self::assertSame([AbaDaPasta::Financeiro, AbaDaPasta::Push], $this->visibilidade()->abasOcultasPara($this->pasta, $this->beto));
    }

    #[TestDox('Tela para quem é oculto: as abas somem, a primeira visível vira a ativa e não há regras à mostra')]
    public function testEstadoParaTelaDoOculto(): void
    {
        $this->regras->method('daPasta')->willReturn([$this->regraParaTodos(AbaDaPasta::Dados), $this->regraPara(AbaDaPasta::Push, [3])]);

        $estado = $this->visibilidade()->estadoParaTela($this->pasta, $this->ana);

        self::assertSame(['dados', 'push'], $estado->ocultas);
        self::assertFalse($estado->podeConfigurar);
        self::assertSame([], $estado->regras);
        self::assertSame('tarefas', $estado->primeiraVisivel());
        self::assertFalse($estado->visivel('push'));
        self::assertTrue($estado->visivel('financeiro'));
    }

    #[TestDox('Tela para o primário: nada oculto, as regras com o title do ícone e o título do menu do desenho')]
    public function testEstadoParaTelaDoPrimario(): void
    {
        $this->regras->method('daPasta')->willReturn([$this->regraParaTodos(AbaDaPasta::Financeiro), $this->regraPara(AbaDaPasta::Push, [3, 4])]);

        $estado = $this->visibilidade()->estadoParaTela($this->pasta, $this->primario);

        self::assertSame([], $estado->ocultas);
        self::assertTrue($estado->podeConfigurar);
        self::assertSame('dados', $estado->primeiraVisivel());
        self::assertSame('Oculta para toda a equipe', $estado->dicaDoIcone('financeiro'));
        self::assertSame('Oculta para 2 pessoa(s)', $estado->dicaDoIcone('push'));
        self::assertSame(' · oculta para toda a equipe', $estado->sufixoDoMenu('financeiro'));
        self::assertSame(' · oculta para 2', $estado->sufixoDoMenu('push'));
        self::assertSame('', $estado->sufixoDoMenu('dados'));
        self::assertFalse($estado->temRegra('dados'));
    }

    #[TestDox('Todas as abas ocultas: não há primeira visível (a tela mostra o aviso)')]
    public function testNenhumaVisivel(): void
    {
        $estado = new AbasDaPastaOutput(AbasDaPastaOutput::ORDEM, false, []);

        self::assertNull($estado->primeiraVisivel());
    }

    #[TestDox('Guarda das escritas: pasta de outro escritório e quem não é primário nem Master são recusados')]
    public function testGuardaDasEscritas(): void
    {
        $vis = $this->visibilidade();
        $vis->garantirQuePodeConfigurar($this->pasta, $this->primario, $this->tenant);
        $vis->garantirQuePodeConfigurar($this->pasta, $this->master, $this->tenant);

        try {
            $vis->garantirQuePodeConfigurar($this->pasta, $this->ana, $this->tenant);
            self::fail('colaborador comum não configura');
        } catch (AbaOcultaRecusadaException $e) {
            self::assertSame('Só o usuário primário pode ocultar abas', $e->getMessage());
            self::assertFalse($e->ehPastaDeOutroEscritorio());
        }

        $this->expectException(AbaOcultaRecusadaException::class);
        $vis->garantirQuePodeConfigurar($this->pasta, $this->primario, new Tenant());
    }
}
