<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use App\AssinaturaEletronica\DTO\DadosDoRascunho;
use App\AssinaturaEletronica\DTO\DadosDoSignatario;
use App\AssinaturaEletronica\DTO\LeitorDeCampos;
use App\AssinaturaEletronica\DTO\SelecaoDeDocumentos;
use App\AssinaturaEletronica\Enum\CorDaLinha;
use App\AssinaturaEletronica\Enum\NivelDeAssinatura;
use App\AssinaturaEletronica\Enum\TipoDeLogo;
use App\AssinaturaEletronica\Exception\AcaoRecusada;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(DadosDoRascunho::class)]
#[CoversClass(DadosDoSignatario::class)]
#[CoversClass(LeitorDeCampos::class)]
#[CoversClass(SelecaoDeDocumentos::class)]
final class DadosDoRascunhoTest extends TestCase
{
    #[TestDox('rascunho vazio: nome "Documento", prazo 7, logo da BlueJus, sem signatários')]
    public function testPadroes(): void
    {
        $dados = DadosDoRascunho::deJson(['nome' => '   ']);

        self::assertSame('Documento', $dados->nome);
        self::assertSame(7, $dados->prazoDias);
        self::assertSame(TipoDeLogo::Bluejus, $dados->logo);
        self::assertNull($dados->pasta);
        self::assertSame('', $dados->layout);
        self::assertSame([], $dados->signatarios);
        self::assertNull($dados->usarLogoComoPadrao, 'ausente não mexe na logo padrão');
    }

    #[TestDox('usarLogoComoPadrao: verdadeiro grava, falso limpa, ausente ou nulo não mexe')]
    public function testUsarLogoComoPadraoTemTresEstados(): void
    {
        self::assertTrue(DadosDoRascunho::deJson(['usarLogoComoPadrao' => true])->usarLogoComoPadrao);
        self::assertFalse(DadosDoRascunho::deJson(['usarLogoComoPadrao' => false])->usarLogoComoPadrao);
        self::assertNull(DadosDoRascunho::deJson(['usarLogoComoPadrao' => null])->usarLogoComoPadrao);
        self::assertNull(DadosDoRascunho::deJson(['nome' => 'X'])->usarLogoComoPadrao);
    }

