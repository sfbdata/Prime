<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit\CarimboVisivel;

use App\AssinaturaEletronica\Service\CarimboVisivelObjetos;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\LimitesDaConferencia;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(CarimboVisivelObjetos::class)]
final class CarimboVisivelObjetosTest extends TestCase
{
    #[TestDox('cada objeto é lido uma vez: a segunda leitura não soma trabalho')]
    public function testLeituraUmaVez(): void
    {
        $objetos = new CarimboVisivelObjetos(MontadorDePdfDoCarimbo::classico([1 => '<</A 1>>', 2 => '<</B 2>>']));

        self::assertSame('<</A 1>>', $objetos->objeto(1, 0));
        $trabalho = $objetos->trabalho();
        self::assertSame('<</A 1>>', $objetos->objeto(1, 0));
        self::assertSame($trabalho, $objetos->trabalho());
        self::assertNull($objetos->objeto(7, 0));
    }

    #[TestDox('cada objeto é analisado uma vez, com o tamanho do corpo somado ao trabalho')]
    public function testAnaliseUmaVez(): void
    {
        $objetos = new CarimboVisivelObjetos(MontadorDePdfDoCarimbo::classico([1 => '<</A 1>>']));
        $vezes = 0;
        $analisar = static function (string $corpo) use (&$vezes): int {
            ++$vezes;

            return \strlen($corpo);
        };

        $objetos->objeto(1, 0);
        $trabalho = $objetos->trabalho();
        self::assertSame(8, $objetos->analisado(1, 0, $analisar));
        self::assertSame($trabalho + 8, $objetos->trabalho());
        self::assertSame(8, $objetos->analisado(1, 0, $analisar));
        self::assertSame(1, $vezes);
        self::assertSame($trabalho + 8, $objetos->trabalho());
        self::assertNull($objetos->analisado(7, 0, $analisar));
        self::assertSame(1, $vezes);
    }

    #[TestDox('passar do orçamento de trabalho da procura lança')]
    public function testOrcamentoDeTrabalho(): void
    {
        $objetos = new CarimboVisivelObjetos('%PDF-1.7');
        $objetos->gastar(CarimboVisivelObjetos::TRABALHO_MAXIMO_EM_BYTES);

        $this->expectException(\OverflowException::class);
        $objetos->gastar(1);
    }

    #[TestDox('teto de objetos diferentes lidos, contando os que não existem')]
    public function testTetoDeObjetos(): void
    {
        $objetos = new CarimboVisivelObjetos(MontadorDePdfDoCarimbo::classico([1 => '<</A 1>>']));
        for ($numero = 1; $numero <= CarimboVisivelObjetos::OBJETOS_MAXIMOS; ++$numero) {
            $objetos->objeto($numero, 0);
        }
        self::assertSame('<</A 1>>', $objetos->objeto(1, 0), 'o que já foi lido continua valendo');

        $this->expectException(\OverflowException::class);
        $objetos->objeto(CarimboVisivelObjetos::OBJETOS_MAXIMOS + 1, 0);
    }

    #[TestDox('/ObjStm: inflado uma vez para todos os objetos dele; o que não cabe no que resta guardar para a procura')]
    public function testFluxosDeObjetos(): void
    {
        $pdf = MontadorDePdfDoCarimbo::classico([
            1 => MontadorDePdfDoCarimbo::fluxoDeObjetos([2 => '<</B 2>>', 3 => '<</C 3>>']),
            4 => MontadorDePdfDoCarimbo::fluxoDeObjetos([9 => '<<>>', 99 => ''], LimitesDaConferencia::INFLADO_MAXIMO_EM_BYTES - 64),
            5 => MontadorDePdfDoCarimbo::fluxoDeObjetos([8 => '<<>>', 98 => ''], LimitesDaConferencia::INFLADO_MAXIMO_EM_BYTES - 64),
        ]);
        $objetos = new CarimboVisivelObjetos($pdf);

        self::assertSame('<<>>', $objetos->objeto(8, 0));
        self::assertNull($objetos->objeto(2, 0), 'a segunda isca não cabe: a procura em fluxos para');
        self::assertLessThanOrEqual(CarimboVisivelObjetos::GUARDADO_MAXIMO_EM_BYTES, $objetos->guardado());

        $semIscas = new CarimboVisivelObjetos(MontadorDePdfDoCarimbo::classico([1 => MontadorDePdfDoCarimbo::fluxoDeObjetos([2 => '<</B 2>>', 3 => '<</C 3>>'])]));
        self::assertSame('<</B 2>>', $semIscas->objeto(2, 0));
        $trabalho = $semIscas->trabalho();
        self::assertSame('<</C 3>>', $semIscas->objeto(3, 0));
        self::assertLessThan(64, $semIscas->trabalho() - $trabalho, 'o 3 sai do fluxo já inflado');
    }

