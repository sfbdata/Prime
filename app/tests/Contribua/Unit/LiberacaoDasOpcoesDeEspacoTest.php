<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Unit;

use App\Contribua\Entity\EspacoDoEscritorio;
use App\Contribua\Enum\OrigemDaLiberacao;
use App\Contribua\Service\LiberacaoDasOpcoesDeEspaco;
use App\Tests\Contribua\Doubles\CriaSituacaoDoEspaco;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(LiberacaoDasOpcoesDeEspaco::class)]
#[CoversClass(EspacoDoEscritorio::class)]
final class LiberacaoDasOpcoesDeEspacoTest extends TestCase
{
    use CriaSituacaoDoEspaco;

    /** @return iterable<string, array{float, bool}> */
    public static function usosEm10Gb(): iterable
    {
        yield '9,6 GB (96%) não libera' => [9.6, false];
        yield '9,69 GB (96,9%) não libera, mesmo arredondando para 97' => [9.69, false];
        yield '9,7 GB (97%) libera' => [9.7, true];
        yield '10 GB (100%) libera' => [10.0, true];
        yield '24 GB (240%) libera' => [24.0, true];
    }

    #[DataProvider('usosEm10Gb')]
    #[TestDox('Sem liberação do Master, libera só com uso a partir de 97% do limite')]
    public function testRegraDos97PorCento(float $usoEmGb, bool $liberadas): void
    {
        $retrato = $this->situacaoDeTeste($this->gb($usoEmGb))->doEscritorio(7);

        self::assertSame($liberadas, $retrato->opcoesLiberadas);
        self::assertSame($liberadas ? OrigemDaLiberacao::Auto : null, $retrato->liberadaPor);
    }

    #[TestDox('A marca automática gravada não vale quando o uso volta abaixo de 97%')]
    public function testAutoSoVale97(): void
    {
        $espaco = new EspacoDoEscritorio($this->escritorioDeTeste(), $this->planosDeTeste()->free());
        $espaco->liberarOpcoes(OrigemDaLiberacao::Auto, new \DateTimeImmutable());

        self::assertFalse($this->situacaoDeTeste($this->gb(5), $espaco)->doEscritorio(7)->opcoesLiberadas);
        self::assertSame(OrigemDaLiberacao::Auto, $this->situacaoDeTeste($this->gb(9.8), $espaco)->doEscritorio(7)->liberadaPor);
    }

    #[TestDox('A liberação do Master é permanente, com qualquer uso, e prevalece sobre a automática')]
    public function testMasterPermanente(): void
    {
        $espaco = new EspacoDoEscritorio($this->escritorioDeTeste(), $this->planosDeTeste()->free());
        $espaco->liberarOpcoes(OrigemDaLiberacao::Master, new \DateTimeImmutable());

        self::assertSame(OrigemDaLiberacao::Master, $this->situacaoDeTeste($this->gb(1), $espaco)->doEscritorio(7)->liberadaPor);
        self::assertSame(OrigemDaLiberacao::Master, $this->situacaoDeTeste($this->gb(10), $espaco)->doEscritorio(7)->liberadaPor);
        self::assertFalse($espaco->liberarOpcoes(OrigemDaLiberacao::Auto, new \DateTimeImmutable()), 'a automática não rebaixa a do Master');
        self::assertSame(OrigemDaLiberacao::Master, $espaco->getOpcoesLiberadasPor());
    }

    #[TestDox('Master sobrescreve a marca automática; a automática repetida não grava de novo')]
    public function testGravacaoDaMarca(): void
    {
        $espaco = new EspacoDoEscritorio($this->escritorioDeTeste(), $this->planosDeTeste()->free());

        self::assertTrue($espaco->liberarOpcoes(OrigemDaLiberacao::Auto, new \DateTimeImmutable()));
        self::assertFalse($espaco->liberarOpcoes(OrigemDaLiberacao::Auto, new \DateTimeImmutable()));
        self::assertTrue($espaco->liberarOpcoes(OrigemDaLiberacao::Master, new \DateTimeImmutable()));
        self::assertSame(OrigemDaLiberacao::Master, $espaco->getOpcoesLiberadasPor());
    }

    #[TestDox('Trava D2: escritório Free sem liberação é recusado, com mensagem simples')]
    public function testTravaRecusaSemLiberacao(): void
    {
        $trava = new LiberacaoDasOpcoesDeEspaco($this->situacaoDeTeste($this->gb(2)), $this->planosDeTeste());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(LiberacaoDasOpcoesDeEspaco::NAO_LIBERADAS);
        $trava->exigirParaComprar($this->quemDeTeste(primario: true));
    }

    #[TestDox('Trava D2: passa com liberação do Master, com 97% de uso, para o Master e para quem já paga plano')]
    public function testTravaPassa(): void
    {
        $liberado = new EspacoDoEscritorio($this->escritorioDeTeste(), $this->planosDeTeste()->free());
        $liberado->liberarOpcoes(OrigemDaLiberacao::Master, new \DateTimeImmutable());
        $pago = new EspacoDoEscritorio($this->escritorioDeTeste(), $this->planosDeTeste()->findOneBy(['codigo' => 'essencial']));

        $casos = [
            'liberado pelo Master' => [$this->situacaoDeTeste($this->gb(1), $liberado), false],
            'uso a partir de 97%' => [$this->situacaoDeTeste($this->gb(9.8)), false],
            'Master' => [$this->situacaoDeTeste($this->gb(1)), true],
            'plano pago ativo' => [$this->situacaoDeTeste($this->gb(1), $pago), false],
        ];
        foreach ($casos as $nome => [$situacao, $master]) {
            (new LiberacaoDasOpcoesDeEspaco($situacao, $this->planosDeTeste()))->exigirParaComprar($this->quemDeTeste(primario: !$master, master: $master));
            self::assertTrue(true, $nome);
        }
    }
}
