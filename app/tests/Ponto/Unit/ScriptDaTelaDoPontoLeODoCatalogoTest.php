<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Shared\Traducao\CatalogoJsExtension;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

/**
 * Spec idioma-do-sistema, lote "script inline da tela do ponto": os textos fixos do `<script>` de `ponto/index.html.twig`
 * (lembrete, relógio, GPS, avisos da batida, trabalho externo e fila sem internet) passam por `BJ_T.t()` e moram em
 * `ponto_cartao.js.*`. O português da tela não pode mudar: cada chave tem o texto exato que estava no script.
 */
final class ScriptDaTelaDoPontoLeODoCatalogoTest extends TestCase
{
    /**
     * Texto que estava fixo no script => chave que o entrega agora (pt_BR idêntico ao literal removido).
     * Amostra: GPS, lembrete, avisos da batida, trabalho externo e fila.
     */
    private const LITERAIS = [
        'Passou do horário previsto. Não esqueça de registrar a saída.' => 'lembrete.aviso_saida',
        'Não esqueça de registrar a saída.' => 'lembrete.notificacao_corpo',
        'Aguardando entrada' => 'relogio.aguardando',
        'Jornada encerrada' => 'relogio.encerrada',
        'Não foi possível descobrir sua localização agora. Tente de novo; no computador, deixe o Wi-Fi ligado.' => 'gps.indisponivel',
        'A busca pela sua localização demorou demais. Tente de novo; no computador, deixe o Wi-Fi ligado.' => 'gps.demorou',
        'A localização do navegador não está funcionando nesta tela. Tente outro navegador ou fale com o gestor.' => 'gps.api',
        'A localização está bloqueada para este site. Para liberar:' => 'gps.bloqueado',
        'Clique no ícone à esquerda do endereço do site (o cadeado ou os controles).' => 'gps.passo_computador_1',
        'Toque em Permissões (ou Configurações do site) e depois em Localização.' => 'gps.passo_celular_2',
        'Obtendo a sua localização...' => 'gps.obtendo',
        'Permitir localização' => 'gps.permitir',
        'Seu navegador não suporta geolocalização.' => 'gps.sem_suporte',
        'Ative o GPS do aparelho, permita a localização no navegador e tente de novo.' => 'gps.sem_localizacao_detalhe',
        'Você está sem internet e não dá para saber se a batida saiu. NÃO feche esta tela. Quando a conexão voltar, atualize a página e confira antes de apertar de novo.' => 'aviso.sem_rede',
        'O dia virou com esta página aberta: os botões acima já são do dia novo, mas a folha e o banco de horas ainda são de ontem. Atualize a página antes de conferir.' => 'aviso.virada_do_dia',
        'Demorou demais para enviar e a hora da batida ficaria errada. Aperte de novo com a tela ligada.' => 'aviso.atrasado',
        'Selecione o tipo de registro (Entrada, Repouso, Retorno ou Saída).' => 'aviso.selecione_tipo',
        'Registrando...' => 'aviso.registrando',
        'Registrar como trabalho externo' => 'externo.registrar',
        'Não há registro livre hoje. O trabalho externo não foi enviado.' => 'externo.dia_completo',
        'Comprovante na aba Comprovantes.' => 'toque.comprovante',
        'Sem internet. A batida ficou guardada neste aparelho e será enviada quando a internet voltar.' => 'fila.guardada_sem_internet',
        'Sem conexão com o servidor. A batida ficou guardada neste aparelho e será enviada sozinha quando a conexão voltar.' => 'fila.guardada_sem_conexao',
        'Batidas sem internet enviadas.' => 'fila.enviada',
        'Em repouso' => 'relogio.repouso',
        'Em jornada' => 'relogio.jornada',
        'Se está em trabalho externo ou home office, peça ao gestor a liberação do dia e depois atualize esta página.' => 'gps.fora_sem_externo',
        'Toque' => 'gps.verbo_toque',
        'Clique' => 'gps.verbo_clique',
        'Em Localização, escolha Permitir.' => 'gps.passo_computador_2',
        'Recarregue esta página.' => 'gps.passo_computador_3',
        'No Safari do Mac: menu Safari, Ajustes, Sites, Localização, e escolha Permitir para este site.' => 'gps.outro_computador',
        'Toque no ícone à esquerda do endereço do site (o cadeado ou os controles).' => 'gps.passo_celular_1',
        'Escolha Permitir e recarregue esta página.' => 'gps.passo_celular_3',
        'No iPhone ou iPad, se não aparecer: Ajustes, Privacidade e Segurança, Serviços de Localização, ligue e escolha "Ao Usar o App" para o navegador.' => 'gps.outro_celular',
        'Não foi possível obter a sua localização' => 'gps.sem_localizacao_titulo',
        'Não recebemos a confirmação do servidor e a batida PODE ter sido registrada. Os horários nos botões acima são de antes da tentativa: atualize a página e confira ANTES de apertar de novo.' => 'aviso.sem_confirmacao',
        'A página não carregou por inteiro e a batida não foi enviada. Atualize a página e tente de novo.' => 'aviso.pagina_incompleta',
        'A internet voltou. Atualize a página e confira suas batidas antes de apertar de novo.' => 'aviso.internet_voltou',
        'A batida não foi registrada: outro registro estava sendo enviado. Espere terminar e toque no botão de novo.' => 'aviso.durante_envio',
        'A batida não foi registrada: a jornada de hoje já está concluída.' => 'aviso.dia_concluido',
        'Um registro ainda está em andamento. Espere a resposta e registre o trabalho externo de novo.' => 'externo.em_andamento',
        'Sem internet, o trabalho externo não pôde ser guardado. Tente de novo quando a conexão voltar.' => 'externo.sem_fila',
    ];

