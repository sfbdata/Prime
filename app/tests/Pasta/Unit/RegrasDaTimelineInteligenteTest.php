<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\DTO\EventoDaTimelineOutput;
use App\Pasta\DTO\FiltroDaTimelineInput;
use App\Pasta\Service\RegrasDaTimelineInteligente;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Regras fixas da Timeline inteligente (L18): prioridade, marcos, "Precisa de atenção", filtros,
 * busca com sinônimos e resumo por contagem. Sem banco, sem relógio — o "agora" é fixo.
 */
#[CoversClass(RegrasDaTimelineInteligente::class)]
#[CoversClass(EventoDaTimelineOutput::class)]
final class RegrasDaTimelineInteligenteTest extends TestCase
{
    private RegrasDaTimelineInteligente $regras;
    private \DateTimeImmutable $agora;

    protected function setUp(): void
    {
        $this->regras = new RegrasDaTimelineInteligente();
        $this->agora  = new \DateTimeImmutable('2026-10-06 15:00:00');
    }

    private function evento(
        string $id,
        string $quando,
        string $categoria = 'registro',
        string $tipo = 'registro',
        string $titulo = 'Registro em Dados da pasta',
        ?string $texto = null,
        ?string $autor = null,
        ?string $prioridade = null,
        bool $pendente = false,
    ): EventoDaTimelineOutput {
        return new EventoDaTimelineOutput(
            id: $id,
            tipo: $tipo,
            categoria: $categoria,
            quando: new \DateTimeImmutable($quando),
            soData: false,
            titulo: $titulo,
            texto: $texto,
            autor: $autor,
            prioridade: $prioridade,
            pendente: $pendente,
        );
    }

    /** @param list<EventoDaTimelineOutput> $eventos @return list<string> */
    private function ids(array $eventos): array
    {
        return array_map(static fn (EventoDaTimelineOutput $e) => $e->id, $eventos);
    }

    // ── prioridade ────────────────────────────────────────────────────────

    /** @return iterable<string, array{int, string}> */
    public static function atrasos(): iterable
    {
        yield 'no prazo (futuro)'    => [-5, 'info'];
        yield 'vence hoje'           => [0, 'info'];
        yield '1 dia de atraso'      => [1, 'importante'];
        yield '30 dias de atraso'    => [30, 'importante'];
        yield '31 dias = crítico'    => [31, 'critico'];
    }

    #[DataProvider('atrasos')]
    #[TestDox('Prazo da meta: $atraso dia(s) de atraso → $esperado')]
    public function testPrioridadeDoPrazoDaMeta(int $atraso, string $esperado): void
    {
        self::assertSame($esperado, $this->regras->prioridadeDoPrazoDaMeta($atraso));
    }

    #[TestDox('Publicação: intimação/citação/prazo é importante; o resto é informativo')]
    public function testPrioridadeDaPublicacao(): void
    {
        self::assertSame('importante', $this->regras->prioridadeDaPublicacao('Intimação'));
        self::assertSame('importante', $this->regras->prioridadeDaPublicacao('CITAÇÃO'));
        self::assertSame('info', $this->regras->prioridadeDaPublicacao('Edital'));
        self::assertSame('info', $this->regras->prioridadeDaPublicacao(null));
    }

    // ── nenhum evento ─────────────────────────────────────────────────────

    #[TestDox('Nenhum evento: sem marco, sem atenção, contagem zero e resumo honesto')]
    public function testNenhumEvento(): void
    {
        self::assertSame([], $this->regras->aplicarMarcos([], true, true));
        self::assertSame([], $this->regras->atencao([]));
        self::assertSame([], $this->regras->filtrar([], new FiltroDaTimelineInput(), $this->agora));
        self::assertSame([], $this->regras->pessoas([]));
        self::assertSame('Nenhuma atividade no período.', $this->regras->resumir([]));
        self::assertNull($this->regras->dataDeTodasAsMetasConcluidas([]));

        $contagens = $this->regras->contagens([]);
        self::assertSame(0, $contagens['tudo']);
        self::assertSame(0, $contagens['pendente']);
        self::assertSame(0, $contagens['marco']);
        foreach (array_keys(RegrasDaTimelineInteligente::CATEGORIAS) as $categoria) {
            self::assertSame(0, $contagens[$categoria]);
        }
    }

    // ── marcos ────────────────────────────────────────────────────────────

    #[TestDox('Marco de criação vai no "Pasta criada" mais antigo, e só nele')]
    public function testMarcoDeCriacao(): void
    {
        $eventos = $this->regras->aplicarMarcos([
            $this->evento('a2', '2026-05-02 10:00', 'cadastro', 'pasta_criada', 'Pasta criada'),
            $this->evento('a1', '2026-05-01 10:00', 'cadastro', 'pasta_criada', 'Pasta criada'),
            $this->evento('m1', '2026-05-03 10:00'),
        ], true, false);

        $marcos = array_filter($eventos, static fn (EventoDaTimelineOutput $e) => $e->marco !== null);
        self::assertSame(['a1'], array_values($this->ids(array_values($marcos))));
        self::assertSame(RegrasDaTimelineInteligente::MARCO_CRIACAO, array_values($marcos)[0]->marco);
    }

