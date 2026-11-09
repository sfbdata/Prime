<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use App\AssinaturaEletronica\DTO\DadosDoModelo;
use App\AssinaturaEletronica\Exception\AcaoRecusada;
use App\AssinaturaEletronica\Service\ValidadorDoModelo;
use App\Tests\Support\CatalogoPtBr;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

/**
 * A `Entrada` do "Salvar modelo" (contrato da etapa 4, seção 6): o que passa sai normalizado, e cada regra que falha
 * vira uma recusa com o texto dela. Nada é cortado em silêncio: uma prova por recusa.
 */
#[CoversClass(DadosDoModelo::class)]
#[CoversClass(ValidadorDoModelo::class)]
final class ValidadorDoModeloTest extends TestCase
{
    /**
     * Um modelo válido; `$troca` substitui as chaves de topo.
     *
     * @param array<string, mixed> $troca
     *
     * @return array<string, mixed>
     */
    private static function entrada(array $troca = []): array
    {
        return array_merge([
            'nome' => 'Procuração simples',
            'categoria' => 'Procurações',
            'layout' => '',
            'lateral' => '',
            'folhaBranca' => false,
            'campos' => [['nome', 'Nome completo', 1]],
            'signatarios' => [['papel' => 'Outorgante']],
            'corpo' => [['t' => 'PROCURAÇÃO'], ['p' => 'Eu, [[nome]], nomeio.'], ['a' => 0, 'r' => '[[nome]]']],
        ], $troca);
    }

    #[TestDox('o modelo válido sai com os campos na forma do catálogo, só o papel de quem assina e os blocos sem nada a mais')]
    public function testNormaliza(): void
    {
        $dados = DadosDoModelo::deJson(self::entrada([
            'nome' => '  Procuração simples  ',
            'categoria' => '   ',
            'layout' => '2col',
            'lateral' => "  OUTORGANTE\n[[nome]]  ",
            'folhaBranca' => true,
            'campos' => [
                ['nome', 'Nome completo'],
                ['cpf', 'CPF', true, '000.000.000-00'],
                ['estado_civil', 'Estado civil', 0, '', ['solteiro(a)', 'casado(a)']],
            ],
            'signatarios' => [['papel' => '  Outorgante ', 'nome' => 'Fulano', 'email' => 'f@x.com']],
            'corpo' => [
                ['t' => 'PROCURAÇÃO'],
                ['p' => 'Eu, [[nome]], nomeio.', 'full' => true],
                ['p' => 'Poderes.', 'full' => 0],
                ['d' => '[[cidade]], [[data_extenso]]'],
                ['a' => 0, 'r' => '   '],
            ],
        ]));

        self::assertSame('Procuração simples', $dados->nome);
        self::assertSame('Meus modelos', $dados->categoria);
        self::assertSame('2col', $dados->layout);
        self::assertSame("  OUTORGANTE\n[[nome]]  ", $dados->lateral);
        self::assertTrue($dados->folhaBranca);
        self::assertSame([
            ['nome', 'Nome completo', 0],
            ['cpf', 'CPF', 1, '000.000.000-00'],
            ['estado_civil', 'Estado civil', 0, '', ['solteiro(a)', 'casado(a)']],
        ], $dados->campos);
        self::assertSame([['papel' => 'Outorgante']], $dados->signatarios);
        self::assertSame([
            ['t' => 'PROCURAÇÃO'],
            ['p' => 'Eu, [[nome]], nomeio.', 'full' => 1],
            ['p' => 'Poderes.'],
            ['d' => '[[cidade]], [[data_extenso]]'],
            ['a' => 0],
        ], $dados->corpo);
    }

    #[TestDox('^ só é recusado no começo da chave: no começo, o [[^campo]] seria lido como maiúsculas de outro campo')]
    public function testCircunflexoNoMeioDaChave(): void
    {
        self::assertSame([['a^b', 'Campo', 0]], ValidadorDoModelo::campos([['a^b', 'Campo']]));
    }

    #[TestDox('texto lateral só de espaço vira nulo, e a folha branca ausente é falsa')]
    public function testLateralVaziaEFolhaAusente(): void
    {
        $json = self::entrada(['lateral' => "  \n "]);
        unset($json['folhaBranca'], $json['categoria']);

        $dados = DadosDoModelo::deJson($json);

        self::assertNull($dados->lateral);
        self::assertFalse($dados->folhaBranca);
        self::assertSame(DadosDoModelo::CATEGORIA_PADRAO, $dados->categoria);
        self::assertFalse(DadosDoModelo::deJson(self::entrada(['folhaBranca' => null]))->folhaBranca, 'nulo é como ausente');
    }