    /** Chaves com parâmetro ou escolhidas por condição (gênero do tipo, celular ou computador): texto e parâmetros. */
    private const COM_PARAMETROS = [
        'lembrete.passou_da_saida' => ['Passou da saída prevista ({hora}). Não esqueça de registrar a saída.', ['hora' => '18:10']],
        'lembrete.saida_prevista' => ['Saída prevista às {hora}.', ['hora' => '18:00']],
        'gps.confirmada' => ['Localização confirmada. Precisão de {precisao} m.', ['precisao' => 12]],
        'gps.impreciso' => ['GPS impreciso ({precisao} m). O sistema ainda validará se você está dentro do raio de alguma sede.', ['precisao' => '1.200']],
        'gps.fora_da_area' => ['Você está a {distancia}m de {sede}, fora da área permitida para bater o ponto.', ['distancia' => 340, 'sede' => 'Matriz']],
        'gps.sistema' => ['O navegador tem permissão, mas a localização do aparelho está desligada ou bloqueada para o navegador. Ligue a localização nas configurações do aparelho e {verbo} em "Tentar de novo".', ['verbo' => 'toque']],
        'gps.pedir' => ['Para registrar o ponto, o navegador precisa da sua localização. {verbo} em "Permitir localização" e escolha Permitir na janela do navegador.', ['verbo' => 'Toque']],
        'toque.registrada_fem' => ['{tipo} registrada às {hora}', ['tipo' => 'Entrada', 'hora' => '08:00:00']],
        'toque.registrada_masc' => ['{tipo} registrado às {hora}', ['tipo' => 'Retorno', 'hora' => '13:00:00']],
        'toque.nao_registrada_fem' => ['{tipo} não registrada: {motivo}.', ['tipo' => 'Saída', 'motivo' => 'Sem rede']],
        'toque.nao_registrada_masc' => ['{tipo} não registrado: {motivo}.', ['tipo' => 'Repouso', 'motivo' => 'Sem rede']],
        'toque.ja_registrada_fem' => ['{tipo} já registrada.', ['tipo' => 'Entrada']],
        'toque.ja_registrada_masc' => ['{tipo} já registrado.', ['tipo' => 'Retorno']],
        'fila.pendentes' => ['Sem internet: {n} batida(s) aguardando envio.', ['n' => 2]],
        'fila.outro_escritorio' => ['{n} batida(s) guardada(s) sem internet em outro escritório. Entre nele para enviar.', ['n' => 1]],
        'fila.recusa' => ['Batida de {tipo} de {dia} às {hora} guardada sem internet não foi registrada: {mensagem}', ['tipo' => 'Saída', 'dia' => '05/11', 'hora' => '18:00', 'mensagem' => 'Dia fechado.']],
    ];

    private static function tela(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../templates/ponto/index.html.twig');
    }

    private static function script(): string
    {
        $tela = self::tela();
        $inicio = (int) strrpos($tela, "<script>\n");

        return substr($tela, $inicio);
    }

    private static function tradutor(): Translator
    {
        $t = new Translator('pt_BR');
        $t->setFallbackLocales(['pt_BR']);
        $t->addLoader('yaml', new YamlFileLoader());
        foreach (['pt_BR', 'en'] as $locale) {
            $t->addResource('yaml', __DIR__ . '/../../../translations/ponto_cartao/messages+intl-icu.' . $locale . '.yaml', $locale, 'messages+intl-icu');
        }

        return $t;
    }

