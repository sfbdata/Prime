<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use App\AssinaturaEletronica\Service\ConferenciaDePdf\AssinaturaConferida;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\ConferenciaDePdf;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\ConferenciaDoAcrescimo;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\ConferenciaDoCpf;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\ConferenciaDoPrefixo;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\ResultadoDaConferencia;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\SituacaoDaConferencia;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\VereditoDaAssinatura;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\VinculoDoCertificado;
use App\AssinaturaEletronica\Service\LeitorDeJsonEstrito;
use App\AssinaturaEletronica\Service\SerializacaoDaConferencia;
use App\Tests\AssinaturaEletronica\Unit\ConferenciaDePdf\OficinaDePdfAssinado;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O resultado do motor em JSON `{"v":1,...}` para a coluna `analise` da versão (contrato da etapa 5, seção 2, item 6):
 * ida e volta de todos os campos (passando por `json_encode`, como no banco) e a volta estrita.
 */
#[CoversClass(SerializacaoDaConferencia::class)]
#[CoversClass(LeitorDeJsonEstrito::class)]
final class SerializacaoDaConferenciaTest extends TestCase
{
    #[TestDox('ida e volta do resultado do motor de verdade: duas assinaturas incrementais, prefixo e CPF conferidos')]
    public function testIdaEVoltaDoMotor(): void
    {
        $primeira = OficinaDePdfAssinado::pdfAssinadoComVinculo();
        $segunda = OficinaDePdfAssinado::pdfAssinadoComVinculo(anterior: $primeira);
        $resultado = (new ConferenciaDePdf())->conferir($segunda, $primeira, OficinaDePdfAssinado::CPF);
        self::assertCount(2, $resultado->assinaturas);
        self::assertNotNull($resultado->assinaturas[1]->horaDeclarada);

        $json = self::peloBanco(SerializacaoDaConferencia::paraJson($resultado));

        self::assertSame(1, $json['v']);
        self::assertEquals($resultado, SerializacaoDaConferencia::deJson($json));
    }

    #[TestDox('ida e volta com todos os campos preenchidos, as datas em ISO 8601 no UTC e os nomes em snake_case')]
    public function testIdaEVoltaCompleta(): void
    {
        $resultado = self::resultadoCompleto();

        $json = self::peloBanco(SerializacaoDaConferencia::paraJson($resultado));

        self::assertSame('2026-01-02T03:04:05+00:00', $json['assinaturas'][0]['valido_desde']);
        self::assertSame('2027-01-02T03:04:05+00:00', $json['assinaturas'][0]['valido_ate']);
        self::assertSame('2026-11-09T13:00:00+00:00', $json['assinaturas'][0]['hora_declarada'], 'o fuso de Brasília vira UTC');
        self::assertSame([0, 100, 200, 50], $json['assinaturas'][0]['faixa']);
        self::assertSame('naoverif', $json['assinaturas'][1]['veredito']);
        self::assertSame(['v', 'situacao', 'motivo', 'tamanho', 'doc_mdp', 'alterado_depois_da_certificacao', 'cifrado', 'permite_carimbo', 'prefixo', 'assinaturas_novas', 'cpf', 'assinaturas'], array_keys($json));
        self::assertEquals($resultado, SerializacaoDaConferencia::deJson($json));
        self::assertSame($json, SerializacaoDaConferencia::paraJson(SerializacaoDaConferencia::deJson($json)), 'a segunda ida grava igual');
    }

    #[TestDox('PDF sem assinatura e PDF ilegível também vão e voltam')]
    public function testSemAssinatura(): void
    {
        $motor = new ConferenciaDePdf();
        foreach ([$motor->conferir(OficinaDePdfAssinado::pdfSemAssinatura()), $motor->conferir('lixo')] as $resultado) {
            self::assertEquals($resultado, SerializacaoDaConferencia::deJson(self::peloBanco(SerializacaoDaConferencia::paraJson($resultado))));
        }
    }

