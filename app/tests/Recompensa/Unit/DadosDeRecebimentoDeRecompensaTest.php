<?php

declare(strict_types=1);

namespace App\Tests\Recompensa\Unit;

use App\Entity\Auth\User;
use App\Recompensa\DTO\LeitorDeValoresDeRecompensa;
use App\Recompensa\Entity\DadosDeRecebimentoDeRecompensa;
use App\Recompensa\Entity\LancamentoDeRecompensa;
use App\Recompensa\Enum\FormaDePagamento;
use App\Recompensa\Exception\RecompensaRecusada;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(DadosDeRecebimentoDeRecompensa::class)]
#[CoversClass(LancamentoDeRecompensa::class)]
#[CoversClass(LeitorDeValoresDeRecompensa::class)]
final class DadosDeRecebimentoDeRecompensaTest extends TestCase
{
    private const AGORA = '2026-10-27 10:00:00';

    #[TestDox('normaliza: nome aparado, documento só com dígitos e chave PIX vazia vira nula')]
    public function testNormaliza(): void
    {
        self::assertSame(['Ana', '52998224725', null], DadosDeRecebimentoDeRecompensa::normalizar('  Ana ', '529.982.247-25', '  '));
        self::assertSame(['Ana', '11222333000181', 'ana@pix.com'], DadosDeRecebimentoDeRecompensa::normalizar('Ana', '11.222.333/0001-81', ' ana@pix.com '));
    }

    /** @return iterable<string, array{string, string, ?string, string}> */
    public static function recusas(): iterable
    {
        yield 'nome vazio' => [' ', '52998224725', null, 'Informe o nome'];
        yield 'nome longo' => [str_repeat('a', 121), '52998224725', null, 'Informe o nome'];
        yield 'cpf com dígito errado' => ['Ana', '52998224726', null, 'CPF ou CNPJ válido'];
        yield 'sequência repetida' => ['Ana', '11111111111', null, 'CPF ou CNPJ válido'];
        yield 'cnpj com dígito errado' => ['Ana', '11222333000182', null, 'CPF ou CNPJ válido'];
        yield 'chave PIX longa' => ['Ana', '52998224725', str_repeat('x', 141), 'chave PIX'];
    }

    #[DataProvider('recusas')]
    #[TestDox('recusa $_dataName')]
    public function testRecusa(string $nome, string $documento, ?string $pix, string $trecho): void
    {
        $this->expectException(RecompensaRecusada::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($trecho, '/') . '/');

        DadosDeRecebimentoDeRecompensa::normalizar($nome, $documento, $pix);
    }

    #[TestDox('mostra só os 2 últimos dígitos do documento, no formato do CPF ou do CNPJ')]
    public function testMascara(): void
    {
        $agora = new \DateTimeImmutable(self::AGORA);

        self::assertSame('***.***.***-25', (new DadosDeRecebimentoDeRecompensa(new User(), 'Ana', '529.982.247-25', null, null, $agora))->documentoMascarado());
        self::assertSame('**.***.***/****-81', (new DadosDeRecebimentoDeRecompensa(new User(), 'Ana', '11.222.333/0001-81', null, null, $agora))->documentoMascarado());
    }

    #[TestDox('pagamento: valor positivo com forma e comprovante; estorno ou zero recusa')]
    public function testPagamento(): void
    {
        $agora = new \DateTimeImmutable(self::AGORA);
        $pago = LancamentoDeRecompensa::pagamento(new User(), 1500, FormaDePagamento::PIX, ' pago ', 'abc.pdf', 'recibo.pdf', 'chave-de-teste-0001', null, $agora);

        self::assertSame([LancamentoDeRecompensa::TIPO_PAGO, 1500, 'pago', FormaDePagamento::PIX, 'abc.pdf', 'recibo.pdf'], [$pago->getTipo(), $pago->getValorCentavos(), $pago->getObservacao(), $pago->getForma(), $pago->getComprovanteChave(), $pago->getComprovanteNome()]);
        self::assertTrue($pago->temComprovante());
        $semArquivo = LancamentoDeRecompensa::pagamento(new User(), 100, FormaDePagamento::TRANSFERENCIA, null, null, 'ignorado.pdf', 'chave-de-teste-0002', null, $agora);
        self::assertFalse($semArquivo->temComprovante());
        self::assertNull($semArquivo->getComprovanteNome(), 'sem arquivo não há nome de arquivo');

        foreach ([0, -100, LancamentoDeRecompensa::VALOR_MAXIMO + 1] as $invalido) {
            try {
                LancamentoDeRecompensa::pagamento(new User(), $invalido, FormaDePagamento::PIX, null, null, null, 'chave-de-teste-0003', null, $agora);
                self::fail('valor ' . $invalido . ' devia recusar');
            } catch (RecompensaRecusada) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[TestDox('valor do pagamento: aceita "150,00" e "1.000,5"; recusa zero, negativo, texto e acima do teto')]
    public function testLeitorDoValorDePagamento(): void
    {
        self::assertSame(15000, LeitorDeValoresDeRecompensa::centavosDePagamento('150,00'));
        self::assertSame(100050, LeitorDeValoresDeRecompensa::centavosDePagamento('1.000,5'));

        foreach ([null, '', '0', '0,00', '-1,00', 'abc', '100000,01'] as $invalido) {
            try {
                LeitorDeValoresDeRecompensa::centavosDePagamento($invalido);
                self::fail('"' . var_export($invalido, true) . '" devia recusar');
            } catch (RecompensaRecusada) {
                self::addToAssertionCount(1);
            }
        }
    }
}
