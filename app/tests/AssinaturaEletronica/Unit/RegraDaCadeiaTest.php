<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use App\AssinaturaEletronica\Enum\NivelDeAssinatura;
use App\AssinaturaEletronica\Enum\SituacaoDaCadeia;
use App\AssinaturaEletronica\Service\Cadeia\RegraDaCadeia;
use App\AssinaturaEletronica\Service\Cadeia\ResultadoDaCadeia;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\ConferenciaDoAcrescimo;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\ConferenciaDoPrefixo;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\ResultadoDaConferencia;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\SituacaoDaConferencia;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\VereditoDaAssinatura;
use App\Tests\AssinaturaEletronica\Support\CadeiaDeExemplo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A regra da E6-6 (etapa 6, bloco 6B): o que recusa antes do validador e a combinação do motor com a resposta dele,
 * por nível e pela posição da assinatura nova.
 */
#[CoversClass(RegraDaCadeia::class)]
#[Group('assinatura')]
final class RegraDaCadeiaTest extends TestCase
{
    private RegraDaCadeia $regra;

    protected function setUp(): void
    {
        $this->regra = new RegraDaCadeia();
    }

    #[TestDox('âncora pedida: ICP só icp; gov.br qualquer (a gov.br ou a ICP-Brasil)')]
    public function testAncoraDoNivel(): void
    {
        self::assertSame('icp', RegraDaCadeia::ancoraDoNivel(NivelDeAssinatura::Icp));
        self::assertSame('qualquer', RegraDaCadeia::ancoraDoNivel(NivelDeAssinatura::Govbr));
    }

    /** @return iterable<string, array{NivelDeAssinatura}> */
    public static function niveisSemValidador(): iterable
    {
        yield 'Simples' => [NivelDeAssinatura::Simples];
        yield 'Reforçada' => [NivelDeAssinatura::Reforcada];
    }

    #[DataProvider('niveisSemValidador')]
    #[TestDox('nível sem validador ($_dataName): LogicException')]
    public function testNivelSemValidador(NivelDeAssinatura $nivel): void
    {
        $this->expectException(\LogicException::class);
        RegraDaCadeia::ancoraDoNivel($nivel);
    }

