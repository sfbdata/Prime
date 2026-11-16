<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Voto\Enum\BaseDaDecisao;
use App\Voto\Enum\RegraDeDecisao;
use App\Voto\Enum\TipoDaPauta;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Service\ModelosDePautas;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** Catálogo do desenho 18 (bj-voto-cenarios.js): cargos de pessoa, pautas Sim/Não e a regra de dois terços da convenção. */
#[CoversClass(ModelosDePautas::class)]
final class ModelosDePautasTest extends TestCase
{
    #[TestDox('Síndico(a) no condomínio é eleição de pessoas com 2 dígitos, sem nomes fictícios e com as regras do condomínio')]
    public function testSindicoEEleicaoComDoisDigitos(): void
    {
        $p = ModelosDePautas::pauta('condominio', 'Síndico(a)');

        self::assertSame(TipoDaPauta::EleicaoDeCargo, $p->tipo);
        self::assertSame([], $p->opcoes);
        self::assertSame(2, $p->regras?->digitos);
        self::assertSame(RegraDeDecisao::Mais, $p->regras->criterio);
        self::assertSame(BaseDaDecisao::Validos, $p->regras->base);
        self::assertSame(50, $p->regras->quorumPrimeira);
    }

    #[TestDox('Alteração da convenção pede dois terços sobre o total de aptos, quórum 0 e 2ª convocação herdada')]
    public function testConvencaoDoisTercos(): void
    {
        $p = ModelosDePautas::pauta('condominio', 'Alteração da convenção');

        self::assertSame(TipoDaPauta::Deliberacao, $p->tipo);
        self::assertSame(\App\Voto\DTO\PautaInput::OPCOES_PADRAO, $p->opcoes);
        self::assertSame(RegraDeDecisao::DoisTercos, $p->regras?->criterio);
        self::assertSame(BaseDaDecisao::Aptos, $p->regras->base);
        self::assertSame(0, $p->regras->quorumPrimeira);
        self::assertNull($p->regras->quorumSegunda);
        self::assertSame(1, $p->regras->digitos);
    }

    #[TestDox('Contrato social, na sociedade, também pede dois terços')]
    public function testContratoSocial(): void
    {
        $p = ModelosDePautas::pauta('sociedade', 'Alteração do contrato social');

        self::assertSame(RegraDeDecisao::DoisTercos, $p->regras?->criterio);
        self::assertNull($p->regras->quorumSegunda);
    }

    #[TestDox('Pauta comum do condomínio é Sim/Não com maioria dos votos válidos e quórum de 50%')]
    public function testPautaComum(): void
    {
        $p = ModelosDePautas::pauta('condominio', 'Aprovação de contas');

        self::assertSame(TipoDaPauta::Deliberacao, $p->tipo);
        self::assertSame(RegraDeDecisao::Mais, $p->regras?->criterio);
        self::assertSame(50, $p->regras->quorumPrimeira);
        self::assertSame(0, $p->regras->quorumSegunda);
    }

    #[TestDox('Na sociedade, a eleição de administradores é de pessoas (maioria, base mantida) e as contas seguem a maioria absoluta dos aptos')]
    public function testSociedade(): void
    {
        $eleicao = ModelosDePautas::pauta('sociedade', 'Eleição de administradores');
        $contas = ModelosDePautas::pauta('sociedade', 'Aprovação das contas');

        self::assertSame(TipoDaPauta::EleicaoDeCargo, $eleicao->tipo);
        self::assertSame(RegraDeDecisao::Mais, $eleicao->regras?->criterio);
        self::assertSame(BaseDaDecisao::Aptos, $eleicao->regras->base);
        self::assertSame(TipoDaPauta::Deliberacao, $contas->tipo);
        self::assertSame(RegraDeDecisao::Absoluta, $contas->regras?->criterio);
    }

    #[TestDox('Na associação todo cargo é eleição de pessoas')]
    public function testAssociacaoSoTemPessoas(): void
    {
        foreach (ModelosDePautas::pautasDoModelo('associacao_eleicao') as $p) {
            self::assertSame(TipoDaPauta::EleicaoDeCargo, $p->tipo, $p->titulo);
            self::assertSame(2, $p->regras?->digitos);
        }
    }

