<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Ponto\Enum\AcaoDaTrilhaDoPonto;
use App\Ponto\Enum\TipoJustificativa;
use App\Ponto\Service\BancoDeHorasPorLotes;
use App\Ponto\Service\CatalogoDeEscalas;
use App\Ponto\Service\DetalheDaTrilhaDoPonto;
use App\Ponto\Service\EscalaDaPessoaNaEquipe;
use App\Ponto\Service\FuncoesDoPonto;
use App\Ponto\Service\JornadaResolver;
use App\Ponto\Service\TextoDoPonto;
use App\Ponto\Service\VerificadorAlertaPonto;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

/**
 * Spec idioma-do-sistema §34 (idioma-ponto-php, PR B3): os rótulos de Service do Ponto que a tela mostra
 * (`ponto_servidor.rotulo.*`: funções, banco de horas, escala, trilha) e os lembretes do alerta
 * (`ponto_servidor.lembrete.*`) têm chave no catálogo; o pt_BR é idêntico ao português da constante e o en é outro texto.
 */
final class RotulosDoPontoNoCatalogoTest extends TestCase
{
    /**
     * Chave => [português da constante, parâmetros do exemplo].
     *
     * @return array<string, array{0: string, 1: array<string, string>}>
     */
    private static function textosDeUmaChave(): array
    {
        $textos = [];
        foreach (FuncoesDoPonto::COM_CAMADA_ESCRITORIO as $funcao => $desenho) {
            $textos[FuncoesDoPonto::chaveDoTitulo($funcao)] = [$desenho['titulo'], []];
            $textos[FuncoesDoPonto::chaveDaAjuda($funcao)] = [$desenho['ajuda'], []];
        }
        foreach (BancoDeHorasPorLotes::CHAVE_DO_TEXTO as $texto => $chave) {
            $textos[$chave] = [$texto, str_contains($texto, '%d') ? ['dias' => '12'] : []];
        }
        $textos[EscalaDaPessoaNaEquipe::PERSONALIZADA] = ['Personalizada (%s)', ['carga' => '44h']];
        $textos[EscalaDaPessoaNaEquipe::DO_ESCRITORIO] = ['Jornada do escritório (%s)', ['carga' => '40h30']];
        foreach (CatalogoDeEscalas::todas() as $escala) {
            $textos[CatalogoDeEscalas::chaveDoRotulo($escala->id)] = [$escala->rotulo, []];
        }
        foreach (DetalheDaTrilhaDoPonto::PORTUGUES as $nome => $texto) {
            $textos[DetalheDaTrilhaDoPonto::chaveDoTexto($nome)] = [$texto, []];
        }
        foreach (AcaoDaTrilhaDoPonto::cases() as $acao) {
            $textos[$acao->chaveDoRotulo()] = [$acao->rotulo(), []];
        }
        foreach (VerificadorAlertaPonto::CHAVE_DO_TEXTO as $texto => $chave) {
            $textos[$chave] = [$texto, []];
        }

        return $textos;
    }

    /** @return iterable<string, array{0: string, 1: string, 2: array<string, string>}> */
    public static function textos(): iterable
    {
        foreach (self::textosDeUmaChave() as $chave => [$texto, $parametros]) {
            yield $chave => [$chave, $texto, $parametros];
        }
    }

    /** @param array<string, string> $parametros */
    #[DataProvider('textos')]
    #[TestDox('pt_BR de $chave é o português da constante (mesmos parâmetros) e o en é outro texto')]
    public function testPtBrIgualAConstanteEEnDiferente(string $chave, string $texto, array $parametros): void
    {
        if (str_contains($texto, '{')) {
            // Molde com `{nome}` (a trilha): o catálogo guarda o mesmo molde em pt_BR e os mesmos nomes no en.
            self::assertSame($texto, self::moldeDoCatalogo($chave, 'pt_BR'), $chave . ' em pt_BR');
            $en = self::moldeDoCatalogo($chave, 'en');
            self::assertNotSame($texto, $en, $chave . ' igual ao português em en');
            self::assertSame(self::nomesDoMolde($texto), self::nomesDoMolde($en), $chave . ' com parâmetros diferentes em en');

            return;
        }
        $esperado = $parametros === [] ? $texto : TextoDoPonto::de($chave, $texto, $parametros)->portugues;
        $en = self::traduzir($chave, $parametros, 'en');

        self::assertSame($esperado, self::traduzir($chave, $parametros, 'pt_BR'), $chave . ' em pt_BR');
        self::assertNotSame($chave, $en, $chave . ' sem en');
        self::assertNotSame($esperado, $en, $chave . ' igual ao português em en');
    }

