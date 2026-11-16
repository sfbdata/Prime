<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Entity\Tenant\Tenant;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\OpcaoDaPauta;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Enum\EstadoDaPauta;
use App\Voto\Enum\OrdemDasCandidaturas;
use App\Voto\Enum\PesoDoVoto;
use App\Voto\Enum\SigiloDoVoto;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Service\ConfiguracaoDaPublicacao;
use App\Voto\Service\OrdemDasCandidaturasNaCedula;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;

/**
 * Onda 2 do redesign (W2-3): horário programado, grupos aptos, ordem das candidaturas e sigilo da pauta (desenho 18),
 * e o que entram no hash da configuração.
 */
#[CoversClass(PautaDaAssembleia::class)]
#[CoversClass(ConfiguracaoDaPublicacao::class)]
#[CoversClass(OrdemDasCandidaturasNaCedula::class)]
final class ProgramacaoEParticipacaoDaPautaTest extends TestCase
{
    private \DateTimeImmutable $agora;

    protected function setUp(): void
    {
        $this->agora = new \DateTimeImmutable('2026-11-20 08:00:00');
    }

    #[TestDox('Padrão: sem horário, sem grupos, ordem de registro e voto secreto')]
    public function testPadrao(): void
    {
        $pauta = $this->pauta();

        self::assertFalse($pauta->programada());
        self::assertSame([], $pauta->getGrupos());
        self::assertSame(OrdemDasCandidaturas::Fixa, $pauta->getOrdemDasCandidaturas());
        self::assertSame(SigiloDoVoto::Secreto, $pauta->getSigilo());
        self::assertFalse($pauta->votoAberto());
        self::assertTrue($pauta->aceitaBloco('Qualquer'));
    }

    #[TestDox('Horário: os dois ou nenhum, encerramento depois da abertura e depois de agora')]
    public function testValidaOHorario(): void
    {
        $pauta = $this->pauta();
        $abre = $this->agora->modify('+1 hour');

        foreach ([[$abre, null], [null, $abre], [$abre, $abre], [$abre, $abre->modify('-1 minute')], [$this->agora->modify('-2 hours'), $this->agora->modify('-1 hour')]] as [$a, $e]) {
            try {
                $pauta->definirParticipacao($a, $e, [], OrdemDasCandidaturas::Fixa, SigiloDoVoto::Secreto, $this->agora);
                self::fail('O horário devia ser recusado.');
            } catch (OperacaoInvalidaNaAssembleiaException) {
                self::assertFalse($pauta->programada());
            }
        }

        $pauta->definirParticipacao($abre, $abre->modify('+2 hours'), [], OrdemDasCandidaturas::Fixa, SigiloDoVoto::Secreto, $this->agora);
        self::assertTrue($pauta->programada());
    }

