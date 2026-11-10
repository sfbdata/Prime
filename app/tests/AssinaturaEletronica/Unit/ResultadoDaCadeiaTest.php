<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use App\AssinaturaEletronica\Service\Cadeia\AssinaturaDaCadeia;
use App\AssinaturaEletronica\Service\Cadeia\CadeiaIndisponivel;
use App\AssinaturaEletronica\Service\Cadeia\CoberturaDaCadeia;
use App\AssinaturaEletronica\Service\Cadeia\ModificacaoDaCadeia;
use App\AssinaturaEletronica\Service\Cadeia\ResultadoDaCadeia;
use App\AssinaturaEletronica\Service\Cadeia\RevogacaoDaCadeia;
use App\AssinaturaEletronica\Service\Cadeia\ValidadorDeCadeia;
use App\AssinaturaEletronica\Service\Cadeia\ValidadorDeCadeiaNaoConfigurado;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O contrato da cadeia (etapa 5, seção 2, item 7; protocolo `v1` da etapa 6): a resposta do validador lida e
 * devolvida igual (vai para a coluna `cadeia`, E6-7), a leitura estrita e a implementação "não configurado".
 */
#[CoversClass(ResultadoDaCadeia::class)]
#[CoversClass(AssinaturaDaCadeia::class)]
#[CoversClass(ValidadorDeCadeiaNaoConfigurado::class)]
final class ResultadoDaCadeiaTest extends TestCase
{
    private const SHA = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    #[TestDox('o exemplo do protocolo vai e volta igual, com titular, carimbo e cadeia aninhados')]
    public function testExemploDoProtocolo(): void
    {
        $json = self::peloFio(self::exemplo());

        $resultado = ResultadoDaCadeia::deJson($json);

        self::assertNull($resultado->erro);
        self::assertFalse($resultado->ilegivel());
        self::assertCount(1, $resultado->assinaturas);
        $assinatura = $resultado->assinaturas[0];
        self::assertSame('Signature1', $assinatura->campo);
        self::assertSame([0, 840, 960, 1200], $assinatura->faixa);
        self::assertTrue($assinatura->intacta && $assinatura->valida && $assinatura->confiavel);
        self::assertSame(ValidadorDeCadeia::ANCORA_ICP, $assinatura->ancora);
        self::assertSame('FULANA DE TAL:52998224725', $assinatura->titularCn);
        self::assertSame('52998224725', $assinatura->titularCpf);
        self::assertNull($assinatura->titularCnpj);
        self::assertSame([['cn' => 'AC Final', 'sha256' => self::SHA], ['cn' => null, 'sha256' => self::SHA]], $assinatura->cadeia);
        self::assertSame(RevogacaoDaCadeia::Boa, $assinatura->revogacao);
        self::assertTrue($assinatura->carimboPresente && $assinatura->carimboValido);
        self::assertSame('2026-11-09T10:00:00.250000-03:00', $assinatura->horaDoCarimbo()?->format('Y-m-d\TH:i:s.uP'));
        self::assertSame(CoberturaDaCadeia::ArquivoInteiro, $assinatura->cobertura);
        self::assertSame(ModificacaoDaCadeia::Nenhuma, $assinatura->nivelDeModificacao);
        self::assertTrue($assinatura->docmdpOk);
        self::assertNull($assinatura->motivo);
        self::assertSame($json, $resultado->paraJson());
        self::assertEquals($resultado, ResultadoDaCadeia::deJson(self::peloFio($resultado->paraJson())));
    }

    #[TestDox('sem carimbo do tempo: {"presente":false,"valido":false,"hora":null}; o motivo é um código')]
    public function testSemCarimbo(): void
    {
        $json = self::exemplo();
        $json['assinaturas'][0]['carimbo'] = ['presente' => false, 'valido' => false, 'hora' => null];
        $json['assinaturas'][0]['confiavel'] = false;
        $json['assinaturas'][0]['ancora'] = null;
        $json['assinaturas'][0]['docmdp_ok'] = null;
        $json['assinaturas'][0]['motivo'] = 'raiz_desconhecida';

        $assinatura = ResultadoDaCadeia::deJson($json)->assinaturas[0];

        self::assertFalse($assinatura->carimboPresente);
        self::assertNull($assinatura->horaDoCarimbo());
        self::assertNull($assinatura->ancora);
        self::assertNull($assinatura->docmdpOk);
        self::assertSame('raiz_desconhecida', $assinatura->motivo);
        self::assertSame($json, ResultadoDaCadeia::deJson($json)->paraJson());
    }

