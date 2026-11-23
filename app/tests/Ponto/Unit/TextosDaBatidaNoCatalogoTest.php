<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Tenant\Sede;
use App\Ponto\Service\AlertasAntesDaBatida;
use App\Ponto\Service\BatidaJaRegistrada;
use App\Ponto\Service\ChaveDoPontoEletronico;
use App\Ponto\Service\ChoqueDeJornadaEntreEscritorios;
use App\Ponto\Service\EntradaAbertaEmOutroEscritorio;
use App\Ponto\Service\EnvioDaBatida;
use App\Ponto\Service\HorarioFuturoDaBatida;
use App\Ponto\Service\PosicaoNaCerca;
use App\Ponto\Service\SaidaDeEsquecimento;
use App\Ponto\Service\TextoDoPonto;
use App\Ponto\Service\TrabalhoExternoDaBatida;
use App\Ponto\Service\TurnoAbertoNoEscritorio;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

/**
 * Spec idioma-do-sistema §32 (idioma-ponto-php, PR B2): cada texto dos Services do Ponto que a batida leva à tela tem
 * chave no catálogo `ponto_servidor.batida`, o pt_BR do catálogo (com os mesmos parâmetros do `sprintf` antigo) é
 * idêntico ao português da constante e o en existe e é outro texto.
 */
final class TextosDaBatidaNoCatalogoTest extends TestCase
{
    /** @var list<class-string> */
    private const CLASSES = [
        PosicaoNaCerca::class,
        TrabalhoExternoDaBatida::class,
        EnvioDaBatida::class,
        ChaveDoPontoEletronico::class,
        ChoqueDeJornadaEntreEscritorios::class,
        EntradaAbertaEmOutroEscritorio::class,
        TurnoAbertoNoEscritorio::class,
        AlertasAntesDaBatida::class,
        SaidaDeEsquecimento::class,
        HorarioFuturoDaBatida::class,
        BatidaJaRegistrada::class,
    ];

    /**
     * Classe => texto => parâmetros de exemplo, na ordem do `sprintf`. Valor sem parâmetro vale `[]`.
     *
     * @return iterable<string, array{0: class-string, 1: string, 2: string, 3: array<string, scalar|TextoDoPonto>}>
     */
    public static function textos(): iterable
    {
        $ontem = TextoDoPonto::de('ponto_servidor.batida.alertas.dia_de_ontem', 'de ontem');
        $exemplos = [
            // Os metros vão como número ({distancia, number, integer}); abaixo de mil o texto é o mesmo do sprintf. O milhar
            // ("1.234" em pt_BR, "1,234" em en) é conferido no teste da recusa da cerca.
            PosicaoNaCerca::DETALHE_FORA_COM_SEDE => ['sede' => 'Matriz', 'distancia' => 234, 'raio' => 100],
            PosicaoNaCerca::DETALHE_INCERTA => ['precisao' => 80],
            EnvioDaBatida::MENSAGEM_FILA_VENCIDA => ['horas' => '72'],
            ChoqueDeJornadaEntreEscritorios::MENSAGEM_CHOQUE => ['inicio' => '08:00', 'fim' => '12:00'],
            ChoqueDeJornadaEntreEscritorios::MENSAGEM_BATIDA => ['inicio' => '08:00', 'fim' => '12:00'],
            EntradaAbertaEmOutroEscritorio::AVISO_PENDENCIA => ['escritorio' => 'Filial', 'dia' => '10/11'],
            AlertasAntesDaBatida::ALERTA_INTRAJORNADA => ['faltam' => AlertasAntesDaBatida::textoDoTempoQueFalta(18 * 60), 'minimo' => '30min'],
            AlertasAntesDaBatida::ALERTA_INTERJORNADA => ['faltam' => '2h10', 'minimo' => '8h', 'dia' => $ontem],
            AlertasAntesDaBatida::ALERTA_INTERJORNADA_DE_HOJE => ['faltam' => AlertasAntesDaBatida::textoDoTempoQueFalta(60), 'minimo' => '1h'],
            AlertasAntesDaBatida::ALERTA_DESCANSO_SEMANAL => ['dias' => '7'],
            'de %s' => ['data' => '08/11'],
            SaidaDeEsquecimento::AVISO_INTERJORNADA => ['dia' => '10/11', 'hora' => '10:40', 'horas' => '8'],
            BatidaJaRegistrada::MENSAGEM => ['tipo' => TextoDoPonto::de(BatidaJaRegistrada::CHAVE_DO_ROTULO['saída'], 'saída')],
        ];
        foreach (self::CLASSES as $classe) {
            foreach ($classe::CHAVE_DO_TEXTO as $texto => $chave) {
                yield $chave => [$classe, $texto, $chave, $exemplos[$texto] ?? []];
            }
        }
        foreach (BatidaJaRegistrada::CHAVE_DO_ROTULO as $rotulo => $chave) {
            yield $chave => [BatidaJaRegistrada::class, $rotulo, $chave, []];
        }
    }

