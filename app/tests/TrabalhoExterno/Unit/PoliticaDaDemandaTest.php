<?php

declare(strict_types=1);

namespace App\Tests\TrabalhoExterno\Unit;

use App\TrabalhoExterno\Entity\ConfiguracaoCentral;
use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\Enum\Situacao;
use App\TrabalhoExterno\Enum\Visibilidade;
use App\TrabalhoExterno\Service\PoliticaDaDemanda;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** As regras de `acoes()`, `visiveis()` e `pode()` do desenho, uma a uma (spec §2, §4). */
#[CoversClass(PoliticaDaDemanda::class)]
final class PoliticaDaDemandaTest extends TestCase
{
    private PoliticaDaDemanda $politica;

    protected function setUp(): void
    {
        $this->politica = new PoliticaDaDemanda();
    }

    /** @return iterable<string, array{Perfil, Situacao, list<string>}> */
    public static function botoesPorPerfilESituacao(): iterable
    {
        yield 'Master, nova' => [Perfil::Master, Situacao::Nova, ['analise', 'recusar']];
        yield 'Master, análise' => [Perfil::Master, Situacao::Analise, ['proposta', 'info', 'recusar']];
        yield 'Master, info' => [Perfil::Master, Situacao::Info, ['retomar']];
        yield 'Master, proposta' => [Perfil::Master, Situacao::Proposta, ['proposta', 'cancelar']];
        yield 'Master, aceita' => [Perfil::Master, Situacao::Aceita, ['iniciar', 'convidar', 'pagamento']];
        yield 'Master, execução' => [Perfil::Master, Situacao::Execucao, ['convidar', 'validar', 'pagamento']];
        yield 'Master, validação' => [Perfil::Master, Situacao::Validacao, ['concluir', 'devolver', 'pagamento']];
        yield 'Master, concluída' => [Perfil::Master, Situacao::Concluida, ['pagamento', 'arquivar', 'reabrir']];
        yield 'Master, arquivada' => [Perfil::Master, Situacao::Arquivada, ['reabrir']];
        yield 'Solicitante, rascunho' => [Perfil::Solicitante, Situacao::Rascunho, ['editar', 'excluirRasc']];
        yield 'Solicitante, nova' => [Perfil::Solicitante, Situacao::Nova, ['cancelar']];
        yield 'Solicitante, info' => [Perfil::Solicitante, Situacao::Info, ['responderInfo', 'cancelar']];
        yield 'Solicitante, proposta' => [Perfil::Solicitante, Situacao::Proposta, ['aceitar', 'ajustes', 'recusarProp']];
        yield 'Solicitante, execução' => [Perfil::Solicitante, Situacao::Execucao, []];
    }

    /** @param list<string> $esperado */
    #[DataProvider('botoesPorPerfilESituacao')]
    #[TestDox('botões na ordem do desenho')]
    public function testBotoes(Perfil $perfil, Situacao $situacao, array $esperado): void
    {
        $botoes = $this->politica->botoes(Apoio::contexto($perfil), Apoio::demanda($situacao));

        self::assertSame($esperado, array_column($botoes, 'k'));
    }

    #[TestDox('o botão leva o texto do modal; clique direto e atalho não têm confirmação')]
    public function testConfirmacaoNoBotao(): void
    {
        $nova = $this->politica->botoes(Apoio::contexto(Perfil::Master), Apoio::demanda(Situacao::Nova));
        self::assertNull($nova[0]['confirmacao'], 'Iniciar análise é clique direto');
        self::assertSame(['titulo' => 'Recusar demanda', 'texto' => 'A recusa é registrada no histórico e pode ser revertida com "Reabrir".', 'botao' => 'Recusar', 'motivo' => true, 'perigo' => true], $nova[1]['confirmacao']);

        $analise = $this->politica->botoes(Apoio::contexto(Perfil::Master), Apoio::demanda(Situacao::Analise));
        self::assertTrue($analise[0]['atalho']);
        self::assertSame('Elaborar proposta', $analise[0]['rot']);
    }

    #[TestDox('Equipe: sem "situacao" não vê botão de fluxo; o atalho de proposta segue "proposta"')]
    public function testEquipeFiltradaPelaConfiguracao(): void
    {
        $cfg = new ConfiguracaoCentral();
        $ctx = Apoio::contexto(Perfil::Equipe, null, $cfg);
        self::assertSame([], array_column($this->politica->botoes($ctx, Apoio::demanda(Situacao::Analise)), 'k'));

        $cfg->definirEquipe('proposta', true);
        self::assertSame(['proposta'], array_column($this->politica->botoes($ctx, Apoio::demanda(Situacao::Analise)), 'k'));

        $cfg->definirEquipe('situacao', true);
        self::assertSame(['proposta', 'info', 'recusar'], array_column($this->politica->botoes($ctx, Apoio::demanda(Situacao::Analise)), 'k'));
    }

