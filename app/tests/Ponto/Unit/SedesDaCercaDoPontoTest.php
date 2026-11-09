<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Tenant\Sede;
use App\Entity\Tenant\Tenant;
use App\Ponto\Service\SedesDaCercaDoPonto;
use App\Repository\SedeRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A cerca da tela continua ignorando a sede sem coordenada (spec `docs/specs/ponto-desenho-0811.md`, E6; inventário
 * 3.4 do desenho: "Sedes sem coordenada são ignoradas"). Na aba Configurações a "Nova sede" fica só no navegador até
 * ter latitude e longitude; esta é a garantia do lado de quem lê, para a sede que chegar sem uma delas.
 */
#[CoversClass(SedesDaCercaDoPonto::class)]
#[Group('ponto')]
final class SedesDaCercaDoPontoTest extends TestCase
{
    #[TestDox('Sede sem latitude ou sem longitude fica fora da cerca; a completa entra com o raio dela')]
    public function testSedeSemCoordenadaFicaFora(): void
    {
        $sedes = $this->createStub(SedeRepository::class);
        $sedes->method('findBy')->willReturn([
            (new Sede())->setNome('Nova sede')->setLongitude('-35.7'),
            (new Sede())->setNome('Sem longitude')->setLatitude('-9.6'),
            (new Sede())->setNome('Nova sede vazia'),
            (new Sede())->setNome('Maceió/AL')->setLatitude('-9.665999')->setLongitude('-35.735')->setRaioPermitido(100),
        ]);

        self::assertSame(
            [['nome' => 'Maceió/AL', 'latitude' => -9.665999, 'longitude' => -35.735, 'raio' => 100]],
            (new SedesDaCercaDoPonto($sedes))->doEscritorio(new Tenant()),
        );
    }

    #[TestDox('A sede nova nasce com 150 m (P70 a)')]
    public function testSedeNovaNasceCom150Metros(): void
    {
        self::assertSame(150, Sede::RAIO_PADRAO);
        self::assertSame(Sede::RAIO_PADRAO, (new Sede())->getRaioPermitido());
    }
}