    #[TestDox('o erro do PDF (400 pdf_ilegivel) vira resultado sem assinaturas e volta igual')]
    public function testPdfIlegivel(): void
    {
        $resultado = ResultadoDaCadeia::deJson(['v' => 1, 'erro' => 'pdf_ilegivel']);

        self::assertTrue($resultado->ilegivel());
        self::assertSame([], $resultado->assinaturas);
        self::assertSame(['v' => 1, 'erro' => 'pdf_ilegivel'], $resultado->paraJson());

        $this->expectExceptionObject(new \LogicException('Resultado da cadeia com erro não traz assinaturas.'));
        new ResultadoDaCadeia(ResultadoDaCadeia::deJson(self::exemplo())->assinaturas, 'pdf_ilegivel');
    }

    #[TestDox('casa a assinatura do motor pela faixa de bytes (/ByteRange); faixa sem conferência devolve nulo')]
    public function testDaFaixa(): void
    {
        $json = self::exemplo();
        $segunda = $json['assinaturas'][0];
        $segunda['campo'] = 'Signature2';
        $segunda['faixa'] = [0, 3000, 4000, 500];
        $json['assinaturas'][] = $segunda;
        $resultado = ResultadoDaCadeia::deJson($json);

        self::assertSame('Signature2', $resultado->daFaixa([0, 3000, 4000, 500])?->campo);
        self::assertSame('Signature1', $resultado->daFaixa([0, 840, 960, 1200])?->campo);
        self::assertNull($resultado->daFaixa([0, 840, 960, 1201]));
    }

    #[TestDox('assinatura sem /ByteRange (faixa nula, o item assinatura_ilegivel do validador): a resposta vale, o item é não íntegro e não casa com nenhuma faixa')]
    public function testFaixaNula(): void
    {
        $json = self::exemplo();
        $legivel = $json['assinaturas'][0];
        $json['assinaturas'][0] = ['campo' => null, 'faixa' => null, 'intacta' => true, 'valida' => false, 'confiavel' => false, 'ancora' => null,
            'titular' => ['cn' => null, 'cpf' => null, 'cnpj' => null], 'cadeia' => [], 'revogacao' => 'desconhecida',
            'carimbo' => ['presente' => false, 'valido' => false, 'hora' => null], 'cobertura' => 'parcial',
            'nivel_de_modificacao' => 'outra', 'docmdp_ok' => null, 'motivo' => 'assinatura_ilegivel'];
        $json['assinaturas'][] = $legivel;

        $resultado = ResultadoDaCadeia::deJson(self::peloFio($json));

        $ilegivel = $resultado->assinaturas[0];
        self::assertNull($ilegivel->faixa);
        self::assertFalse($ilegivel->intacta, 'sem a faixa, nunca íntegra (mesmo que venha verdadeiro)');
        self::assertSame('assinatura_ilegivel', $ilegivel->motivo);
        self::assertFalse($ilegivel->temAFaixa([0, 840, 960, 1200]));
        self::assertSame('Signature1', $resultado->daFaixa([0, 840, 960, 1200])?->campo);
        self::assertTrue($resultado->assinaturas[1]->intacta);
        self::assertNull($resultado->paraJson()['assinaturas'][0]['faixa']);
        self::assertEquals($resultado, ResultadoDaCadeia::deJson(self::peloFio($resultado->paraJson())));
    }