    /** @return iterable<string, array{ResultadoDaConferencia, ?string}> */
    public static function antes(): iterable
    {
        $boa = CadeiaDeExemplo::assinaturaDoMotor();
        $antiga = CadeiaDeExemplo::assinaturaDoMotor(CadeiaDeExemplo::FAIXA_ANTIGA);
        $alterada = CadeiaDeExemplo::assinaturaDoMotor(CadeiaDeExemplo::FAIXA_ANTIGA, VereditoDaAssinatura::Alterada);
        $mudaConteudo = CadeiaDeExemplo::assinaturaDoMotor(ordem: 2, acrescimo: ConferenciaDoAcrescimo::MudaConteudo);

        yield 'íntegro com uma nova: o validador decide' => [CadeiaDeExemplo::motor([$boa]), null];
        yield 'nova com acréscimo só da assinatura: o validador decide' => [CadeiaDeExemplo::motor([$antiga, CadeiaDeExemplo::assinaturaDoMotor(ordem: 2, acrescimo: ConferenciaDoAcrescimo::SoAssinatura)]), null];
        yield 'nova seguida do carimbo de documento: o validador decide' => [CadeiaDeExemplo::motor([$boa, CadeiaDeExemplo::carimboDoMotor()], novas: 2), null];
        yield 'ilegível' => [CadeiaDeExemplo::motor([], situacao: SituacaoDaConferencia::Ilegivel), RegraDaCadeia::MOTOR_ILEGIVEL];
        yield 'sem assinatura' => [CadeiaDeExemplo::motor([], situacao: SituacaoDaConferencia::SemAssinatura), RegraDaCadeia::SEM_ASSINATURA];
        yield 'íntegro sem assinatura nenhuma' => [CadeiaDeExemplo::motor([]), RegraDaCadeia::SEM_ASSINATURA];
        yield 'alterado' => [CadeiaDeExemplo::motor([$boa], situacao: SituacaoDaConferencia::Alterado), RegraDaCadeia::ASSINATURA_ALTERADA];
        yield 'uma antiga alterada, mesmo com a situação íntegra' => [CadeiaDeExemplo::motor([$alterada, $boa]), RegraDaCadeia::ASSINATURA_ALTERADA];
        yield 'prefixo que não confere' => [CadeiaDeExemplo::motor([$boa], prefixo: ConferenciaDoPrefixo::NaoConfere), RegraDaCadeia::PREFIXO_NAO_CONFERE];
        yield 'prefixo não pedido (E5-1)' => [CadeiaDeExemplo::motor([$boa], prefixo: ConferenciaDoPrefixo::NaoPedida), RegraDaCadeia::PREFIXO_NAO_PEDIDO];
        yield 'nenhuma assinatura nova' => [CadeiaDeExemplo::motor([$boa], novas: 0), RegraDaCadeia::SEM_ASSINATURA_NOVA];
        yield 'só o carimbo de documento é novo' => [CadeiaDeExemplo::motor([$antiga, CadeiaDeExemplo::carimboDoMotor()]), RegraDaCadeia::SEM_ASSINATURA_NOVA];
        yield 'nova com acréscimo que muda o conteúdo (M27)' => [CadeiaDeExemplo::motor([$antiga, $mudaConteudo]), RegraDaCadeia::ACRESCIMO_MUDA_CONTEUDO];
        yield 'acréscimo que muda o conteúdo vem antes do motor não conferido' => [CadeiaDeExemplo::motor([$antiga, $mudaConteudo], situacao: SituacaoDaConferencia::NaoConferido), RegraDaCadeia::ACRESCIMO_MUDA_CONTEUDO];
        yield 'motor não conferido' => [CadeiaDeExemplo::motor([CadeiaDeExemplo::assinaturaDoMotor(veredito: VereditoDaAssinatura::NaoConferida)], situacao: SituacaoDaConferencia::NaoConferido), RegraDaCadeia::MOTOR_NAO_CONFERIDO];
    }

    #[DataProvider('antes')]
    #[TestDox('antes do validador ($_dataName)')]
    public function testAntesDoValidador(ResultadoDaConferencia $motor, ?string $esperado): void
    {
        self::assertSame($esperado, $this->regra->antesDoValidador($motor));
    }

    #[TestDox('as novas são as últimas assinaturasNovas do motor, sem o carimbo de documento')]
    public function testNovasSemOCarimbo(): void
    {
        $antiga = CadeiaDeExemplo::assinaturaDoMotor(CadeiaDeExemplo::FAIXA_ANTIGA);
        $nova = CadeiaDeExemplo::assinaturaDoMotor(ordem: 2);

        self::assertSame([$nova], RegraDaCadeia::novas(CadeiaDeExemplo::motor([$antiga, $nova, CadeiaDeExemplo::carimboDoMotor(ordem: 3)], novas: 2)));
        self::assertSame([], RegraDaCadeia::novas(CadeiaDeExemplo::motor([$antiga], novas: 0)));
    }

    #[TestDox('decidir() repete a recusa do motor, mas guarda a resposta do validador para a E6-7')]
    public function testDecidirRepeteARecusaDoMotor(): void
    {
        $cadeia = CadeiaDeExemplo::resultado([CadeiaDeExemplo::item()]);

        $decisao = $this->regra->decidir(CadeiaDeExemplo::motor([CadeiaDeExemplo::assinaturaDoMotor()], novas: 0), NivelDeAssinatura::Icp, $cadeia);

        self::assertFalse($decisao->confirmou());
        self::assertSame(RegraDaCadeia::SEM_ASSINATURA_NOVA, $decisao->motivo);
        self::assertSame($cadeia, $decisao->cadeia);
    }

