<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Tenant\Sede;
use App\Ponto\Service\CercaDoPonto;
use App\Ponto\Service\PosicaoNaCerca;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A cerca da batida com a precisão do GPS (spec ponto-desenho-0811 §5 E12): dentro, incerta ou fora, e a recusa que a
 * pessoa lê. Os limites saem de `classificar`, com as distâncias exatas; a geometria, de `avaliar`, com coordenadas.
 */
#[CoversClass(CercaDoPonto::class)]
#[CoversClass(PosicaoNaCerca::class)]
#[Group('ponto')]
final class CercaDoPontoTest extends TestCase
{
    private const SP_LAT = -23.55;
    private const SP_LNG = -46.63;

    /** @return iterable<string, array{float, int, float, string}> distância, raio, precisão, situação */
    public static function limites(): iterable
    {
        yield 'distância exatamente no raio, com a precisão ruim: entra (nunca mais rígido)' => [100.0, 100, 500.0, PosicaoNaCerca::DENTRO];
        yield 'distância no raio com precisão 51: entra' => [99.0, 100, 51.0, PosicaoNaCerca::DENTRO];
        yield 'distância menos precisão no raio, precisão 50: entra' => [140.0, 100, 50.0, PosicaoNaCerca::DENTRO];
        yield 'distância menos precisão exatamente no raio, precisão 50: entra' => [150.0, 100, 50.0, PosicaoNaCerca::DENTRO];
        yield 'distância menos precisão no raio, precisão 51: incerta' => [140.0, 100, 51.0, PosicaoNaCerca::INCERTA];
        yield 'distância menos precisão exatamente no raio, precisão 51: incerta' => [151.0, 100, 51.0, PosicaoNaCerca::INCERTA];
        yield 'distância menos precisão um metro além do raio, precisão 50: fora' => [151.0, 100, 50.0, PosicaoNaCerca::FORA];
        yield 'longe e com a precisão ruim: fora' => [1000.0, 100, 300.0, PosicaoNaCerca::FORA];
    }

    #[DataProvider('limites')]
    #[TestDox('Limite: distância $distancia, raio $raio, precisão $precisao: $situacao')]
    public function testLimites(float $distancia, int $raio, float $precisao, string $situacao): void
    {
        $sede = $this->sede('Centro', 0.0, 0.0, $raio);

        $posicao = (new CercaDoPonto())->classificar([['sede' => $sede, 'distancia' => $distancia, 'raio' => $raio]], $precisao);

        self::assertSame($situacao, $posicao->situacao);
        self::assertSame($sede, $posicao->sede, 'dentro, a aceita; fora e incerta, a mais próxima');
        self::assertSame([$distancia, $raio, $precisao], [$posicao->distancia, $posicao->raio, $posicao->precisao]);
    }

    #[TestDox('Vale a mais próxima entre as aceitas, inclusive a aceita só pela precisão')]
    public function testValeAMaisProximaEntreAsAceitas(): void
    {
        $larga = $this->sede('Larga', 0.0, 0.0, 100);
        $justa = $this->sede('Justa', 0.0, 0.0, 10);
        $longe = $this->sede('Longe', 0.0, 0.0, 5);

        $posicao = (new CercaDoPonto())->classificar([
            ['sede' => $larga, 'distancia' => 90.0, 'raio' => 100],
            ['sede' => $justa, 'distancia' => 30.0, 'raio' => 10],    // 30 - 30 = 0, precisão 30: aceita só pela precisão
            ['sede' => $longe, 'distancia' => 20.0, 'raio' => 5],     // 20 - 30 abaixo de 5: aceita só pela precisão, e a mais perto
        ], 30.0);

        self::assertTrue($posicao->estaDentro());
        self::assertSame($longe, $posicao->sede);
        self::assertSame(20.0, $posicao->distancia);
    }

    #[TestDox('Dentro de uma e incerta em outra mais perto: vale a de dentro')]
    public function testDentroVenceIncerta(): void
    {
        $dentro = $this->sede('Dentro', 0.0, 0.0, 100);
        $incerta = $this->sede('Incerta', 0.0, 0.0, 10);

        $posicao = (new CercaDoPonto())->classificar([
            ['sede' => $dentro, 'distancia' => 95.0, 'raio' => 100],
            ['sede' => $incerta, 'distancia' => 60.0, 'raio' => 10],
        ], 80.0);

        self::assertTrue($posicao->estaDentro());
        self::assertSame($dentro, $posicao->sede);
    }

    #[TestDox('Na mesma posição da sede: dentro, com a distância zero')]
    public function testNaSede(): void
    {
        $sede = $this->sede('Paulista', self::SP_LAT, self::SP_LNG, 100);

        $posicao = (new CercaDoPonto())->avaliar([$sede], self::SP_LAT, self::SP_LNG, 10.0);

        self::assertTrue($posicao->estaDentro());
        self::assertSame($sede, $posicao->sede);
        self::assertEqualsWithDelta(0.0, $posicao->distancia, 0.001);
    }

