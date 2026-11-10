<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\DadosDoComprovante;
use App\Ponto\Entity\ComprovanteDoPonto;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Repository\ComprovanteDoPontoRepository;
use App\Ponto\Service\ChavesDoHmacDoPonto;
use App\Ponto\Service\SeloDoComprovante;
use App\Ponto\Service\SeloDoComprovanteHmac;
use App\Ponto\UseCase\ValidarComprovanteUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Validação do código (E15, P50): autêntico só quando o código existe E o HMAC confere E os dados selados trazem o
 * mesmo código, no formato que o leitor conhece. Qualquer outro caso devolve null, sem dizer qual. O selo é o de
 * produção, com as chaves da E9 sobre um segredo de teste.
 */
#[Group('ponto')]
#[CoversClass(ValidarComprovanteUseCase::class)]
#[CoversClass(SeloDoComprovanteHmac::class)]
final class ValidarComprovanteUseCaseTest extends TestCase
{
    private const CODIGO = 'BJP-ABCD-EFGH-JK23';

    #[TestDox('Código que existe e selo que confere: autêntico, com o nome inteiro e o NSR; o digitado é normalizado')]
    public function testAutentico(): void
    {
        $selo = $this->selo();
        $dados = $this->executar($this->comprovante($selo), $selo, '  bjp-abcd-efgh-jk23 ');

        self::assertNotNull($dados);
        self::assertSame('Entrada de Maria da Silva Pereira em 09/11/2026, 08:01:02, NSR 000000005.', $dados->textoDaValidacao());
    }

    #[TestDox('Selo que não confere (dado trocado depois de selado ou HMAC trocado): não localizado')]
    public function testSeloQueNaoConfere(): void
    {
        $selo = $this->selo();
        $adulterado = $this->comprovante($selo, fn (string $c): string => str_replace('Maria', 'Mário', $c));
        self::assertNull($this->executar($adulterado, $selo, self::CODIGO), 'nome trocado depois do selo');

        $hmacTrocado = $this->comprovante($selo, null, str_repeat('0', 64));
        self::assertNull($this->executar($hmacTrocado, $selo, self::CODIGO), 'HMAC trocado');
    }

    #[TestDox('Selo que confere, mas com dados de outro código (linha trocada de código): não localizado')]
    public function testDadosDeOutroCodigo(): void
    {
        $selo = $this->selo();
        $outro = $this->comprovante($selo, null, null, 'BJP-ZZZZ-ZZZZ-ZZZZ');

        self::assertNull($this->executar($outro, $selo, self::CODIGO));
    }

    #[TestDox('Código que não existe: não localizado')]
    public function testCodigoQueNaoExiste(): void
    {
        self::assertNull($this->executar(null, $this->selo(), self::CODIGO));
    }

    #[TestDox('Formato errado: não localizado, sem consultar o banco')]
    public function testFormatoErradoNemConsulta(): void
    {
        $repositorio = $this->createMock(ComprovanteDoPontoRepository::class);
        $repositorio->expects(self::never())->method('porCodigo');

        self::assertNull((new ValidarComprovanteUseCase($repositorio, $this->selo()))->executar("BJP-ABCD' OR 1=1"));
    }

    #[TestDox('Sem o segredo do HMAC (vazio ou ausente): nada confere, nem o comprovante que existe, e selar recusa')]
    public function testSeloIndisponivel(): void
    {
        foreach ([null, ''] as $segredo) {
            $indisponivel = new SeloDoComprovanteHmac(new ChavesDoHmacDoPonto($segredo));
            self::assertFalse($indisponivel->disponivel());
            self::assertNull($this->executar($this->comprovante($this->selo()), $indisponivel, self::CODIGO));
        }

        $this->expectException(\LogicException::class);
        (new SeloDoComprovanteHmac(new ChavesDoHmacDoPonto(null)))->selar('{}');
    }

    #[TestDox('O selo: HMAC-SHA256 com a chave "ponto-comprovante" da versão atual (1), diferente da chave da trilha')]
    public function testSeloComAChaveDoComprovante(): void
    {
        $selado = $this->selo()->selar('{"formato":1}');

        $chave = hash_hkdf('sha256', 'segredo-de-teste', 32, 'ponto-comprovante');
        self::assertSame(['hmac' => hash_hmac('sha256', '{"formato":1}', $chave), 'versao' => 1], $selado);
        $daTrilha = hash_hkdf('sha256', 'segredo-de-teste', 32, ChavesDoHmacDoPonto::FINALIDADE_TRILHA);
        self::assertNotSame(hash_hmac('sha256', '{"formato":1}', $daTrilha), $selado['hmac']);
    }

    #[TestDox('Versão de chave gravada que o servidor não conhece, ou outro segredo: não confere')]
    public function testVersaoDesconhecidaEOutroSegredo(): void
    {
        $selado = $this->selo()->selar('{"formato":1}');

        self::assertTrue($this->selo()->conferir('{"formato":1}', $selado['hmac'], 1));
        self::assertFalse($this->selo()->conferir('{"formato":1}', $selado['hmac'], 2));
        self::assertFalse((new SeloDoComprovanteHmac(new ChavesDoHmacDoPonto('outro-segredo')))->conferir('{"formato":1}', $selado['hmac'], 1));
    }

    #[TestDox('Formato que o leitor não conhece (outro número), mesmo com o selo conferindo: não localizado')]
    public function testFormatoDesconhecido(): void
    {
        $selo = $this->selo();
        $formato2 = $this->comprovante($selo, null, null, self::CODIGO, fn (string $c): string => str_replace('"formato":1,', '"formato":2,', $c));

        self::assertTrue($selo->conferir($formato2->getDadosAssinados(), $formato2->getHmac(), 1), 'o selo confere');
        self::assertNull($this->executar($formato2, $selo, self::CODIGO));
    }

    private function selo(): SeloDoComprovanteHmac
    {
        return new SeloDoComprovanteHmac(new ChavesDoHmacDoPonto('segredo-de-teste'));
    }

    private function executar(?ComprovanteDoPonto $encontrado, SeloDoComprovante $selo, string $digitado): ?DadosDoComprovante
    {
        $repositorio = $this->createStub(ComprovanteDoPontoRepository::class);
        $repositorio->method('porCodigo')->willReturn($encontrado);

        return (new ValidarComprovanteUseCase($repositorio, $selo))->executar($digitado);
    }

    /**
     * @param (callable(string): string)|null $adulterar troca o texto gravado DEPOIS de selado
     * @param (callable(string): string)|null $antesDeSelar troca o texto ANTES de selar (o selo confere com ele)
     */
    private function comprovante(SeloDoComprovante $selo, ?callable $adulterar = null, ?string $hmac = null, string $codigoNosDados = self::CODIGO, ?callable $antesDeSelar = null): ComprovanteDoPonto
    {
        $dados = new DadosDoComprovante($codigoNosDados, 5, 7, 'Maria da Silva Pereira', 'Escritório Modelo', null, new \DateTimeImmutable('2026-11-09T08:01:02-03:00'), 'entrada', DadosDoComprovante::METODO_SISTEMA, '-23.55000, -46.63000 · precisão 12 m', false, false);
        $canonico = $antesDeSelar !== null ? $antesDeSelar($dados->canonico()) : $dados->canonico();
        $selado = $selo->selar($canonico);

        return new ComprovanteDoPonto(new Tenant(), (new RegistroPonto())->setUser(new User()), 5, self::CODIGO, $hmac ?? $selado['hmac'], $selado['versao'], new \DateTimeImmutable(), $adulterar !== null ? $adulterar($canonico) : $canonico);
    }
}