    #[TestDox('versão: vale o último solto; um solto antigo vence a cópia mais nova dentro de /ObjStm, como no motor')]
    public function testRegraDeVersao(): void
    {
        $pdf = MontadorDePdfDoCarimbo::classico([2 => '<</Velho 1>>', 4 => MontadorDePdfDoCarimbo::fluxoDeObjetos([2 => '<</Novo 1>>'])])
            . "3 0 obj\n<</Primeiro 1>>\nendobj\n3 0 obj\n<</Ultimo 1>>\nendobj\n";
        $objetos = new CarimboVisivelObjetos($pdf);

        self::assertSame('<</Velho 1>>', $objetos->objeto(2, 0));
        self::assertSame('<</Ultimo 1>>', $objetos->objeto(3, 0));
    }

    #[TestDox('teto do corpo de um objeto (o trecho entre `obj` e `endobj`), solto ou dentro de /ObjStm')]
    public function testTetoDoCorpo(): void
    {
        // O montador põe uma quebra de linha de cada lado do corpo: o trecho tem o corpo mais dois bytes.
        $no = static fn (int $tamanho): string => '(' . str_repeat('a', $tamanho - 2) . ')';
        $pdf = MontadorDePdfDoCarimbo::classico([
            1 => $no(CarimboVisivelObjetos::CORPO_MAXIMO_EM_BYTES - 2),
            2 => $no(CarimboVisivelObjetos::CORPO_MAXIMO_EM_BYTES - 1),
            3 => MontadorDePdfDoCarimbo::fluxoDeObjetos([4 => $no(CarimboVisivelObjetos::CORPO_MAXIMO_EM_BYTES + 1)]),
        ]);

        self::assertSame(CarimboVisivelObjetos::CORPO_MAXIMO_EM_BYTES - 2, \strlen((string) (new CarimboVisivelObjetos($pdf))->objeto(1, 0)));
        foreach ([2, 4] as $numero) {
            try {
                (new CarimboVisivelObjetos($pdf))->objeto($numero, 0);
                self::fail('o objeto ' . $numero . ' passa do teto do corpo');
            } catch (\OverflowException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[TestDox('o /N de cada /ObjStm entra no teto de entradas antes de percorrer o cabeçalho')]
    public function testNDoFluxoNoTetoDeEntradas(): void
    {
        $objetos = [1 => '<</A 1>>'];
        $fluxos = intdiv(CarimboVisivelObjetos::ENTRADAS_MAXIMAS, LimitesDaConferencia::OBJETOS_MAXIMOS) + 1;
        for ($numero = 2; $numero < $fluxos + 2; ++$numero) {
            $objetos[$numero] = "<</Type/ObjStm/N 10000/First 0/Length 0>>\nstream\n\nendstream";
        }
        $objetos = new CarimboVisivelObjetos(MontadorDePdfDoCarimbo::classico($objetos));

        $this->expectException(\OverflowException::class);
        $objetos->objeto(99_999, 0);
    }

    #[TestDox('passar do teto do que a procura guarda lança')]
    public function testTetoDoGuardado(): void
    {
        $objetos = new CarimboVisivelObjetos('%PDF-1.7');
        $objetos->guardar(CarimboVisivelObjetos::GUARDADO_MAXIMO_EM_BYTES);

        $this->expectException(\OverflowException::class);
        $objetos->guardar(1);
    }
}