    #[TestDox('AGO completa do condomínio traz as 4 pautas do desenho, na ordem, com os tipos certos')]
    public function testAgoCompletaDoCondominio(): void
    {
        $pautas = ModelosDePautas::pautasDoModelo('condominio_ago');

        self::assertSame(['Aprovação de contas', 'Previsão orçamentária', 'Síndico(a)', 'Conselho fiscal'], array_map(static fn ($p) => $p->titulo, $pautas));
        self::assertSame(
            [TipoDaPauta::Deliberacao, TipoDaPauta::Deliberacao, TipoDaPauta::EleicaoDeCargo, TipoDaPauta::EleicaoDeCargo],
            array_map(static fn ($p) => $p->tipo, $pautas),
        );
    }

    #[TestDox('O catálogo tem os 7 modelos do desenho: 4 do condomínio, cooperativa, sociedade e associação')]
    public function testCatalogo(): void
    {
        $modelos = ModelosDePautas::modelos();

        self::assertSame(
            ['AGO completa', 'Eleição de síndico e conselho', 'AGE: alteração da convenção', 'AGE: obra ou benfeitoria', 'AGO completa', 'AGO de sócios', 'Eleição da diretoria'],
            array_values(array_map(static fn (array $m): string => $m['rotulo'], $modelos)),
        );
        foreach (array_keys($modelos) as $chave) {
            self::assertNotSame([], ModelosDePautas::pautasDoModelo($chave));
        }
    }

    #[TestDox('Os modelos seguem o cenário: condomínio 4, cooperativa 1, associação 1 e os demais nenhum')]
    public function testModelosPorCenario(): void
    {
        self::assertCount(4, ModelosDePautas::modelos('condominio'));
        self::assertSame(['AGO completa'], array_values(array_map(static fn (array $m): string => $m['rotulo'], ModelosDePautas::modelos('cooperativa'))));
        self::assertSame(['associacao_eleicao'], array_keys(ModelosDePautas::modelos('associacao')));
        foreach (['classe', 'sindicato', 'ensino', 'consulta', 'livre'] as $cenario) {
            self::assertSame([], ModelosDePautas::modelos($cenario), $cenario);
        }
    }

    #[TestDox('Os atalhos de cargos e pautas frequentes seguem o cenário, como o CG do desenho')]
    public function testAtalhosPorCenario(): void
    {
        self::assertCount(7, ModelosDePautas::atalhos('condominio'));
        self::assertSame(['Diretoria', 'Conselho', 'Delegados', 'Caixa de assistência'], ModelosDePautas::atalhos('classe'));
        self::assertSame(['Pergunta da consulta'], ModelosDePautas::atalhos('consulta'));
        self::assertSame([], ModelosDePautas::atalhos('livre'));
        self::assertSame([], ModelosDePautas::atalhos('inexistente'));
    }

    #[TestDox('Classe, sindicato, ensino e livre elegem pessoas (2 dígitos); consulta é só Sim/Não')]
    public function testRegrasDosDemaisCenarios(): void
    {
        foreach (['classe' => 'Diretoria', 'sindicato' => 'Diretoria executiva', 'ensino' => 'Reitoria ou direção'] as $cenario => $nome) {
            $p = ModelosDePautas::pauta($cenario, $nome);
            self::assertSame(TipoDaPauta::EleicaoDeCargo, $p->tipo, $cenario);
            self::assertSame(2, $p->regras?->digitos, $cenario);
        }
        $consulta = ModelosDePautas::pauta('consulta', 'Pergunta da consulta');
        self::assertSame(TipoDaPauta::Deliberacao, $consulta->tipo);
        self::assertSame(1, $consulta->regras?->digitos);
        self::assertSame(0, $consulta->regras->quorumPrimeira);
    }

    #[TestDox('Modelo desconhecido é recusado')]
    public function testModeloDesconhecido(): void
    {
        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);

        ModelosDePautas::pautasDoModelo('inexistente');
    }
}