    /** @return iterable<string, array{\Closure(array<string, mixed>): array<string, mixed>, string}> */
    public static function jsonsInvalidos(): iterable
    {
        yield 'versão desconhecida' => [static fn (array $j): array => ['v' => 2] + $j, 'Versão desconhecida do JSON da análise do PDF.'];
        yield 'sem versão' => [static function (array $j): array {
            unset($j['v']);

            return $j;
        }, 'JSON sem o campo "v".'];
        yield 'campo ausente' => [static function (array $j): array {
            unset($j['cifrado']);

            return $j;
        }, 'JSON sem o campo "cifrado".'];
        yield 'situação desconhecida' => [static fn (array $j): array => ['situacao' => 'quase'] + $j, 'O campo "situacao" do JSON não é um dos valores de ' . SituacaoDaConferencia::class . '.'];
        yield 'número em texto' => [static fn (array $j): array => ['tamanho' => '10'] + $j, 'O campo "tamanho" do JSON não é inteiro.'];
        yield 'assinaturas que não são lista' => [static fn (array $j): array => ['assinaturas' => ['a' => 1]] + $j, 'O campo "assinaturas" do JSON não é lista.'];
        yield 'assinatura que não é objeto' => [static fn (array $j): array => ['assinaturas' => [1]] + $j, 'Item de "assinaturas" que não é objeto.'];
        yield 'data impossível' => [static function (array $j): array {
            $j['assinaturas'][0]['valido_ate'] = '2027-02-30T03:04:05+00:00';

            return $j;
        }, 'O campo "valido_ate" do JSON não é data ISO 8601.'];
        yield 'data sem fuso' => [static function (array $j): array {
            $j['assinaturas'][0]['hora_declarada'] = '2026-11-09 13:00:00';

            return $j;
        }, 'O campo "hora_declarada" do JSON não é data ISO 8601.'];
        yield 'faixa com texto' => [static function (array $j): array {
            $j['assinaturas'][0]['faixa'] = [0, '100', 200, 50];

            return $j;
        }, 'O campo "faixa" do JSON não é lista de inteiros.'];
    }

    /** @param \Closure(array<string, mixed>): array<string, mixed> $estragar */
    #[DataProvider('jsonsInvalidos')]
    #[TestDox('a volta é estrita: $_dataName')]
    public function testVoltaEstrita(\Closure $estragar, string $mensagem): void
    {
        $json = $estragar(self::peloBanco(SerializacaoDaConferencia::paraJson(self::resultadoCompleto())));

        $this->expectExceptionObject(new \UnexpectedValueException($mensagem));
        SerializacaoDaConferencia::deJson($json);
    }

    private static function resultadoCompleto(): ResultadoDaConferencia
    {
        $utc = new \DateTimeZone('UTC');
        $integra = new AssinaturaConferida(
            ordem: 1,
            faixaDeBytes: [0, 100, 200, 50],
            faixaValida: true,
            fim: 250,
            cobreODocumento: false,
            bytesDepois: 30,
            tipo: 'PAdES',
            algoritmoDoResumo: 'SHA-256',
            resumoConfere: true,
            chaveConfere: true,
            titular: 'FULANA DE TAL',
            cpf: '52998224725',
            cnpj: '11222333000181',
            documentoMascarado: '***.982.247-**',
            emissor: 'AC Teste',
            emissorDeclaraIcpBrasil: true,
            numeroDeSerie: '0a1b',
            validoDesde: new \DateTimeImmutable('2026-01-02 03:04:05', $utc),
            validoAte: new \DateTimeImmutable('2027-01-02 03:04:05', $utc),
            horaDeclarada: new \DateTimeImmutable('2026-11-09 10:00:00', new \DateTimeZone('America/Sao_Paulo')),
            docMdp: 2,
            veredito: VereditoDaAssinatura::Integra,
            motivo: 'O conteúdo e a assinatura conferem com o certificado',
            vinculoDoCertificado: VinculoDoCertificado::Vinculado,
            acrescimo: ConferenciaDoAcrescimo::MudaConteudo,
            motivoDoAcrescimo: 'O acréscimo da assinatura 1 muda o objeto 3, uma página (/Contents)',
        );
        $naoConferida = new AssinaturaConferida(2, [0, -1, 5, 5], false, -1, false, 0, '', null, null, null, null, null, null, null, null, false, null, null, null, null, null, VereditoDaAssinatura::NaoConferida, 'Faixa de bytes inválida');

        return new ResultadoDaConferencia(
            situacao: SituacaoDaConferencia::NaoConferido,
            motivo: 'O acréscimo da assinatura 1 muda o objeto 3',
            tamanho: 280,
            assinaturas: [$integra, $naoConferida],
            docMdp: 2,
            alteradoDepoisDaCertificacao: true,
            cifrado: true,
            permiteCarimbo: false,
            prefixo: ConferenciaDoPrefixo::NaoConfere,
            assinaturasNovas: 1,
            cpf: ConferenciaDoCpf::SemCpfNoCertificado,
        );
    }

    /**
     * Como volta do banco: `json_encode` e `json_decode` em array.
     *
     * @param array<string, mixed> $json
     *
     * @return array<string, mixed>
     */
    private static function peloBanco(array $json): array
    {
        return json_decode(json_encode($json, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);
    }
}
