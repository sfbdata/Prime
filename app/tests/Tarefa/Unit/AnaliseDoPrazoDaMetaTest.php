<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use App\Agenda\Service\CalendarioDoSistema;
use App\Agenda\Service\DiasUteisNacionais;
use App\Tarefa\DTO\AnaliseDoPrazo;
use App\Tarefa\DTO\EntradaDaAnaliseDoPrazo;
use App\Tarefa\Enum\EstadoDoPrazoJuridico;
use App\Tarefa\Service\AnaliseDoPrazoDaMeta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O motor `IA_PRAZO` do desenho 02 (dc 3971-4026), com o calendário de dias úteis do sistema. Hoje é segunda,
 * 19/10/2026; 02/11 (Finados) é feriado nacional.
 */
#[CoversClass(AnaliseDoPrazoDaMeta::class)]
final class AnaliseDoPrazoDaMetaTest extends TestCase
{
    private const HOJE = '2026-10-19';

    #[TestDox('Contestação com a ciência informada: 15 dias úteis (CPC 335) pulando Finados, folga de 4, estado estimado')]
    public function testContestacaoComCiencia(): void
    {
        $a = $this->analisar('Contestação', ciencia: '2026-10-19');

        self::assertSame('Contestação', $a?->tipo);
        self::assertSame('2026-11-10', $a?->prazoJuridico?->format('Y-m-d'));
        self::assertSame(EstadoDoPrazoJuridico::Estimado, $a?->estado);
        self::assertFalse($a?->aproximado);
        self::assertSame('2026-11-04', $a?->prazoInterno->format('Y-m-d'));
        self::assertSame(4, $a?->folga);
        self::assertSame(15, $a?->diasUteisAteOJuridico);
        self::assertSame(1, $a?->feriadosNoPeriodo, 'Finados');
        self::assertSame('ciencia', $a?->origem);
        self::assertStringStartsWith('Contei 15 dias úteis (CPC, art. 335) a partir do termo inicial 19/10/2026, dia da ciência/intimação informado por você.', (string) $a?->porque);
    }

    #[TestDox('Só a publicação: o termo é o 1º dia útil seguinte')]
    public function testSoPublicacao(): void
    {
        $a = $this->analisar('Manifestação sobre o laudo', publicacao: '2026-10-23');

        self::assertSame('2026-10-26', $a?->termoInicial?->format('Y-m-d'), 'publicada na sexta, termo na segunda');
        // O 1º dia útil depois da publicação já é o dia 1 (CPC 224 § 3º): 26, 27, 28, 29 e 30/10. É a mesma data
        // da "Situação atual" do Push; o desenho contava a partir do termo e dava 03/11 (um dia útil a mais).
        self::assertSame('2026-10-30', $a?->prazoJuridico?->format('Y-m-d'));
        self::assertEquals((new DiasUteisNacionais(new CalendarioDoSistema()))->somarUteis(new \DateTimeImmutable('2026-10-23'), 5), $a?->prazoJuridico);
        self::assertSame('publicacao', $a?->origem);
    }

    #[TestDox('Sem termo inicial: conta de hoje, aproximado, e pede conferência ("nunca afirma o que não sabe")')]
    public function testSemTermoPedeConferencia(): void
    {
        $a = $this->analisar('Apresentar contestação');

        self::assertSame(EstadoDoPrazoJuridico::NecessitaConferencia, $a?->estado);
        self::assertTrue($a?->aproximado);
        self::assertStringContainsString('só como estimativa; confira a intimação', (string) $a?->porque);
    }

    #[TestDox('Data escrita no texto vale como prazo informado; folga do tipo antes dela')]
    public function testDataEscrita(): void
    {
        $a = $this->analisar("Protocolar petição de juntada\naté 23/10");

        self::assertSame('Petição', $a?->tipo);
        self::assertSame('2026-10-23', $a?->prazoJuridico?->format('Y-m-d'));
        self::assertSame(EstadoDoPrazoJuridico::Informado, $a?->estado);
        self::assertSame('2026-10-22', $a?->prazoInterno->format('Y-m-d'));
        self::assertStringStartsWith('Encontrei data 23/10/2026 escrita na meta.', (string) $a?->porque);
    }

    /** @return iterable<string, array{string, string}> */
    public static function datasNoTexto(): iterable
    {
        yield 'amanhã é o próximo dia útil' => ['Retornar ao cliente amanhã', '2026-10-20'];
        yield 'em 3 dias úteis' => ['Juntar documentos em 3 dias uteis', '2026-10-22'];
        yield 'em 10 dias sem qualificador conta úteis (CPC 219), pula Finados' => ['Juntar documentos em 10 dias', '2026-11-03'];
        yield 'em 10 dias corridos escrito' => ['Juntar documentos em 10 dias corridos', '2026-10-29'];
        yield 'até sexta' => ['Ligar para o cliente até sexta', '2026-10-23'];
        yield 'data passada sem ano vira o ano seguinte' => ['Atualizar cadastro até 10/01', '2027-01-10'];
        yield 'ano com 2 dígitos' => ['Atualizar cadastro até 05/03/27', '2027-03-05'];
        yield 'hoje' => ['Ligar para o cliente hoje', '2026-10-19'];
        yield 'prazo de N dias sem qualificador conta úteis' => ['Juntar procuração, prazo de 5 dias', '2026-10-26'];
        yield 'até a sexta' => ['Retornar ao cliente até a sexta', '2026-10-23'];
        yield 'em 0 dias úteis é hoje' => ['Juntar documentos em 0 dias uteis', '2026-10-19'];
        yield 'data inválida cai na próxima regra' => ['Atualizar cadastro 15/13 hoje', '2026-10-19'];
    }