    /** @return iterable<string, array{NivelDeAssinatura, string}> */
    public static function confirmadas(): iterable
    {
        yield 'ICP com âncora icp' => [NivelDeAssinatura::Icp, 'icp'];
        yield 'gov.br com âncora govbr' => [NivelDeAssinatura::Govbr, 'govbr'];
        yield 'gov.br com âncora icp (ICP vale para gov.br)' => [NivelDeAssinatura::Govbr, 'icp'];
    }

    #[DataProvider('confirmadas')]
    #[TestDox('confirmada ($_dataName)')]
    public function testConfirmada(NivelDeAssinatura $nivel, string $ancora): void
    {
        $cadeia = CadeiaDeExemplo::resultado([CadeiaDeExemplo::item(['ancora' => $ancora])]);

        $decisao = $this->regra->decidir(CadeiaDeExemplo::motor([CadeiaDeExemplo::assinaturaDoMotor()]), $nivel, $cadeia);

        self::assertTrue($decisao->confirmou());
        self::assertSame(SituacaoDaCadeia::Confirmada, $decisao->situacao);
        self::assertNull($decisao->motivo);
        self::assertNull($decisao->recusada);
        self::assertSame($cadeia, $decisao->cadeia);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function itensRecusados(): iterable
    {
        yield 'não intacta' => [['intacta' => false], RegraDaCadeia::NAO_INTACTA];
        yield 'inválida' => [['valida' => false], RegraDaCadeia::INVALIDA];
        yield 'não confiável' => [['confiavel' => false, 'ancora' => null], RegraDaCadeia::NAO_CONFIAVEL];
        yield 'âncora govbr para ICP' => [['ancora' => 'govbr'], RegraDaCadeia::ANCORA_DE_OUTRO_NIVEL];
        yield 'revogada' => [['revogacao' => 'revogada'], RegraDaCadeia::REVOGACAO_NAO_BOA];
        yield 'revogação desconhecida' => [['revogacao' => 'desconhecida'], RegraDaCadeia::REVOGACAO_NAO_BOA];
        yield 'DocMDP violado' => [['docmdp_ok' => false], RegraDaCadeia::DOCMDP_VIOLADO];
        yield 'cobertura parcial' => [['cobertura' => 'parcial'], RegraDaCadeia::COBERTURA_PARCIAL];
        yield 'a primeira falha vale (integridade antes da revogação)' => [['intacta' => false, 'revogacao' => 'revogada'], RegraDaCadeia::NAO_INTACTA];
        yield 'DocMDP antes do que veio depois' => [['docmdp_ok' => false, 'cobertura' => 'parcial'], RegraDaCadeia::DOCMDP_VIOLADO];
    }

    #[DataProvider('itensRecusados')]
    #[TestDox('ICP recusada pelo item do validador ($_dataName)')]
    public function testRecusadaPeloItem(array $troca, string $motivo): void
    {
        $decisao = $this->regra->decidir(
            CadeiaDeExemplo::motor([CadeiaDeExemplo::assinaturaDoMotor()]),
            NivelDeAssinatura::Icp,
            CadeiaDeExemplo::resultado([CadeiaDeExemplo::item($troca)]),
        );

        self::assertFalse($decisao->confirmou());
        self::assertSame(SituacaoDaCadeia::Recusada, $decisao->situacao);
        self::assertSame($motivo, $decisao->motivo);
        self::assertNotNull($decisao->recusada);
        self::assertSame(CadeiaDeExemplo::FAIXA, $decisao->recusada->faixa);
    }

    /** @return iterable<string, array{string, string, ?string}> */
    public static function depoisDaUltima(): iterable
    {
        yield 'arquivo inteiro, nada depois' => ['arquivo_inteiro', 'nenhuma', null];
        yield 'revisão inteira com DSS e carimbo depois (ltv)' => ['revisao_inteira', 'ltv', null];
        yield 'revisão inteira com formulário depois' => ['revisao_inteira', 'formulario', RegraDaCadeia::MODIFICACAO_NAO_PERMITIDA];
        yield 'revisão inteira com anotações depois' => ['revisao_inteira', 'anotacoes', RegraDaCadeia::MODIFICACAO_NAO_PERMITIDA];
        yield 'revisão inteira com outra mudança depois' => ['revisao_inteira', 'outra', RegraDaCadeia::MODIFICACAO_NAO_PERMITIDA];
        yield 'revisão inteira sem nada depois (contraditório)' => ['revisao_inteira', 'nenhuma', RegraDaCadeia::MODIFICACAO_NAO_PERMITIDA];
        yield 'arquivo inteiro com mudança depois (contraditório)' => ['arquivo_inteiro', 'ltv', RegraDaCadeia::MODIFICACAO_NAO_PERMITIDA];
        yield 'parcial com ltv' => ['parcial', 'ltv', RegraDaCadeia::COBERTURA_PARCIAL];
    }

    #[DataProvider('depoisDaUltima')]
    #[TestDox('o que veio depois da última assinatura nova ($_dataName)')]
    public function testDepoisDaUltima(string $cobertura, string $modificacao, ?string $motivo): void
    {
        $decisao = $this->regra->decidir(
            CadeiaDeExemplo::motor([CadeiaDeExemplo::assinaturaDoMotor()]),
            NivelDeAssinatura::Icp,
            CadeiaDeExemplo::resultado([CadeiaDeExemplo::item(['cobertura' => $cobertura, 'nivel_de_modificacao' => $modificacao])]),
        );

        self::assertSame($motivo, $decisao->motivo);
        self::assertSame($motivo === null, $decisao->confirmou());
    }

    /** @return iterable<string, array{string, string, ?string}> */
    public static function depoisDeOutraNova(): iterable
    {
        yield 'revisão inteira com o campo da outra assinada depois (formulário)' => ['revisao_inteira', 'formulario', null];
        yield 'revisão inteira com ltv' => ['revisao_inteira', 'ltv', null];
        yield 'revisão inteira com anotações depois' => ['revisao_inteira', 'anotacoes', RegraDaCadeia::MODIFICACAO_NAO_PERMITIDA];
        yield 'revisão inteira com outra mudança depois' => ['revisao_inteira', 'outra', RegraDaCadeia::MODIFICACAO_NAO_PERMITIDA];
        yield 'parcial' => ['parcial', 'formulario', RegraDaCadeia::COBERTURA_PARCIAL];
    }

    #[DataProvider('depoisDeOutraNova')]
    #[TestDox('duas novas: o que veio depois da primeira, seguida da outra nova ($_dataName)')]
    public function testDepoisDeOutraNova(string $cobertura, string $modificacao, ?string $motivo): void
    {
        $motor = CadeiaDeExemplo::motor([
            CadeiaDeExemplo::assinaturaDoMotor(CadeiaDeExemplo::FAIXA_ANTIGA, ordem: 1),
            CadeiaDeExemplo::assinaturaDoMotor(CadeiaDeExemplo::FAIXA, ordem: 2),
        ], novas: 2);
        $cadeia = CadeiaDeExemplo::resultado([
            CadeiaDeExemplo::item(['faixa' => CadeiaDeExemplo::FAIXA_ANTIGA, 'cobertura' => $cobertura, 'nivel_de_modificacao' => $modificacao]),
            CadeiaDeExemplo::item(),
        ]);

        $decisao = $this->regra->decidir($motor, NivelDeAssinatura::Icp, $cadeia);

        self::assertSame($motivo, $decisao->motivo);
        self::assertSame($motivo === null ? null : CadeiaDeExemplo::FAIXA_ANTIGA, $decisao->recusada?->faixa);
    }

    #[TestDox('duas novas: a última segue com a regra da última (formulário depois dela recusa)')]
    public function testDuasNovasAUltimaSegueARegraDaUltima(): void
    {
        $motor = CadeiaDeExemplo::motor([
            CadeiaDeExemplo::assinaturaDoMotor(CadeiaDeExemplo::FAIXA_ANTIGA, ordem: 1),
            CadeiaDeExemplo::assinaturaDoMotor(CadeiaDeExemplo::FAIXA, ordem: 2),
        ], novas: 2);
        $cadeia = CadeiaDeExemplo::resultado([
            CadeiaDeExemplo::item(['faixa' => CadeiaDeExemplo::FAIXA_ANTIGA, 'cobertura' => 'revisao_inteira', 'nivel_de_modificacao' => 'formulario']),
            CadeiaDeExemplo::item(['cobertura' => 'revisao_inteira', 'nivel_de_modificacao' => 'formulario']),
        ]);

        $decisao = $this->regra->decidir($motor, NivelDeAssinatura::Icp, $cadeia);

        self::assertSame(RegraDaCadeia::MODIFICACAO_NAO_PERMITIDA, $decisao->motivo);
        self::assertSame(CadeiaDeExemplo::FAIXA, $decisao->recusada?->faixa);
    }

    #[TestDox('o carimbo de documento novo não vai ao validador: sem item para ele, a nova antes dele confirma com ltv')]
    public function testCarimboDeDocumentoNaoEConferido(): void
    {
        $motor = CadeiaDeExemplo::motor([CadeiaDeExemplo::assinaturaDoMotor(), CadeiaDeExemplo::carimboDoMotor()], novas: 2);
        $cadeia = CadeiaDeExemplo::resultado([CadeiaDeExemplo::item(['cobertura' => 'revisao_inteira', 'nivel_de_modificacao' => 'ltv'])]);

        self::assertTrue($this->regra->decidir($motor, NivelDeAssinatura::Icp, $cadeia)->confirmou());
    }

    #[TestDox('docmdp_ok verdadeiro ou nulo passa')]
    public function testDocMdp(): void
    {
        $motor = CadeiaDeExemplo::motor([CadeiaDeExemplo::assinaturaDoMotor()]);

        self::assertTrue($this->regra->decidir($motor, NivelDeAssinatura::Icp, CadeiaDeExemplo::resultado([CadeiaDeExemplo::item(['docmdp_ok' => true])]))->confirmou());
        self::assertTrue($this->regra->decidir($motor, NivelDeAssinatura::Icp, CadeiaDeExemplo::resultado([CadeiaDeExemplo::item(['docmdp_ok' => null])]))->confirmou());
    }

    #[TestDox('o motivo do validador não decide: item que passa com motivo preenchido confirma; item que falha com motivo nulo recusa')]
    public function testMotivoDoValidadorNaoDecide(): void
    {
        $motor = CadeiaDeExemplo::motor([CadeiaDeExemplo::assinaturaDoMotor()]);

        self::assertTrue($this->regra->decidir($motor, NivelDeAssinatura::Icp, CadeiaDeExemplo::resultado([CadeiaDeExemplo::item(['motivo' => 'ressalva qualquer'])]))->confirmou());
        self::assertFalse($this->regra->decidir($motor, NivelDeAssinatura::Icp, CadeiaDeExemplo::resultado([CadeiaDeExemplo::item(['valida' => false, 'motivo' => null])]))->confirmou());
    }

    #[TestDox('a nova sem item do validador na mesma faixa: assinatura_nao_conferida')]
    public function testNovaNaoConferida(): void
    {
        $decisao = $this->regra->decidir(
            CadeiaDeExemplo::motor([CadeiaDeExemplo::assinaturaDoMotor()]),
            NivelDeAssinatura::Icp,
            CadeiaDeExemplo::resultado([CadeiaDeExemplo::item(['faixa' => CadeiaDeExemplo::FAIXA_ANTIGA])]),
        );

        self::assertSame(RegraDaCadeia::NAO_CONFERIDA, $decisao->motivo);
        self::assertNull($decisao->recusada);
    }

    #[TestDox('validador sem assinatura nenhuma para um PDF que o motor viu assinado: assinatura_nao_conferida')]
    public function testValidadorSemItens(): void
    {
        $decisao = $this->regra->decidir(CadeiaDeExemplo::motor([CadeiaDeExemplo::assinaturaDoMotor()]), NivelDeAssinatura::Govbr, CadeiaDeExemplo::resultado([]));

        self::assertSame(RegraDaCadeia::NAO_CONFERIDA, $decisao->motivo);
    }

    #[TestDox('só as novas contam: a antiga reprovada pelo validador não recusa')]
    public function testSoAsNovasContam(): void
    {
        $motor = CadeiaDeExemplo::motor([
            CadeiaDeExemplo::assinaturaDoMotor(CadeiaDeExemplo::FAIXA_ANTIGA, ordem: 1),
            CadeiaDeExemplo::assinaturaDoMotor(CadeiaDeExemplo::FAIXA, ordem: 2, acrescimo: ConferenciaDoAcrescimo::SoAssinatura),
        ]);
        $cadeia = CadeiaDeExemplo::resultado([
            CadeiaDeExemplo::item(['faixa' => CadeiaDeExemplo::FAIXA_ANTIGA, 'confiavel' => false, 'ancora' => null, 'cobertura' => 'revisao_inteira', 'nivel_de_modificacao' => 'anotacoes']),
            CadeiaDeExemplo::item(),
        ]);

        self::assertTrue($this->regra->decidir($motor, NivelDeAssinatura::Icp, $cadeia)->confirmou());
    }

    #[TestDox('duas novas: a primeira reprovada pelo validador recusa, com a faixa dela')]
    public function testDuasNovasAsDuasPrecisamPassar(): void
    {
        $motor = CadeiaDeExemplo::motor([
            CadeiaDeExemplo::assinaturaDoMotor(CadeiaDeExemplo::FAIXA_ANTIGA, ordem: 1),
            CadeiaDeExemplo::assinaturaDoMotor(CadeiaDeExemplo::FAIXA, ordem: 2),
        ], novas: 2);
        $cadeia = CadeiaDeExemplo::resultado([
            CadeiaDeExemplo::item(['faixa' => CadeiaDeExemplo::FAIXA_ANTIGA, 'revogacao' => 'revogada', 'cobertura' => 'revisao_inteira', 'nivel_de_modificacao' => 'formulario']),
            CadeiaDeExemplo::item(),
        ]);

        $decisao = $this->regra->decidir($motor, NivelDeAssinatura::Icp, $cadeia);

        self::assertSame(RegraDaCadeia::REVOGACAO_NAO_BOA, $decisao->motivo);
        self::assertSame(CadeiaDeExemplo::FAIXA_ANTIGA, $decisao->recusada?->faixa);
    }

    /** @return iterable<string, array{string}> */
    public static function errosDefinitivos(): iterable
    {
        yield 'pdf_ilegivel' => [ResultadoDaCadeia::ERRO_PDF_ILEGIVEL];
        yield 'pdf_grande_demais' => ['pdf_grande_demais'];
    }

    #[DataProvider('errosDefinitivos')]
    #[TestDox('resposta definitiva do validador ($_dataName): recusada com o erro, e a resposta vai para a E6-7')]
    public function testErroDefinitivo(string $erro): void
    {
        $cadeia = new ResultadoDaCadeia([], $erro);

        $decisao = $this->regra->decidir(CadeiaDeExemplo::motor([CadeiaDeExemplo::assinaturaDoMotor()]), NivelDeAssinatura::Icp, $cadeia);

        self::assertFalse($decisao->confirmou());
        self::assertSame($erro, $decisao->motivo);
        self::assertTrue($decisao->temCadeia());
    }
}
