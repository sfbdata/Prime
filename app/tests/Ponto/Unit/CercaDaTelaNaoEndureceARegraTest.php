<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A tela do ponto passou a bloquear o botão para quem está fora do raio, por decisão do dono em
 * 11/09/2026. Antes ela só travava por falta de posição, e quem estava fora apertava e levava erro.
 *
 * 🔑 **O invariante que estes testes protegem é o sentido do desvio.** A regra continua sendo a do
 * servidor (`PontoController::batida`). A tela é cortesia, calcula com uma posição possivelmente
 * antiga e menos precisa, e por isso tem de ser **MAIS PERMISSIVA**: só bloqueia quem está fora
 * mesmo descontando a margem de precisão do GPS.
 *
 * Divergir para o lado permissivo é inofensivo — o servidor recusa e a pessoa vê a mensagem.
 * Divergir para o lado rígido transforma imprecisão de GPS em **falta**, que num módulo de risco
 * ALTO é o dano que não se pode aceitar. Medido em produção em 11/09/2026, sobre 1.683 batidas
 * desde 01/06: mediana de 27,1 m, **p90 de 99,0 m** e 7,5% acima de 100 m — contra raio de 100 m.
 * Uma em cada dez leituras carrega incerteza do tamanho do raio inteiro. ⚠️ A amostra é de batidas
 * ACEITAS, então é otimista: as recusadas por posição não estão nela.
 *
 * ⚠️ Em 14/09/2026 o impedimento MUDOU DE LUGAR: saiu de `updateButtonState` (que deixava o botão
 * cinza) para o handler do clique, que responde ao toque em `#batida-aviso`. A regra é a mesma; o
 * que mudou é que agora ela fala. Ver `docs/specs/ponto-batida-que-responde-e-conta-certa.md`.
 *
 * Spec: `docs/specs/ponto-batida-nao-se-perde-no-navegador.md`.
 *
 * Confere o ARRANJO do JS no fonte, que é o que a suíte alcança: PHPUnit não executa JavaScript.
 * O escopo do dado que chega à tela é provado por `App\Tests\Ponto\Functional\CercaNaTelaDoPontoTest`.
 */
final class CercaDaTelaNaoEndureceARegraTest extends TestCase
{
    private const CAMINHO_TELA = __DIR__ . '/../../../templates/ponto/index.html.twig';

    #[TestDox('a tela só bloqueia quem está fora mesmo descontando a margem de precisão do GPS')]
    public function testBloqueioDescontaAMargemDePrecisao(): void
    {
        $corpo = $this->corpoDaFuncao('function avaliarPosicao(');

        self::assertMatchesRegularExpression(
            '/\(\s*menorExcedente\s*-\s*margem\s*\)\s*>\s*0/',
            $corpo,
            'sem descontar a margem, a tela vira MAIS rígida que o servidor e transforma GPS '
            . 'impreciso em falta'
        );

        // 🪤 Pinar a EXPRESSÃO INTEIRA, e não só a presença de `precisaoGps`. Um
        // `Math.min(Number(currentLocation.precisaoGps) || 0, 30)` endurece a tela e passava nos
        // dois asserts anteriores: a forma da comparação continuava a mesma e a string também.
        self::assertStringContainsString(
            'const margem = Math.max(Number(currentLocation.precisaoGps) || 0, 0);',
            $corpo,
            'a margem é a precisão real, sem teto e sem piso negativo: teto endurece a tela, '
            . 'precisão negativa somaria à distância e endureceria também'
        );

        // Medido na produção em 11/09/2026, sobre 1.683 batidas desde 01/06: p90 de 99 m, com raio
        // de 100 m. Uma em cada dez leituras tem incerteza do tamanho do raio inteiro — é esse
        // número que torna a margem obrigatória, e não uma cortesia.
        self::assertMatchesRegularExpression(
            '/if \(\(distancia - sede\.raio\) < menorExcedente\)/',
            $corpo,
            'com raios diferentes, olhar só a sede mais próxima bloquearia quem o servidor aceita'
        );
        // A sede citada na mensagem tem de ser a DECISIVA. Medido por fuzz na revisão: com raios
        // diferentes, a mais próxima em metros não é a que decide em ~10% dos bloqueios, e a
        // pessoa seria mandada olhar para o lugar errado.
        self::assertStringContainsString(
            'sede: decisiva,',
            $corpo,
            'a sede devolvida tem que ser a que decidiu o bloqueio, não a mais próxima'
        );
    }

