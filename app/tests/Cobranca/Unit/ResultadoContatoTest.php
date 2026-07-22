<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Enum\ResultadoContato;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Ajuste 2026-07 (spec `docs/specs/cobranca-contato-resultado-relato.md`): novas opções de resultado
 * de contato + `PrometeuPagar` sai da lista selecionável mas continua legível para o histórico legado.
 */
#[CoversClass(ResultadoContato::class)]
final class ResultadoContatoTest extends TestCase
{
    #[Test]
    public function selecionaveisNaoContemPrometeuPagar(): void
    {
        self::assertNotContains(ResultadoContato::PrometeuPagar, ResultadoContato::selecionaveis());
    }

    #[Test]
    public function selecionaveisEstaExatamenteNaOrdemEsperada(): void
    {
        self::assertSame(
            [
                ResultadoContato::NaoAtendido,
                ResultadoContato::Atendido,
                ResultadoContato::CaixaPostal,
                ResultadoContato::NumeroErrado,
                ResultadoContato::PediuRetorno,
                ResultadoContato::InformouOutroNumero,
                ResultadoContato::Outro,
            ],
            ResultadoContato::selecionaveis(),
        );
    }

    #[Test]
    public function prometeuPagarLegadoAindaResolveEExibeLabel(): void
    {
        $resultado = ResultadoContato::from('prometeu_pagar');

        self::assertSame(ResultadoContato::PrometeuPagar, $resultado);
        self::assertSame('Prometeu pagar', $resultado->label());
    }

    #[Test]
    public function novosCasosExistemComOLabelCerto(): void
    {
        self::assertSame('Atendido', ResultadoContato::Atendido->label());
        self::assertSame('Pediu retorno', ResultadoContato::PediuRetorno->label());
        self::assertSame('Informou outro número', ResultadoContato::InformouOutroNumero->label());
    }

    #[Test]
    public function casosExistentesMantemOValorEOLabel(): void
    {
        self::assertSame('nao_atendido', ResultadoContato::NaoAtendido->value);
        self::assertSame('Não atendido', ResultadoContato::NaoAtendido->label());
        self::assertSame('caixa_postal', ResultadoContato::CaixaPostal->value);
        self::assertSame('Caixa postal', ResultadoContato::CaixaPostal->label());
        self::assertSame('numero_errado', ResultadoContato::NumeroErrado->value);
        self::assertSame('Número errado', ResultadoContato::NumeroErrado->label());
        self::assertSame('outro', ResultadoContato::Outro->value);
        self::assertSame('Outro', ResultadoContato::Outro->label());
    }
}