    #[TestDox('Primeira publicação só é marco com a lista do Push completa')]
    public function testMarcoDaPrimeiraPublicacao(): void
    {
        $lista = [
            $this->evento('p2', '2026-08-20', 'processo', 'publicacao', 'Intimação no processo'),
            $this->evento('p1', '2026-07-01', 'processo', 'publicacao', 'Intimação no processo'),
        ];

        $completa = $this->regras->aplicarMarcos($lista, true, false);
        self::assertNull($completa[0]->marco);
        self::assertSame(RegrasDaTimelineInteligente::MARCO_PRIMEIRA_PUB, $completa[1]->marco);

        // Teto atingido: a mais antiga carregada pode não ser a primeira — nenhum marco.
        $cortada = $this->regras->aplicarMarcos($lista, false, false);
        self::assertSame([null, null], array_map(static fn (EventoDaTimelineOutput $e) => $e->marco, $cortada));
    }

    #[TestDox('Financeiro quitado marca a ÚLTIMA quitação, e só quando tudo está pago')]
    public function testMarcoDoFinanceiroQuitado(): void
    {
        $lista = [
            $this->evento('pq1', '2026-09-01', 'financeiro', 'pagamento_quitado', 'Pagamento quitado'),
            $this->evento('pq2', '2026-09-10', 'financeiro', 'pagamento_quitado', 'Pagamento quitado'),
        ];

        $quitado = $this->regras->aplicarMarcos($lista, true, true);
        self::assertNull($quitado[0]->marco);
        self::assertSame(RegrasDaTimelineInteligente::MARCO_FINANCEIRO, $quitado[1]->marco);

        $emAberto = $this->regras->aplicarMarcos($lista, true, false);
        self::assertSame([null, null], array_map(static fn (EventoDaTimelineOutput $e) => $e->marco, $emAberto));
    }

    #[TestDox('Todas as metas concluídas: só com todas concluídas E com data; devolve a última')]
    public function testTodasAsMetasConcluidas(): void
    {
        $d1 = new \DateTimeImmutable('2026-09-01 10:00');
        $d2 = new \DateTimeImmutable('2026-09-05 11:00');

        self::assertEquals($d2, $this->regras->dataDeTodasAsMetasConcluidas([
            ['concluida' => true, 'concluidaEm' => $d2],
            ['concluida' => true, 'concluidaEm' => $d1],
        ]));
        self::assertNull($this->regras->dataDeTodasAsMetasConcluidas([
            ['concluida' => true, 'concluidaEm' => $d1],
            ['concluida' => false, 'concluidaEm' => null],
        ]));
        // Concluída sem data (legado): não se inventa o dia.
        self::assertNull($this->regras->dataDeTodasAsMetasConcluidas([
            ['concluida' => true, 'concluidaEm' => null],
        ]));
    }

    // ── atenção e ordem ───────────────────────────────────────────────────

    #[TestDox('"Precisa de atenção": pendentes e críticos, mais novos primeiro, no máximo 8')]
    public function testAtencao(): void
    {
        $eventos = [
            $this->evento('info', '2026-10-05 10:00', prioridade: 'importante'),
            $this->evento('crit', '2026-10-01 10:00', prioridade: 'critico'),
            $this->evento('pend', '2026-10-04 10:00', pendente: true),
        ];
        self::assertSame(['pend', 'crit'], $this->ids($this->regras->atencao($eventos)));

        $muitos = [];
        for ($i = 1; $i <= 12; ++$i) {
            $muitos[] = $this->evento('p' . $i, sprintf('2026-09-%02d 10:00', $i), pendente: true);
        }
        $atencao = $this->regras->atencao($muitos);
        self::assertCount(8, $atencao);
        self::assertSame('p12', $atencao[0]->id);
    }

    #[TestDox('Ordem: mais novo primeiro; empate de horário desempatado pelo id, estável')]
    public function testOrdenar(): void
    {
        $ordenados = $this->regras->ordenar([
            $this->evento('a', '2026-10-01 10:00'),
            $this->evento('c', '2026-10-03 10:00'),
            $this->evento('b1', '2026-10-02 10:00'),
            $this->evento('b2', '2026-10-02 10:00'),
        ]);

        self::assertSame(['c', 'b2', 'b1', 'a'], $this->ids($ordenados));
    }

    // ── filtros ───────────────────────────────────────────────────────────