    #[TestDox('existe a situação indeterminada: precisão maior que a distância não é estar fora')]
    public function testExisteSituacaoIndeterminada(): void
    {
        $corpo = $this->corpoDaFuncao('function avaliarPosicao(');

        self::assertStringContainsString(
            "'indeterminado'",
            $corpo,
            'com 120 m de erro e 70 m de distância o aparelho não sabe dizer se está dentro: '
            . 'tratar isso como "fora" é decidir contra a pessoa sem informação'
        );
        self::assertMatchesRegularExpression(
            '/SEDES\.length === 0/',
            $corpo,
            'sem sede cadastrada a tela não tem como avaliar e não pode bloquear por conta própria'
        );
    }

    #[TestDox('a batida só é impedida pela cerca quando a avaliação diz fora, nunca em indeterminado')]
    public function testBotaoSoBloqueiaNoForaCerteza(): void
    {
        // Em 14/09/2026 o impedimento saiu de `updateButtonState` para o handler do clique: botão
        // `disabled` não emite evento, e o toque de quem estava fora do raio não produzia resposta
        // nenhuma. O invariante NÃO mudou — quem decide continua sendo `avaliarPosicao()`, com a
        // margem de precisão descontada. Mudou só onde a decisão é tomada e onde ela é dita.
        // Ver `App\Tests\Ponto\Unit\BotaoDoPontoRespondeAoToqueTest`.
        $corpo = $this->corpoDaFuncao("btnPonto.addEventListener('click'");

        self::assertStringContainsString(
            "onde.situacao === 'fora'",
            $corpo,
            'o impedimento da tela tem que depender da avaliação com margem, não da distância crua'
        );
        self::assertStringContainsString(
            'const onde = avaliarPosicao();',
            $corpo,
            'a cerca do clique precisa consultar `avaliarPosicao()`, que é quem desconta a margem'
        );
        self::assertStringNotContainsString(
            "'indeterminado'",
            $corpo,
            'indeterminado não pode bloquear: quem decide nesse caso é o servidor'
        );
    }

    #[TestDox('home office continua dispensando a cerca na tela, como no servidor')]
    public function testHomeOfficeNaoPassaPelaCerca(): void
    {
        $corpo = $this->corpoDaFuncao("btnPonto.addEventListener('click'");

        // 🪤 Procurar `homeOfficeHoje` em qualquer ponto ANTES de `avaliarPosicao()` não prova
        // nada: o guarda de posição (`!homeOfficeHoje && !currentLocation`) já aparece antes no
        // handler, e apagar o guarda DA CERCA deixaria a asserção verde. O que precisa ser
        // provado é que a chamada da cerca está dentro do bloco condicionado ao home office.
        self::assertMatchesRegularExpression(
            '/if \(!homeOfficeHoje\)\s*\{\s*const onde = avaliarPosicao\(\);/',
            $corpo,
            'a cerca do clique tem que estar DENTRO do `if (!homeOfficeHoje)`, senão quem está '
            . 'liberado do dia passa a ser bloqueado por estar em casa — a tela ficaria mais '
            . 'rígida que o servidor, que dispensa o geofencing nesse caso'
        );
    }

    #[TestDox('a posição continua sendo atualizada com a página aberta, não só na carga')]
    public function testPosicaoNaoCongelaNaCarga(): void
    {
        $fonte = (string) file_get_contents(self::CAMINHO_TELA);

        // 🔴 Desde que o botão bloqueia por raio, decidir com a leitura da carga vira armadilha:
        // quem abre a tela no caminho, longe do escritório, ficaria travado mesmo depois de chegar.
        self::assertStringContainsString(
            'navigator.geolocation.watchPosition(',
            $fonte,
            'sem atualização contínua, o bloqueio usa uma posição velha e não há como destravar'
        );
        self::assertMatchesRegularExpression(
            '/watchPosition\(\s*aplicarPosicao/',
            $fonte,
            'a atualização contínua tem que reavaliar a posição e o botão, não só guardar o dado'
        );
    }

