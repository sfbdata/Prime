<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Botão `disabled` **não emite evento de clique**. Enquanto a tela desabilitava o botão por
 * impedimento de regra, o toque de quem não escolheu o tipo ou estava fora do raio não produzia
 * nada — sem mensagem, sem vibração. Para quem não tem intimidade com a tela, "não aconteceu nada"
 * é indistinguível de "registrou", e a pessoa ia embora achando que tinha batido.
 *
 * Foi assim que o repouso de 14/09/2026 de um colaborador não chegou ao servidor: o `audit_log`
 * daquele dia tem só a entrada dele, e não há exclusão nenhuma. O deploy da manhã tinha acabado de
 * trocar a recusa VISÍVEL da cerca (403 do servidor, com mensagem vermelha acima do botão) por
 * botão cinza com o aviso em `#gps-status`, no topo do card.
 *
 * 🔑 **O invariante: só o ENVIO desabilita o botão.** Todo impedimento de regra responde ao toque,
 * em `#batida-aviso` — a mesma caixa em que a pessoa lê o resultado de uma batida que deu certo.
 * A cerca continua impedindo o envio; o que mudou foi o feedback, não a regra.
 *
 * Spec: `docs/specs/ponto-batida-que-responde-e-conta-certa.md`.
 *
 * 🪤 As asserções são sobre o ARQUIVO INTEIRO, não sobre o recorte de uma função. O furo já voltou
 * três vezes neste mesmo template por função declarada fora do trecho asserido — e voltou de novo
 * aqui: `erroGps` e o `catch` da API de geolocalização matavam o botão por outra porta.
 * Ver `feedback_provar_teste_reintroduzindo_defeito`.
 *
 * PHPUnit não executa JavaScript: isto confere o arranjo do fonte. Que o botão responde ao toque
 * num Android é smoke do dono, no celular.
 */
final class BotaoDoPontoRespondeAoToqueTest extends TestCase
{
    private const CAMINHO_TELA = __DIR__ . '/../../../templates/ponto/index.html.twig';

    private function tela(): string
    {
        $conteudo = file_get_contents(self::CAMINHO_TELA);
        self::assertNotFalse($conteudo, 'não foi possível ler a tela do ponto');

        return $conteudo;
    }

    #[TestDox('no arquivo inteiro, o único lugar que desabilita o botão é o envio')]
    public function testSoOEnvioDesabilitaOBotao(): void
    {
        $tela = $this->tela();

        preg_match_all('/btnPonto\.disabled\s*=\s*([^;]+);/', $tela, $casamentos);
        $atribuicoes = array_map('trim', $casamentos[1]);

        self::assertSame(
            ['envioEmAndamento', 'true'],
            $atribuicoes,
            'o botão voltou a ser desabilitado por impedimento de regra: toque sem resposta é '
            . 'indistinguível de batida registrada para quem não conhece a tela'
        );
    }

    #[TestDox('o único `disabled = true` é o da batida em envio')]
    public function testOUnicoDisabledVerdadeiroEODoEnvio(): void
    {
        $tela = $this->tela();

        // A trava do envio dura ~1,5 s, com "Registrando..." escrito no próprio botão, e impede a
        // segunda batida do mesmo tipo. É o único caso em que o sistema já respondeu ao toque.
        self::assertMatchesRegularExpression(
            '/envioEmAndamento\s*=\s*true;\s*\n\s*btnPonto\.disabled\s*=\s*true;/',
            $tela,
            'o `disabled = true` que sobrou não é o do envio'
        );
    }

    #[TestDox('o botão nasce cinza no HTML e é o JS que o liga na inicialização')]
    public function testBotaoNasceCinzaEOJsOLiga(): void
    {
        $tela = $this->tela();

        // O `disabled` do HTML cobre o único caso que o clique não alcança: o script não ter
        // rodado. Aí bater ponto realmente não funciona, e cinza é honesto. O que não pode voltar
        // é o botão permanecer cinza com o script vivo — por isso a inicialização precisa ligá-lo.
        self::assertMatchesRegularExpression(
            '/<button id="btn-bater-ponto"[^>]*\bdisabled\b[^>]*>/',
            $tela,
            'sem o `disabled` inicial, um erro de JS deixa um botão vivo que não responde ao toque'
        );

        // 🪤 `\s*` aqui não serve: o listener do `change` tem exatamente estas duas chamadas na
        // mesma ordem, e um padrão frouxo casa o corpo DELE. Apagar a inicialização deixava o
        // teste verde. A indentação de exatamente quatro espaços é o que distingue o escopo do
        // script (4) do corpo de uma função (8) — e é a única âncora estrutural disponível, já
        // que PHPUnit não executa o JS para perguntar em que escopo a chamada está.
        self::assertMatchesRegularExpression(
            '/\n    updateButtonState\(\);\n    atualizarRotuloBotao\(\);/',
            $tela,
            'o JS parou de ligar o botão na inicialização. Com o `disabled` do HTML, isso não '
            . 'deixa o botão mudo: deixa o ponto TRAVADO para quem não encostar no select — e, '
            . 'como o select já vem pré-selecionado, ninguém tem motivo para encostar'
        );
    }

