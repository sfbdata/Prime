<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Voto\DTO\AssembleiaNoPainel;
use App\Voto\DTO\PautaNoPainel;
use App\Voto\Enum\EstadoDaAssembleia;
use App\Voto\Enum\EstadoDaPauta;
use App\Voto\Enum\PesoDoVoto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** As 6 etapas do desenho (Criar, Lista de aptos, Publicar, Votação, Apuração, Relatório): qual é a atual. */
#[CoversClass(AssembleiaNoPainel::class)]
final class EtapasDaAssembleiaTest extends TestCase
{
    /** @return iterable<string, array{EstadoDaAssembleia, ?EstadoDaPauta, int, int, int}> */
    public static function casos(): iterable
    {
        yield 'rascunho sem lista de aptos: Lista de aptos' => [EstadoDaAssembleia::Rascunho, null, 0, 0, 1];
        yield 'rascunho com lista: Publicar' => [EstadoDaAssembleia::Rascunho, null, 3, 0, 2];
        yield 'rascunho só com inaptas: já tem lista, Publicar' => [EstadoDaAssembleia::Rascunho, null, 0, 2, 2];
        yield 'publicada aguardando abertura: Votação' => [EstadoDaAssembleia::Publicada, EstadoDaPauta::Aguardando, 3, 0, 3];
        yield 'aberta: Votação' => [EstadoDaAssembleia::Publicada, EstadoDaPauta::Aberta, 3, 0, 3];
        yield 'encerrada (apura ao encerrar): tudo concluído' => [EstadoDaAssembleia::Publicada, EstadoDaPauta::Encerrada, 3, 0, 6];
    }

    #[DataProvider('casos')]
    #[TestDox('Etapa atual por situação')]
    public function testEtapaAtual(EstadoDaAssembleia $estado, ?EstadoDaPauta $pauta, int $aptas, int $inaptas, int $esperada): void
    {
        $pautas = null === $pauta ? [] : [new PautaNoPainel(1, 1, 'Contas', null, $pauta, true, null, null, null, null, null, null, null, null, [], 0, '0.000000', $aptas, '0.000000', null)];
        $assembleia = new AssembleiaNoPainel(1, 'AGO', PesoDoVoto::Unidade, $estado, 'G', new \DateTimeImmutable(), null, false, false, $aptas, $inaptas, '0.000000', $pautas);

        self::assertSame($esperada, $assembleia->etapaAtual());
    }
}
