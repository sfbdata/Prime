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
 * aba "Minhas Justificativas", no mês da justificativa (achado da revisão da E3).
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
