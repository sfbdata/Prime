<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Ponto\DTO\AbonoEmLoteOutput;
use App\Ponto\Exception\ConfiguracaoDoPontoRecusadaException;
use App\Ponto\Exception\EscalaDesconhecidaException;
use App\Ponto\Exception\ExclusaoDeJustificativaRecusadaException;
use App\Ponto\Exception\ForaDaEquipeException;
use App\Ponto\Exception\FuncaoDoPontoRecusadaException;
use App\Ponto\Exception\HorasPagasInvalidaException;
use App\Ponto\Exception\LimitesDoBancoRecusadosException;
use App\Ponto\Exception\RecusaDoPonto;
use App\Ponto\Exception\VersaoDaJornadaNaoEncontradaException;
use App\Ponto\Exception\VersaoDaJornadaRecusadaException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

/**
 * Spec idioma-do-sistema §30 (idioma-ponto-php, PR B): cada constante RECUSA_* das exceções de domínio do Ponto tem
 * chave no catálogo `ponto_servidor`, o pt_BR do catálogo é idêntico ao português da constante (com o parâmetro de
 * exemplo no lugar do %d ou %s) e o en existe e é outro texto.
 */
final class RecusasDoPontoNoCatalogoTest extends TestCase
{
    /** @var list<class-string> */
    private const EXCECOES = [
        EscalaDesconhecidaException::class,
        ExclusaoDeJustificativaRecusadaException::class,
        ForaDaEquipeException::class,
        FuncaoDoPontoRecusadaException::class,
        HorasPagasInvalidaException::class,
        LimitesDoBancoRecusadosException::class,
        VersaoDaJornadaNaoEncontradaException::class,
        VersaoDaJornadaRecusadaException::class,
    ];

    /**
     * Exceção => recusa => parâmetros de exemplo (pelo nome do parâmetro no catálogo, na ordem do sprintf).
     *
     * @return iterable<string, array{0: class-string, 1: string, 2: string, 3: array<string, string>}>
     */
    public static function recusas(): iterable
    {
        $exemplos = [
            HorasPagasInvalidaException::RECUSA_QUANTIDADE_MAXIMA => ['limite' => '100000'],
            HorasPagasInvalidaException::RECUSA_MOTIVO_CURTO => ['minimo' => '3'],
            LimitesDoBancoRecusadosException::RECUSA_LIMITE_ABAIXO_DO_MINIMO => ['minimo' => '1'],
            LimitesDoBancoRecusadosException::RECUSA_LIMITE_ACIMA_DO_MAXIMO => ['maximo' => '24'],
            LimitesDoBancoRecusadosException::RECUSA_PRAZO_FORA_DA_FAIXA => ['minimo' => '1', 'maximo' => '12'],
            VersaoDaJornadaRecusadaException::RECUSA_DATA_REPETIDA => ['data' => '05/11/2026'],
        ];
        foreach (self::EXCECOES as $classe) {
            foreach ($classe::CHAVE_DA_RECUSA as $recusa => $chave) {
                yield $classe . ' ' . $chave => [$classe, $recusa, $chave, $exemplos[$recusa] ?? []];
            }
        }
        foreach (ConfiguracaoDoPontoRecusadaException::CHAVE_DA_RECUSA as $recusa => $chave) {
            yield ConfiguracaoDoPontoRecusadaException::class . ' ' . $chave => [ConfiguracaoDoPontoRecusadaException::class, $recusa, $chave, []];
        }
    }

    /**
     * @param class-string               $classe
     * @param array<string, string>      $parametros
     */
    #[DataProvider('recusas')]
    #[TestDox('pt_BR de $chave é a constante da exceção e o en é outro texto')]
    public function testPtBrIgualAConstanteEEnDiferente(string $classe, string $recusa, string $chave, array $parametros): void
    {
        $tradutor = self::tradutor();

        self::assertSame(
            vsprintf($recusa, array_values($parametros)),
            $tradutor->trans($chave, $parametros, null, 'pt_BR'),
            $chave . ' em pt_BR',
        );
        $en = $tradutor->trans($chave, $parametros, null, 'en');
        self::assertNotSame($chave, $en, $chave . ' sem en');
        self::assertNotSame(vsprintf($recusa, array_values($parametros)), $en, $chave . ' igual ao português em en');
    }