    /** @return iterable<string, array{\Closure(array<string, mixed>): array<string, mixed>, string}> */
    public static function respostasInvalidas(): iterable
    {
        yield 'versão desconhecida' => [static fn (array $j): array => ['v' => 2] + $j, 'Versão desconhecida do protocolo do validador de cadeia.'];
        yield 'versão em texto' => [static fn (array $j): array => ['v' => '1'] + $j, 'O campo "v" do JSON não é inteiro.'];
        yield 'erro vazio' => [static fn (array $j): array => ['v' => 1, 'erro' => ''], 'Erro vazio do validador de cadeia.'];
        yield 'sem assinaturas' => [static fn (array $j): array => ['v' => 1], 'JSON sem o campo "assinaturas".'];
        yield 'assinatura que não é objeto' => [static fn (array $j): array => ['v' => 1, 'assinaturas' => ['x']], 'Assinatura da cadeia que não é objeto.'];
        yield 'âncora fora do protocolo' => [self::troca('ancora', 'qualquer'), 'Âncora da assinatura fora do protocolo.'];
        yield 'CPF com máscara' => [self::trocaNo('titular', 'cpf', '529.982.247-25'), 'O cpf do titular não tem 11 dígitos.'];
        yield 'CNPJ curto' => [self::trocaNo('titular', 'cnpj', '1122233300018'), 'O cnpj do titular não tem 14 dígitos.'];
        yield 'faixa com três números' => [self::troca('faixa', [0, 840, 960]), 'A faixa da assinatura não tem quatro números não negativos.'];
        yield 'faixa negativa' => [self::troca('faixa', [0, -1, 960, 1200]), 'A faixa da assinatura não tem quatro números não negativos.'];
        yield 'sem o campo faixa (nula vale, ausente não)' => [static function (array $j): array {
            unset($j['assinaturas'][0]['faixa']);

            return $j;
        }, 'JSON sem o campo "faixa".'];
        yield 'sha256 da cadeia maiúsculo' => [self::troca('cadeia', [['cn' => 'AC', 'sha256' => strtoupper(self::SHA)]]), 'Impressão digital da cadeia fora do formato (64 hexadecimais minúsculos).'];
        yield 'item da cadeia que não é objeto' => [self::troca('cadeia', ['AC']), 'Item da cadeia que não é objeto.'];
        yield 'revogação desconhecida' => [self::troca('revogacao', 'talvez'), 'O campo "revogacao" do JSON não é um dos valores de ' . RevogacaoDaCadeia::class . '.'];
        yield 'hora sem fuso' => [self::trocaNo('carimbo', 'hora', '2026-11-09T13:00:00'), 'A hora do carimbo do tempo não está em ISO 8601.'];
        yield 'hora impossível' => [self::trocaNo('carimbo', 'hora', '2026-02-30T13:00:00Z'), 'A hora do carimbo do tempo não está em ISO 8601.'];
        yield 'carimbo sem o presente' => [self::troca('carimbo', ['valido' => false, 'hora' => null]), 'JSON sem o campo "presente".'];
        yield 'titular que não é objeto' => [self::troca('titular', ['FULANA']), 'O campo "titular" do JSON não é objeto.'];
    }

    /** @param \Closure(array<string, mixed>): array<string, mixed> $estragar */
    #[DataProvider('respostasInvalidas')]
    #[TestDox('a leitura é estrita: $_dataName')]
    public function testRespostaInvalida(\Closure $estragar, string $mensagem): void
    {
        $this->expectExceptionObject(new \UnexpectedValueException($mensagem));
        ResultadoDaCadeia::deJson($estragar(self::exemplo()));
    }

    #[TestDox('"não configurado": configurado() é falso e conferir() lança CadeiaIndisponivel')]
    public function testNaoConfigurado(): void
    {
        $validador = new ValidadorDeCadeiaNaoConfigurado();

        self::assertFalse($validador->configurado());
        $this->expectExceptionObject(new CadeiaIndisponivel('O validador de cadeia não está configurado.'));
        $validador->conferir('%PDF-1.7', ValidadorDeCadeia::ANCORA_ICP);
    }

    /** @return array<string, mixed> a resposta de exemplo do protocolo (etapa 6, seção 2), já preenchida */
    private static function exemplo(): array
    {
        return [
            'v' => 1,
            'assinaturas' => [[
                'campo' => 'Signature1',
                'faixa' => [0, 840, 960, 1200],
                'intacta' => true,
                'valida' => true,
                'confiavel' => true,
                'ancora' => 'icp',
                'titular' => ['cn' => 'FULANA DE TAL:52998224725', 'cpf' => '52998224725', 'cnpj' => null],
                'cadeia' => [['cn' => 'AC Final', 'sha256' => self::SHA], ['cn' => null, 'sha256' => self::SHA]],
                'revogacao' => 'boa',
                'carimbo' => ['presente' => true, 'valido' => true, 'hora' => '2026-11-09T10:00:00.25-03:00'],
                'cobertura' => 'arquivo_inteiro',
                'nivel_de_modificacao' => 'nenhuma',
                'docmdp_ok' => true,
                'motivo' => null,
            ]],
        ];
    }

    /** @return \Closure(array<string, mixed>): array<string, mixed> */
    private static function troca(string $campo, mixed $valor): \Closure
    {
        return static function (array $json) use ($campo, $valor): array {
            $json['assinaturas'][0][$campo] = $valor;

            return $json;
        };
    }

    /** @return \Closure(array<string, mixed>): array<string, mixed> */
    private static function trocaNo(string $objeto, string $campo, mixed $valor): \Closure
    {
        return static function (array $json) use ($objeto, $campo, $valor): array {
            $json['assinaturas'][0][$objeto][$campo] = $valor;

            return $json;
        };
    }

    /**
     * Como chega pela rede: `json_encode` e `json_decode` em array.
     *
     * @param array<string, mixed> $json
     *
     * @return array<string, mixed>
     */
    private static function peloFio(array $json): array
    {
        return json_decode(json_encode($json, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);
    }
}