    #[TestDox('Solicitante só vê demanda do escritório atual; Central não vê rascunho')]
    public function testPodeVer(): void
    {
        $a = Apoio::tenant(1);
        $b = Apoio::tenant(2);
        $deA = Apoio::demanda(Situacao::Nova, $a);

        self::assertTrue($this->politica->podeVer(Apoio::contexto(Perfil::Solicitante, $a), $deA));
        self::assertFalse($this->politica->podeVer(Apoio::contexto(Perfil::Solicitante, $b), $deA));
        self::assertTrue($this->politica->podeVer(Apoio::contexto(Perfil::Master, $b), $deA));
        self::assertFalse($this->politica->podeVer(Apoio::contexto(Perfil::Master, $b), Apoio::demanda(Situacao::Rascunho, $a)));
        self::assertTrue($this->politica->podeVer(Apoio::contexto(Perfil::Solicitante, $a), Apoio::demanda(Situacao::Rascunho, $a)));
    }

    #[TestDox('visibilidade da aba: só equipe bloqueia externos; suspensa bloqueia também a Equipe; Master nunca')]
    public function testAbaBloqueada(): void
    {
        $cfg = new ConfiguracaoCentral();
        $cfg->definirVisibilidade(Visibilidade::Interno);
        self::assertTrue($this->politica->abaBloqueada(Apoio::contexto(Perfil::Solicitante, null, $cfg)));
        self::assertFalse($this->politica->abaBloqueada(Apoio::contexto(Perfil::Equipe, null, $cfg)));

        $cfg->definirVisibilidade(Visibilidade::Oculta);
        self::assertTrue($this->politica->abaBloqueada(Apoio::contexto(Perfil::Equipe, null, $cfg)));
        self::assertFalse($this->politica->abaBloqueada(Apoio::contexto(Perfil::Master, null, $cfg)));

        $cfg->definirVisibilidade(Visibilidade::Todos);
        $cfg->definirEquipe('ver', false);
        self::assertTrue($this->politica->abaBloqueada(Apoio::contexto(Perfil::Equipe, null, $cfg)));
    }

    #[TestDox('nome de quem atende: "Central BlueJus" para externos, a menos que a privacidade libere')]
    public function testNomeVisivel(): void
    {
        $cfg = new ConfiguracaoCentral();
        $externo = Apoio::contexto(Perfil::Solicitante, null, $cfg);

        self::assertSame('Central BlueJus', $this->politica->nomeVisivel($externo, 'Fulano', Perfil::Equipe));
        self::assertSame('Cliente', $this->politica->nomeVisivel($externo, 'Cliente', Perfil::Solicitante));
        self::assertSame('Fulano', $this->politica->nomeVisivel(Apoio::contexto(Perfil::Master), 'Fulano', Perfil::Equipe));

        $cfg->definirPrivacidade('nomeResp', true);
        self::assertSame('Fulano', $this->politica->nomeVisivel($externo, 'Fulano', Perfil::Master));
    }

    #[TestDox('chat: Equipe depende de "chat"; nota interna só Central; nada em rascunho')]
    public function testConversa(): void
    {
        $cfg = new ConfiguracaoCentral();
        $equipe = Apoio::contexto(Perfil::Equipe, null, $cfg);
        $d = Apoio::demanda(Situacao::Nova);

        self::assertTrue($this->politica->podeConversar($equipe, $d));
        self::assertTrue($this->politica->podeEscreverNotaInterna($equipe, $d));
        self::assertFalse($this->politica->podeEscreverNotaInterna(Apoio::contexto(Perfil::Solicitante), $d));
        self::assertFalse($this->politica->podeConversar(Apoio::contexto(Perfil::Solicitante), Apoio::demanda(Situacao::Rascunho)));

        $cfg->definirEquipe('chat', false);
        self::assertFalse($this->politica->podeConversar($equipe, $d));
    }

    #[TestDox('corte do aceite: só vale para o Convidado e, sem convite aceito, tudo é anterior')]
    public function testAnteriorAoAceiteFalhaFechado(): void
    {
        $agora = new \DateTimeImmutable();

        foreach ([Perfil::Master, Perfil::Equipe, Perfil::Solicitante] as $perfil) {
            self::assertFalse($this->politica->anteriorAoAceite(Apoio::contexto($perfil), $agora), $perfil->value);
            self::assertFalse($this->politica->marcoReservado(Apoio::contexto($perfil), 'proposta', $agora), $perfil->value);
        }

        $semConvite = Apoio::contexto(Perfil::Convidado);
        self::assertTrue($this->politica->anteriorAoAceite($semConvite, $agora));
        foreach (['analise', 'info', 'proposta', 'pago', 'validacao', 'recusada'] as $marco) {
            self::assertTrue($this->politica->marcoReservado($semConvite, $marco, $agora), $marco);
        }
        foreach (['nova', 'aceita', 'execucao'] as $marco) {
            self::assertFalse($this->politica->marcoReservado($semConvite, $marco, $agora), $marco);
        }
    }
}