    #[TestDox('quem está fora da área recebe uma saída na tela, não só um botão cinza')]
    public function testBloqueadoTemCaminhoDeSaida(): void
    {
        $corpo = $this->corpoDaFuncao('function guardarPosicao(');

        self::assertStringContainsString(
            "btnAtualizarPagina.classList.remove('d-none')",
            $corpo,
            'a liberação do dia é decidida no servidor e só chega na carga: sem o botão de '
            . 'atualizar, quem consegue a liberação com a página aberta continua bloqueado'
        );
    }

    #[TestDox('a cerca nunca reexibe o botão de atualizar por cima de um aviso de envio')]
    public function testCercaNaoAtropelaOAvisoDeEnvio(): void
    {
        $corpo = $this->corpoDaFuncao('function guardarPosicao(');

        // 🪤 Contar chamadas era frouxo dos dois lados: duas chamadas no MESMO ramo passavam, e
        // extrair o resultado para uma variável quebrava sem defeito. Os asserts amarram a guarda
        // a CADA mutação do botão.
        self::assertMatchesRegularExpression(
            '/if \(cercaMandaNoBotaoAtualizar\(\)\) \{\s*btnAtualizarPagina\.classList\.remove/',
            $corpo,
            'mostrar o botão tem que passar pela guarda'
        );
        self::assertMatchesRegularExpression(
            '/if \(cercaMandaNoBotaoAtualizar\(\)\) \{\s*btnAtualizarPagina\.classList\.add/',
            $corpo,
            'esconder o botão tem que passar pela mesma guarda'
        );

        $dono = $this->corpoDaFuncao('function cercaMandaNoBotaoAtualizar(');
        self::assertStringContainsString(
            '!envioEmAndamento',
            $dono,
            'clique com a requisição em voo recarrega no meio dela'
        );

        // 🔴 SÓ o aviso de sem rede veta, porque só ele protege prova. Vetar por qualquer aviso
        // criava tela sem saída: recusa do servidor deixava o aviso na tela, e depois a cerca
        // mandava "atualize esta página" com o botão de atualizar escondido pelo aviso da recusa.
        self::assertStringContainsString(
            'avisoBatida.textContent !== AVISO_SEM_REDE',
            $dono,
            'o veto é do aviso de sem rede, não de qualquer aviso'
        );
        self::assertStringNotContainsString(
            "classList.contains('d-none')",
            $dono,
            'vetar por presença de aviso deixa quem está fora da área sem saída nenhuma'
        );
    }

    #[TestDox('a virada do dia não atropela o aviso de sem rede')]
    public function testViradaDoDiaNaoAtropelaOAvisoSemRede(): void
    {
        $corpo = $this->corpoDaFuncao('function conferirViradaDoDia(');

        // 🪤 Terceiro cliente do botão de atualizar, e o mais fácil de esquecer. Quem bateu sem rede
        // e deixou a página aberta até a virada do dia tinha o aviso SUBSTITUÍDO e o botão de
        // atualizar reaparecia — ainda sem rede, apagando a única pista de que a batida pode ter
        // entrado.
        self::assertStringContainsString(
            'avisoBatida.textContent === AVISO_SEM_REDE',
            $corpo,
            'a virada do dia não pode substituir o aviso de sem rede nem reexibir o botão'
        );
    }

    /** Corpo de uma função do script, delimitado por contagem de chaves. */
    private function corpoDaFuncao(string $assinatura): string
    {
        $fonte  = (string) file_get_contents(self::CAMINHO_TELA);
        $inicio = strpos($fonte, $assinatura);
        self::assertIsInt($inicio, sprintf('não achei `%s` na tela do ponto', $assinatura));

        $abre = strpos($fonte, '{', $inicio);
        self::assertIsInt($abre, 'não achei a abertura do corpo da função');

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
}