    #[TestDox('Toda chave de recusa do catálogo é citada por uma exceção, e o en tem as mesmas chaves do pt_BR')]
    public function testCatalogoSemChaveSobrando(): void
    {
        $citadas = [];
        foreach ([...self::EXCECOES, ConfiguracaoDoPontoRecusadaException::class] as $classe) {
            foreach ($classe::CHAVE_DA_RECUSA as $chave) {
                $citadas[] = $chave;
            }
        }
        $citadas = array_values(array_filter($citadas, static fn (string $chave): bool => str_starts_with($chave, 'ponto_servidor.recusa.')));

        foreach (['pt_BR', 'en'] as $locale) {
            $catalogo = self::tradutor()->getCatalogue($locale)->all('messages+intl-icu');
            $doBloco = array_values(array_filter(array_keys($catalogo), static fn (string $chave): bool => str_starts_with($chave, 'ponto_servidor.recusa.')));
            sort($doBloco);
            $esperadas = $citadas;
            sort($esperadas);
            self::assertSame($esperadas, $doBloco, 'chave de recusa do catálogo (' . $locale . ') sem exceção, ou o inverso');
        }
    }

    /** @param class-string<RecusaDoPonto> $classe */
    #[DataProvider('excecoesComConstrutorAberto')]
    #[TestDox('Recusa fora das constantes dá LogicException em $classe')]
    public function testConstanteDesconhecidaDaLogicException(string $classe): void
    {
        $this->expectException(\LogicException::class);

        new $classe('Texto que não é uma recusa conhecida.');
    }

    /** @return iterable<string, array{0: class-string}> */
    public static function excecoesComConstrutorAberto(): iterable
    {
        yield HorasPagasInvalidaException::class => [HorasPagasInvalidaException::class];
        yield VersaoDaJornadaRecusadaException::class => [VersaoDaJornadaRecusadaException::class];
        yield LimitesDoBancoRecusadosException::class => [LimitesDoBancoRecusadosException::class];
    }

    #[TestDox('Configuração do ponto: texto fora das constantes dá LogicException (não encontrada, inválida e chaveDe)')]
    public function testConfiguracaoComTextoDesconhecidoDaLogicException(): void
    {
        foreach ([
            static fn () => ConfiguracaoDoPontoRecusadaException::naoEncontrada('Outro texto.'),
            static fn () => ConfiguracaoDoPontoRecusadaException::invalida(['nome' => 'Outro texto.']),
            static fn () => ConfiguracaoDoPontoRecusadaException::chaveDe('Outro texto.'),
        ] as $acao) {
            try {
                $acao();
                self::fail('Devia dar LogicException.');
            } catch (\LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[TestDox('Configuração do ponto: a mensagem da exceção segue o português de hoje (uma recusa e várias, sem repetir)')]
    public function testConfiguracaoMantemAMensagemEmPortugues(): void
    {
        self::assertSame('Sede não encontrada.', ConfiguracaoDoPontoRecusadaException::naoEncontrada(ConfiguracaoDoPontoRecusadaException::RECUSA_SEDE_NAO_ENCONTRADA)->getMessage());
        $invalida = ConfiguracaoDoPontoRecusadaException::invalida([
            'latitude' => ConfiguracaoDoPontoRecusadaException::RECUSA_LATITUDE_OBRIGATORIA,
            'longitude' => ConfiguracaoDoPontoRecusadaException::RECUSA_LONGITUDE_OBRIGATORIA,
            'outra' => ConfiguracaoDoPontoRecusadaException::RECUSA_LATITUDE_OBRIGATORIA,
        ]);
        self::assertSame('Latitude é obrigatória. Longitude é obrigatória.', $invalida->getMessage());
    }

    /** @return iterable<string, array{0: int, 1: string, 2: string}> */
    public static function abonadas(): iterable
    {
        yield 'uma' => [1, '1 justificativa abonada.', '1 justification excused.'];
        yield 'varias' => [3, '3 justificativas abonadas.', '3 justifications excused.'];
        yield 'mil, sem separador como o sprintf' => [1000, '1000 justificativas abonadas.', '1000 justifications excused.'];
    }

    #[DataProvider('abonadas')]
    #[TestDox('Abono em lote: $quantidade no plural ICU, pt_BR igual ao texto de AbonoEmLoteOutput')]
    public function testAbonadasComPluralIcu(int $quantidade, string $pt, string $en): void
    {
        $tradutor = self::tradutor();

        self::assertSame($pt, (new AbonoEmLoteOutput($quantidade, []))->mensagemDasAbonadas());
        self::assertSame($pt, $tradutor->trans('ponto_servidor.equipe.abonadas', ['quantidade' => $quantidade, 'numero' => (string) $quantidade], null, 'pt_BR'));
        self::assertSame($en, $tradutor->trans('ponto_servidor.equipe.abonadas', ['quantidade' => $quantidade, 'numero' => (string) $quantidade], null, 'en'));
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
