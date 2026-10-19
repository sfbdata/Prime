<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\DTO\CobrancaDoDia;
use App\Pasta\DTO\PendenciaDoChecklist;
use App\Pasta\Entity\Pasta;
use App\Pasta\Service\MensagensDaCobrancaDoChecklist;
use App\Pasta\Service\PlanoDeCobrancaDoChecklist;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** As regras do `ckCobPlano` e do `cobrarChecklist` do desenho 02 (dc 4278-4372). */
#[CoversClass(PlanoDeCobrancaDoChecklist::class)]
#[CoversClass(MensagensDaCobrancaDoChecklist::class)]
final class PlanoDeCobrancaDoChecklistTest extends TestCase
{
    private const QUARTA = '2026-10-21';

    /** @return iterable<string, array{string, int}> */
    public static function pesos(): iterable
    {
        yield 'procuração' => ['PROCURAÇÃO AD JUDICIA', 3];
        yield 'contrato' => ['CONTRATO DE HONORÁRIOS', 3];
        yield 'hipossuficiência' => ['DECLARAÇÃO DE HIPOSSUFICIÊNCIA', 2];
        yield 'RG' => ['RG DO AUTOR', 2];
        yield 'CPF' => ['CPF', 2];
        yield 'outro' => ['COMPROVANTE DE RESIDÊNCIA', 1];
    }

    #[DataProvider('pesos')]
    #[TestDox('Peso do item pelo título, como o CK_PESO do desenho')]
    public function testPeso(string $titulo, int $peso): void
    {
        self::assertSame($peso, PlanoDeCobrancaDoChecklist::peso($titulo));
    }

    #[TestDox('Fim de semana e feriado (dia não útil) não cobram nada')]
    public function testSemDiaUtil(): void
    {
        self::assertSame([], (new PlanoDeCobrancaDoChecklist())->doDia([$this->p(1, 'PROCURAÇÃO')], new \DateTimeImmutable(self::QUARTA), false));
    }

    #[TestDox('Urgentes todo dia; dos demais, um por dia; teto de 2 por pasta')]
    public function testTetoEUrgentes(): void
    {
        $hoje = new \DateTimeImmutable(self::QUARTA);
        $plano = (new PlanoDeCobrancaDoChecklist())->doDia([
            $this->p(1, 'COMPROVANTE DE RESIDÊNCIA'),
            $this->p(2, 'PROCURAÇÃO'),
            $this->p(3, 'CONTRATO'),
            $this->p(4, 'CERTIDÃO'),
        ], $hoje, true);

        self::assertSame([2, 3], array_map(static fn (CobrancaDoDia $c): int => $c->pendencia->itemId, $plano));
        self::assertSame([1, 1], array_map(static fn (CobrancaDoDia $c): int => $c->nivel, $plano));
    }

    #[TestDox('Não urgente: um por dia na pasta, com 2 dias entre cobranças do mesmo item')]
    public function testIntervaloDosNaoUrgentes(): void
    {
        $plano = new PlanoDeCobrancaDoChecklist();
        $hoje = new \DateTimeImmutable(self::QUARTA);

        self::assertSame([], $plano->doDia([$this->p(1, 'CERTIDÃO', ['2026-10-20'])], $hoje, true), 'cobrado ontem: espera');
        self::assertCount(1, $plano->doDia([$this->p(1, 'CERTIDÃO', ['2026-10-19'])], $hoje, true), 'cobrado há 2 dias: cobra');
        self::assertSame([], $plano->doDia([$this->p(1, 'CERTIDÃO', ['2026-10-19', self::QUARTA]), $this->p(2, 'LAUDO')], $hoje, true), 'já saiu um não urgente hoje');
    }

    #[TestDox('O que já saiu hoje conta no teto e não repete (rodar duas vezes no dia não duplica)')]
    public function testIdempotenteNoDia(): void
    {
        $plano = (new PlanoDeCobrancaDoChecklist())->doDia([
            $this->p(1, 'PROCURAÇÃO', [self::QUARTA]),
            $this->p(2, 'CONTRATO'),
            $this->p(3, 'RG', [], '-10 days'),
        ], new \DateTimeImmutable(self::QUARTA), true);

        self::assertSame([2], array_map(static fn (CobrancaDoDia $c): int => $c->pendencia->itemId, $plano));
    }