    #[TestDox('a virada do dia devolve o select ao vazio, senão a sugestão é de ontem')]
    public function testViradaDoDiaLimpaASugestao(): void
    {
        $tela = $this->tela();

        $corpo = $this->corpoDaFuncao('function conferirViradaDoDia(');

        // Sugestão velha + um toque = `saida` gravada no dia novo, e a interjornada de 11 h do
        // servidor passa a recusar a entrada da pessoa pelo resto da manhã.
        self::assertMatchesRegularExpression(
            "/tipoRegistro\.value\s*=\s*'';\s*\n\s*atualizarRotuloBotao\(\);/",
            $corpo,
            'a sugestão do dia anterior sobreviveu à virada do dia'
        );
    }

    #[TestDox('cada impedimento do clique escreve em #batida-aviso')]
    public function testTodoImpedimentoRespondeNoAviso(): void
    {
        $corpo = $this->corpoDaFuncao("btnPonto.addEventListener('click'");

        $impedimentos = [
            'tipo não escolhido' => '/!tipoRegistro\.value\s*\)\s*\{\s*mostrarAvisoBatida\(/',
            'sem posição'        => '/!homeOfficeHoje && !currentLocation\s*\)\s*\{\s*mostrarAvisoBatida\(/',
            'fora do raio'       => '/situacao\s*===\s*\'fora\'\s*\)\s*\{\s*(?:\/\/[^\n]*\n\s*)*mostrarAvisoBatida\(/',
        ];

        foreach ($impedimentos as $nome => $padrao) {
            self::assertMatchesRegularExpression(
                $padrao,
                $corpo,
                sprintf('"%s" voltou a impedir a batida sem dizer nada à pessoa', $nome)
            );
        }
    }

    /** Recorta o corpo de uma função pelo balanceamento de chaves. */
    private function corpoDaFuncao(string $assinatura): string
    {
        $fonte  = $this->tela();
        $inicio = strpos($fonte, $assinatura);
        self::assertIsInt($inicio, sprintf('não achei `%s` na tela do ponto', $assinatura));

        $abre = strpos($fonte, '{', $inicio);
        self::assertIsInt($abre, 'não achei a abertura do corpo');

        $profundidade = 0;
        for ($i = $abre; $i < strlen($fonte); $i++) {
            if ($fonte[$i] === '{') {
                $profundidade++;
            } elseif ($fonte[$i] === '}') {
                $profundidade--;
                if ($profundidade === 0) {
                    return substr($fonte, $inicio, $i - $inicio + 1);
                }
            }
        }

        self::fail('o corpo da função não fecha — chaves desbalanceadas na tela');
    }

    #[TestDox('updateButtonState não olha tipo, posição nem cerca')]
    public function testUpdateButtonStateSoOlhaOEnvio(): void
    {
        $tela = $this->tela();

        self::assertMatchesRegularExpression(
            '/function updateButtonState\(\)\s*\{\s*btnPonto\.disabled\s*=\s*envioEmAndamento;\s*\}/',
            $tela,
            'updateButtonState voltou a decidir por tipo, posição ou cerca'
        );
    }

    #[TestDox('a cerca responde ao toque em #batida-aviso, não só em #gps-status')]
    public function testCercaAvisaOndeAPessoaLeOResultado(): void
    {
        $tela = $this->tela();

        self::assertMatchesRegularExpression(
            '/situacao\s*===\s*\'fora\'\s*\)\s*\{\s*(?:\/\/[^\n]*\n\s*)*mostrarAvisoBatida\(/',
            $tela,
            'quem está fora do raio voltou a tocar no botão sem receber resposta'
        );
    }

