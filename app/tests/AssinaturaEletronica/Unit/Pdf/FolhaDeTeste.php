<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit\Pdf;

use App\AssinaturaEletronica\Pdf\EscritorioDaFolha;
use App\AssinaturaEletronica\Pdf\FolhaParaMontar;
use App\AssinaturaEletronica\Pdf\LayoutDaFolha;
use App\AssinaturaEletronica\Pdf\ModoDaFolha;
use App\AssinaturaEletronica\Pdf\PaginasDepoisDoConteudo;
use App\AssinaturaEletronica\Pdf\RecursosDaFolha;
use App\AssinaturaEletronica\Pdf\SemPaginasDepoisDoConteudo;
use App\AssinaturaEletronica\Pdf\SignatarioDaFolha;
use App\AssinaturaEletronica\Pdf\Tela;
use App\AssinaturaEletronica\Pdf\TextosDaFolha;
use App\Tests\Support\CatalogoPtBr;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Folhas prontas para os testes do PDF, e o que eles precisam do ambiente (fontes URW e Ghostscript). Os textos da
 * folha saem do catálogo de verdade ({@see textos()}), como em produção, sem repetir os textos à mão.
 */
final class FolhaDeTeste
{
    public const CODIGO = 'BJA-7KQ2-M9XD-4HRT';
    public const FONTE = '/usr/share/fonts/opentype/urw-base35/NimbusSans-Regular.otf';
    private const CATALOGO_DA_TELA = 'assinatura_eletronica_tela';
    private const TEXTO = 'Pelo presente instrumento particular, as partes acima qualificadas têm entre si justo e contratado o que segue, mediante as cláusulas e condições abaixo descritas, que mutuamente aceitam e outorgam.';

    private static ?TranslatorInterface $tradutor = null;

    private function __construct()
    {
    }

    /** O contêiner tem as fontes (pacote fonts-urw-base35); onde não houver, o teste é pulado. */
    public static function exigirFontes(TestCase $teste): void
    {
        if (!is_readable(self::FONTE)) {
            $teste->markTestSkipped('Fontes URW (fonts-urw-base35) ausentes neste ambiente.');
        }
    }

    /**
     * @param list<array<string, int|string>>|null $blocos
     * @param array<string, mixed>                 $mudancas argumentos nomeados de FolhaParaMontar
     */
    public static function folha(?array $blocos = null, array $mudancas = []): FolhaParaMontar
    {
        $quando = new \DateTimeImmutable('2026-11-09 14:05:03', new \DateTimeZone('America/Sao_Paulo'));
        $argumentos = array_merge([
            'modo' => ModoDaFolha::Final,
            'codigo' => self::CODIGO,
            'hash' => hash('sha256', 'documento de teste'),
            'nome' => 'Procuração',
            'blocos' => $blocos ?? self::blocosDeUmaPagina(),
            'layout' => LayoutDaFolha::PaginaInteira,
            'lateral' => null,
            'folhaBranca' => false,
            'logo' => null,
            'escritorio' => new EscritorioDaFolha('Escritório de Teste', 'Rua A, 1', '(61) 3333-4444'),
            'signatarios' => [new SignatarioDaFolha('Maria da Silva', $quando), new SignatarioDaFolha('João Souza')],
            'concluido' => false,
            'emitidoEm' => $quando,
            'urlDeValidacao' => 'https://www.bluejus.com.br/assinar/validar/' . self::CODIGO,
            'textos' => self::textos(),
        ], $mudancas);
        $argumentos['paginasDepois'] ??= $argumentos['folhaBranca'] === true && $argumentos['codigo'] === null
            ? new SemPaginasDepoisDoConteudo()
            : self::manifestoSemPaginas();

        return new FolhaParaMontar(...$argumentos);
    }

    /** Os textos da folha pelo catálogo de verdade, o bloco `pdf:` da tela. */
    public static function textos(?TranslatorInterface $tradutor = null): TextosDaFolha
    {
        return new TextosDaFolha($tradutor ?? self::tradutor());
    }

    /** O tradutor pt_BR com o catálogo da tela da Assinatura (o arquivo de verdade), montado uma vez. */
    public static function tradutor(): TranslatorInterface
    {
        if (self::$tradutor === null) {
            $tradutor = new Translator('pt_BR');
            $tradutor->addLoader('yaml', new YamlFileLoader());
            $arquivo = CatalogoPtBr::raiz() . '/' . self::CATALOGO_DA_TELA . '/' . CatalogoPtBr::NOME_DO_ARQUIVO;
            $tradutor->addResource('yaml', $arquivo, 'pt_BR', 'messages+intl-icu');
            self::$tradutor = $tradutor;
        }

        return self::$tradutor;
    }

    /**
     * Um manifesto que não acrescenta página: os testes de diagramação contam só as páginas do conteúdo. Em produção,
     * toda folha que não é branca de rascunho leva o manifesto do bloco 1b, com página.
     */
    public static function manifestoSemPaginas(): PaginasDepoisDoConteudo
    {
        return new class () implements PaginasDepoisDoConteudo {
            public function quantas(): int
            {
                return 0;
            }

            public function desenhar(int $indice, Tela $tela, RecursosDaFolha $recursos): void
            {
            }
        };
    }

    /** @return list<array<string, int|string>> */
    public static function blocosDeUmaPagina(): array
    {
        return [
            ['k' => 't', 'x' => 'PROCURAÇÃO', 'full' => 0],
            ['k' => 'p', 'x' => self::TEXTO, 'full' => 0],
            ['k' => 'd', 'x' => 'Brasília/DF, 9 de novembro de 2026.', 'full' => 0],
            ['k' => 'a', 'i' => 0, 'r' => ''],
        ];
    }

    /** @return list<array<string, int|string>> */
    public static function paragrafos(int $quantos, int $repeticoes = 1): array
    {
        $blocos = [];
        for ($i = 1; $i <= $quantos; ++$i) {
            $blocos[] = ['k' => 'p', 'x' => $i . ') ' . str_repeat(self::TEXTO . ' ', $repeticoes), 'full' => 0];
        }

        return $blocos;
    }
}