    #[TestDox('lê todos os campos do Rascunho')]
    public function testLeTudo(): void
    {
        $dados = DadosDoRascunho::deJson([
            'nome' => 'Contrato', 'pasta' => 'Silva', 'categoria' => 'Contratos', 'modelo' => 'honorarios',
            'blocos' => [['k' => 't', 'x' => 'A']], 'layout' => '2col', 'lateral' => ' nota ', 'folhaBranca' => true,
            'emOrdem' => true, 'mensagem' => 'Assine', 'prazoDias' => 90, 'logo' => ['tipo' => 'propria'],
            'usarLogoComoPadrao' => true,
            'signatarios' => [['nome' => 'Ana', 'email' => 'ana@exemplo.com.br', 'cpf' => '123.456.789-09', 'papel' => 'Cliente', 'nivel' => 'icp']],
        ]);

        self::assertSame(['Contrato', 'Silva', 'Contratos', 'honorarios', '2col', ' nota ', 'Assine', 90], [$dados->nome, $dados->pasta, $dados->categoria, $dados->modelo, $dados->layout, $dados->lateral, $dados->mensagem, $dados->prazoDias]);
        self::assertTrue($dados->folhaBranca && $dados->emOrdem && $dados->usarLogoComoPadrao);
        self::assertSame(TipoDeLogo::Propria, $dados->logo);
        self::assertSame('12345678909', $dados->signatarios[0]->cpf, 'a máscara sai, ficam os 11 dígitos');
        self::assertSame(NivelDeAssinatura::Icp, $dados->signatarios[0]->nivel);
        self::assertSame('ana@exemplo.com.br', $dados->signatarios[0]->email);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function noLimite(): iterable
    {
        yield 'nome com 200' => [['nome' => str_repeat('a', 200)]];
        yield 'pasta com 200' => [['pasta' => str_repeat('a', 200)]];
        yield 'mensagem com 2.000' => [['mensagem' => str_repeat('a', 2000)]];
        yield 'lateral com 20.000' => [['lateral' => str_repeat('a', 20000)]];
        yield 'prazo 1' => [['prazoDias' => 1]];
        yield 'prazo 90' => [['prazoDias' => 90]];
        yield '50 signatários' => [['signatarios' => array_fill(0, 50, ['nome' => 'A'])]];
        yield 'signatário com nome de 200 e papel de 120' => [['signatarios' => [['nome' => str_repeat('a', 200), 'papel' => str_repeat('p', 120)]]]];
        yield 'signatário sem nome, e-mail nem CPF (conferidos no envio)' => [['signatarios' => [[]]]];
    }

    /** @param array<string, mixed> $json */
    #[DataProvider('noLimite')]
    #[TestDox('aceita no limite: $_dataName')]
    public function testAceitaNoLimite(array $json): void
    {
        self::assertInstanceOf(DadosDoRascunho::class, DadosDoRascunho::deJson($json));
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function foraDoLimite(): iterable
    {
        yield 'nome com 201' => [['nome' => str_repeat('a', 201)], 'nome_longo'];
        yield 'pasta com 201' => [['pasta' => str_repeat('a', 201)], 'pasta_longa'];
        yield 'categoria com 81' => [['categoria' => str_repeat('a', 81)], 'categoria_longa'];
        yield 'modelo com 61' => [['modelo' => str_repeat('a', 61)], 'modelo_invalido'];
        yield 'mensagem com 2.001' => [['mensagem' => str_repeat('a', 2001)], 'mensagem_longa'];
        yield 'lateral com 20.001' => [['lateral' => str_repeat('a', 20001)], 'lateral_longa'];
        yield 'prazo 0' => [['prazoDias' => 0], 'prazo_invalido'];
        yield 'prazo 91' => [['prazoDias' => 91], 'prazo_invalido'];
        yield 'prazo em texto' => [['prazoDias' => 'sete'], 'prazo_invalido'];
        yield 'layout desconhecido' => [['layout' => '3col'], 'layout_invalido'];
        yield 'logo desconhecida' => [['logo' => ['tipo' => 'outra']], 'logo_invalida'];
        yield '51 signatários' => [['signatarios' => array_fill(0, 51, ['nome' => 'A'])], 'signatarios_demais'];
        yield 'nome do signatário com 201' => [['signatarios' => [['nome' => str_repeat('a', 201)]]], 'signatario_nome_longo'];
        yield 'papel com 121' => [['signatarios' => [['papel' => str_repeat('a', 121)]]], 'signatario_papel_longo'];
        yield 'e-mail inválido' => [['signatarios' => [['email' => 'ana@']]], 'signatario_email_invalido'];
        yield 'CPF com 10 dígitos' => [['signatarios' => [['cpf' => '1234567890']]], 'signatario_cpf_invalido'];
        yield 'CPF com letra' => [['signatarios' => [['cpf' => '1234567890a']]], 'signatario_cpf_invalido'];
        yield 'nível desconhecido' => [['signatarios' => [['nivel' => 'cartorio']]], 'signatario_nivel_invalido'];
        yield 'nome em lista' => [['nome' => ['a']], 'dados_invalidos'];
        yield 'blocos que não são lista' => [['blocos' => 'x'], 'dados_invalidos'];
        yield 'signatários fora de lista' => [['signatarios' => ['a' => []]], 'dados_invalidos'];
        yield 'dados que não são objeto' => ['texto', 'dados_invalidos'];
        yield 'dados em lista' => [[1, 2], 'dados_invalidos'];
    }

    #[DataProvider('foraDoLimite')]
    #[TestDox('recusa: $_dataName')]
    public function testRecusaForaDoLimite(mixed $json, string $erro): void
    {
        try {
            DadosDoRascunho::deJson($json);
            self::fail('deveria recusar');
        } catch (AcaoRecusada $e) {
            self::assertSame('assinatura_eletronica.erro.' . $erro, $e->getMessage());
        }
    }

    #[TestDox('o erro do signatário diz qual é, a partir de 1')]
    public function testNumeroDoSignatarioNaMensagem(): void
    {
        try {
            DadosDoRascunho::deJson(['signatarios' => [['nome' => 'A'], ['email' => 'x']]]);
            self::fail('deveria recusar');
        } catch (AcaoRecusada $e) {
            self::assertSame(['numero' => 2], $e->parametros);
        }
    }

    #[TestDox('seleção em lote: ids inteiros positivos, sem repetição, e a cor quando vier')]
    public function testSelecao(): void
    {
        $selecao = SelecaoDeDocumentos::deJson(['ids' => [3, 1, 3], 'cor' => 'verde']);

        self::assertSame([3, 1], $selecao->ids);
        self::assertSame(CorDaLinha::Verde, $selecao->cor);
        self::assertSame(CorDaLinha::Nenhuma, SelecaoDeDocumentos::deJson(['ids' => [1], 'cor' => ''])->cor);
        self::assertNull(SelecaoDeDocumentos::deJson(['ids' => [1]])->cor);

        foreach ([['ids' => []], ['ids' => [0]], ['ids' => ['1']], ['ids' => 'x'], [], ['ids' => [1], 'cor' => 'rosa']] as $invalida) {
            try {
                SelecaoDeDocumentos::deJson($invalida);
                self::fail('deveria recusar ' . json_encode($invalida));
            } catch (AcaoRecusada) {
                self::addToAssertionCount(1);
            }
        }
    }
}