    #[TestDox('a cerca continua impedindo o envio: a regra não afrouxou')]
    public function testCercaContinuaImpedindoOEnvio(): void
    {
        $tela = $this->tela();

        // 🪤 Recortar o handler é obrigatório aqui: `onde.situacao === 'fora'` também aparece em
        // `guardarPosicao`, ANTES no arquivo, e procurar no fonte inteiro faria a comparação de
        // posições passar sozinha, provando nada.
        $handler = $this->corpoDaFuncao("btnPonto.addEventListener('click'");

        $posicaoDaCerca = strpos($handler, "onde.situacao === 'fora'");
        self::assertNotFalse($posicaoDaCerca, 'a verificação da cerca sumiu do handler do clique');

        $posicaoDoEnvio = strpos($handler, 'fetch(');
        self::assertNotFalse($posicaoDoEnvio, 'o envio sumiu do handler');
        self::assertLessThan(
            $posicaoDoEnvio,
            $posicaoDaCerca,
            'a cerca precisa decidir ANTES do envio: deixar passar troca bloqueio por ida ao '
            . 'servidor, e falha offline'
        );

        // E o ramo sai sem enviar.
        self::assertMatchesRegularExpression(
            '/onde\.situacao\s*===\s*\'fora\'[\s\S]{0,1200}?\breturn;/',
            $handler,
            'o ramo da cerca deixou de interromper o envio'
        );
    }

    #[TestDox('a tela sugere a primeira batida que hoje ainda não tem')]
    public function testTelaSugereOProximoTipo(): void
    {
        $tela = $this->tela();

        self::assertStringContainsString(
            "{% for tipoPossivel in ['entrada', 'repouso', 'retorno', 'saida'] %}",
            $tela,
            'a ordem das batidas do dia sumiu da sugestão'
        );
        self::assertMatchesRegularExpression(
            '/if tipoSugerido is null and pontoHoje\[tipoPossivel\] is null/',
            $tela,
            'a sugestão deixou de sair das batidas já registradas hoje'
        );

        foreach (['entrada', 'repouso', 'retorno', 'saida'] as $tipo) {
            self::assertStringContainsString(
                sprintf('<option value="%s" {{ tipoSugerido == \'%s\' ? \'selected\' : \'\' }}>', $tipo, $tipo),
                $tela,
                sprintf('o tipo "%s" deixou de poder ser pré-selecionado', $tipo)
            );
        }
    }

    #[TestDox('a sugestão é pré-seleção, não trava: os quatro tipos seguem escolhíveis')]
    public function testSugestaoNaoRemoveOsOutrosTipos(): void
    {
        $tela = $this->tela();

        foreach (['entrada', 'repouso', 'retorno', 'saida'] as $tipo) {
            self::assertMatchesRegularExpression(
                sprintf('/<option value="%s"(?![^>]*\bdisabled\b)/', $tipo),
                $tela,
                sprintf('o tipo "%s" virou indisponível: a sugestão não pode virar trava', $tipo)
            );
        }
    }

    #[TestDox('o botão diz qual tipo vai registrar, inclusive depois de uma falha')]
    public function testBotaoMostraOTipoEscolhido(): void
    {
        $tela = $this->tela();

        // Pré-selecionar sem mostrar trocaria o erro de "não escolheu" pelo de "não percebeu".
        self::assertMatchesRegularExpression(
            '/function rotuloBotaoBatida\(\)[\s\S]{0,400}?Bater Ponto\'\s*\+\s*\(nome\s*\?\s*\' — \'\s*\+\s*nome\s*:\s*\'\'\)/',
            $tela,
            'o rótulo do botão deixou de dizer o tipo escolhido'
        );

        // `liberarBotaoBatida` roda depois de toda falha de envio; se ela reescrever o rótulo sem
        // o tipo, a tela passa a mentir sobre o que o próximo toque vai registrar.
        self::assertMatchesRegularExpression(
            '/function liberarBotaoBatida\(\)\s*\{[^}]*btnPonto\.innerHTML\s*=\s*rotuloBotaoBatida\(\);/',
            $tela,
            'ao devolver o botão a tela perde o tipo no rótulo'
        );
    }

    #[TestDox('trocar o tipo no select atualiza o rótulo do botão')]
    public function testTrocarOTipoAtualizaORotulo(): void
    {
        $tela = $this->tela();

        self::assertMatchesRegularExpression(
            '/tipoRegistro\.addEventListener\(\'change\',\s*function\(\)\s*\{\s*updateButtonState\(\);\s*atualizarRotuloBotao\(\);/',
            $tela,
            'o rótulo parou de acompanhar a troca de tipo'
        );
    }

    #[TestDox('o rótulo não sobrescreve o "Registrando..." de uma batida em envio')]
    public function testRotuloNaoAtropelaOEnvio(): void
    {
        $tela = $this->tela();

        self::assertMatchesRegularExpression(
            '/function atualizarRotuloBotao\(\)\s*\{\s*(?:\/\/[^\n]*\n\s*)*if \(envioEmAndamento\)\s*\{\s*return;/',
            $tela,
            'o rótulo voltou a poder apagar o "Registrando..." durante o envio'
        );
    }
}