    /** @return list<EventoDaTimelineOutput> */
    private function base(): array
    {
        return [
            $this->evento('hoje', '2026-10-06 09:00', 'documento', 'auditoria', 'Documento enviado', 'Contrato de honorários.pdf', 'Ana Souza'),
            $this->evento('ontem', '2026-10-05 18:00', 'registro', 'registro', 'Registro em Dados da pasta', 'Cliente ligou pelo celular', 'Bruno Lima'),
            $this->evento('semana', '2026-09-30 08:00', 'processo', 'publicacao', 'Intimação no processo', null, null, 'importante', true),
            $this->evento('antigo', '2026-08-01 08:00', 'meta', 'prazo_meta', 'Prazo da meta', '«Juntar procuração»', null, 'critico'),
        ];
    }

    /** @return iterable<string, array{FiltroDaTimelineInput, list<string>}> */
    public static function filtros(): iterable
    {
        yield 'tudo'                     => [new FiltroDaTimelineInput(), ['hoje', 'ontem', 'semana', 'antigo']];
        yield 'categoria documento'      => [new FiltroDaTimelineInput(categoria: 'documento'), ['hoje']];
        yield 'pendências'               => [new FiltroDaTimelineInput(categoria: 'pendente'), ['semana']];
        yield 'período hoje'             => [new FiltroDaTimelineInput(periodo: 'hoje'), ['hoje']];
        yield 'período ontem'            => [new FiltroDaTimelineInput(periodo: 'ontem'), ['ontem']];
        yield 'período semana (7 dias)'  => [new FiltroDaTimelineInput(periodo: 'semana'), ['hoje', 'ontem', 'semana']];
        yield 'pessoa'                   => [new FiltroDaTimelineInput(pessoa: 'Bruno Lima'), ['ontem']];
        yield 'sinônimo: contrato→honorár' => [new FiltroDaTimelineInput(busca: 'quando falaram sobre o contrato'), ['hoje']];
        yield 'sinônimo: telefone→celular' => [new FiltroDaTimelineInput(busca: 'telefone'), ['ontem']];
        // Sem acento casa com "Intimação"; e "intimação" é do grupo "prazo", que pega o prazo da meta.
        yield 'sinônimo e acento: intimacao' => [new FiltroDaTimelineInput(busca: 'intimacao'), ['semana', 'antigo']];
        yield 'nome da pessoa na busca'  => [new FiltroDaTimelineInput(busca: 'Bruno'), ['ontem']];
        yield 'só palavras de pergunta'  => [new FiltroDaTimelineInput(busca: 'quando foi'), ['hoje', 'ontem', 'semana', 'antigo']];
        yield 'filtros combinados'       => [new FiltroDaTimelineInput(categoria: 'documento', periodo: 'hoje', busca: 'contrato'), ['hoje']];
    }

    #[DataProvider('filtros')]
    public function testFiltrar(FiltroDaTimelineInput $filtro, array $esperado): void
    {
        self::assertSame($esperado, $this->ids($this->regras->filtrar($this->base(), $filtro, $this->agora)));
    }

    /** @return iterable<string, array{FiltroDaTimelineInput}> */
    public static function filtrosQueRemovemTudo(): iterable
    {
        yield 'categoria sem evento'     => [new FiltroDaTimelineInput(categoria: 'financeiro')];
        yield 'marcos sem marco'         => [new FiltroDaTimelineInput(categoria: 'marco')];
        yield 'busca sem resultado'      => [new FiltroDaTimelineInput(busca: 'xyzwq')];
        yield 'pessoa que não aparece'   => [new FiltroDaTimelineInput(pessoa: 'Ninguém')];
        yield 'combinação impossível'    => [new FiltroDaTimelineInput(categoria: 'documento', periodo: 'ontem')];
    }

    #[DataProvider('filtrosQueRemovemTudo')]
    #[TestDox('Filtro válido que não casa com nada devolve VAZIO, nunca a lista inteira')]
    public function testFiltroQueRemoveTudo(FiltroDaTimelineInput $filtro): void
    {
        self::assertSame([], $this->regras->filtrar($this->base(), $filtro, $this->agora));
    }

    // ── contagens, pessoas, resumo ─────────────────────────────────

    #[TestDox('Contagem dos chips sobre a lista inteira, pessoas em ordem alfabética')]
    public function testContagensEPessoas(): void
    {
        $eventos   = $this->regras->aplicarMarcos($this->base(), true, false);
        $contagens = $this->regras->contagens($eventos);

        self::assertSame(4, $contagens['tudo']);
        self::assertSame(1, $contagens['pendente']);
        self::assertSame(1, $contagens['marco']); // a única publicação, lista completa
        self::assertSame(1, $contagens['documento']);
        self::assertSame(0, $contagens['financeiro']);
        self::assertSame(['Ana Souza', 'Bruno Lima'], $this->regras->pessoas($eventos));
    }

    #[TestDox('Resumo por contagem, com frases fixas')]
    public function testResumir(): void
    {
        self::assertSame(
            '1 evento(s) de documentos. 1 registro(s) na pasta. 1 movimentação(ões) no processo. 1 meta(s) com prazo vencido há mais de 30 dias.',
            $this->regras->resumir($this->base()),
        );
    }
}