    #[TestDox('Previsão no futuro pausa; vencida vira urgente e marca a quebra')]
    public function testPrevisao(): void
    {
        $plano = new PlanoDeCobrancaDoChecklist();
        $hoje = new \DateTimeImmutable(self::QUARTA);

        self::assertSame([], $plano->doDia([$this->p(1, 'CERTIDÃO', [], 'today', '2026-10-21')], $hoje, true), 'até hoje: pausado');
        $quebrou = $plano->doDia([$this->p(1, 'CERTIDÃO', ['2026-10-20'], 'today', '2026-10-20')], $hoje, true);
        self::assertCount(1, $quebrou);
        self::assertTrue($quebrou[0]->quebrou);
        self::assertTrue(PlanoDeCobrancaDoChecklist::copiaCoordenacao($quebrou[0]));
    }

    #[TestDox('7 dias pendente sobe o peso: hipossuficiência vira urgente')]
    public function testPesoSobeComOTempo(): void
    {
        $hoje = new \DateTimeImmutable(self::QUARTA);
        $velho = $this->p(1, 'HIPOSSUFICIÊNCIA', ['2026-10-20'], '2026-10-14');

        self::assertCount(1, (new PlanoDeCobrancaDoChecklist())->doDia([$velho], $hoje, true));
    }

    #[TestDox('A coordenação vai junto no 4º, 7º e 10º aviso')]
    public function testCopiaDaCoordenacao(): void
    {
        $niveis = array_filter(range(1, 11), fn (int $n): bool => PlanoDeCobrancaDoChecklist::copiaCoordenacao(new CobrancaDoDia($this->p(1, 'X'), $n, false, 0)));

        self::assertSame([4, 7, 10], array_values($niveis));
    }

    #[TestDox('Textos literais do desenho: o tom sobe por nível e termina com a pergunta da data')]
    public function testMensagens(): void
    {
        $pasta = (new Pasta())->setNup('0001')->setNomeCliente('Maria da Silva Souza');
        [$t1, $x1] = MensagensDaCobrancaDoChecklist::cobranca(new CobrancaDoDia($this->p(1, 'PROCURAÇÃO'), 1, false, 3), $pasta);
        [$t4] = MensagensDaCobrancaDoChecklist::cobranca(new CobrancaDoDia($this->p(1, 'PROCURAÇÃO'), 4, false, 3), $pasta);
        [$t6] = MensagensDaCobrancaDoChecklist::cobranca(new CobrancaDoDia($this->p(1, 'PROCURAÇÃO'), 6, false, 3), $pasta);
        [$tq, $xq] = MensagensDaCobrancaDoChecklist::cobranca(new CobrancaDoDia($this->p(1, 'PROCURAÇÃO', [], 'today', '2026-10-20', 1), 2, true, 3), $pasta);

        self::assertSame('Documento pendente: PROCURAÇÃO', $t1);
        self::assertSame('A pasta 0001 (MARIA DA) está com a documentação incompleta há 3 dias. Providencie hoje. Até que dia você resolve? Responda com a data aqui no Chat I.A, na conversa com a BlueJus IA (ex.: até 08/10).', $x1);
        self::assertSame('Último aviso antes da coordenação: PROCURAÇÃO', $t4);
        self::assertSame('6º aviso sem retorno: PROCURAÇÃO', $t6);
        self::assertSame('Você não cumpriu o prazo: PROCURAÇÃO', $tq);
        self::assertStringContainsString('até 20/10 e não resolveu. É a 2ª vez que a data informada não é cumprida.', $xq);
    }

