<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Entity\Tenant\Tenant;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\OpcaoDaPauta;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Enum\EstadoDaPauta;
use App\Voto\Enum\PesoDoVoto;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(PautaDaAssembleia::class)]
final class PautaDaAssembleiaTest extends TestCase
{
    private \DateTimeImmutable $agora;

    protected function setUp(): void
    {
        $this->agora = new \DateTimeImmutable('2026-11-10 19:00:00');
    }

    #[TestDox('Nasce aguardando, com o escritório da assembleia')]
    public function testNasceAguardando(): void
    {
        $tenant = new Tenant();
        $pauta = new PautaDaAssembleia(new Assembleia($tenant, 'AGO', PesoDoVoto::Unidade, 'G'), 2, 'Orçamento', 'Previsão 2027');

        self::assertSame(EstadoDaPauta::Aguardando, $pauta->getEstado());
        self::assertSame($tenant, $pauta->getTenant());
        self::assertSame(2, $pauta->getOrdem());
        self::assertSame('Previsão 2027', $pauta->getDescricao());
        self::assertNull($pauta->getQuorumExigido());
        self::assertFalse($pauta->aceitaVotoEm($this->agora));
    }

    #[TestDox('Abrir na 1ª convocação: aberta, quórum 50%, quem e quando, sem prazo')]
    public function testAbrirPrimeiraConvocacao(): void
    {
        $pauta = $this->pautaDeAssembleiaPublicada();

        $pauta->abrir('Mesa', 1, null, $this->agora);

        self::assertSame(EstadoDaPauta::Aberta, $pauta->getEstado());
        self::assertSame(1, $pauta->getConvocacao());
        self::assertSame('50.00', $pauta->getQuorumExigido());
        self::assertSame('Mesa', $pauta->getAbertaPorNome());
        self::assertSame($this->agora, $pauta->getAbertaEm());
        self::assertNull($pauta->getPrazoAte());
        self::assertTrue($pauta->aceitaVotoEm($this->agora->modify('+5 hours')));
        self::assertFalse($pauta->prazoVencidoEm($this->agora->modify('+5 hours')));
    }

    #[TestDox('Abrir na 2ª convocação grava quórum 0%')]
    public function testAbrirSegundaConvocacao(): void
    {
        $pauta = $this->pautaDeAssembleiaPublicada();

        $pauta->abrir('Mesa', 2, $this->agora->modify('+5 minutes'), $this->agora);

        self::assertSame('0.00', $pauta->getQuorumExigido());
        self::assertSame(2, $pauta->getConvocacao());
    }