    #[DataProvider('datasNoTexto')]
    #[TestDox('Formas de data no texto, como o desenho as entende')]
    public function testFormasDeData(string $texto, string $esperado): void
    {
        self::assertSame($esperado, $this->analisar($texto)?->prazoJuridico?->format('Y-m-d'));
    }

    #[TestDox('Prazo informado pela pessoa vale; a regra mostra a alternativa pela origem; confirmado não troca')]
    public function testInformadoEAlternativa(): void
    {
        $a = $this->analisar('Contestação', ciencia: '2026-10-19', informado: '2026-11-06', confirmado: true);

        self::assertSame('2026-11-06', $a?->prazoJuridico?->format('Y-m-d'));
        self::assertSame(EstadoDoPrazoJuridico::Confirmado, $a?->estado);
        self::assertSame('2026-11-10', $a?->alternativa?->format('Y-m-d'));
        self::assertStringStartsWith('Usei o prazo jurídico confirmado por você (06/11/2026).', (string) $a?->porque);
    }

    #[TestDox('Urgência antecipa o prazo interno para o próximo dia útil e sobe a prioridade')]
    public function testUrgencia(): void
    {
        $a = $this->analisar('Manifestação urgente sobre a tutela', ciencia: '2026-10-19');

        self::assertTrue($a?->urgente);
        self::assertSame('2026-10-20', $a?->prazoInterno->format('Y-m-d'));
        self::assertSame('Crítico', $a?->prioridade);
        self::assertStringContainsString('antecipei o prazo interno para o próximo dia útil', (string) $a?->porque);
    }

    #[TestDox('Sem prazo legal no tipo: prazo interno pela natureza da tarefa, sem prazo jurídico')]
    public function testSemPrazoLegal(): void
    {
        $a = $this->analisar('Ligar para o cliente sobre o andamento');

        self::assertSame('Retorno ao cliente', $a?->tipo);
        self::assertNull($a?->prazoJuridico);
        self::assertSame(EstadoDoPrazoJuridico::SemPrazoLegal, $a?->estado);
        self::assertSame('2026-10-22', $a?->prazoInterno->format('Y-m-d'));
        self::assertNull($a?->folga);
        self::assertSame('Atenção', $a?->prioridade, 'peso 1 (2 pontos) + 3 dias úteis até o interno (2 pontos)');
    }

    #[TestDox('O recesso de 20/12 a 20/01 não conta como dia útil')]
    public function testRecesso(): void
    {
        $a = (new AnaliseDoPrazoDaMeta(new DiasUteisNacionais(new CalendarioDoSistema())))->analisar(new EntradaDaAnaliseDoPrazo('Embargos de declaração', new \DateTimeImmutable('2026-12-17'), ciencia: new \DateTimeImmutable('2026-12-17')));

        self::assertSame('2027-01-26', $a?->prazoJuridico?->format('Y-m-d'), '18/12 e depois de 21/01: 18, 21, 22, 25, 26');
    }

    #[TestDox('Texto curto demais: nada a dizer (o desenho esconde a análise)')]
    public function testTextoCurto(): void
    {
        self::assertNull($this->analisar('ok'));
    }

    #[TestDox('Data escrita e ciência juntas: vale a data do texto, e a explicação diz isso (não uma contagem que não fez)')]
    public function testDataDoTextoVenceOTermo(): void
    {
        $a = $this->analisar("Contestação\naté 30/10", ciencia: '2026-10-19');

        self::assertSame('2026-10-30', $a?->prazoJuridico?->format('Y-m-d'));
        self::assertStringStartsWith('Encontrei data 30/10/2026 escrita na meta.', (string) $a?->porque);
    }

    #[TestDox('O tipo sai do título antes do corpo; informado sem confirmar; folga zero mantém a data; alternativa só quando difere')]
    public function testDemaisRegras(): void
    {
        self::assertSame('Apelação', $this->analisar("Apelação\nmanifestação do cliente")?->tipo);

        $informado = $this->analisar('Contestação', ciencia: '2026-10-19', informado: '2026-11-10');
        self::assertSame(EstadoDoPrazoJuridico::Informado, $informado?->estado);
        self::assertNull($informado?->alternativa, 'igual à calculada pela origem');

        $semFolga = $this->analisar('Analisar publicação até 23/10');
        self::assertSame(0, $semFolga?->folga);
        self::assertSame('2026-10-23', $semFolga?->prazoInterno->format('Y-m-d'));
        self::assertStringContainsString('Mantive a data como prazo interno.', (string) $semFolga?->porque);
    }

    #[TestDox('Prazo jurídico já vencido: o interno fica em hoje e a prioridade é Crítico')]
    public function testPrazoVencido(): void
    {
        $a = $this->analisar('Contestação', informado: '2026-10-16');

        self::assertSame('2026-10-19', $a?->prazoInterno->format('Y-m-d'));
        self::assertSame('Crítico', $a?->prioridade);
    }

    private function analisar(string $texto, ?string $publicacao = null, ?string $ciencia = null, ?string $informado = null, bool $confirmado = false): ?AnaliseDoPrazo
    {
        $d = static fn (?string $v): ?\DateTimeImmutable => $v === null ? null : new \DateTimeImmutable($v);

        return (new AnaliseDoPrazoDaMeta(new DiasUteisNacionais(new CalendarioDoSistema())))
            ->analisar(new EntradaDaAnaliseDoPrazo($texto, new \DateTimeImmutable(self::HOJE . ' 10:00'), $d($publicacao), $d($ciencia), $d($informado), $confirmado));
    }
}