    #[TestDox('no limite passa: nome 200, categoria 80, lateral 20.000, 100 campos, chave 60, rótulo e exemplo 120, 20 opções de 60')]
    public function testNoLimiteDoTopoEDosCampos(): void
    {
        $campos = array_map(static fn (int $i): array => ['c' . $i, 'Campo ' . $i, 0], range(1, 99));
        $campos[] = [str_repeat('ç', 60), str_repeat('r', 120), 1, str_repeat('e', 120), array_fill(0, 20, str_repeat('o', 60))];

        $dados = DadosDoModelo::deJson(self::entrada([
            'nome' => str_repeat('n', 200),
            'categoria' => str_repeat('c', 80),
            'lateral' => str_repeat('l', 20000),
            'campos' => $campos,
        ]));

        self::assertSame(200, mb_strlen($dados->nome));
        self::assertSame(80, mb_strlen($dados->categoria));
        self::assertSame(20000, mb_strlen((string) $dados->lateral));
        self::assertCount(100, $dados->campos);
    }

    #[TestDox('no limite passa: 50 signatários com papel de 120, 2.000 blocos, trecho de 20.000, papel do bloco de 500 e 300.000 no total')]
    public function testNoLimiteDoCorpo(): void
    {
        $signatarios = array_fill(0, 50, ['papel' => str_repeat('p', 120)]);
        $corpo = array_fill(0, 1998, ['h' => 'Seção']);
        $corpo[] = ['p' => str_repeat('á', 20000)];
        $corpo[] = ['a' => 49, 'r' => str_repeat('r', 500)];

        $dados = DadosDoModelo::deJson(self::entrada(['signatarios' => $signatarios, 'corpo' => $corpo]));
        self::assertCount(50, $dados->signatarios);
        self::assertCount(2000, $dados->corpo);

        $total = array_fill(0, 15, ['p' => str_repeat('x', 20000)]);
        $total[] = ['a' => 0];
        self::assertCount(16, ValidadorDoModelo::corpo($total, 1));
    }

