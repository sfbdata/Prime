<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Master\DTO\AbrirIncidenteInput;
use App\Master\DTO\RegistrarBackupInput;
use App\Master\Entity\IncidenteDeDesempenho;
use App\Master\Enum\CategoriaDeIncidente;
use App\Master\Enum\GravidadeDeIncidente;
use App\Master\Enum\TipoDeRegistroDeBackup;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class EntradasDeGravacaoDeDesempenhoTest extends TestCase
{
    #[TestDox('incidente válido: título aparado e cortado no tamanho da coluna')]
    public function testIncidenteValido(): void
    {
        $input = AbrirIncidenteInput::doJson(null, ['titulo' => '  ' . str_repeat('a', 300) . ' ', 'categoria' => 'banco', 'gravidade' => 'crit']);

        self::assertNotNull($input);
        self::assertSame(IncidenteDeDesempenho::TITULO_MAXIMO, mb_strlen($input->titulo));
        self::assertSame(CategoriaDeIncidente::BancoDeDados, $input->categoria);
        self::assertSame(GravidadeDeIncidente::Critica, $input->gravidade);
    }

    #[TestDox('incidente sem título, ou com categoria ou gravidade fora do catálogo, vira nulo')]
    public function testIncidenteInvalido(): void
    {
        self::assertNull(AbrirIncidenteInput::doJson(null, []));
        self::assertNull(AbrirIncidenteInput::doJson(null, ['titulo' => ' ', 'categoria' => 'login', 'gravidade' => 'alta']));
        self::assertNull(AbrirIncidenteInput::doJson(null, ['titulo' => 'X', 'categoria' => 'x', 'gravidade' => 'alta']));
        self::assertNull(AbrirIncidenteInput::doJson(null, ['titulo' => 'X', 'categoria' => 'login', 'gravidade' => ['alta']]));
    }

    #[TestDox('backup exige tipo do catálogo e "ok" booleano de verdade (a string "false" não passa)')]
    public function testBackup(): void
    {
        $input = RegistrarBackupInput::doJson(null, ['tipo' => 'restauracao', 'ok' => false, 'observacao' => 'x']);
        self::assertNotNull($input);
        self::assertSame(TipoDeRegistroDeBackup::Restauracao, $input->tipo);
        self::assertFalse($input->ok);

        self::assertNull(RegistrarBackupInput::doJson(null, ['tipo' => 'backup', 'ok' => 'false']));
        self::assertNull(RegistrarBackupInput::doJson(null, ['tipo' => 'nada', 'ok' => true]));
    }
}
