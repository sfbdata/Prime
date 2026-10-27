<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Master\DTO\RegistrarEventoDeDesempenhoInput;
use App\Master\Enum\TipoDeEventoDeDesempenho;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(RegistrarEventoDeDesempenhoInput::class)]
final class RegistrarEventoDeDesempenhoInputTest extends TestCase
{
    #[TestDox('a página vira só o caminho: sem query string nem fragmento')]
    public function testPaginaSoOCaminho(): void
    {
        $input = RegistrarEventoDeDesempenhoInput::doJson(['tipo' => 'erro', 'pag' => '/pasta/12?token=abc&cpf=1#topo'], null);

        self::assertNotNull($input);
        self::assertSame('/pasta/12', $input->pagina);
        self::assertNull(RegistrarEventoDeDesempenhoInput::doJson(['tipo' => 'erro', 'pag' => '?cpf=1'], null)?->pagina);
    }

    #[TestDox('o navegador não pode reportar "login" nem tipo desconhecido')]
    public function testTiposRecusados(): void
    {
        self::assertNull(RegistrarEventoDeDesempenhoInput::doJson(['tipo' => 'login', 'ok' => false], null));
        self::assertNull(RegistrarEventoDeDesempenhoInput::doJson(['tipo' => 'xpto'], null));
        self::assertNull(RegistrarEventoDeDesempenhoInput::doJson([], null));
    }

    #[TestDox('ms é limitado, tipos errados viram nulo')]
    public function testLimitesETipos(): void
    {
        $input = RegistrarEventoDeDesempenhoInput::doJson(['tipo' => 'carga', 'ms' => 99_999_999, 'ok' => 'sim', 'msg' => ['x']], null);

        self::assertNotNull($input);
        self::assertSame(TipoDeEventoDeDesempenho::Carga, $input->tipo);
        self::assertSame(RegistrarEventoDeDesempenhoInput::MS_MAXIMO, $input->ms);
        self::assertNull($input->ok);
        self::assertNull($input->mensagem);
        self::assertSame(0, RegistrarEventoDeDesempenhoInput::doJson(['tipo' => 'carga', 'ms' => -5], null)?->ms);
    }
}
