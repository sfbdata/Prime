<?php

declare(strict_types=1);

namespace App\Tests\Calculo\Unit;

use App\Calculo\DTO\ResultadoDaVerificacao;
use App\Calculo\Entity\CalculoEmitido;
use App\Calculo\Repository\CalculoEmitidoRepository;
use App\Calculo\UseCase\VerificarCodigoDoCalculoUseCase;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(VerificarCodigoDoCalculoUseCase::class)]
final class VerificarCodigoDoCalculoUseCaseTest extends TestCase
{
    private function emitido(): CalculoEmitido
    {
        $tenant = (new Tenant())->setName('Escritório Teste');

        return new CalculoEmitido($tenant, null, 'BJC-MVV219D2', new \DateTimeImmutable('2026-10-30 14:05:00'), new \DateTimeImmutable('2026-10-30'), '20094.57', hash('sha256', 'pdf-original'));
    }

    private function useCase(?CalculoEmitido $achado, bool $deveConsultar = true): VerificarCodigoDoCalculoUseCase
    {
        $repo = $this->createMock(CalculoEmitidoRepository::class);
        $repo->expects($deveConsultar ? self::once() : self::never())->method('porCodigo')->with('BJC-MVV219D2')->willReturn($achado);

        return new VerificarCodigoDoCalculoUseCase($repo);
    }

    public function testCodigoMalFormadoNemConsultaOBanco(): void
    {
        $repo = $this->createMock(CalculoEmitidoRepository::class);
        $repo->expects(self::never())->method('porCodigo');

        foreach (['', 'abc', 'BJC-123', "BJC-MVV219D2'; DROP", 'BJC-MVV219D23'] as $texto) {
            $r = (new VerificarCodigoDoCalculoUseCase($repo))->executar($texto);
            self::assertSame(ResultadoDaVerificacao::FORMATO_INVALIDO, $r->estado, $texto);
        }
    }

    public function testCodigoInexistente(): void
    {
        $r = $this->useCase(null)->executar('BJC-MVV219D2');

        self::assertSame(ResultadoDaVerificacao::NAO_ENCONTRADO, $r->estado);
        self::assertNull($r->escritorio);
    }

    public function testCodigoEncontradoAceitaMinusculaEEspacos(): void
    {
        $r = $this->useCase($this->emitido())->executar('  bjc-mvv219d2 ');

        self::assertSame(ResultadoDaVerificacao::ENCONTRADO, $r->estado);
        self::assertSame('Escritório Teste', $r->escritorio);
        self::assertSame('20094.57', $r->total);
        self::assertNull($r->confere, 'sem arquivo não há o que conferir');
    }

    public function testConfereOHashDoArquivo(): void
    {
        $igual = $this->useCase($this->emitido())->executar('BJC-MVV219D2', hash('sha256', 'pdf-original'));
        $outro = $this->useCase($this->emitido())->executar('BJC-MVV219D2', hash('sha256', 'pdf-adulterado'));

        self::assertTrue($igual->confere);
        self::assertFalse($outro->confere);
    }
}