    #[TestDox('Convocação fora de 1 e 2 é recusada')]
    public function testConvocacaoInvalida(): void
    {
        $pauta = $this->pautaDeAssembleiaPublicada();

        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);
        $pauta->abrir('Mesa', 3, null, $this->agora);
    }

    #[TestDox('Abrir pauta de assembleia em rascunho é recusado')]
    public function testAbrirComAssembleiaEmRascunho(): void
    {
        $pauta = new PautaDaAssembleia(new Assembleia(new Tenant(), 'AGO', PesoDoVoto::Unidade, 'G'), 1, 'Contas');

        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);
        $this->expectExceptionMessage('Publique a assembleia antes de abrir a votação.');
        $pauta->abrir('Mesa', 1, null, $this->agora);
    }

    #[TestDox('Abrir pauta já aberta é recusado')]
    public function testAbrirDuasVezes(): void
    {
        $pauta = $this->pautaDeAssembleiaPublicada();
        $pauta->abrir('Mesa', 1, null, $this->agora);

        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);
        $pauta->abrir('Mesa', 1, null, $this->agora);
    }

    #[TestDox('Prazo no passado é recusado na abertura')]
    public function testPrazoNoPassado(): void
    {
        $pauta = $this->pautaDeAssembleiaPublicada();

        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);
        $pauta->abrir('Mesa', 1, $this->agora, $this->agora);
    }

    #[TestDox('Com prazo: aceita voto antes e não no instante do prazo, que já conta como vencido')]
    public function testAceitaVotoAteOPrazo(): void
    {
        $pauta = $this->pautaDeAssembleiaPublicada();
        $prazo = $this->agora->modify('+5 minutes');
        $pauta->abrir('Mesa', 1, $prazo, $this->agora);

        self::assertTrue($pauta->aceitaVotoEm($prazo->modify('-1 second')));
        self::assertFalse($pauta->prazoVencidoEm($prazo->modify('-1 second')));
        self::assertFalse($pauta->aceitaVotoEm($prazo));
        self::assertTrue($pauta->prazoVencidoEm($prazo));
    }

    #[TestDox('Definir prazo e retirar o prazo só com a pauta aberta')]
    public function testDefinirPrazo(): void
    {
        $pauta = $this->pautaDeAssembleiaPublicada();
        $pauta->abrir('Mesa', 1, null, $this->agora);
        $prazo = $this->agora->modify('+3 minutes');

        $pauta->definirPrazo($prazo, $this->agora);
        self::assertSame($prazo, $pauta->getPrazoAte());

        $pauta->definirPrazo(null, $this->agora);
        self::assertNull($pauta->getPrazoAte());
    }

    #[TestDox('Definir prazo em pauta aguardando é recusado')]
    public function testDefinirPrazoSemEstarAberta(): void
    {
        $pauta = $this->pautaDeAssembleiaPublicada();

        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);
        $pauta->definirPrazo($this->agora->modify('+1 minute'), $this->agora);
    }

    #[TestDox('Encerrar: motivo vazio vira "Determinação da mesa"; encerrada não aceita voto')]
    public function testEncerrarComMotivoVazio(): void
    {
        $pauta = $this->pautaDeAssembleiaPublicada();
        $pauta->abrir('Mesa', 1, null, $this->agora);
        $fim = $this->agora->modify('+10 minutes');

        $pauta->encerrar('Presidente', '  ', $fim);

        self::assertSame(EstadoDaPauta::Encerrada, $pauta->getEstado());
        self::assertSame('Determinação da mesa', $pauta->getMotivoEncerramento());
        self::assertSame('Presidente', $pauta->getEncerradaPorNome());
        self::assertSame($fim, $pauta->getEncerradaEm());
        self::assertFalse($pauta->aceitaVotoEm($fim));
    }

    #[TestDox('Encerrar guarda o motivo escrito')]
    public function testEncerrarComMotivo(): void
    {
        $pauta = $this->pautaDeAssembleiaPublicada();
        $pauta->abrir('Mesa', 1, null, $this->agora);

        $pauta->encerrar('Presidente', 'Todos votaram', $this->agora->modify('+1 minute'));

        self::assertSame('Todos votaram', $pauta->getMotivoEncerramento());
    }

    #[TestDox('Encerrar pauta que não está aberta é recusado')]
    public function testEncerrarAguardando(): void
    {
        $pauta = $this->pautaDeAssembleiaPublicada();

        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);
        $this->expectExceptionMessage('Só a pauta aberta pode ser encerrada.');
        $pauta->encerrar('Mesa', '', $this->agora);
    }

    #[TestDox('Prazo vencido: encerra uma vez, com a hora do prazo e o motivo "Prazo definido pela mesa"')]
    public function testEncerrarSePrazoVenceu(): void
    {
        $pauta = $this->pautaDeAssembleiaPublicada();
        $prazo = $this->agora->modify('+5 minutes');
        $pauta->abrir('Mesa', 1, $prazo, $this->agora);

        self::assertFalse($pauta->encerrarSePrazoVenceu($prazo->modify('-1 second')));
        self::assertTrue($pauta->encerrarSePrazoVenceu($prazo->modify('+30 seconds')));

        self::assertSame(EstadoDaPauta::Encerrada, $pauta->getEstado());
        self::assertSame($prazo, $pauta->getEncerradaEm());
        self::assertSame('Prazo definido pela mesa', $pauta->getMotivoEncerramento());
        self::assertNull($pauta->getEncerradaPorNome());
        self::assertFalse($pauta->encerrarSePrazoVenceu($prazo->modify('+1 minute')), 'segunda leitura não encerra de novo');
    }

    #[TestDox('A mesa encerra depois do prazo vencido: vale o encerramento pelo prazo')]
    public function testEncerrarDepoisDoPrazo(): void
    {
        $pauta = $this->pautaDeAssembleiaPublicada();
        $prazo = $this->agora->modify('+5 minutes');
        $pauta->abrir('Mesa', 1, $prazo, $this->agora);

        $pauta->encerrar('Presidente', 'Fim', $prazo->modify('+2 minutes'));

        self::assertSame($prazo, $pauta->getEncerradaEm());
        self::assertSame('Prazo definido pela mesa', $pauta->getMotivoEncerramento());
    }

    #[TestDox('Editar a pauta só com a assembleia em rascunho')]
    public function testEditarSoEmRascunho(): void
    {
        $pauta = $this->pautaDeAssembleiaPublicada();

        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);
        $pauta->editar('Outro título', null);
    }

    #[TestDox('Opções de voto pelo número, sem a abstenção; a abstenção à parte')]
    public function testOpcoesDeVotoEAbstencao(): void
    {
        $pauta = new PautaDaAssembleia(new Assembleia(new Tenant(), 'AGO', PesoDoVoto::Unidade, 'G'), 1, 'Contas');
        $sim = new OpcaoDaPauta($pauta, 1, 'Sim');
        $abstencao = new OpcaoDaPauta($pauta, 0, 'Abstenção', true);
        $nao = new OpcaoDaPauta($pauta, 2, 'Não');

        self::assertSame([$sim, $nao], $pauta->getOpcoesDeVoto());
        self::assertSame($abstencao, $pauta->getAbstencao());
        self::assertSame($pauta->getTenant(), $sim->getTenant());
    }

    private function pautaDeAssembleiaPublicada(): PautaDaAssembleia
    {
        $assembleia = new Assembleia(new Tenant(), 'AGO', PesoDoVoto::Unidade, 'G');
        $pauta = new PautaDaAssembleia($assembleia, 1, 'Contas');
        new OpcaoDaPauta($pauta, 1, 'Sim');
        new OpcaoDaPauta($pauta, 2, 'Não');
        new UnidadeDaAssembleia($assembleia, '101', str_repeat('a', 32), str_repeat('b', 64));
        $assembleia->publicar();

        return $pauta;
    }
}
