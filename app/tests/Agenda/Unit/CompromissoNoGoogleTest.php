<?php

declare(strict_types=1);

namespace App\Tests\Agenda\Unit;

use App\Agenda\Entity\Evento;
use App\Agenda\Service\CompromissoNoGoogle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompromissoNoGoogle::class)]
final class CompromissoNoGoogleTest extends TestCase
{
    private CompromissoNoGoogle $sut;

    protected function setUp(): void
    {
        $this->sut = new CompromissoNoGoogle();
    }

    #[TestDox('Compromisso com hora vai com a hora de parede e o fuso de Brasília; "Só eu" vai como particular')]
    public function testCorpoComHora(): void
    {
        $e = $this->evento('2030-03-10 10:00', '2030-03-10 11:30')->setVisibilidade(Evento::VISIBILIDADE_SOMENTE_EU)->setLocal('Fórum');

        $corpo = $this->sut->corpo($e);

        self::assertSame(['dateTime' => '2030-03-10T10:00:00', 'timeZone' => 'America/Sao_Paulo'], $corpo['start']);
        self::assertSame(['dateTime' => '2030-03-10T11:30:00', 'timeZone' => 'America/Sao_Paulo'], $corpo['end']);
        self::assertSame('private', $corpo['visibility']);
        self::assertSame('confirmed', $corpo['status']);
        self::assertSame('Fórum', $corpo['location']);
        self::assertArrayNotHasKey('recurrence', $corpo);
    }

    #[TestDox('Dia inteiro vai com data e fim exclusivo; repetição semanal com fim vira RRULE com UNTIL; cancelado vai cancelado')]
    public function testCorpoDiaInteiroERepeticao(): void
    {
        $e = $this->evento('2030-03-10 00:00', '2030-03-11 23:59:59')->setDiaInteiro(true)
            ->setRecorrente(true)->setTipoRecorrencia('semanal')->setFimRecorrencia(new \DateTimeImmutable('2030-06-30'))
            ->setStatus(Evento::STATUS_CANCELADO);

        $corpo = $this->sut->corpo($e);

        self::assertSame(['date' => '2030-03-10'], $corpo['start']);
        self::assertSame(['date' => '2030-03-12'], $corpo['end']);
        self::assertSame(['RRULE:FREQ=WEEKLY;UNTIL=20300630'], $corpo['recurrence'], 'dia inteiro: UNTIL é data (RFC 5545)');
        self::assertSame('cancelled', $corpo['status']);
    }

    #[TestDox('O que vem do Google com fuso diferente vira hora de Brasília; dia inteiro volta ao formato da Agenda')]
    public function testAplicarDatas(): void
    {
        $e = new Evento();
        $this->sut->aplicar(['summary' => ' Reunião ', 'start' => ['dateTime' => '2030-03-10T10:00:00-04:00'], 'end' => ['dateTime' => '2030-03-10T11:00:00-04:00']], $e);
        self::assertSame('Reunião', $e->getTitulo());
        self::assertSame('2030-03-10 11:00', $e->getDataInicio()?->format('Y-m-d H:i'));
        self::assertFalse($e->isDiaInteiro());

        $this->sut->aplicar(['summary' => 'Férias', 'start' => ['date' => '2030-07-01'], 'end' => ['date' => '2030-07-04']], $e);
        self::assertTrue($e->isDiaInteiro());
        self::assertSame('2030-07-01 00:00:00', $e->getDataInicio()?->format('Y-m-d H:i:s'));
        self::assertSame('2030-07-03 23:59:59', $e->getDataFim()?->format('Y-m-d H:i:s'));
    }

    #[TestDox('Cancelado no Google não apaga: vira a situação "cancelado"; e particular lá vira "Só eu" aqui, nunca o contrário')]
    public function testCanceladoEVisibilidade(): void
    {
        $e = $this->evento('2030-03-10 10:00', '2030-03-10 11:00');
        $this->sut->aplicar(['status' => 'cancelled'], $e);
        self::assertSame(Evento::STATUS_CANCELADO, $e->getStatus());
        self::assertSame('Audiência', $e->getTitulo(), 'o registro fica como estava');

        $this->sut->aplicar(['summary' => 'Audiência', 'visibility' => 'private', 'start' => ['dateTime' => '2030-03-10T10:00:00-03:00']], $e);
        self::assertSame(Evento::STATUS_AGENDADO, $e->getStatus(), 'reaberto no Google volta a agendado');
        self::assertSame(Evento::VISIBILIDADE_SOMENTE_EU, $e->getVisibilidade());
        $this->sut->aplicar(['summary' => 'Audiência', 'visibility' => 'public', 'start' => ['dateTime' => '2030-03-10T10:00:00-03:00']], $e);
        self::assertSame(Evento::VISIBILIDADE_SOMENTE_EU, $e->getVisibilidade(), 'o Google não torna público o que é "Só eu"');
    }