    #[TestDox('Função, situação do lote, escala, texto da trilha e aviso fora do mapa dão LogicException')]
    public function testForaDoMapaDaLogicException(): void
    {
        $fora = [
            static fn () => FuncoesDoPonto::chaveDoTitulo('ponto-inexistente'),
            static fn () => FuncoesDoPonto::chaveDaAjuda('ponto-inexistente'),
            static fn () => BancoDeHorasPorLotes::chaveDe('Situação desconhecida'),
            static fn () => CatalogoDeEscalas::chaveDoRotulo('99-9'),
            static fn () => DetalheDaTrilhaDoPonto::chaveDoTexto('inexistente'),
            static fn () => VerificadorAlertaPonto::chaveDe('Texto que não é conhecido.'),
        ];
        foreach ($fora as $i => $chamada) {
            try {
                $chamada();
                self::fail('Caso ' . $i . ': devia dar LogicException.');
            } catch (\LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[TestDox('A chave de cada situação do lote e de cada aviso é a do mapa')]
    public function testChaveDeDevolveAChaveDoMapa(): void
    {
        foreach (BancoDeHorasPorLotes::CHAVE_DO_TEXTO as $texto => $chave) {
            self::assertSame($chave, BancoDeHorasPorLotes::chaveDe($texto));
        }
        foreach (VerificadorAlertaPonto::CHAVE_DO_TEXTO as $texto => $chave) {
            self::assertSame($chave, VerificadorAlertaPonto::chaveDe($texto));
        }
        self::assertContains(JornadaResolver::LEMBRETE_ENTRADA, array_keys(VerificadorAlertaPonto::CHAVE_DO_TEXTO));
        self::assertContains(JornadaResolver::LEMBRETE_REPOUSO, array_keys(VerificadorAlertaPonto::CHAVE_DO_TEXTO));
        self::assertContains(JornadaResolver::LEMBRETE_RETORNO, array_keys(VerificadorAlertaPonto::CHAVE_DO_TEXTO));
        self::assertContains(JornadaResolver::LEMBRETE_SAIDA, array_keys(VerificadorAlertaPonto::CHAVE_DO_TEXTO));
    }

    #[TestDox('Toda chave dos blocos rotulo e lembrete do catálogo é citada por uma classe, e o en tem as mesmas chaves do pt_BR')]
    public function testCatalogoSemChaveSobrando(): void
    {
        $citadas = array_keys(self::textosDeUmaChave());
        sort($citadas);

        foreach (['pt_BR', 'en'] as $locale) {
            $catalogo = self::tradutor()->getCatalogue($locale)->all('messages+intl-icu');
            $doBloco = array_values(array_filter(
                array_keys($catalogo),
                static fn (string $chave): bool => str_starts_with($chave, 'ponto_servidor.rotulo.') || str_starts_with($chave, 'ponto_servidor.lembrete.'),
            ));
            sort($doBloco);
            self::assertSame($citadas, $doBloco, 'chave dos blocos rotulo e lembrete (' . $locale . ') sem classe, ou o inverso');
        }
    }

    /**
     * O evento de cada ação com os dados que a trilha grava: [ação, detalhe].
     *
     * @return iterable<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function eventosDaTrilha(): iterable
    {
        $tipo = TipoJustificativa::cases()[0]->value;
        $batida = ['colaborador' => 'Ana', 'tipo' => 'entrada', 'data_hora' => '2026-10-21 08:01:12'];
        $completa = $batida + ['home_office' => true, 'trabalho_externo' => true, 'sem_internet' => true];
        $justificativa = ['colaborador' => 'Ana', 'tipo' => $tipo, 'data' => '2026-10-21'];

        yield 'batida simples' => ['batida_registrada', $batida];
        yield 'batida com as três marcas' => ['batida_registrada', $completa];
        foreach (['repouso', 'retorno', 'saida'] as $outro) {
            yield 'batida de ' . $outro => ['batida_registrada', ['tipo' => $outro] + $batida];
        }
        yield 'batida de tipo desconhecido' => ['batida_registrada', ['tipo' => 'xyz'] + $batida];
        yield 'batida sem tipo nem horário' => ['batida_registrada', ['colaborador' => 'Ana']];
        yield 'batida com data ilegível' => ['batida_registrada', ['data_hora' => 'não é data'] + $batida];
        yield 'batida sem colaborador' => ['batida_registrada', ['tipo' => 'saida']];
        yield 'batida do esquecimento' => ['batida_do_esquecimento', $completa];
        yield 'justificativa enviada' => ['justificativa_enviada', $justificativa];
        yield 'justificativa com esquecimento' => ['justificativa_enviada', $justificativa + ['batida_esquecida' => 'saida', 'hora_esquecida' => '18:00']];
        yield 'justificativa parcial com horário' => ['justificativa_lancada', $justificativa + ['parcial' => true, 'parcial_de' => '14:00', 'parcial_ate' => '16:00']];
        yield 'justificativa parcial sem horário' => ['justificativa_lancada', $justificativa + ['parcial' => true]];
        yield 'justificativa sem tipo' => ['justificativa_editada', ['colaborador' => 'Ana', 'data' => '2026-10-21']];
        yield 'justificativa excluída' => ['justificativa_excluida', $justificativa];
        foreach (['pendente', 'abonado', 'rejeitado', 'outra'] as $situacao) {
            yield 'reaberta, estava ' . $situacao => ['justificativa_reaberta', $justificativa + ['situacao_anterior' => $situacao]];
        }
        yield 'lote de vários dias' => ['abono_em_lote', ['colaborador' => 'Ana', 'tipo' => $tipo, 'de' => '2026-10-01', 'ate' => '2026-10-05', 'quantidade' => 5]];
        yield 'lote de um dia com várias' => ['abono_em_lote', ['colaborador' => 'Ana', 'tipo' => $tipo, 'de' => '2026-10-01', 'ate' => '2026-10-01', 'quantidade' => 3]];
        yield 'lote de um dia com uma' => ['justificativa_enviada', ['colaborador' => 'Ana', 'tipo' => $tipo, 'de' => '2026-10-01', 'ate' => '2026-10-01', 'quantidade' => 1]];
        yield 'lote só com a primeira data' => ['abono_em_lote', ['colaborador' => 'Ana', 'tipo' => $tipo, 'de' => '2026-10-01']];
        yield 'abono da equipe, uma' => ['abono_em_lote', ['da_equipe' => [1], 'quantidade' => 1]];
        yield 'abono da equipe, várias' => ['abono_em_lote', ['da_equipe' => [1, 2, 3]]];
        foreach (['versao_salva', 'versao_excluida', 'escala_limpa', 'outra'] as $operacao) {
            yield 'escala ' . $operacao => ['escala_alterada', ['colaborador' => 'Ana', 'operacao' => $operacao, 'vale_a_partir_de' => '2026-11-03']];
        }
        yield 'escala sem data' => ['escala_alterada', ['colaborador' => 'Ana', 'operacao' => 'versao_salva']];
        yield 'escala trocada na equipe' => ['escala_alterada', ['colaborador' => 'Ana', 'escala' => '44h · seg a sáb (7h20)']];
        yield 'sincronização de uma' => ['sincronizacao', ['colaborador' => 'Ana', 'quantidade' => 1]];
        yield 'sincronização de três' => ['sincronizacao', ['colaborador' => 'Ana', 'quantidade' => 3]];
        yield 'batida incluída' => ['batida_incluida', ['colaborador' => 'Ana', 'novo' => ['tipo' => 'saida', 'data_hora' => '2026-10-21 18:00:00']]];
        yield 'batida ajustada' => ['batida_ajustada', ['colaborador' => 'Ana', 'anterior' => ['tipo' => 'saida', 'data_hora' => '2026-10-21 18:00:00'], 'novo' => ['tipo' => 'saida', 'data_hora' => '2026-10-21 18:30:00']]];
        yield 'batida desconsiderada' => ['batida_desconsiderada', ['colaborador' => 'Ana', 'anterior' => ['tipo' => 'entrada', 'data_hora' => '2026-10-21 08:00:00']]];
        yield 'ajuste desfeito, restaurada' => ['ajuste_desfeito', ['colaborador' => 'Ana', 'novo' => ['tipo' => 'entrada', 'data_hora' => '2026-10-21 08:00:00']]];
        yield 'ajuste desfeito, do esquecimento' => ['ajuste_desfeito', ['colaborador' => 'Ana', 'anterior' => ['tipo' => 'entrada', 'data_hora' => '2026-10-21 08:00:00']]];
        yield 'folha emitida' => ['folha_emitida', ['colaborador' => 'Ana', 'ano' => 2026, 'mes' => 11, 'codigo' => 'BJF-ABCD-EFGH-JK23']];
        yield 'folha emitida sem competência' => ['folha_emitida', ['colaborador' => 'Ana']];
        yield 'folha assinada' => ['folha_assinada', ['colaborador' => 'Ana', 'ano' => 2026, 'mes' => 11]];
        yield 'folha assinada com ressalva' => ['folha_assinada_com_ressalva', ['colaborador' => 'Ana', 'ano' => 2026, 'mes' => 11]];
        yield 'configuração ligada' => ['configuracao_do_escritorio', ['chave' => 'ponto-geo', 'titulo' => 'Geolocalização', 'ligada' => true]];
        yield 'configuração desligada' => ['configuracao_do_escritorio', ['chave' => 'ponto-lembrete', 'titulo' => 'Lembrete de saída', 'ligada' => false]];
        yield 'configuração com título antigo' => ['configuracao_do_escritorio', ['chave' => 'ponto-geo', 'titulo' => 'Título de antes', 'ligada' => true]];
        yield 'configuração só com a chave' => ['configuracao_do_escritorio', ['chave' => 'ponto-geo']];
        yield 'configuração sem nada' => ['configuracao_do_escritorio', ['ligada' => true]];
        yield 'ação desconhecida' => ['acao_que_nao_existe', $justificativa];
    }

    /** @param array<string, mixed> $detalhe */
    #[DataProvider('eventosDaTrilha')]
    #[TestDox('Trilha ($acao): o texto traduzido em pt_BR é idêntico ao texto de sempre')]
    public function testTextoDaTrilhaTraduzidoEmPtBrIgualAoDeSempre(string $acao, array $detalhe): void
    {
        $tradutor = self::tradutor();
        $tradutor->setLocale('pt_BR');

        self::assertSame(DetalheDaTrilhaDoPonto::texto($acao, $detalhe), DetalheDaTrilhaDoPonto::textoTraduzido($acao, $detalhe, $tradutor));
    }

    #[TestDox('Trilha em en: a batida com as três marcas, a configuração (título pelo catálogo) e o plural da sincronização')]
    public function testTextoDaTrilhaEmIngles(): void
    {
        $tradutor = self::tradutor();
        $tradutor->setLocale('en');
        try {
            $batida = ['colaborador' => 'Ana', 'tipo' => 'entrada', 'data_hora' => '2026-10-21 08:01:12', 'home_office' => true, 'trabalho_externo' => true, 'sem_internet' => true];

            self::assertSame('Ana: Clock-in at 08:01:12 on 21/10/2026 · working from home · external work · no internet', DetalheDaTrilhaDoPonto::textoTraduzido('batida_registrada', $batida, $tradutor));
            self::assertSame('Geolocation turned on', DetalheDaTrilhaDoPonto::textoTraduzido('configuracao_do_escritorio', ['chave' => 'ponto-geo', 'titulo' => 'Geolocalização', 'ligada' => true], $tradutor));
            self::assertSame('Título de antes turned off', DetalheDaTrilhaDoPonto::textoTraduzido('configuracao_do_escritorio', ['chave' => 'ponto-geo', 'titulo' => 'Título de antes'], $tradutor), 'título gravado que não é o de hoje fica como foi gravado');
            self::assertSame('Ana: 1 punch sent when the internet came back', DetalheDaTrilhaDoPonto::textoTraduzido('sincronizacao', ['colaborador' => 'Ana', 'quantidade' => 1], $tradutor));
            self::assertSame('Ana: 3 punches sent when the internet came back', DetalheDaTrilhaDoPonto::textoTraduzido('sincronizacao', ['colaborador' => 'Ana', 'quantidade' => 3], $tradutor));
            self::assertSame('Punch recorded', AcaoDaTrilhaDoPonto::rotuloTraduzidoDe('batida_registrada', $tradutor));
            self::assertSame('codigo_novo', AcaoDaTrilhaDoPonto::rotuloTraduzidoDe('codigo_novo', $tradutor), 'código que esta versão não conhece aparece como está');
        } finally {
            $tradutor->setLocale('pt_BR');
        }
    }

    /** @param array<string, string> $parametros */
    private static function traduzir(string $chave, array $parametros, string $locale): string
    {
        $tradutor = self::tradutor();
        $tradutor->setLocale($locale);
        try {
            return $tradutor->trans($chave, $parametros);
        } finally {
            $tradutor->setLocale('pt_BR');
        }
    }

    /** @return list<string> */
    private static function nomesDoMolde(string $molde): array
    {
        preg_match_all('/\{(\w+)\}/', $molde, $achados);
        sort($achados[1]);

        return $achados[1];
    }

    /** O texto do catálogo sem formatar (com o `{nome}` no lugar), para conferir o molde dos textos da trilha. */
    private static function moldeDoCatalogo(string $chave, string $locale): string
    {
        return (string) self::tradutor()->getCatalogue($locale)->get($chave, 'messages+intl-icu');
    }

    private static function tradutor(): Translator
    {
        static $tradutor = null;
        if ($tradutor === null) {
            $tradutor = new Translator('pt_BR');
            $tradutor->addLoader('yaml', new YamlFileLoader());
            foreach (['pt_BR', 'en'] as $locale) {
                $tradutor->addResource('yaml', \dirname(__DIR__, 3) . '/translations/ponto_servidor/messages+intl-icu.' . $locale . '.yaml', $locale, 'messages+intl-icu');
            }
        }

        return $tradutor;
    }
}
