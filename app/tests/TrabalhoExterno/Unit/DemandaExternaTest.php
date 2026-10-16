<?php

declare(strict_types=1);

namespace App\Tests\TrabalhoExterno\Unit;

use App\TrabalhoExterno\DTO\DadosDaProposta;
use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Entity\PropostaDemanda;
use App\TrabalhoExterno\Enum\AcaoDemanda;
use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\Enum\Situacao;
use App\TrabalhoExterno\Enum\SituacaoProposta;
use App\TrabalhoExterno\Enum\TipoMensagem;
use App\TrabalhoExterno\Exception\DadosInvalidos;
use App\TrabalhoExterno\Exception\TransicaoInvalida;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** As invariantes da demanda: regra de estado, motivo, registro no chat e versões de proposta. */
#[CoversClass(DemandaExterna::class)]
#[CoversClass(PropostaDemanda::class)]
#[CoversClass(DadosDaProposta::class)]
final class DemandaExternaTest extends TestCase
{
    #[TestDox('o código deriva do id e volta para ele')]
    public function testCodigo(): void
    {
        $d = Apoio::demanda(Situacao::Rascunho);

        self::assertSame('TE-0007', $d->getCodigo());
        self::assertSame(7, DemandaExterna::idDoCodigo('TE-0007'));
        self::assertNull(DemandaExterna::idDoCodigo('7'));
        self::assertNull(DemandaExterna::idDoCodigo('TE-7 OR 1=1'));
    }

    #[TestDox('ação fora da situação de origem é recusada e não deixa rastro')]
    public function testOrigemInvalida(): void
    {
        $d = Apoio::demanda(Situacao::Nova);
        $historicoAntes = count($d->getHistorico());

        try {
            $d->aplicar(AcaoDemanda::Concluir, Apoio::autor(), null);
            self::fail('deveria recusar');
        } catch (TransicaoInvalida) {
        }

        self::assertSame(Situacao::Nova, $d->getSituacao());
        self::assertCount($historicoAntes, $d->getHistorico());
    }

    #[TestDox('motivo em branco não vale onde o desenho o exige')]
    public function testMotivoObrigatorio(): void
    {
        $this->expectException(TransicaoInvalida::class);
        Apoio::demanda(Situacao::Nova)->aplicar(AcaoDemanda::Recusar, Apoio::autor(), "  \n ");
    }

    #[TestDox('ação confirmada grava histórico com motivo e registro no chat; clique direto só o histórico')]
    public function testRegistros(): void
    {
        $d = Apoio::demanda(Situacao::Nova);
        $mensagensAntes = count($d->getMensagens());

        $d->aplicar(AcaoDemanda::IniciarAnalise, Apoio::autor(), null);
        self::assertCount($mensagensAntes, $d->getMensagens());

        $d->aplicar(AcaoDemanda::SolicitarInformacoes, Apoio::autor(), 'Falta a procuração');
        $ultima = $d->getMensagens()[count($d->getMensagens()) - 1];
        self::assertSame('Informações solicitadas: Falta a procuração', $ultima->getTexto());
        self::assertSame(TipoMensagem::Sistema, $ultima->getTipo());
        $h = $d->getHistorico()[count($d->getHistorico()) - 1];
        self::assertSame(['analise', 'info', 'Falta a procuração'], [$h->getDe(), $h->getPara(), $h->getMotivo()]);
    }

    #[TestDox('nova versão da proposta substitui a anterior em aberto; aceitar marca a vigente')]
    public function testVersoesDeProposta(): void
    {
        $d = Apoio::demanda(Situacao::Proposta);
        $m = Apoio::autor();
        $v2 = new PropostaDemanda($d, $d->proximaVersaoDeProposta(), '200.00', null, null, new \DateTimeImmutable(), null, null, null, $m);
        $d->enviarProposta($v2, $m);

        self::assertSame([SituacaoProposta::Substituida, SituacaoProposta::Enviada], array_map(static fn (PropostaDemanda $p) => $p->getSituacao(), $d->getPropostas()));
        self::assertSame('Proposta v2 enviada: R$ 200,00.', $d->getMensagens()[count($d->getMensagens()) - 1]->getTexto());

        $d->aplicar(AcaoDemanda::AceitarProposta, Apoio::autor(Perfil::Solicitante), null);
        self::assertSame(SituacaoProposta::Aceita, $v2->getSituacao());
        self::assertSame(Situacao::Aceita, $d->getSituacao());
    }

    #[TestDox('pagamento é marco único: registra uma vez, sem mudar a situação')]
    public function testPagamento(): void
    {
        $d = Apoio::demanda(Situacao::Execucao);
        $d->aplicar(AcaoDemanda::RegistrarPagamento, Apoio::autor(), null);

        self::assertNotNull($d->getPagoEm());
        self::assertSame(Situacao::Execucao, $d->getSituacao());
        self::assertSame(Situacao::MARCO_PAGO, $d->getHistorico()[count($d->getHistorico()) - 1]->getPara());

        $this->expectException(TransicaoInvalida::class);
        $d->aplicar(AcaoDemanda::RegistrarPagamento, Apoio::autor(), null);
    }

    #[TestDox('enviada não volta a ser editável; checklist só em execução')]
    public function testEdicaoEChecklist(): void
    {
        $d = Apoio::demanda(Situacao::Analise);
        try {
            $d->preencher(new \App\TrabalhoExterno\DTO\DadosDaSolicitacao());
            self::fail('deveria recusar');
        } catch (TransicaoInvalida) {
        }

        $this->expectException(TransicaoInvalida::class);
        $d->alternarItemDoChecklist(0, Apoio::autor());
    }

    /** @return iterable<string, array{string, string}> */
    public static function reais(): iterable
    {
        yield 'milhar e vírgula' => ['1.500,00', '1500.00'];
        yield 'só vírgula' => ['1500,5', '1500.50'];
        yield 'inteiro' => ['1500', '1500.00'];
        yield 'ponto decimal' => ['1500.5', '1500.50'];
        yield 'ponto de milhar' => ['1.500', '1500.00'];
        yield 'com R$' => ['R$ 2.000,10', '2000.10'];
    }

    #[DataProvider('reais')]
    #[TestDox('valor em reais é lido como o brl() do desenho')]
    public function testReais(string $digitado, string $esperado): void
    {
        self::assertSame($esperado, DadosDaProposta::reais($digitado));
    }

    #[TestDox('proposta sem valor é recusada com a mensagem do desenho')]
    public function testPropostaSemValor(): void
    {
        $this->expectExceptionObject(new DadosInvalidos('Informe o valor do serviço.'));
        DadosDaProposta::deArray(['valor' => ' ']);
    }
}
