<?php

declare(strict_types=1);

namespace App\Tests\Processo\Unit;

use App\Agenda\Service\CalendarioDoSistema;
use App\Agenda\Service\DiasUteisNacionais;
use App\Processo\DTO\MovimentacaoParaPrevisao;
use App\Processo\DTO\SituacaoAtualOutput;
use App\Processo\Service\PrevisaoProcessual;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * "Situação atual" do painel do Push: porte do `previsaoProc` e das `REGRAS_PROC` do desenho 1.2.3
 * (dc L.5064-5117). Cada regra com um texto de publicação, os ajustes de contexto, o selo e o
 * exemplo do próprio desenho.
 */
#[CoversClass(PrevisaoProcessual::class)]
#[CoversClass(SituacaoAtualOutput::class)]
#[CoversClass(MovimentacaoParaPrevisao::class)]
final class PrevisaoProcessualTest extends TestCase
{
    private const HOJE = '2026-10-19 10:00';

    private PrevisaoProcessual $previsao;

    protected function setUp(): void
    {
        $this->previsao = new PrevisaoProcessual(new DiasUteisNacionais(new CalendarioDoSistema()));
    }

    private static function mov(string $data, string $tipo, string $teorHtml): MovimentacaoParaPrevisao
    {
        return MovimentacaoParaPrevisao::doTeorHtml(new \DateTimeImmutable($data), $tipo, $teorHtml);
    }

    /** @param MovimentacaoParaPrevisao[] $movs */
    private function prever(array $movs, string $classe = 'Procedimento Comum', string $hoje = self::HOJE): SituacaoAtualOutput
    {
        $situacao = $this->previsao->prever($movs, $classe, new \DateTimeImmutable($hoje));
        self::assertNotNull($situacao);

        return $situacao;
    }

    /** @return iterable<string, array{string, string, string, string}> tipo, teor, fase esperada, prazo esperado */
    public static function regras(): iterable
    {
        yield 'citacao' => ['Citação', 'Fica a parte ré <b>citada</b> para, querendo, apresentar resposta.', 'Prazo para contestação', '15 dias úteis'];
        yield 'contestacao' => ['Intimação', 'Juntada de contestação pela parte ré.', 'Aguardando intimação para réplica', 'Sem prazo legal definido'];
        yield 'replica' => ['Intimação', 'Intime-se a parte autora para manifestação sobre a contestação.', 'Prazo para réplica', '15 dias úteis'];
        // `replica_ok` fica atrás de `replica`, cujo padrão já casa "réplica" solta: no desenho,
        // "Juntada de réplica" cai na regra de réplica. A ordem é a do desenho e foi mantida.
        yield 'replica_ok (sombreada por replica, como no desenho)' => ['Intimação', 'Juntada de réplica pela parte autora.', 'Prazo para réplica', '15 dias úteis'];
        yield 'provas' => ['Intimação', 'Intimem-se as partes para especificação de provas.', 'Prazo para especificar provas', 'Sem prazo legal definido'];
        yield 'saneamento' => ['Decisão', 'Decisão de saneamento e organização do processo.', 'Prazo para esclarecimentos do saneamento', '5 dias úteis'];
        yield 'audiencia' => ['Intimação', 'Audiência designada para 10/11/2026, às 14h.', 'Audiência designada', 'Sem prazo legal definido'];
        yield 'sentenca' => ['Intimação', 'Sentença publicada. Julgo procedente o pedido.', 'Prazo recursal', '15 dias úteis'];
        yield 'apelacao' => ['Intimação', 'Apelação interposta pela parte ré. Intime-se o apelado.', 'Prazo para contrarrazões', '15 dias úteis'];
        yield 'transito' => ['Certidão', 'Certifico o trânsito em julgado da sentença.', 'Fase de cumprimento', 'Sem prazo legal definido'];
        yield 'conclusos' => ['Despacho', 'Conclusos para julgamento.', 'Concluso ao juízo', 'Sem prazo legal definido'];
        yield 'interlocutoria' => ['Decisão', 'Tutela de urgência deferida.', 'Prazo para recorrer da decisão', '15 dias úteis'];
        yield 'prazo_texto' => ['Intimação', 'Manifeste-se a parte no prazo de 10 dias.', 'Prazo em curso', '10 dias úteis'];
    }