    /**
     * @param class-string                       $classe
     * @param array<string, scalar|TextoDoPonto> $parametros
     */
    #[DataProvider('textos')]
    #[TestDox('pt_BR de $chave é o português da constante (mesmos parâmetros) e o en é outro texto')]
    public function testPtBrIgualAConstanteEEnDiferente(string $classe, string $texto, string $chave, array $parametros): void
    {
        $esperado = TextoDoPonto::de($chave, $texto, $parametros)->portugues;
        $valores = array_map(static fn (mixed $v): string => $v instanceof TextoDoPonto ? $v->portugues : (string) $v, array_values($parametros));

        self::assertSame($parametros === [] ? $texto : vsprintf($texto, $valores), $esperado, 'o português do objeto é o sprintf antigo');
        self::assertSame($esperado, self::traduzir($chave, $parametros, 'pt_BR'), $chave . ' em pt_BR');
        $en = self::traduzir($chave, $parametros, 'en');
        self::assertNotSame($chave, $en, $chave . ' sem en');
        self::assertNotSame($esperado, $en, $chave . ' igual ao português em en');
    }

    #[TestDox('A chave de cada constante é a do mapa da classe')]
    public function testChaveDeDevolveAChaveDoMapa(): void
    {
        foreach (self::CLASSES as $classe) {
            foreach ($classe::CHAVE_DO_TEXTO as $texto => $chave) {
                self::assertSame($chave, $classe::chaveDe($texto));
            }
        }
    }

