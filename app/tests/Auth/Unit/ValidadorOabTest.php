<?php

declare(strict_types=1);

namespace App\Tests\Auth\Unit;

use App\Auth\DTO\ConsultaOabResultado;
use App\Auth\Enum\StatusOab;
use App\Auth\Service\ValidadorOab;
use App\Tests\Auth\Doubles\OabWebServiceClientFake;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ValidadorOab::class)]
final class ValidadorOabTest extends TestCase
{
    private function validador(?OabWebServiceClientFake $fake = null): ValidadorOab
    {
        return new ValidadorOab($fake ?? new OabWebServiceClientFake());
    }

    // ---------------------------------------------------------------- validarFormato

    #[TestDox('validarFormato: OAB ausente é aceita (opcional)')]
    public function testFormatoAusenteEhOk(): void
    {
        $this->validador()->validarFormato(null, null);
        $this->validador()->validarFormato('', '');

        $this->expectNotToPerformAssertions();
    }

    #[TestDox('validarFormato: número+UF válidos passam')]
    public function testFormatoValidoPassa(): void
    {
        $this->validador()->validarFormato('12345', 'SP');

        $this->expectNotToPerformAssertions();
    }

    /** @return iterable<string,array{?string,?string}> */
    public static function formatosInvalidos(): iterable
    {
        yield 'número com letra'   => ['12a', 'SP'];
        yield 'UF minúscula'       => ['123', 'sp'];
        yield 'UF com 3 letras'    => ['123', 'SPP'];
        yield 'só número'          => ['123', ''];
        yield 'só UF'              => ['', 'SP'];
    }

    #[DataProvider('formatosInvalidos')]
    #[TestDox('validarFormato: formato inválido lança')]
    public function testFormatoInvalidoLanca(?string $numero, ?string $uf): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->validador()->validarFormato($numero, $uf);
    }

    // ---------------------------------------------------------------- verificar

    #[TestDox('verificar: advogado regular com nome batendo → Confirmada')]
    public function testConfirmada(): void
    {
        $fake = (new OabWebServiceClientFake())->comResultado(new ConsultaOabResultado(true, 'JOÃO DA SILVA', 'Regular'));

        $r = $this->validador($fake)->verificar('123', 'SP', 'joão da silva');

        self::assertSame(StatusOab::Confirmada, $r->status);
        self::assertSame('JOÃO DA SILVA', $r->nomeOficial);
    }

    #[TestDox('verificar: matcher lenient (nome do meio omitido) → Confirmada')]
    public function testMatcherLenient(): void
    {
        $fake = (new OabWebServiceClientFake())->comResultado(new ConsultaOabResultado(true, 'JOÃO DA SILVA SOUZA', 'Regular'));

        $r = $this->validador($fake)->verificar('123', 'SP', 'João Silva');

        self::assertSame(StatusOab::Confirmada, $r->status);
    }

    #[TestDox('verificar: nome diverge → Divergente (guarda o nome oficial)')]
    public function testNomeDivergente(): void
    {
        $fake = (new OabWebServiceClientFake())->comResultado(new ConsultaOabResultado(true, 'MARIA OLIVEIRA', 'Regular'));

        $r = $this->validador($fake)->verificar('123', 'SP', 'João Silva');

        self::assertSame(StatusOab::Divergente, $r->status);
        self::assertSame('MARIA OLIVEIRA', $r->nomeOficial);
    }

    #[TestDox('verificar: situação irregular → Divergente mesmo com nome batendo')]
    public function testSituacaoIrregular(): void
    {
        $fake = (new OabWebServiceClientFake())->comResultado(new ConsultaOabResultado(true, 'JOÃO DA SILVA', 'Suspenso'));

        $r = $this->validador($fake)->verificar('123', 'SP', 'João da Silva');

        self::assertSame(StatusOab::Divergente, $r->status);
    }

    #[TestDox('verificar: número+UF não existe → Divergente com nome oficial nulo')]
    public function testNaoExiste(): void
    {
        $fake = (new OabWebServiceClientFake())->comResultado(new ConsultaOabResultado(false, null, null));

        $r = $this->validador($fake)->verificar('999999', 'SP', 'Fulano');

        self::assertSame(StatusOab::Divergente, $r->status);
        self::assertNull($r->nomeOficial);
    }

    #[TestDox('verificar: serviço indisponível → NaoVerificada (fail-open)')]
    public function testIndisponivelFailOpen(): void
    {
        $fake = (new OabWebServiceClientFake())->indisponivel();

        $r = $this->validador($fake)->verificar('123', 'SP', 'João da Silva');

        self::assertSame(StatusOab::NaoVerificada, $r->status);
        self::assertNull($r->nomeOficial);
    }
}
