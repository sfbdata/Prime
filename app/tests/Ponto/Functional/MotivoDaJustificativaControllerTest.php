<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Controller\PontoController;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Form\JustificativaPontoType;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * E4 do desenho do Ponto (spec docs/specs/ponto-desenho-0811.md, P44, P46 e P47): o "Motivo" das janelas, obrigatório
 * no envio do colaborador e gravado em cada dia do lote; o horário do esquecimento com segundos; e a volta da edição na
 * aba "Minhas Justificativas", no mês da justificativa (achado da revisão da E3). Revisão da E4: a edição guarda os
 * segundos do esquecimento quando o H:i não muda, e o motivo editado vai para os dias em análise do lote.
 */
#[Group('ponto')]
#[CoversClass(PontoController::class)]
#[CoversClass(JustificativaPontoType::class)]
final class MotivoDaJustificativaControllerTest extends JusPrimeWebTestCase
{
    use CenarioDeJustificativaTrait;

    private KernelBrowser $client;
    private Tenant $escritorio;
    private User $eu;
    private \DateTimeImmutable $mesPassado;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->instalarCsrfPrevisivel();
        $this->escritorio = $this->escritorio('Escritório Motivo');
        $this->eu         = $this->pessoa('motivo');
        $this->vincularColaborador($this->eu, $this->escritorio);
        $this->mesPassado = new \DateTimeImmutable('first day of last month');
        $this->logarComTenant($this->client, $this->eu, $this->escritorio);
    }

    #[TestDox('Justificar falta com motivo: o texto (sem os espaços das pontas) vai para cada dia do lote')]
    public function testMotivoGravadoEmCadaDiaDoLote(): void
    {
        $dias = [$this->mesPassado->modify('+2 days'), $this->mesPassado->modify('+3 days')];

        $this->justificar(['datas' => $this->chaves($dias), 'motivo' => "  Consulta médica e exame.\n  "]);

        self::assertResponseRedirects('/ponto/?competencia=' . $this->mesPassado->format('Y-m'));
        $linhas = $this->linhas();
        self::assertCount(2, $linhas);
        self::assertSame(['Consulta médica e exame.', 'Consulta médica e exame.'], array_column($linhas, 'motivo'));
        self::assertCount(1, array_unique(array_column($linhas, 'batch_id')), 'um lote só');
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function motivosRecusados(): iterable
    {
        yield 'sem o campo' => [[], 'Informe o motivo.'];
        yield 'em branco' => [['motivo' => "   \n "], 'Informe o motivo.'];
        yield 'longo demais' => [['motivo' => str_repeat('a', JustificativaPontoType::MOTIVO_MAXIMO + 1)], 'O motivo pode ter até 2000 caracteres.'];
    }

    /** @param array<string, string> $motivo */
    #[TestDox('Justificar falta sem motivo válido ($_dataName): nada nasce e a frase volta no aviso')]
    #[DataProvider('motivosRecusados')]
    public function testSemMotivoNadaNasce(array $motivo, string $frase): void
    {
        $this->justificar(['datas' => $this->mesPassado->modify('+2 days')->format('Y-m-d')] + $motivo);

        self::assertResponseRedirects('/ponto/?competencia=' . $this->mesPassado->format('Y-m'));
        self::assertSame([], $this->linhas());
        self::assertContains($frase, $this->flashes()['danger'] ?? []);
    }

    #[TestDox('Registro de esquecimento: o horário com segundos é gravado inteiro (P46); só os minutos viram :00')]
    public function testHorarioDoEsquecimentoComSegundos(): void
    {
        $this->esquecimento($this->mesPassado->modify('+2 days'), '14:32:10');
        $this->esquecimento($this->mesPassado->modify('+3 days'), '18:05');

        $horas = array_column($this->linhas(), 'hora_registro_esquecido', 'data');
        self::assertSame('14:32:10', $horas[$this->mesPassado->modify('+2 days')->format('Y-m-d')]);
        self::assertSame('18:05:00', $horas[$this->mesPassado->modify('+3 days')->format('Y-m-d')]);
        self::assertSame(['esquecimento_registro', 'esquecimento_registro'], array_column($this->linhas(), 'tipo'));
        self::assertSame(['Esqueci de bater a saída.', 'Esqueci de bater a saída.'], array_column($this->linhas(), 'motivo'));
    }

    #[TestDox('Registro de esquecimento sem motivo: nada nasce')]
    public function testEsquecimentoSemMotivo(): void
    {
        $this->esquecimento($this->mesPassado->modify('+2 days'), '14:32:10', null);

        self::assertSame([], $this->linhas());
        self::assertContains('Informe o motivo.', $this->flashes()['danger'] ?? []);
    }

    #[TestDox('Editar grava o motivo e volta na aba Minhas Justificativas, no mês da justificativa (não no mês de hoje)')]
    public function testEdicaoGravaOMotivoEVoltaNaAba(): void
    {
        $j = $this->justificativa($this->eu, $this->escritorio, $this->mesPassado->modify('+4 days'), 'pendente', ['motivo' => 'Antigo.']);

        $this->editar($j, ['tipo' => 'licenca_gala', 'motivo' => '  Motivo novo.  ']);

        self::assertResponseRedirects(sprintf('/ponto/?competencia=%s&aba=justificativas', $this->mesPassado->format('Y-m')));
        self::assertContains('Justificativa atualizada com sucesso.', $this->flashes()['success'] ?? []);
        $linha = $this->linhas()[0];
        self::assertSame('Motivo novo.', $linha['motivo']);
        self::assertSame('licenca_gala', $linha['tipo']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function motivosRecusadosNaEdicao(): iterable
    {
        yield 'em branco' => ['  ', 'Informe o motivo.'];
        yield 'longo demais' => [str_repeat('a', JustificativaPontoType::MOTIVO_MAXIMO + 1), 'O motivo pode ter até 2000 caracteres.'];
    }

    #[TestDox('Editar com motivo $_dataName: nada muda (nem o tipo), o aviso explica e a tela volta na aba')]
    #[DataProvider('motivosRecusadosNaEdicao')]
    public function testEdicaoRecusaMotivoInvalido(string $motivo, string $frase): void
    {
        $j = $this->justificativa($this->eu, $this->escritorio, $this->mesPassado->modify('+4 days'), 'pendente', ['motivo' => 'Antigo.']);

        $this->editar($j, ['tipo' => 'licenca_gala', 'motivo' => $motivo]);

        self::assertResponseRedirects(sprintf('/ponto/?competencia=%s&aba=justificativas', $this->mesPassado->format('Y-m')));
        self::assertContains($frase, $this->flashes()['warning'] ?? []);
        self::assertArrayNotHasKey('success', $this->flashes());
        $linha = $this->linhas()[0];
        self::assertSame('Antigo.', $linha['motivo']);
        self::assertSame('atestado_medico', $linha['tipo'], 'a recusa descarta a edição inteira');
    }

    #[TestDox('Editar sem o campo motivo (pedido de antes da E4) não apaga o motivo gravado')]
    public function testEdicaoSemOCampoMantemOMotivo(): void
    {
        $j = $this->justificativa($this->eu, $this->escritorio, $this->mesPassado->modify('+4 days'), 'pendente', ['motivo' => 'Antigo.']);

        $this->editar($j, ['tipo' => 'licenca_gala']);

        self::assertResponseRedirects(sprintf('/ponto/?competencia=%s&aba=justificativas', $this->mesPassado->format('Y-m')));
        self::assertSame('Antigo.', $this->linhas()[0]['motivo']);
        self::assertSame('licenca_gala', $this->linhas()[0]['tipo']);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function horariosDaEdicao(): iterable
    {
        yield 'só o motivo (mesmo tipo e mesmo H:i): os segundos ficam' => ['saida', '14:32', '14:32:10'];
        yield 'outro H:i: regravado com :00' => ['saida', '15:00', '15:00:00'];
        yield 'outra batida no mesmo H:i: regravado com :00' => ['entrada', '14:32', '14:32:00'];
    }

    #[TestDox('Editar o esquecimento gravado às 14:32:10 ($_dataName)')]
    #[DataProvider('horariosDaEdicao')]
    public function testEdicaoDoEsquecimentoGuardaOsSegundos(string $batida, string $horaEnviada, string $horaGravada): void
    {
        $j = $this->justificativa($this->eu, $this->escritorio, $this->mesPassado->modify('+4 days'), 'pendente', ['tipo' => 'esquecimento_registro', 'tipoRegistroEsquecido' => 'saida', 'motivo' => 'Antigo.']);
        $j->setHoraRegistroEsquecido(new \DateTime('1970-01-01 14:32:10'));
        $this->em()->flush();

        $this->editar($j, ['tipo' => 'esquecimento_registro', 'tipoRegistroEsquecido' => $batida, 'horaRegistroEsquecido' => $horaEnviada, 'motivo' => 'Motivo novo.']);

        self::assertContains('Justificativa atualizada com sucesso.', $this->flashes()['success'] ?? []);
        $linha = $this->linhas()[0];
        self::assertSame($horaGravada, $linha['hora_registro_esquecido']);
        self::assertSame('Motivo novo.', $linha['motivo']);
    }

    #[TestDox('Editar o motivo pelo 1º dia do lote muda os 3 dias; o lote de outra pessoa e o de outro escritório (mesmo batchId) não')]
    public function testMotivoEditadoVaiParaOLote(): void
    {
        $lote = 'lote-' . uniqid();
        $dias = $this->loteDeTresDias($lote, ['pendente', 'pendente', 'pendente']);
        $colega = $this->pessoa('colega-lote');
        $this->vincularColaborador($colega, $this->escritorio);
        $doColega = $this->justificativa($colega, $this->escritorio, $this->mesPassado->modify('+2 days'), 'pendente', ['batchId' => $lote, 'motivo' => 'Do colega.']);
        $deOutroEscritorio = $this->justificativa($this->eu, $this->escritorio('Outro Escritório Lote'), $this->mesPassado->modify('+2 days'), 'pendente', ['batchId' => $lote, 'motivo' => 'De outro escritório.']);

        $this->editar($dias[0], ['tipo' => 'atestado_medico', 'motivo' => '  Motivo novo do lote.  ']);

        self::assertContains('Justificativa atualizada com sucesso.', $this->flashes()['success'] ?? []);
        $motivos = $this->motivosDoLote($lote);
        foreach ($dias as $dia) {
            self::assertSame('Motivo novo do lote.', $motivos[$dia->getId()]);
        }
        self::assertSame('Do colega.', $motivos[$doColega->getId()], 'lote de outra pessoa não é tocado');
        self::assertSame('De outro escritório.', $motivos[$deOutroEscritorio->getId()], 'lote de outro escritório não é tocado');
    }

    #[TestDox('Lote com um dia já abonado: o motivo novo vai para os dias em análise; o abonado guarda o antigo')]
    public function testDiaAnalisadoDoLoteGuardaOMotivo(): void
    {
        $lote = 'lote-' . uniqid();
        [$primeiro, $abonado, $terceiro] = $this->loteDeTresDias($lote, ['pendente', 'abonado', 'pendente']);

        $this->editar($primeiro, ['tipo' => 'atestado_medico', 'motivo' => 'Motivo novo do lote.']);

        self::assertContains('Justificativa atualizada com sucesso.', $this->flashes()['success'] ?? []);
        $motivos = $this->motivosDoLote($lote);
        self::assertSame('Motivo novo do lote.', $motivos[$primeiro->getId()]);
        self::assertSame('Antigo.', $motivos[$abonado->getId()], 'dia analisado não muda');
        self::assertSame('Motivo novo do lote.', $motivos[$terceiro->getId()]);
    }

    #[TestDox('Edição recusada depois do motivo (esquecimento sem horário): nenhum dia do lote ganha o motivo novo')]
    public function testRecusaNaoGravaOMotivoNoLote(): void
    {
        $lote = 'lote-' . uniqid();
        $dias = $this->loteDeTresDias($lote, ['pendente', 'pendente', 'pendente']);

        $this->editar($dias[0], ['tipo' => 'esquecimento_registro', 'tipoRegistroEsquecido' => 'saida', 'motivo' => 'Motivo novo do lote.']);

        self::assertContains('Informe o tipo e o horário do registro esquecido.', $this->flashes()['warning'] ?? []);
        self::assertSame(['Antigo.', 'Antigo.', 'Antigo.'], array_values($this->motivosDoLote($lote)));
    }

    #[TestDox('Sem lote (batchId vazio), o motivo editado fica só no dia editado')]
    public function testSemLoteSoODiaEditado(): void
    {
        $editado = $this->justificativa($this->eu, $this->escritorio, $this->mesPassado->modify('+4 days'), 'pendente', ['batchId' => '', 'motivo' => 'Antigo.']);
        $vizinho = $this->justificativa($this->eu, $this->escritorio, $this->mesPassado->modify('+5 days'), 'pendente', ['batchId' => '', 'motivo' => 'Antigo.']);

        $this->editar($editado, ['tipo' => 'atestado_medico', 'motivo' => 'Motivo novo.']);

        self::assertContains('Justificativa atualizada com sucesso.', $this->flashes()['success'] ?? []);
        $conexao = $this->emSemFiltro()->getConnection();
        self::assertSame('Motivo novo.', $conexao->fetchOne('SELECT motivo FROM justificativa_ponto WHERE id = ?', [$editado->getId()]));
        self::assertSame('Antigo.', $conexao->fetchOne('SELECT motivo FROM justificativa_ponto WHERE id = ?', [$vizinho->getId()]), 'batchId vazio não junta os dias');
    }

    #[TestDox('Editar o motivo pelo 2º dia do lote também muda os 3 dias')]
    public function testMotivoEditadoPeloSegundoDia(): void
    {
        $lote = 'lote-' . uniqid();
        $dias = $this->loteDeTresDias($lote, ['pendente', 'pendente', 'pendente']);

        $this->editar($dias[1], ['tipo' => 'atestado_medico', 'motivo' => 'Pelo segundo dia.']);

        self::assertSame(['Pelo segundo dia.', 'Pelo segundo dia.', 'Pelo segundo dia.'], array_values($this->motivosDoLote($lote)));
    }

    #[TestDox('Motivo "0" aparece na aba Minhas Justificativas (no Twig, "0" é falso, mas não é vazio)')]
    public function testMotivoZeroAparece(): void
    {
        // Mês corrente: o vínculo do cenário nasce hoje, e a tela não abre competência anterior a ele.
        $hoje = new \DateTimeImmutable('today');
        $this->justificativa($this->eu, $this->escritorio, $hoje, 'pendente', ['motivo' => '0']);

        $tela = $this->client->request('GET', '/ponto/?competencia=' . $hoje->format('Y-m') . '&aba=justificativas');

        self::assertResponseIsSuccessful();
        self::assertSame(['0'], $tela->filter('#ponto-painel-justificativas td.pe-just-celtipo > div.pe-just-mot')->each(static fn ($n): string => $n->text()));
    }

    #[TestDox('Justificativa de outra pessoa do mesmo escritório: a edição é negada e o motivo dela não muda')]
    public function testEdicaoDeOutraPessoaENegada(): void
    {
        $colega = $this->pessoa('colega-motivo');
        $this->vincularColaborador($colega, $this->escritorio);
        $j = $this->justificativa($colega, $this->escritorio, $this->mesPassado->modify('+4 days'), 'pendente', ['motivo' => 'Do colega.']);

        $this->editar($j, ['tipo' => 'licenca_gala', 'motivo' => 'Invadido.']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('Do colega.', $this->emSemFiltro()->getConnection()->fetchOne('SELECT motivo FROM justificativa_ponto WHERE id = ?', [$j->getId()]));
    }

    /** @param array<string, string> $campos */
    private function justificar(array $campos): void
    {
        $this->client->request('POST', '/ponto/justificativa/nova', [
            'justificativa_ponto' => $campos + ['_token' => 'TOKEN_justificativa_ponto', 'tipo' => 'atestado_medico'],
            'competencia'         => $this->mesPassado->format('Y-m'),
        ]);
    }

    private function esquecimento(\DateTimeImmutable $dia, string $hora, ?string $motivo = 'Esqueci de bater a saída.'): void
    {
        $campos = [
            'datas'                 => $dia->format('Y-m-d'),
            'tipo'                  => 'esquecimento_registro',
            'tipoRegistroEsquecido' => 'saida',
            'horaRegistroEsquecido' => $hora,
        ];
        if ($motivo !== null) {
            $campos['motivo'] = $motivo;
        }
        $this->justificar($campos);
        self::assertResponseRedirects('/ponto/?competencia=' . $this->mesPassado->format('Y-m'));
    }

    /** @param array<string, string> $campos */
    private function editar(JustificativaPonto $j, array $campos): void
    {
        $id = (int) $j->getId();
        $this->em()->clear();
        $this->client->request('POST', sprintf('/ponto/justificativa/%d/editar', $id), ['_token' => 'TOKEN_editar_justificativa_' . $id] + $campos);
    }

    /** @param list<\DateTimeImmutable> $dias */
    private function chaves(array $dias): string
    {
        return implode(',', array_map(static fn (\DateTimeImmutable $d): string => $d->format('Y-m-d'), $dias));
    }

    /**
     * Três dias seguidos do mesmo lote, meus, no escritório da sessão, com o motivo "Antigo.".
     *
     * @param array{string, string, string} $situacoes
     *
     * @return list<JustificativaPonto>
     */
    private function loteDeTresDias(string $lote, array $situacoes): array
    {
        $dias = [];
        foreach ($situacoes as $i => $situacao) {
            $dias[] = $this->justificativa($this->eu, $this->escritorio, $this->mesPassado->modify(sprintf('+%d days', $i + 4)), $situacao, ['batchId' => $lote, 'motivo' => 'Antigo.']);
        }

        return $dias;
    }

    /** @return array<int, string|null> o motivo de cada linha do lote, de qualquer pessoa e escritório, por id */
    private function motivosDoLote(string $lote): array
    {
        /** @var array<int, string|null> $motivos */
        $motivos = $this->emSemFiltro()->getConnection()->fetchAllKeyValue('SELECT id, motivo FROM justificativa_ponto WHERE batch_id = ? ORDER BY data, id', [$lote]);

        return $motivos;
    }

    /** @return list<array<string, mixed>> as justificativas minhas, pela conexão (o EntityManager pode ter fechado) */
    private function linhas(): array
    {
        return $this->emSemFiltro()->getConnection()->fetchAllAssociative(
            'SELECT data, tipo, motivo, batch_id, hora_registro_esquecido FROM justificativa_ponto WHERE user_id = ? ORDER BY data',
            [$this->eu->getId()],
        );
    }

    /** @return array<string, list<string>> */
    private function flashes(): array
    {
        return $this->client->getRequest()->getSession()->getFlashBag()->peekAll();
    }
}