    #[TestDox('Texto fora do mapa dá LogicException em todas as classes')]
    public function testTextoForaDoMapaDaLogicException(): void
    {
        foreach (self::CLASSES as $classe) {
            try {
                $classe::chaveDe('Texto que não é conhecido.');
                self::fail($classe . ': devia dar LogicException.');
            } catch (\LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[TestDox('Toda chave do bloco batida do catálogo é citada por uma classe, e o en tem as mesmas chaves do pt_BR')]
    public function testCatalogoSemChaveSobrando(): void
    {
        $citadas = [AlertasAntesDaBatida::CHAVE_DOS_MINUTOS];
        foreach (self::CLASSES as $classe) {
            array_push($citadas, ...array_values($classe::CHAVE_DO_TEXTO));
        }
        array_push($citadas, ...array_values(BatidaJaRegistrada::CHAVE_DO_ROTULO));
        sort($citadas);

        foreach (['pt_BR', 'en'] as $locale) {
            $catalogo = self::tradutor()->getCatalogue($locale)->all('messages+intl-icu');
            $doBloco = array_values(array_filter(array_keys($catalogo), static fn (string $chave): bool => str_starts_with($chave, 'ponto_servidor.batida.')));
            sort($doBloco);
            self::assertSame($citadas, $doBloco, 'chave do bloco batida (' . $locale . ') sem classe, ou o inverso');
        }
    }

    /** @return iterable<string, array{0: int, 1: string, 2: string}> */
    public static function minutos(): iterable
    {
        yield 'um minuto' => [1, '1 minuto', '1 minute'];
        yield 'dezoito' => [18 * 60, '18 minutos', '18 minutes'];
        yield 'quase uma hora' => [59 * 60, '59 minutos', '59 minutes'];
    }

    #[DataProvider('minutos')]
    #[TestDox('O tempo que falta abaixo de uma hora ($segundos s): pt_BR igual ao tempoQueFalta e en no plural')]
    public function testTempoQueFaltaEmMinutos(int $segundos, string $pt, string $en): void
    {
        $tempo = AlertasAntesDaBatida::textoDoTempoQueFalta($segundos);

        self::assertInstanceOf(TextoDoPonto::class, $tempo);
        self::assertSame($pt, AlertasAntesDaBatida::tempoQueFalta($segundos));
        self::assertSame($pt, self::traduzir($tempo->chave, $tempo->parametros, 'pt_BR'));
        self::assertSame($en, self::traduzir($tempo->chave, $tempo->parametros, 'en'));
        self::assertSame('2h10', AlertasAntesDaBatida::textoDoTempoQueFalta(130 * 60), 'de uma hora para cima o texto é o mesmo em qualquer idioma');
    }

    #[TestDox('A recusa da cerca traduzida junta (título, detalhe e saída) é o português de recusa(), fora e incerta, com e sem a saída')]
    public function testRecusaDaCercaTraduzidaIgualARecusaEmPortugues(): void
    {
        $sede = (new Sede())->setNome('Matriz');
        $posicoes = [
            'fora com sede' => PosicaoNaCerca::fora($sede, 1234.4, 100, 20.0),
            'fora sem sede' => PosicaoNaCerca::fora(null, null, null, 20.0),
            'incerta' => PosicaoNaCerca::incerta($sede, 50.0, 100, 80.0),
        ];
        $tradutor = self::tradutor();
        foreach ($posicoes as $nome => $posicao) {
            foreach ([true, false] as $externo) {
                $recusa = $posicao->recusa($externo);
                $traduzida = $posicao->recusaComChave($externo);
                $tradutor->setLocale('pt_BR');
                self::assertSame($recusa['titulo'], $traduzida['titulo']->traduzir($tradutor), $nome);
                self::assertSame($recusa['detalhe'], $traduzida['detalhe']->traduzir($tradutor) . ($traduzida['saida']?->traduzir($tradutor) ?? ''), $nome);
                $tradutor->setLocale('en');
                self::assertNotSame($recusa['titulo'], $traduzida['titulo']->traduzir($tradutor), $nome . ' em en');
                self::assertSame($externo, $traduzida['saida'] !== null, $nome);
            }
        }
        $tradutor->setLocale('en');
        self::assertStringContainsString(', 1,234 m away. The allowed radius is 100 m.', $posicoes['fora com sede']->recusaComChave(false)['detalhe']->traduzir($tradutor), 'milhar no formato do en');
        $tradutor->setLocale('pt_BR');
        self::assertStringContainsString(', a 1.234 m. O raio permitido é de 100 m.', $posicoes['fora com sede']->recusaComChave(false)['detalhe']->traduzir($tradutor), 'milhar no formato do pt_BR');
    }

    #[TestDox('A recusa do local e motivo leva o código que o JS compara (sem_motivo e motivo_longo) e a mensagem traduzida')]
    public function testRecusaDoMotivoComCodigo(): void
    {
        $semMotivo = TrabalhoExternoDaBatida::recusaDoMotivoComCodigo('');
        $longo = TrabalhoExternoDaBatida::recusaDoMotivoComCodigo(str_repeat('a', 501));

        self::assertNull(TrabalhoExternoDaBatida::recusaDoMotivoComCodigo('Cliente'));
        self::assertSame('sem_motivo', $semMotivo['codigo'] ?? null);
        self::assertSame(TrabalhoExternoDaBatida::MENSAGEM_SEM_MOTIVO, $semMotivo['texto']->portugues ?? null);
        self::assertSame('motivo_longo', $longo['codigo'] ?? null);
        self::assertSame('Enter the location and the reason.', self::traduzir('ponto_servidor.batida.externo.sem_motivo', [], 'en'));
    }

    /** @param array<string, scalar|TextoDoPonto> $parametros */
    private static function traduzir(string $chave, array $parametros, string $locale): string
    {
        $tradutor = self::tradutor();
        $tradutor->setLocale($locale);
        try {
            return (new TextoDoPonto($chave, $parametros, ''))->traduzir($tradutor);
        } finally {
            $tradutor->setLocale('pt_BR');
        }
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