    #[DataProvider('regras')]
    #[TestDox('Regra $_dataName: "$fase"')]
    public function testCadaRegraComUmTexto(string $tipo, string $teor, string $fase, string $prazo): void
    {
        // Com contestação no histórico, a réplica não leva a nota do `exige`.
        $situacao = $this->prever([
            self::mov('2026-10-14', $tipo, $teor),
            self::mov('2026-09-01', 'Juntada', 'Contestação apresentada no prazo.'),
        ]);

        self::assertSame($fase, $situacao->situacao);
        self::assertSame($prazo, $situacao->prazo);
    }

    #[TestDox('Exemplo do desenho, corrigido pela lei: Intimação disponibilizada em 02/10/2026 leva à réplica até 27/10/2026, faltam 6 em 19/10, confiança Média pela classe')]
    public function testExemploDoDesenho(): void
    {
        $situacao = $this->prever([
            self::mov('2026-09-25', 'Intimação', 'Juntada de contestação pela parte ré.'),
            self::mov('2026-10-02', 'Intimação', 'Intime-se a parte autora para manifestação sobre a contestação.'),
        ], 'Cumprimento de sentença');

        self::assertSame('Prazo para réplica', $situacao->situacao);
        self::assertSame('Em andamento', $situacao->status);
        self::assertSame(SituacaoAtualOutput::STATUS_ANDAMENTO, $situacao->statusMod);
        self::assertSame('02/10/2026 · Intimação', $situacao->ultimo);
        self::assertSame('Réplica / manifestação da parte autora', $situacao->proximo);
        self::assertSame('Parte autora', $situacao->quem);
        self::assertSame('15 dias úteis', $situacao->prazo);
        self::assertSame('27/10/2026 · faltam 6 dias úteis', $situacao->previsao);
        self::assertSame('Conclusão para decisão / saneamento (CPC art. 357)', $situacao->posterior);
        self::assertSame('Média', $situacao->conf);
        self::assertSame('media', $situacao->confMod);
        self::assertSame('CPC arts. 350 e 351 · histórico de 2 movimentações', $situacao->base);
        self::assertSame('A classe cadastrada é "Cumprimento de sentença", mas a sequência é de fase de conhecimento. Confira a classe do processo.', $situacao->nota);
        self::assertSame(PrevisaoProcessual::DICA, $situacao->dica);
        self::assertSame('02/10/2026 Intimação', $situacao->chave);
        self::assertSame('Réplica / manifestação da parte autora', $situacao->previstoParaConferir);
    }

    #[TestDox('Réplica sem contestação no histórico: confiança Média e a nota do desenho')]
    public function testReplicaSemContestacaoNoHistorico(): void
    {
        $situacao = $this->prever([self::mov('2026-10-02', 'Intimação', 'Manifestação sobre a contestação.')]);

        self::assertSame('Média', $situacao->conf);
        self::assertSame('Não encontrei a contestação no histórico; confira se ela foi juntada.', $situacao->nota);
    }

    #[TestDox('Réplica sem contestação E classe de cumprimento: as duas notas, na ordem, e confiança Baixa')]
    public function testNotasSeSomam(): void
    {
        $situacao = $this->prever([self::mov('2026-10-02', 'Intimação', 'Manifestação sobre a contestação.')], 'Cumprimento de Sentença');

        self::assertSame('Baixa', $situacao->conf);
        self::assertSame(
            'Não encontrei a contestação no histórico; confira se ela foi juntada. A classe cadastrada é "Cumprimento de sentença", mas a sequência é de fase de conhecimento. Confira a classe do processo.',
            $situacao->nota,
        );
    }

    #[TestDox('Classe de cumprimento não rebaixa regra fora da fase de conhecimento (sentença)')]
    public function testClasseNaoRebaixaSentenca(): void
    {
        $situacao = $this->prever([self::mov('2026-10-14', 'Intimação', 'Sentença publicada.')], 'Cumprimento de sentença');

        self::assertSame('Alta', $situacao->conf);
        self::assertNull($situacao->nota);
    }

    #[TestDox('"Prazo de N dias" no texto vence o prazo da regra, com a nota exata')]
    public function testPrazoDoTextoVenceORegra(): void
    {
        $situacao = $this->prever([self::mov('2026-10-14', 'Citação', 'Cite-se a parte ré para contestar no prazo de 5 dias.')]);

        self::assertSame('Prazo para contestação', $situacao->situacao);
        self::assertSame('5 dias úteis', $situacao->prazo);
        self::assertSame('O texto fala em 5 dias; usei o prazo do texto.', $situacao->nota);
        // 14/10 (quarta): 15, 16, 19, 20, 21.
        self::assertSame('22/10/2026 · faltam 3 dias úteis', $situacao->previsao);
    }