    #[TestDox('Horário igual ao gravado e já vencido salva (outro campo mudou); horário novo no passado é recusado')]
    public function testHorarioVencidoSoRecusaQuandoMuda(): void
    {
        $pauta = $this->pauta();
        $abre = $this->agora->modify('+1 hour');
        $encerra = $this->agora->modify('+2 hours');
        $pauta->definirParticipacao($abre, $encerra, [], OrdemDasCandidaturas::Fixa, SigiloDoVoto::Secreto, $this->agora);

        $depois = $this->agora->modify('+3 hours');
        $pauta->definirParticipacao($abre, $encerra, ['Bloco A'], OrdemDasCandidaturas::Sorteada, SigiloDoVoto::Secreto, $depois);
        self::assertSame(['Bloco A'], $pauta->getGrupos());

        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);
        $pauta->definirParticipacao($abre, $this->agora->modify('+150 minutes'), [], OrdemDasCandidaturas::Fixa, SigiloDoVoto::Secreto, $depois);
    }

    #[TestDox('Grupos: tab e quebra de linha nas pontas do bloco e do grupo são ignorados')]
    public function testGruposComTab(): void
    {
        $pauta = $this->pauta();
        $pauta->definirParticipacao(null, null, ["\tBloco A\n"], OrdemDasCandidaturas::Fixa, SigiloDoVoto::Secreto);

        self::assertSame(['Bloco A'], $pauta->getGrupos());
        self::assertTrue($pauta->aceitaBloco("bloco a\t"));
    }

    #[TestDox('As regras só mudam em rascunho: depois de publicar o sigilo, o horário, os grupos e a ordem ficam fixos')]
    public function testFixasDepoisDePublicar(): void
    {
        $pauta = $this->pauta();
        $pauta->getAssembleia()->publicar($this->agora);

        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);
        $pauta->definirParticipacao(null, null, [], OrdemDasCandidaturas::Fixa, SigiloDoVoto::Aberto);
    }

    #[TestDox('Grupos: sem repetir, sem vazio, no máximo 50; a comparação ignora caixa e espaços das pontas')]
    public function testGrupos(): void
    {
        $pauta = $this->pauta();
        $pauta->definirParticipacao(null, null, [' Bloco A ', 'bloco a', '', 'Bloco B'], OrdemDasCandidaturas::Fixa, SigiloDoVoto::Secreto);

        self::assertSame(['Bloco A', 'Bloco B'], $pauta->getGrupos());
        self::assertTrue($pauta->aceitaBloco('BLOCO b '));
        self::assertFalse($pauta->aceitaBloco('Bloco C'));
        self::assertFalse($pauta->aceitaBloco(''));

        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);
        $pauta->definirParticipacao(null, null, array_map(static fn (int $i): string => 'G' . $i, range(1, 51)), OrdemDasCandidaturas::Fixa, SigiloDoVoto::Secreto);
    }

    #[TestDox('Programação abre a pauta no horário: aberta, 1ª convocação, "Programado", prazo no encerramento')]
    public function testAbrePorProgramacao(): void
    {
        $pauta = $this->programada();
        $abre = $this->agora->modify('+1 hour');
        $encerra = $abre->modify('+2 hours');

        self::assertFalse($pauta->deveAbrirPorProgramacaoEm($abre->modify('-1 second')));
        self::assertTrue($pauta->deveAbrirPorProgramacaoEm($abre));

        $pauta->abrirPorProgramacao($abre);

        self::assertSame(EstadoDaPauta::Aberta, $pauta->getEstado());
        self::assertSame(1, $pauta->getConvocacao());
        self::assertSame('Programado', $pauta->getAbertaPorNome());
        self::assertEquals($abre, $pauta->getAbertaEm());
        self::assertEquals($encerra, $pauta->getPrazoAte());
        self::assertTrue($pauta->aceitaVotoEm($abre->modify('+1 hour')));
    }

    #[TestDox('Programação não abre: fora da janela, retirada, assembleia arquivada ou ainda em rascunho')]
    public function testNaoAbre(): void
    {
        $abre = $this->agora->modify('+1 hour');
        $dentro = $abre->modify('+10 minutes');

        $retirada = $this->programada();
        $retirada->retirarDePauta('Fora da pauta', 'Mesa', $this->agora);
        self::assertFalse($retirada->deveAbrirPorProgramacaoEm($dentro));

        $arquivada = $this->programada();
        $arquivada->getAssembleia()->arquivar('Arquivada de teste', 'Mesa', $this->agora);
        self::assertFalse($arquivada->deveAbrirPorProgramacaoEm($dentro));

        self::assertFalse($this->programada()->deveAbrirPorProgramacaoEm($abre->modify('+3 hours')), 'a janela já fechou');

        $rascunho = $this->pauta();
        $rascunho->definirParticipacao($abre, $abre->modify('+2 hours'), [], OrdemDasCandidaturas::Fixa, SigiloDoVoto::Secreto, $this->agora);
        self::assertFalse($rascunho->deveAbrirPorProgramacaoEm($dentro), 'assembleia não publicada');

        $aberta = $this->programada();
        $aberta->abrir('Mesa', 2, null, $this->agora->modify('+30 minutes'));
        self::assertFalse($aberta->deveAbrirPorProgramacaoEm($dentro), 'a mesa já abriu');
    }

    #[TestDox('Encerra sozinha no horário com o motivo "Encerramento programado"; a aberta pela mesa mantém o motivo do prazo')]
    public function testEncerraPorProgramacao(): void
    {
        $pauta = $this->programada();
        $abre = $this->agora->modify('+1 hour');
        $encerra = $abre->modify('+2 hours');
        $pauta->abrirPorProgramacao($abre);

        self::assertFalse($pauta->encerrarSePrazoVenceu($encerra->modify('-1 second')));
        self::assertTrue($pauta->encerrarSePrazoVenceu($encerra));

        self::assertSame(EstadoDaPauta::Encerrada, $pauta->getEstado());
        self::assertSame('Encerramento programado', $pauta->getMotivoEncerramento());
        self::assertEquals($encerra, $pauta->getEncerradaEm());
        self::assertNull($pauta->getEncerradaPorNome());

        $pelaMesa = $this->pauta();
        $pelaMesa->getAssembleia()->publicar($this->agora);
        $pelaMesa->abrir('Mesa', 1, $abre, $this->agora);
        $pelaMesa->encerrarSePrazoVenceu($abre);
        self::assertSame(PautaDaAssembleia::MOTIVO_DO_PRAZO, $pelaMesa->getMotivoEncerramento());
    }

    #[TestDox('Suspensa não encerra no horário (o prazo congela) e voltar a aceitar voto só depois de retomada; cancelada nunca reabre')]
    public function testSuspensaECancelada(): void
    {
        $abre = $this->agora->modify('+1 hour');
        $encerra = $abre->modify('+2 hours');

        $suspensa = $this->programada();
        $suspensa->abrirPorProgramacao($abre);
        $suspensa->suspender('Queda de energia', 'Mesa', $abre->modify('+10 minutes'));
        self::assertFalse($suspensa->encerrarSePrazoVenceu($encerra->modify('+1 hour')));
        self::assertTrue($suspensa->suspensa());

        $cancelada = $this->programada();
        $cancelada->cancelar('Erro na pauta', 'Mesa', $this->agora);
        self::assertTrue($cancelada->retirada(), 'cancelar antes de abrir equivale a retirar');
        self::assertFalse($cancelada->deveAbrirPorProgramacaoEm($abre->modify('+10 minutes')));
    }

    #[TestDox('O hash da configuração muda com o horário, os grupos, a ordem e o sigilo; sem eles é o de antes')]
    public function testHashMudaComAsRegrasNovas(): void
    {
        $pauta = $this->pauta();
        $assembleia = $pauta->getAssembleia();
        $padrao = ConfiguracaoDaPublicacao::codigo($assembleia);
        $abre = $this->agora->modify('+1 hour');

        $pauta->definirParticipacao(null, null, [], OrdemDasCandidaturas::Fixa, SigiloDoVoto::Secreto);
        self::assertSame($padrao, ConfiguracaoDaPublicacao::codigo($assembleia), 'regras no padrão: código de antes');

        $variacoes = [
            [$abre, $abre->modify('+1 hour'), [], OrdemDasCandidaturas::Fixa, SigiloDoVoto::Secreto],
            [null, null, ['Bloco A'], OrdemDasCandidaturas::Fixa, SigiloDoVoto::Secreto],
            [null, null, [], OrdemDasCandidaturas::Sorteada, SigiloDoVoto::Secreto],
            [null, null, [], OrdemDasCandidaturas::Fixa, SigiloDoVoto::Aberto],
            [null, null, [], OrdemDasCandidaturas::Fixa, SigiloDoVoto::AbertoAoVivo],
        ];
        $codigos = [$padrao];
        foreach ($variacoes as [$a, $e, $g, $o, $s]) {
            $pauta->definirParticipacao($a, $e, $g, $o, $s, $this->agora);
            $codigos[] = ConfiguracaoDaPublicacao::codigo($assembleia);
        }

        self::assertCount(6, array_unique($codigos));
    }

    #[TestDox('Ordem de registro: pelo número, abstenção por último; sorteada: as mesmas opções em outra ordem, abstenção ainda por último')]
    public function testOrdemDasCandidaturas(): void
    {
        $pauta = $this->pauta();
        foreach ($pauta->getOpcoes()->toArray() as $padrao) {
            $pauta->getOpcoes()->removeElement($padrao);
        }
        foreach ([[5, 'Eva'], [1, 'Ana'], [3, 'Caio'], [2, 'Bia'], [4, 'Duda']] as [$numero, $nome]) {
            new OpcaoDaPauta($pauta, $numero, $nome);
        }
        new OpcaoDaPauta($pauta, 0, 'Abstenção', true);
        $ordem = new OrdemDasCandidaturasNaCedula(new Mt19937(7));
        $nomes = static fn (array $opcoes): array => array_map(static fn (OpcaoDaPauta $o): string => $o->getRotulo(), $opcoes);

        self::assertSame(['Ana', 'Bia', 'Caio', 'Duda', 'Eva', 'Abstenção'], $nomes($ordem->ordenar($pauta)));

        $pauta->definirParticipacao(null, null, [], OrdemDasCandidaturas::Sorteada, SigiloDoVoto::Secreto);
        $sorteadas = [];
        for ($i = 0; $i < 8; ++$i) {
            $lista = $nomes($ordem->ordenar($pauta));
            self::assertSame('Abstenção', $lista[5], 'a abstenção não entra no sorteio');
            $semAbstencao = \array_slice($lista, 0, 5);
            sort($semAbstencao);
            self::assertSame(['Ana', 'Bia', 'Caio', 'Duda', 'Eva'], $semAbstencao, 'ninguém some nem repete');
            $sorteadas[implode(',', $lista)] = true;
        }
        self::assertGreaterThan(1, \count($sorteadas), 'a cada visualização a ordem pode mudar');
    }

    private function pauta(): PautaDaAssembleia
    {
        $assembleia = new Assembleia(new Tenant(), 'AGO 2026', PesoDoVoto::Unidade, 'Gestor');
        new UnidadeDaAssembleia($assembleia, '101', str_repeat('a', 32), str_repeat('b', 64), 'Bloco A');

        $pauta = new PautaDaAssembleia($assembleia, 1, 'Aprovação das contas');
        new OpcaoDaPauta($pauta, 1, 'Sim');
        new OpcaoDaPauta($pauta, 2, 'Não');
        new OpcaoDaPauta($pauta, 0, 'Abstenção', true);

        return $pauta;
    }

    /** Pauta programada para abrir daqui a 1h e encerrar 2h depois, com a assembleia já publicada. */
    private function programada(): PautaDaAssembleia
    {
        $pauta = $this->pauta();
        $abre = $this->agora->modify('+1 hour');
        $pauta->definirParticipacao($abre, $abre->modify('+2 hours'), [], OrdemDasCandidaturas::Fixa, SigiloDoVoto::Secreto, $this->agora);
        $pauta->getAssembleia()->publicar($this->agora);

        return $pauta;
    }
}
