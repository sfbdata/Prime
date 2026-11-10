<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use App\AssinaturaEletronica\Enum\NivelDeAssinatura;
use App\AssinaturaEletronica\Enum\SituacaoDaCadeia;
use App\AssinaturaEletronica\Service\Cadeia\RegraDaCadeia;
use App\AssinaturaEletronica\Service\Cadeia\ResultadoDaCadeia;
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
 * por nível.
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
        $alterada = CadeiaDeExemplo::assinaturaDoMotor(CadeiaDeExemplo::FAIXA_ANTIGA, VereditoDaAssinatura::Alterada);

        yield 'íntegro com uma nova: o validador decide' => [CadeiaDeExemplo::motor([$boa]), null];
        yield 'não conferido pelo motor: o validador decide' => [CadeiaDeExemplo::motor([CadeiaDeExemplo::assinaturaDoMotor(veredito: VereditoDaAssinatura::NaoConferida)], situacao: SituacaoDaConferencia::NaoConferido), null];
        yield 'prefixo não pedido: o validador decide' => [CadeiaDeExemplo::motor([$boa], prefixo: ConferenciaDoPrefixo::NaoPedida), null];
        yield 'ilegível' => [CadeiaDeExemplo::motor([], situacao: SituacaoDaConferencia::Ilegivel), RegraDaCadeia::MOTOR_ILEGIVEL];
        yield 'sem assinatura' => [CadeiaDeExemplo::motor([], situacao: SituacaoDaConferencia::SemAssinatura), RegraDaCadeia::SEM_ASSINATURA];
        yield 'íntegro sem assinatura nenhuma' => [CadeiaDeExemplo::motor([]), RegraDaCadeia::SEM_ASSINATURA];
        yield 'alterado' => [CadeiaDeExemplo::motor([$boa], situacao: SituacaoDaConferencia::Alterado), RegraDaCadeia::ASSINATURA_ALTERADA];
        yield 'uma antiga alterada, mesmo com a situação íntegra' => [CadeiaDeExemplo::motor([$alterada, $boa]), RegraDaCadeia::ASSINATURA_ALTERADA];
        yield 'prefixo que não confere' => [CadeiaDeExemplo::motor([$boa], prefixo: ConferenciaDoPrefixo::NaoConfere), RegraDaCadeia::PREFIXO_NAO_CONFERE];
        yield 'nenhuma assinatura nova' => [CadeiaDeExemplo::motor([$boa], novas: 0), RegraDaCadeia::SEM_ASSINATURA_NOVA];
    }

    #[DataProvider('antes')]
    #[TestDox('antes do validador ($_dataName)')]
    public function testAntesDoValidador(ResultadoDaConferencia $motor, ?string $esperado): void
    {
        self::assertSame($esperado, $this->regra->antesDoValidador($motor));
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
        yield 'anotações depois' => [['nivel_de_modificacao' => 'anotacoes'], RegraDaCadeia::MODIFICACAO_NAO_PERMITIDA];
        yield 'outra modificação' => [['nivel_de_modificacao' => 'outra'], RegraDaCadeia::MODIFICACAO_NAO_PERMITIDA];
        yield 'cobre só a revisão' => [['cobertura' => 'revisao_inteira'], RegraDaCadeia::COBERTURA_PARCIAL];
        yield 'cobertura parcial' => [['cobertura' => 'parcial'], RegraDaCadeia::COBERTURA_PARCIAL];
        yield 'DocMDP violado' => [['docmdp_ok' => false], RegraDaCadeia::DOCMDP_VIOLADO];
        yield 'a primeira falha vale (integridade antes da revogação)' => [['intacta' => false, 'revogacao' => 'revogada'], RegraDaCadeia::NAO_INTACTA];
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

    #[TestDox('modificação ltv e formulário e docmdp_ok verdadeiro passam')]
    public function testModificacoesAceitas(): void
    {
        foreach ([['nivel_de_modificacao' => 'ltv'], ['nivel_de_modificacao' => 'formulario'], ['docmdp_ok' => true]] as $troca) {
            $decisao = $this->regra->decidir(
                CadeiaDeExemplo::motor([CadeiaDeExemplo::assinaturaDoMotor()]),
                NivelDeAssinatura::Icp,
                CadeiaDeExemplo::resultado([CadeiaDeExemplo::item($troca)]),
            );
            self::assertTrue($decisao->confirmou(), (string) json_encode($troca));
        }
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
            CadeiaDeExemplo::assinaturaDoMotor(CadeiaDeExemplo::FAIXA, ordem: 2),
        ]);
        $cadeia = CadeiaDeExemplo::resultado([
            CadeiaDeExemplo::item(['faixa' => CadeiaDeExemplo::FAIXA_ANTIGA, 'confiavel' => false, 'ancora' => null, 'cobertura' => 'revisao_inteira']),
            CadeiaDeExemplo::item(),
        ]);

        self::assertTrue($this->regra->decidir($motor, NivelDeAssinatura::Icp, $cadeia)->confirmou());
    }

    #[TestDox('duas novas: as duas precisam passar')]
    public function testDuasNovas(): void
    {
        $motor = CadeiaDeExemplo::motor([
            CadeiaDeExemplo::assinaturaDoMotor(CadeiaDeExemplo::FAIXA_ANTIGA, ordem: 1),
            CadeiaDeExemplo::assinaturaDoMotor(CadeiaDeExemplo::FAIXA, ordem: 2),
        ], novas: 2);
        $cadeia = CadeiaDeExemplo::resultado([
            CadeiaDeExemplo::item(['faixa' => CadeiaDeExemplo::FAIXA_ANTIGA, 'revogacao' => 'revogada']),
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