    /** @return iterable<string, array{mixed, string, array<string, int|string>}> */
    public static function recusados(): iterable
    {
        $um = static fn (array $troca): array => self::entrada($troca);

        yield 'pedido que não é objeto' => ['texto', 'dados_invalidos', []];
        yield 'pedido em lista' => [[1, 2], 'dados_invalidos', []];
        yield 'campos que não são lista' => [$um(['campos' => ['a' => ['k', 'R']]]), 'dados_invalidos', []];
        yield 'signatários que não são lista' => [$um(['signatarios' => 'Outorgante']), 'dados_invalidos', []];
        yield 'corpo que não é lista' => [$um(['corpo' => 'Eu, [[nome]]']), 'dados_invalidos', []];
        yield 'nome que não é texto' => [$um(['nome' => 12]), 'dados_invalidos', []];
        yield 'folha branca em texto' => [$um(['folhaBranca' => 'sim']), 'dados_invalidos', []];
        yield 'folha branca 2' => [$um(['folhaBranca' => 2]), 'dados_invalidos', []];
        yield 'folha branca 1' => [$um(['folhaBranca' => 1]), 'dados_invalidos', []];
        yield 'folha branca em lista' => [$um(['folhaBranca' => []]), 'dados_invalidos', []];
        yield 'chave html no topo' => [$um(['html' => '<p>Texto</p>']), 'dados_invalidos', []];
        yield 'chave id no topo' => [$um(['id' => 3]), 'dados_invalidos', []];
        yield 'nome vazio' => [$um(['nome' => '   ']), 'nome_vazio', []];
        yield 'nome com 201' => [$um(['nome' => str_repeat('n', 201)]), 'nome_longo', []];
        yield 'categoria com 81' => [$um(['categoria' => str_repeat('c', 81)]), 'categoria_longa', []];
        yield 'formato desconhecido' => [$um(['layout' => '3col']), 'layout_invalido', []];
        yield 'formato que não é texto' => [$um(['layout' => 2]), 'layout_invalido', []];
        yield 'lateral com 20.001' => [$um(['lateral' => str_repeat('l', 20001)]), 'lateral_longa', []];
        yield '101 campos' => [$um(['campos' => array_map(static fn (int $i): array => ['c' . $i, 'C'], range(1, 101))]), 'campos_demais', []];
        yield 'campo que não é lista' => [$um(['campos' => ['nome']]), 'campo_invalido', ['posicao' => 1]];
        yield 'campo em objeto' => [$um(['campos' => [['k' => 'nome', 'r' => 'Nome']]]), 'campo_invalido', ['posicao' => 1]];
        yield 'campo com 1 item' => [$um(['campos' => [['nome']]]), 'campo_invalido', ['posicao' => 1]];
        yield 'campo com 6 itens' => [$um(['campos' => [['nome', 'Nome', 0, '', ['a'], 'x']]]), 'campo_invalido', ['posicao' => 1]];
        yield 'rótulo vazio' => [$um(['campos' => [['nome', 'Nome'], ['cpf', '  ']]]), 'campo_invalido', ['posicao' => 2]];
        yield 'rótulo com 121' => [$um(['campos' => [['nome', str_repeat('r', 121)]]]), 'campo_invalido', ['posicao' => 1]];
        yield 'largo 2' => [$um(['campos' => [['nome', 'Nome', 2]]]), 'campo_invalido', ['posicao' => 1]];
        yield 'largo em texto' => [$um(['campos' => [['nome', 'Nome', '1']]]), 'campo_invalido', ['posicao' => 1]];
        yield 'exemplo com 121' => [$um(['campos' => [['nome', 'Nome', 0, str_repeat('e', 121)]]]), 'campo_invalido', ['posicao' => 1]];
        yield 'opções vazias' => [$um(['campos' => [['nome', 'Nome', 0, '', []]]]), 'campo_invalido', ['posicao' => 1]];
        yield '21 opções' => [$um(['campos' => [['nome', 'Nome', 0, '', array_fill(0, 21, 'a')]]]), 'campo_invalido', ['posicao' => 1]];
        yield 'opção com 61' => [$um(['campos' => [['nome', 'Nome', 0, '', [str_repeat('o', 61)]]]]), 'campo_invalido', ['posicao' => 1]];
        yield 'opção que não é texto' => [$um(['campos' => [['nome', 'Nome', 0, '', [1]]]]), 'campo_invalido', ['posicao' => 1]];
        yield 'chave que não é texto' => [$um(['campos' => [[12, 'Nome']]]), 'campo_invalido', ['posicao' => 1]];
        yield 'chave vazia' => [$um(['campos' => [[' ', 'Nome']]]), 'campo_chave', ['posicao' => 1]];
        yield 'chave com 61' => [$um(['campos' => [[str_repeat('k', 61), 'Nome']]]), 'campo_chave', ['posicao' => 1]];
        yield 'chave com [' => [$um(['campos' => [['no[me', 'Nome']]]), 'campo_chave', ['posicao' => 1]];
        yield 'chave com ]' => [$um(['campos' => [['nome]]', 'Nome']]]), 'campo_chave', ['posicao' => 1]];
        yield 'chave começando com ^' => [$um(['campos' => [['nome', 'Nome'], ['^Nome', 'Nome em maiúsculas']]]), 'campo_chave', ['posicao' => 2]];
        yield 'chave repetida' => [$um(['campos' => [['nome', 'Nome'], ['nome', 'Outro']]]), 'campo_repetido', ['nome' => 'nome']];
        yield '51 signatários' => [$um(['signatarios' => array_fill(0, 51, ['papel' => 'Parte'])]), 'signatarios_demais', []];
        yield 'signatário que não é objeto' => [$um(['signatarios' => ['Outorgante']]), 'signatario_invalido', ['numero' => 1]];
        yield 'signatário em lista' => [$um(['signatarios' => [['Outorgante']]]), 'signatario_invalido', ['numero' => 1]];
        yield 'papel vazio' => [$um(['signatarios' => [['papel' => 'A'], ['papel' => ' ']]]), 'signatario_invalido', ['numero' => 2]];
        yield 'papel que não é texto' => [$um(['signatarios' => [['papel' => 5]]]), 'signatario_invalido', ['numero' => 1]];
        yield 'papel com 121' => [$um(['signatarios' => [['papel' => str_repeat('p', 121)]]]), 'signatario_papel_longo', ['numero' => 1]];
        yield '2.001 blocos' => [$um(['corpo' => array_fill(0, 2001, ['p' => 'a'])]), 'blocos_demais', []];
        yield 'bloco que não é objeto' => [$um(['corpo' => ['texto']]), 'bloco_invalido', ['posicao' => 1]];
        yield 'bloco em lista' => [$um(['corpo' => [['p', 'texto']]]), 'bloco_invalido', ['posicao' => 1]];
        yield 'tipo desconhecido' => [$um(['corpo' => [['p' => 'a'], ['img' => 'x.png']]]), 'bloco_invalido', ['posicao' => 2]];
        yield 'bloco na forma do documento' => [$um(['corpo' => [['k' => 'p', 'x' => 'a']]]), 'bloco_invalido', ['posicao' => 1]];
        yield 'dois tipos no bloco' => [$um(['corpo' => [['t' => 'A', 'p' => 'b']]]), 'bloco_invalido', ['posicao' => 1]];
        yield 'chave a mais no texto' => [$um(['corpo' => [['p' => 'a', 'style' => 'color:red']]]), 'bloco_invalido', ['posicao' => 1]];
        yield 'full fora do parágrafo' => [$um(['corpo' => [['t' => 'A', 'full' => 1]]]), 'bloco_invalido', ['posicao' => 1]];
        yield 'full 2' => [$um(['corpo' => [['p' => 'a', 'full' => 2]]]), 'bloco_invalido', ['posicao' => 1]];
        yield 'texto vazio' => [$um(['corpo' => [['p' => '   ']]]), 'bloco_invalido', ['posicao' => 1]];
        yield 'texto que não é texto' => [$um(['corpo' => [['p' => ['a']]]]), 'bloco_invalido', ['posicao' => 1]];
        yield 'trecho com 20.001' => [$um(['corpo' => [['p' => str_repeat('a', 20001)]]]), 'bloco_texto_longo', ['posicao' => 1]];
        yield 'assinatura em texto' => [$um(['corpo' => [['p' => 'a'], ['a' => '0']]]), 'assinatura_invalida', ['posicao' => 2]];
        yield 'assinatura negativa' => [$um(['corpo' => [['p' => 'a'], ['a' => -1]]]), 'assinatura_invalida', ['posicao' => 2]];
        yield 'assinatura de quem não existe' => [$um(['corpo' => [['p' => 'a'], ['a' => 1]]]), 'assinatura_invalida', ['posicao' => 2]];
        yield 'papel do bloco que não é texto' => [$um(['corpo' => [['p' => 'a'], ['a' => 0, 'r' => 5]]]), 'assinatura_invalida', ['posicao' => 2]];
        yield 'papel do bloco com 501' => [$um(['corpo' => [['p' => 'a'], ['a' => 0, 'r' => str_repeat('r', 501)]]]), 'assinatura_invalida', ['posicao' => 2]];
        yield 'chave a mais na assinatura' => [$um(['corpo' => [['p' => 'a'], ['a' => 0, 'img' => 'data:']]]), 'bloco_invalido', ['posicao' => 2]];
        yield 'mais de 300.000 no total' => [$um(['corpo' => [...array_fill(0, 16, ['p' => str_repeat('x', 20000)]), ['a' => 0]]]), 'corpo_longo', []];
        yield 'sem parágrafo' => [$um(['corpo' => [['t' => 'PROCURAÇÃO'], ['a' => 0]]]), 'sem_texto', []];
        yield 'sem lugar de assinatura' => [$um(['corpo' => [['p' => 'Texto.']]]), 'sem_assinatura', []];
        yield 'sem signatário' => [$um(['signatarios' => [], 'corpo' => [['p' => 'Texto.']]]), 'sem_assinatura', []];
        yield 'folha única sem título' => [$um(['layout' => '2col', 'corpo' => [['p' => 'Texto.'], ['a' => 0]]]), 'sem_titulo', []];
    }