    #[TestDox('Fora: leva a sede válida mais próxima, com a distância e o raio dela')]
    public function testForaLevaAMaisProxima(): void
    {
        $centro = $this->sede('Centro', self::SP_LAT, self::SP_LNG, 100);
        $sul = $this->sede('Sul', -23.56, self::SP_LNG, 200);
        $semCoordenada = $this->sede('Sem coordenada', null, null, 5000);
        $semRaio = $this->sede('Sem raio', -23.60, self::SP_LNG, 0);

        $posicao = (new CercaDoPonto())->avaliar([$centro, $sul, $semCoordenada, $semRaio], -23.60, self::SP_LNG, 10.0);

        self::assertSame(PosicaoNaCerca::FORA, $posicao->situacao);
        self::assertSame($sul, $posicao->sede, 'a sem raio está em cima da posição, mas não conta');
        self::assertEqualsWithDelta(CercaDoPonto::distanciaEmMetros(-23.60, self::SP_LNG, -23.56, self::SP_LNG), $posicao->distancia, 0.001);
        self::assertSame(200, $posicao->raio);
    }

    #[TestDox('Sem sede, sede sem coordenada e raio zero: fora, sem sede')]
    public function testSemSedeValida(): void
    {
        $cerca = new CercaDoPonto();

        foreach ([[], [$this->sede('Sem coordenada', null, self::SP_LNG, 100)], [$this->sede('Raio zero', self::SP_LAT, self::SP_LNG, 0)]] as $sedes) {
            $posicao = $cerca->avaliar($sedes, self::SP_LAT, self::SP_LNG, 5.0);

            self::assertSame(PosicaoNaCerca::FORA, $posicao->situacao);
            self::assertNull($posicao->sede);
            self::assertNull($posicao->distancia);
            self::assertSame(
                ['titulo' => 'Você está fora do raio permitido', 'detalhe' => 'O registro exige estar dentro do raio de uma sede do escritório.', 'trabalhoExterno' => false],
                $posicao->recusa(false),
            );
        }
    }

    #[TestDox('Recusa fora do raio: a sede mais próxima, a distância arredondada com o milhar em ponto e o raio; a saída pelo trabalho externo só com ele permitido')]
    public function testRecusaForaDoRaio(): void
    {
        $sede = $this->sede('Sede Paulista', 0.0, 0.0, 1500);
        $posicao = (new CercaDoPonto())->classificar([['sede' => $sede, 'distancia' => 12345.6, 'raio' => 1500]], 10.0);

        self::assertSame(403, $posicao->statusDaRecusa());
        self::assertSame(
            ['titulo' => 'Você está fora do raio permitido', 'detalhe' => 'A sede mais próxima é Sede Paulista, a 12.346 m. O raio permitido é de 1.500 m.', 'trabalhoExterno' => false],
            $posicao->recusa(false),
        );
        self::assertSame(
            'A sede mais próxima é Sede Paulista, a 12.346 m. O raio permitido é de 1.500 m. Aproxime-se ou informe o trabalho externo.',
            $posicao->recusa(true)['detalhe'],
        );
        self::assertTrue($posicao->recusa(true)['trabalhoExterno']);
    }

    #[TestDox('Recusa com o GPS impreciso: 422, a precisão arredondada e, com o trabalho externo permitido, a saída por ele')]
    public function testRecusaIncerta(): void
    {
        $posicao = (new CercaDoPonto())->classificar([['sede' => $this->sede('Centro', 0.0, 0.0, 100), 'distancia' => 120.0, 'raio' => 100]], 80.4);

        self::assertSame(422, $posicao->statusDaRecusa());
        self::assertSame(
            ['titulo' => 'GPS impreciso', 'detalhe' => 'A precisão do GPS (80 m) não basta para confirmar que você está no raio da sede. Atualize a localização e tente de novo.', 'trabalhoExterno' => false],
            $posicao->recusa(false),
        );
        self::assertSame(
            'A precisão do GPS (80 m) não basta para confirmar que você está no raio da sede. Atualize a localização e tente de novo. Se está fora da sede, informe o trabalho externo.',
            $posicao->recusa(true)['detalhe'],
        );
    }

    #[TestDox('Dentro do raio não tem recusa')]
    public function testDentroNaoTemRecusa(): void
    {
        $posicao = (new CercaDoPonto())->classificar([['sede' => $this->sede('Centro', 0.0, 0.0, 100), 'distancia' => 10.0, 'raio' => 100]], 5.0);

        $this->expectException(\LogicException::class);
        $posicao->recusa(true);
    }

    private function sede(string $nome, ?float $latitude, ?float $longitude, int $raio): Sede
    {
        $sede = new Sede();
        $sede->setNome($nome);
        $sede->setLatitude($latitude === null ? null : (string) $latitude);
        $sede->setLongitude($longitude === null ? null : (string) $longitude);
        $sede->setRaioPermitido($raio);

        return $sede;
    }
}