    private static function extensao(string $locale): CatalogoJsExtension
    {
        $pilha = new RequestStack();
        $requisicao = new Request();
        $requisicao->setLocale($locale);
        $pilha->push($requisicao);

        return new CatalogoJsExtension(self::tradutor(), $pilha);
    }

    private static function troca(string $texto, array $parametros): string
    {
        return (string) preg_replace_callback('/\{(\w+)\}/', static fn (array $m): string => array_key_exists($m[1], $parametros) ? (string) $parametros[$m[1]] : $m[0], $texto);
    }

    #[TestDox('A página do ponto entrega o catálogo ponto_cartao.js, que o script inline lê')]
    public function testPaginaEntregaOCatalogo(): void
    {
        self::assertStringContainsString("{{ catalogo_js('ponto_cartao.js') }}", self::tela());
        self::assertStringContainsString("bj-traducao.js", self::tela());
    }

    #[TestDox('Toda chave que o script inline usa existe em pt_BR e em en, e o catálogo entregue a traz')]
    public function testChavesExistemNosDoisIdiomas(): void
    {
        preg_match_all("/ponto_cartao\\.js\\.([a-z_0-9.]+)/", self::script(), $achadas);
        $chaves = array_unique($achadas[1]);
        // Chaves montadas por condição: o prefixo vai ao script e o final sai do gênero ('fem' ou 'masc').
        foreach (['registrada', 'nao_registrada', 'ja_registrada'] as $base) {
            foreach (['fem', 'masc'] as $genero) {
                $chaves[] = 'toque.' . $base . '_' . $genero;
            }
        }
        $chaves = array_values(array_filter(array_unique($chaves), static fn (string $c): bool => !str_ends_with($c, '_')));
        self::assertGreaterThan(60, count($chaves));

        $pt = self::extensao('pt_BR')->mensagens(['ponto_cartao.js']);
        $en = self::extensao('en')->mensagens(['ponto_cartao.js']);
        foreach ($chaves as $chave) {
            $completa = 'ponto_cartao.js.' . $chave;
            self::assertArrayHasKey($completa, $pt, $completa . ' sem pt_BR');
            self::assertArrayHasKey($completa, $en, $completa . ' sem en');
            self::assertNotSame('', trim($en[$completa]), $completa . ' vazia em en');
        }
        // As chaves novas têm tradução própria em inglês (não caem no português do fallback).
        foreach (array_merge(array_values(self::LITERAIS), array_keys(self::COM_PARAMETROS)) as $chave) {
            self::assertNotSame($pt['ponto_cartao.js.' . $chave], $en['ponto_cartao.js.' . $chave], $chave . ' igual ao português em en');
        }
    }

    #[TestDox('O português entregue é exatamente o texto que estava fixo no script, e o script não o traz mais')]
    public function testPortuguesIgualEScriptSemLiterais(): void
    {
        $pt = self::extensao('pt_BR')->mensagens(['ponto_cartao.js']);
        $script = self::script();
        foreach (self::LITERAIS as $literal => $chave) {
            self::assertSame($literal, $pt['ponto_cartao.js.' . $chave], $chave);
            self::assertStringNotContainsString("'" . $literal . "'", $script, 'literal ainda no script: ' . $literal);
        }
        foreach (self::COM_PARAMETROS as $chave => [$modelo, $parametros]) {
            self::assertSame($modelo, $pt['ponto_cartao.js.' . $chave], $chave);
            self::assertSame(self::troca($modelo, $parametros), self::troca($pt['ponto_cartao.js.' . $chave], $parametros));
        }
        // Pedaços de frase montados antes por concatenação não voltam.
        foreach (["' batida(s) aguardando envio.'", "'Passou da saída prevista ('", "'Saída prevista às '", "' guardada sem internet não foi registrada: '", "'Localização confirmada. Precisão de '", "' já ' + registradoDoTipo"] as $pedaco) {
            self::assertStringNotContainsString($pedaco, $script);
        }
    }

    #[TestDox('A frase do raio parte no marcador da sede: o nome (um nó) entra no meio, sem mudar o texto')]
    public function testFraseDoRaioPartidaNaSede(): void
    {
        $pt = self::extensao('pt_BR')->mensagens(['ponto_cartao.js'])['ponto_cartao.js.gps.fora_da_area'];
        $partes = explode('{sede}', self::troca($pt, ['distancia' => 340, 'sede' => '{sede}']));

        self::assertSame(['Você está a 340m de ', ', fora da área permitida para bater o ponto.'], $partes);
    }
}
