<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\DadosDoComprovante;
use App\Ponto\Entity\ComprovanteDoPonto;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Repository\ComprovanteDoPontoRepository;
use App\Ponto\Service\SeloDoComprovante;
use App\Ponto\Service\SeloDoComprovanteIndisponivel;
use App\Ponto\UseCase\ValidarComprovanteUseCase;
use App\Tests\Ponto\Support\SeloDoComprovanteDeTeste;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Validação do código (E15, P50): autêntico só quando o código existe E o HMAC confere E os dados selados trazem o
 * mesmo código. Qualquer outro caso devolve null, sem dizer qual.
 */
#[Group('ponto')]
#[CoversClass(ValidarComprovanteUseCase::class)]
#[CoversClass(SeloDoComprovanteIndisponivel::class)]
final class ValidarComprovanteUseCaseTest extends TestCase
{
    private const CODIGO = 'BJP-ABCD-EFGH-JK23';

    #[TestDox('Código que existe e selo que confere: autêntico, com o nome inteiro e o NSR; o digitado é normalizado')]
    public function testAutentico(): void
    {
        $selo = new SeloDoComprovanteDeTeste();
        $dados = $this->executar($this->comprovante($selo), $selo, '  bjp-abcd-efgh-jk23 ');

        self::assertNotNull($dados);
        self::assertSame('Entrada de Maria da Silva Pereira em 09/11/2026, 08:01:02, NSR 000000005.', $dados->textoDaValidacao());
    }

    #[TestDox('Selo que não confere (dado trocado depois de selado ou HMAC trocado): não localizado')]
    public function testSeloQueNaoConfere(): void
    {
        $selo = new SeloDoComprovanteDeTeste();
        $adulterado = $this->comprovante($selo, fn (string $c): string => str_replace('Maria', 'Mário', $c));
        self::assertNull($this->executar($adulterado, $selo, self::CODIGO), 'nome trocado depois do selo');

        $hmacTrocado = $this->comprovante($selo, null, str_repeat('0', 64));
        self::assertNull($this->executar($hmacTrocado, $selo, self::CODIGO), 'HMAC trocado');
    }

    #[TestDox('Selo que confere, mas com dados de outro código (linha trocada de código): não localizado')]
    public function testDadosDeOutroCodigo(): void
    {
        $selo = new SeloDoComprovanteDeTeste();
        $outro = $this->comprovante($selo, null, null, 'BJP-ZZZZ-ZZZZ-ZZZZ');

        self::assertNull($this->executar($outro, $selo, self::CODIGO));
    }

    #[TestDox('Código que não existe: não localizado')]
    public function testCodigoQueNaoExiste(): void
    {
        self::assertNull($this->executar(null, new SeloDoComprovanteDeTeste(), self::CODIGO));
    }

    #[TestDox('Formato errado: não localizado, sem consultar o banco')]
    public function testFormatoErradoNemConsulta(): void
    {
        $repositorio = $this->createMock(ComprovanteDoPontoRepository::class);
        $repositorio->expects(self::never())->method('porCodigo');

        self::assertNull((new ValidarComprovanteUseCase($repositorio, new SeloDoComprovanteDeTeste()))->executar("BJP-ABCD' OR 1=1"));
    }

    #[TestDox('Selo indisponível (sem o segredo do HMAC): nada confere, nem o comprovante que existe')]
    public function testSeloIndisponivel(): void
    {
        $indisponivel = new SeloDoComprovanteIndisponivel();
        self::assertFalse($indisponivel->disponivel());
        self::assertNull($this->executar($this->comprovante(new SeloDoComprovanteDeTeste()), $indisponivel, self::CODIGO));

        $this->expectException(\LogicException::class);
        $indisponivel->selar('{}');
    }

    private function executar(?ComprovanteDoPonto $encontrado, SeloDoComprovante $selo, string $digitado): ?DadosDoComprovante
    {
        $repositorio = $this->createStub(ComprovanteDoPontoRepository::class);
        $repositorio->method('porCodigo')->willReturn($encontrado);

        return (new ValidarComprovanteUseCase($repositorio, $selo))->executar($digitado);
    }

    /** @param (callable(string): string)|null $adulterar troca o texto gravado DEPOIS de selado */
    private function comprovante(SeloDoComprovante $selo, ?callable $adulterar = null, ?string $hmac = null, string $codigoNosDados = self::CODIGO): ComprovanteDoPonto
    {
        $dados = new DadosDoComprovante($codigoNosDados, 5, 7, 'Maria da Silva Pereira', 'Escritório Modelo', null, new \DateTimeImmutable('2026-11-09T08:01:02-03:00'), 'entrada', DadosDoComprovante::METODO_SISTEMA);
        $canonico = $dados->canonico();
        $selado = $selo->selar($canonico);

        return new ComprovanteDoPonto(new Tenant(), new RegistroPonto(), 5, self::CODIGO, $hmac ?? $selado['hmac'], $selado['versao'], new \DateTimeImmutable(), $adulterar !== null ? $adulterar($canonico) : $canonico);
    }
}
