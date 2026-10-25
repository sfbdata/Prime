<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Inteligencia\Enum\DocumentoJuridico;
use App\Inteligencia\Juridico\CatalogoDeQuestoesJuridicas;
use App\Inteligencia\Juridico\QuestaoJuridica;
use App\Tests\Inteligencia\Support\CatalogoJuridicoDeTeste;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(CatalogoDeQuestoesJuridicas::class)]
#[CoversClass(QuestaoJuridica::class)]
final class CatalogoDeQuestoesJuridicasTest extends TestCase
{
    #[TestDox('o catálogo entregue (config/inteligencia/questoes_juridicas.json) é válido, sem chave repetida e só com documentos conhecidos')]
    public function testCatalogoEntregueEhValido(): void
    {
        $catalogo = new CatalogoDeQuestoesJuridicas(\dirname(__DIR__, 3) . '/config/inteligencia/questoes_juridicas.json');

        $questoes = $catalogo->todas();

        self::assertNotEmpty($questoes);
        foreach ($questoes as $q) {
            self::assertNotEmpty($q->documentosJuridicos(), $q->chave . ' pede ao menos um documento');
            self::assertNotEmpty($q->fontes, $q->chave . ' tem fonte');
            foreach ([$q->titulo, $q->problema, $q->risco, $q->sugestao, ...$q->opcoes] as $texto) {
                self::assertDoesNotMatchRegularExpression('/[—–]/u', $texto, 'sem travessão em texto visível');
            }
        }
    }

    #[TestDox('lê os campos do JSON na ordem, com os documentos como enum e a busca por chave')]
    public function testLeOsCampos(): void
    {
        $catalogo = CatalogoJuridicoDeTeste::criar();

        self::assertSame(['primeira', 'segunda'], $catalogo->chaves());
        $q = $catalogo->porChave('primeira');
        self::assertNotNull($q);
        self::assertSame('Questão primeira', $q->titulo);
        self::assertSame('alto', $q->riscoGrau);
        self::assertSame(['Opção A', 'Opção B'], $q->opcoes);
        self::assertSame([['rotulo' => 'Fonte de primeira', 'url' => 'https://exemplo.gov.br/primeira']], $q->fontes);
        self::assertSame([DocumentoJuridico::PoliticaDePrivacidade, DocumentoJuridico::AceiteDaIa], $q->documentosJuridicos());
        self::assertTrue($q->pedeODocumento(DocumentoJuridico::AceiteDaIa));
        self::assertFalse($q->pedeODocumento(DocumentoJuridico::TermosDeUso));
        self::assertNull($catalogo->porChave('nao_existe'));
    }

    #[TestDox('parágrafos: o texto separado por linha em branco vira uma lista, sem os vazios')]
    public function testParagrafos(): void
    {
        $q = CatalogoJuridicoDeTeste::criar()->todas()[0];

        self::assertSame(['Primeiro parágrafo de primeira.', 'Segundo parágrafo.'], $q->paragrafos($q->problema));
        self::assertSame(['um', 'dois'], $q->paragrafos("\n\num\n\n\n\n  dois  \n"));
        self::assertSame(["linha 1\nlinha 2"], $q->paragrafos("linha 1\nlinha 2"), 'quebra simples não separa');
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function questoesInvalidas(): iterable
    {
        yield 'fonte javascript:' => [['fontes' => [['rotulo' => 'x', 'url' => 'javascript:alert(1)']]] + CatalogoJuridicoDeTeste::questao('a')];
        yield 'grau de risco desconhecido' => [['risco_grau' => 'altissimo'] + CatalogoJuridicoDeTeste::questao('a')];
        yield 'documento desconhecido' => [['documentos' => ['contrato_social']] + CatalogoJuridicoDeTeste::questao('a')];
        yield 'chave fora do formato' => [['chave' => 'Com Espaço'] + CatalogoJuridicoDeTeste::questao('a')];
        yield 'campo faltando' => [array_diff_key(CatalogoJuridicoDeTeste::questao('a'), ['sugestao' => true])];
        yield 'opções não é lista' => [['opcoes' => 'uma só'] + CatalogoJuridicoDeTeste::questao('a')];
    }

    /** @param array<string, mixed> $questao */
    #[DataProvider('questoesInvalidas')]
    #[TestDox('catálogo inválido ($_dataName) lança na leitura')]
    public function testQuestaoInvalida(array $questao): void
    {
        $this->expectException(\UnexpectedValueException::class);

        CatalogoJuridicoDeTeste::criar([$questao])->todas();
    }

    #[TestDox('chave repetida, JSON quebrado, raiz que não é lista e arquivo ausente lançam')]
    public function testArquivoInvalido(): void
    {
        $casos = [
            CatalogoJuridicoDeTeste::gravar((string) json_encode([CatalogoJuridicoDeTeste::questao('a'), CatalogoJuridicoDeTeste::questao('a')])),
            CatalogoJuridicoDeTeste::gravar('[{"chave": '),
            CatalogoJuridicoDeTeste::gravar('{"chave": "a"}'),
            sys_get_temp_dir() . '/nao-existe-' . bin2hex(random_bytes(4)) . '.json',
        ];
        foreach ($casos as $caminho) {
            try {
                (new CatalogoDeQuestoesJuridicas($caminho))->todas();
                self::fail('deveria lançar: ' . $caminho);
            } catch (\UnexpectedValueException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