    /** @param array<string, int|string> $parametros */
    #[DataProvider('recusados')]
    #[TestDox('recusa: $_dataName')]
    public function testRecusa(mixed $json, string $erro, array $parametros): void
    {
        try {
            DadosDoModelo::deJson($json);
            self::fail('deveria recusar');
        } catch (AcaoRecusada $e) {
            self::assertSame('assinatura_eletronica.modelo.erro.' . $erro, $e->getMessage());
            self::assertSame($parametros, $e->parametros);
        }
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function ordemDoDesenho(): iterable
    {
        yield 'nome antes do texto' => [['nome' => '', 'corpo' => [['a' => 0]]], 'nome_vazio'];
        yield 'texto antes da assinatura' => [['corpo' => [['t' => 'A']]], 'sem_texto'];
        yield 'assinatura antes do título' => [['layout' => '2col', 'corpo' => [['p' => 'a']]], 'sem_assinatura'];
    }

    /** @param array<string, mixed> $troca */
    #[DataProvider('ordemDoDesenho')]
    #[TestDox('as conferências do desenho vêm na ordem dele: $_dataName')]
    public function testOrdemDoDesenho(array $troca, string $erro): void
    {
        $this->expectExceptionObject(new AcaoRecusada('assinatura_eletronica.modelo.erro.' . $erro));

        DadosDoModelo::deJson(self::entrada($troca));
    }

    /** @return iterable<string, array{string, array<string, int|string>, string}> */
    public static function textos(): iterable
    {
        yield 'dados_invalidos' => ['dados_invalidos', [], 'Os dados do modelo não puderam ser lidos. Recarregue a página e tente de novo.'];
        yield 'nome_vazio' => ['nome_vazio', [], 'Informe o nome do modelo.'];
        yield 'nome_longo' => ['nome_longo', [], 'O nome do modelo pode ter até 200 caracteres.'];
        yield 'categoria_longa' => ['categoria_longa', [], 'A categoria pode ter até 80 caracteres.'];
        yield 'layout_invalido' => ['layout_invalido', [], 'Formato do modelo inválido.'];
        yield 'lateral_longa' => ['lateral_longa', [], 'O texto lateral pode ter até 20.000 caracteres.'];
        yield 'campos_demais' => ['campos_demais', [], 'O modelo pode ter até 100 campos.'];
        yield 'campo_invalido' => ['campo_invalido', ['posicao' => 3], 'O modelo tem um campo inválido (posição 3).'];
        yield 'campo_chave' => ['campo_chave', ['posicao' => 2], 'O nome do campo 2 deve ter de 1 a 60 caracteres, sem colchetes e sem começar com ^.'];
        yield 'campo_repetido' => ['campo_repetido', ['nome' => 'cpf'], 'O campo "cpf" aparece mais de uma vez no modelo.'];
        yield 'signatarios_demais' => ['signatarios_demais', [], 'O modelo pode ter até 50 assinaturas.'];
        yield 'signatario_invalido' => ['signatario_invalido', ['numero' => 2], 'A assinatura 2 do modelo é inválida.'];
        yield 'signatario_papel_longo' => ['signatario_papel_longo', ['numero' => 1], 'O papel da assinatura 1 pode ter até 120 caracteres.'];
        yield 'blocos_demais' => ['blocos_demais', [], 'O texto do modelo pode ter até 2.000 blocos (títulos, parágrafos e assinaturas).'];
        yield 'bloco_invalido' => ['bloco_invalido', ['posicao' => 7], 'O texto do modelo tem um bloco inválido (posição 7).'];
        yield 'bloco_texto_longo' => ['bloco_texto_longo', ['posicao' => 4], 'Um trecho do modelo passa de 20.000 caracteres (posição 4).'];
        yield 'assinatura_invalida' => ['assinatura_invalida', ['posicao' => 9], 'O texto do modelo tem um lugar de assinatura inválido (posição 9).'];
        yield 'corpo_longo' => ['corpo_longo', [], 'O texto do modelo passa de 300.000 caracteres. Divida em mais de um modelo.'];
        yield 'sem_texto' => ['sem_texto', [], 'Escreva o texto do modelo.'];
        yield 'sem_assinatura' => ['sem_assinatura', [], 'Inclua ao menos uma assinatura.'];
        yield 'sem_titulo' => ['sem_titulo', [], 'No formato de folha única, marque uma linha como Título.'];
        yield 'nao_encontrado' => ['nao_encontrado', [], 'Modelo não encontrado.'];
    }

    /** @param array<string, int|string> $parametros */
    #[DataProvider('textos')]
    #[TestDox('o texto da recusa $_dataName é o do contrato')]
    public function testTextoDaRecusa(string $chave, array $parametros, string $texto): void
    {
        $tradutor = new Translator('pt_BR');
        $tradutor->addLoader('yaml', new YamlFileLoader());
        CatalogoPtBr::registrarEm($tradutor);

        self::assertSame($texto, $tradutor->trans('assinatura_eletronica.modelo.erro.' . $chave, $parametros, 'messages+intl-icu'));
    }
}