    #[TestDox('Selo "Prazo encerrado" com "(encerrado)" quando o fim já passou')]
    public function testSeloPrazoEncerrado(): void
    {
        $situacao = $this->prever([self::mov('2026-09-01', 'Citação', 'Citação da parte ré.')]);

        self::assertSame('Prazo encerrado', $situacao->status);
        self::assertSame(SituacaoAtualOutput::STATUS_ENCERRADO, $situacao->statusMod);
        // 01/09 (terça) + 15 dias úteis, pulando o 07/09: 23/09.
        self::assertSame('24/09/2026 (encerrado)', $situacao->previsao);
    }

    #[TestDox('Selo "Prazo terminando" com até 3 dias úteis')]
    public function testSeloPrazoTerminando(): void
    {
        $situacao = $this->prever(
            [self::mov('2026-10-02', 'Citação', 'Citação da parte ré.')],
            hoje: '2026-10-22',
        );

        self::assertSame('Prazo terminando', $situacao->status);
        self::assertSame(SituacaoAtualOutput::STATUS_TERMINANDO, $situacao->statusMod);
        self::assertSame('27/10/2026 · faltam 3 dias úteis', $situacao->previsao);
    }

    #[TestDox('Sem regra: fase não identificada, sem prazo e sem data, confiança Baixa')]
    public function testSemRegra(): void
    {
        $situacao = $this->prever([
            self::mov('2026-10-14', 'Intimação', 'Vista dos autos ao Ministério Público.'),
            self::mov('2026-10-01', 'Edital', 'Edital publicado.'),
        ]);

        self::assertSame('Fase não identificada', $situacao->situacao);
        self::assertSame('Sem prazo legal para a parte', $situacao->status);
        self::assertSame(SituacaoAtualOutput::STATUS_ANDAMENTO, $situacao->statusMod, 'sem prazo, o verde do desenho');
        self::assertSame('Aguardar a próxima movimentação', $situacao->proximo);
        self::assertSame('', $situacao->quem);
        self::assertSame('Sem prazo legal definido', $situacao->prazo);
        self::assertSame('Sem data', $situacao->previsao);
        self::assertSame('Depende de ato do juízo', $situacao->posterior);
        self::assertSame('Baixa', $situacao->conf);
        self::assertSame('baixa', $situacao->confMod);
        self::assertSame('histórico de 2 movimentações', $situacao->base);
        self::assertSame('', $situacao->previstoParaConferir);
    }

    #[TestDox('A mais recente manda, em qualquer ordem de entrada')]
    public function testMaisRecenteManda(): void
    {
        $situacao = $this->prever([
            self::mov('2026-09-01', 'Citação', 'Citação da parte ré.'),
            self::mov('2026-10-14', 'Intimação', 'Sentença publicada.'),
            self::mov('2026-09-20', 'Intimação', 'Juntada de contestação.'),
        ]);

        self::assertSame('Prazo recursal', $situacao->situacao);
        self::assertSame('14/10/2026 · Intimação', $situacao->ultimo);
    }

    #[TestDox('O texto é lido sem acento, sem caixa e sem HTML, até 5.000 caracteres')]
    public function testNormalizacaoECorte(): void
    {
        $longo = str_repeat('a ', 2600) . 'Sentença publicada.';
        $situacao = $this->prever([self::mov('2026-10-14', 'Intimação', '<p>' . $longo . '</p>')]);
        self::assertSame('Fase não identificada', $situacao->situacao, 'o que passa de 5.000 caracteres não é lido');

        self::assertSame('intimacao <b> citacao', PrevisaoProcessual::normalizar('INTIMAÇÃO <b> CITAÇÃO'));
    }

    #[TestDox('Sem movimentação não há situação')]
    public function testSemMovimentacao(): void
    {
        self::assertNull($this->previsao->prever([], 'Procedimento Comum', new \DateTimeImmutable(self::HOJE)));
    }

    #[TestDox('Taxa de acertos: acertou se uma palavra longa do previsto aparece no que aconteceu (dc L.5105)')]
    public function testAcertou(): void
    {
        self::assertTrue(PrevisaoProcessual::acertou('Contestação da parte ré', 'juntada juntada de contestacao'));
        self::assertTrue(PrevisaoProcessual::acertou('Réplica / manifestação da parte autora', 'intimacao replica apresentada'));
        self::assertFalse(PrevisaoProcessual::acertou('Contestação da parte ré', 'despacho de mero expediente'));
        self::assertFalse(PrevisaoProcessual::acertou('Ato da vara', 'ato da vara'), 'palavras de até 4 letras não contam');
    }
}