    #[TestDox('Textos literais dos níveis 2, 3 e 5, da coordenação, do "sem anexo", do desativar e do lembrete')]
    public function testDemaisTextos(): void
    {
        $pasta = (new Pasta())->setNup('0001')->setNomeCliente('Maria da Silva Souza');
        $c = fn (int $n, int $dias = 3): CobrancaDoDia => new CobrancaDoDia($this->p(1, 'RG'), $n, false, $dias);

        self::assertSame(['Segundo aviso: RG', 'RG continua pendente na pasta 0001 (MARIA DA). Sem esse documento a pasta não avança. Preciso disso resolvido hoje. Até que dia você resolve? Responda com a data aqui no Chat I.A, na conversa com a BlueJus IA (ex.: até 08/10).'], MensagensDaCobrancaDoChecklist::cobranca($c(2), $pasta));
        self::assertSame('Estou insistindo e não estou vendo retorno. RG segue pendente há 1 dia na pasta 0001 (MARIA DA). Isso já virou atraso. Resolva agora ou registre o impedimento na pasta. Até que dia você resolve? Responda com a data aqui no Chat I.A, na conversa com a BlueJus IA (ex.: até 08/10).', MensagensDaCobrancaDoChecklist::cobranca($c(3, 1), $pasta)[1]);
        self::assertSame('Já são 4 cobranças ignoradas. RG segue pendente desde hoje na pasta 0001 (MARIA DA) e a coordenação está acompanhando. Resolva hoje ou registre por escrito o que está impedindo. Até que dia você resolve? Responda com a data aqui no Chat I.A, na conversa com a BlueJus IA (ex.: até 08/10).', MensagensDaCobrancaDoChecklist::cobranca($c(5, 0), $pasta)[1]);
        self::assertSame(['Documento sem retorno: RG', 'Ana recebeu 4 cobranças e o item segue pendente há 3 dias na pasta 0001.'], MensagensDaCobrancaDoChecklist::paraCoordenacao($c(4), $pasta, 'Ana'));
        self::assertSame(['Item marcado sem anexo: RG', 'Você marcou RG como entregue na pasta 0001, mas não há nenhum documento correspondente anexado. Marcar sem juntar o documento não resolve a pendência. A pasta será auditada. Anexe o documento hoje.'], MensagensDaCobrancaDoChecklist::semAnexoParaQuemMarcou('RG', $pasta));
        self::assertSame(['Pasta 0001 para auditoria', 'Ana marcou RG como entregue sem anexar o documento. Recomendo auditar a pasta 0001 (MARIA DA SILVA SOUZA).'], MensagensDaCobrancaDoChecklist::semAnexoParaControladoria('RG', $pasta, 'Ana'));
        self::assertSame(['Checklist desativado: Pasta 0001', 'Ana desativou o checklist de documentação (Pasta encerrada) com 2 pendências em aberto.'], MensagensDaCobrancaDoChecklist::desativado($pasta, 'Ana', 'Pasta encerrada', 2));
        self::assertSame(['Revisar checklist da Pasta 0001', 'O checklist de documentação está desativado há 31 dias (Pasta encerrada). Sem ele, o sistema não confere os documentos necessários para instruir o processo. Reative ou confirme que continua não se aplicando.'], MensagensDaCobrancaDoChecklist::lembrete($pasta, 31, 'Pasta encerrada'));
    }

    #[TestDox('Empate de peso: cobra primeiro o que foi cobrado há mais tempo')]
    public function testDesempatePeloMaisAntigo(): void
    {
        $plano = (new PlanoDeCobrancaDoChecklist())->doDia([
            $this->p(1, 'PROCURAÇÃO', ['2026-10-20']),
            $this->p(2, 'CONTRATO', ['2026-10-16']),
            $this->p(3, 'PROCURAÇÃO DO SÓCIO', ['2026-10-19']),
        ], new \DateTimeImmutable(self::QUARTA), true);

        self::assertSame([2, 3], array_map(static fn (CobrancaDoDia $c): int => $c->pendencia->itemId, $plano));
    }

    /** @param list<string> $envios */
    private function p(int $id, string $titulo, array $envios = [], string $desde = 'today', ?string $previsao = null, int $quebras = 0): PendenciaDoChecklist
    {
        $base = new \DateTimeImmutable(self::QUARTA);
        $inicio = $desde === 'today' ? $base : ($desde[0] === '-' ? $base->modify($desde) : new \DateTimeImmutable($desde));

        return new PendenciaDoChecklist($id, $titulo, $inicio, $envios, $previsao === null ? null : new \DateTimeImmutable($previsao), $quebras);
    }
}