    #[TestDox('Repetição do Google que a Agenda não representa: entra como única, com observação, e fica só do Google')]
    public function testRepeticaoNaoSuportada(): void
    {
        $e = new Evento();
        $item = ['summary' => 'Plantão', 'description' => 'Escala', 'recurrence' => ['RRULE:FREQ=WEEKLY;INTERVAL=2'], 'start' => ['dateTime' => '2030-03-10T10:00:00-03:00']];

        self::assertFalse($this->sut->aplicar($item, $e));
        self::assertFalse($e->isRecorrente());
        self::assertSame("Escala\n\n" . CompromissoNoGoogle::REPETICAO_NAO_SUPORTADA, $e->getDescricao());

        self::assertTrue($this->sut->aplicar(['summary' => 'Reunião', 'recurrence' => ['RRULE:FREQ=MONTHLY;UNTIL=20310101T025959Z'], 'start' => ['dateTime' => '2030-03-10T10:00:00-03:00']], $e));
        self::assertSame('mensal', $e->getTipoRecorrencia());
        self::assertSame('2030-12-31', $e->getFimRecorrencia()?->format('Y-m-d'), 'UNTIL em UTC lido na data de Brasília');

        self::assertFalse($this->sut->aplicar(['summary' => 'Comitê', 'recurrence' => ['RRULE:FREQ=MONTHLY;BYDAY=2TU'], 'start' => ['dateTime' => '2030-03-12T10:00:00-03:00']], $e), 'segunda terça do mês não é "mensal" daqui');
        self::assertTrue($this->sut->aplicar(['summary' => 'Comitê', 'recurrence' => ['RRULE:FREQ=WEEKLY;BYDAY=TU'], 'start' => ['dateTime' => '2030-03-12T10:00:00-03:00']], $e), 'toda terça, começando numa terça, é semanal');
        self::assertFalse($this->sut->aplicar(['summary' => 'Comitê', 'recurrence' => ['RRULE:FREQ=WEEKLY;BYDAY=MO'], 'start' => ['dateTime' => '2030-03-12T10:00:00-03:00']], $e));
    }

    #[TestDox('Com hora, o UNTIL vai em UTC às 23:59:59 de Brasília (a última ocorrência da noite não some)')]
    public function testUntilComHora(): void
    {
        $e = $this->evento('2030-03-10 22:00', '2030-03-10 23:00')->setRecorrente(true)->setTipoRecorrencia('diario')->setFimRecorrencia(new \DateTimeImmutable('2030-03-20'));

        self::assertSame(['RRULE:FREQ=DAILY;UNTIL=20300321T025959Z'], $this->sut->corpo($e)['recurrence']);
    }

    #[TestDox('Eco do envio: o que volta igual ao último resumo enviado é reconhecido; mudança de verdade não')]
    public function testEco(): void
    {
        $e = $this->evento('2030-03-10 10:00', '2030-03-10 11:00');
        $corpo = $this->sut->corpo($e);
        $e->sincronizadoComGoogle('g1', $this->sut->resumo($e));
        $e->setTitulo('Editado aqui depois');

        self::assertTrue($this->sut->ehEcoDoEnvio($corpo, $e));
        self::assertSame('Editado aqui depois', $e->getTitulo(), 'a conferência não mexe no compromisso');
        self::assertFalse($this->sut->ehEcoDoEnvio(['summary' => 'Mudou no Google'] + $corpo, $e));
    }

    #[TestDox('Ida e volta não muda o resumo: o que veio do Google não é devolvido a ele')]
    public function testIdaEVoltaEstavel(): void
    {
        $e = $this->evento('2030-03-10 10:00', '2030-03-10 11:00')->setRecorrente(true)->setTipoRecorrencia('anual');
        $resumo = $this->sut->resumo($e);
        $corpo = $this->sut->corpo($e);
        $corpo['start']['dateTime'] .= '-03:00';
        $corpo['end']['dateTime'] .= '-03:00';

        $this->sut->aplicar($corpo, $e);

        self::assertSame($resumo, $this->sut->resumo($e));
    }

    private function evento(string $inicio, string $fim): Evento
    {
        return (new Evento())->setTitulo('Audiência')->setDataInicio(new \DateTimeImmutable($inicio))->setDataFim(new \DateTimeImmutable($fim));
    }
}
