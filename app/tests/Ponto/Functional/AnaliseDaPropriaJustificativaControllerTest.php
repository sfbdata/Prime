<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Controller\TenantController;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Controller\PontoController;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Service\AnaliseDaPropriaJustificativa;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * P43 a (desenho do Ponto, E3): o primário e o Master abonam e recusam a PRÓPRIA justificativa, pela aba "Minhas
 * Justificativas", com a mesma regra de análise da ficha do gestor. Quem gere pelo perfil e não é primário nem
 * Master não vê os botões na própria, e o servidor recusa; a de outra pessoa ele continua analisando. Voltar para
 * análise e lançar justificativa para si também são analisar a própria (decisão do Coordenador, 09/11/2026), e a
 * ficha do gestor esconde os botões pela mesma decisão do servidor.
 */
#[Group('ponto')]
#[CoversClass(TenantController::class)]
#[CoversClass(PontoController::class)]
#[CoversClass(AnaliseDaPropriaJustificativa::class)]
final class AnaliseDaPropriaJustificativaControllerTest extends JusPrimeWebTestCase
{
    use CenarioDeJustificativaTrait;

    private KernelBrowser $client;
    private Tenant $escritorio;
    private \DateTimeImmutable $dia;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->instalarCsrfPrevisivel();
        $this->escritorio = $this->escritorio('Escritório Análise');
        $this->dia        = new \DateTimeImmutable('first day of this month');
    }

    #[TestDox('Primário abona a própria pela aba: o servidor aceita e volta para a aba no mesmo mês')]
    public function testPrimarioAbonaAPropria(): void
    {
        $primario = $this->pessoa('primario');
        $this->vincularPrimario($primario, $this->escritorio);
        $j = $this->justificativa($primario, $this->escritorio, $this->dia, 'pendente');

        $this->analisar($primario, $j, 'aprovar', 'Conferido.');

        self::assertResponseRedirects('/ponto/?aba=justificativas&competencia=' . $this->dia->format('Y-m'));
        self::assertSame('abonado', $this->statusNoBanco($j));
    }

    #[TestDox('Master recusa a própria com motivo: o servidor aceita e o sininho diz "recusada"')]
    public function testMasterRecusaAPropria(): void
    {
        $master = $this->pessoa('master', ['ROLE_USER', 'ROLE_SUPER_ADMIN']);
        $this->vincularColaborador($master, $this->escritorio);
        $j = $this->justificativa($master, $this->escritorio, $this->dia, 'pendente');

        $this->analisar($master, $j, 'rejeitar', 'Sem atestado.');

        self::assertResponseRedirects('/ponto/?aba=justificativas&competencia=' . $this->dia->format('Y-m'));
        self::assertSame('rejeitado', $this->statusNoBanco($j));
        self::assertSame(
            'Sua justificativa de ponto foi recusada.',
            $this->emSemFiltro()->getConnection()->fetchOne(
                'SELECT mensagem FROM notificacao WHERE usuario_id = ? ORDER BY id DESC LIMIT 1',
                [$master->getId()],
            ),
        );
    }

    #[TestDox('Recusar sem motivo pela aba: volta para a aba com o aviso, e nada muda')]
    public function testRecusarSemMotivoVoltaComAviso(): void
    {
        $primario = $this->pessoa('primario');
        $this->vincularPrimario($primario, $this->escritorio);
        $j = $this->justificativa($primario, $this->escritorio, $this->dia, 'pendente');

        $this->analisar($primario, $j, 'rejeitar', '');

        self::assertResponseRedirects('/ponto/?aba=justificativas&competencia=' . $this->dia->format('Y-m'));
        self::assertSame('pendente', $this->statusNoBanco($j));
        self::assertStringContainsString('Informe o motivo da recusa.', $this->client->followRedirect()->text());
    }

    #[TestDox('Gestor pelo perfil (nem primário nem Master): o servidor recusa abonar, recusar e aprovar todos na própria')]
    public function testGestorSecundarioNaoAnalisaAPropria(): void
    {
        $gestor = $this->pessoa('gestor');
        $this->vincularGestor($gestor, $this->escritorio);
        $j = $this->justificativa($gestor, $this->escritorio, $this->dia, 'pendente', ['batchId' => 'lote-gestor']);
        $id = (int) $j->getId();
        $gestorId = (int) $gestor->getId();

        $this->analisar($gestor, $j, 'aprovar', 'Eu mesmo.');
        self::assertResponseStatusCodeSame(403);
        $this->analisar($this->recarregar($gestorId), $id, 'rejeitar', 'Eu mesmo.');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', sprintf('/tenant/%d/user/%d/justificativa/batch/lote-gestor/aprovar-todos', $this->escritorio->getId(), $gestorId), [
            '_token' => 'TOKEN_justificativa_aprovar_todos_lote-gestor',
        ]);
        self::assertResponseStatusCodeSame(403);

        self::assertSame('pendente', $this->statusNoBanco($id));
    }

    #[TestDox('Gestor pelo perfil continua analisando a justificativa de outra pessoa (regra de hoje)')]
    public function testGestorSecundarioAnalisaADeOutraPessoa(): void
    {
        $gestor = $this->pessoa('gestor');
        $this->vincularGestor($gestor, $this->escritorio);
        $colaborador = $this->pessoa('colaborador');
        $this->vincularColaborador($colaborador, $this->escritorio);
        $j = $this->justificativa($colaborador, $this->escritorio, $this->dia, 'pendente');

        $this->analisar($gestor, $j, 'aprovar', '', volta: false);

        self::assertResponseRedirects(sprintf('/tenant/%d/user/%d/edit-role?tab=justificativas', $this->escritorio->getId(), $colaborador->getId()));
        self::assertSame('abonado', $this->statusNoBanco($j));
    }

    #[TestDox('Na aba: primário e Master veem Abonar e Recusar na própria em análise; o gestor pelo perfil e o colaborador, não')]
    public function testBotoesDaAnaliseSeguemARegra(): void
    {
        $casos = [
            'primário' => [$this->pessoa('primario'), 'vincularPrimario', 1],
            'Master'   => [$this->pessoa('master', ['ROLE_USER', 'ROLE_SUPER_ADMIN']), 'vincularColaborador', 1],
            'gestor'   => [$this->pessoa('gestor'), 'vincularGestor', 0],
            'colaborador' => [$this->pessoa('colaborador'), 'vincularColaborador', 0],
        ];
        $ids = [];
        foreach ($casos as $nome => [$pessoa, $vincular, ]) {
            $this->{$vincular}($pessoa, $this->escritorio);
            $this->justificativa($pessoa, $this->escritorio, $this->dia, 'pendente');
            $this->justificativa($pessoa, $this->escritorio, $this->dia, 'abonado');
            $ids[$nome] = (int) $pessoa->getId();
        }

        foreach ($casos as $nome => [, , $esperado]) {
            $this->logarComTenant($this->client, $this->recarregar($ids[$nome]), $this->escritorio);
            $tela = $this->client->request('GET', '/ponto/?aba=justificativas');
            $acoes = 'section.pe-abas > #ponto-painel-justificativas > .pe-abas-rolagem > table.pe-tab > tbody > tr.pe-just-lin > td.pe-just-acoes';

            self::assertCount($esperado, $tela->filter($acoes . ' > span.pe-just-analise > button.pe-just-btn--abonar[data-analisar="abonar"]'), $nome);
            self::assertCount($esperado, $tela->filter($acoes . ' > span.pe-just-analise > button.pe-just-btn--recusar[data-analisar="recusar"]'), $nome);
            self::assertCount(0, $tela->filter($acoes . ' > span.pe-just-trava ~ span.pe-just-analise'), $nome . ': analisada não tem botão de análise');
            $this->em()->clear();
        }
    }

    #[TestDox('Gestor pelo perfil: o servidor recusa voltar para análise e lançar justificativa para si')]
    public function testGestorSecundarioNaoReverteNemLancaParaSi(): void
    {
        $gestor = $this->pessoa('gestor');
        $this->vincularGestor($gestor, $this->escritorio);
        $id       = (int) $this->justificativa($gestor, $this->escritorio, $this->dia, 'abonado')->getId();
        $gestorId = (int) $gestor->getId();

        $this->reverter($gestor, $id);
        self::assertResponseStatusCodeSame(403);
        $this->lancarParaSi($this->recarregar($gestorId));
        self::assertResponseStatusCodeSame(403);

        self::assertSame('abonado', $this->statusNoBanco($id));
        self::assertSame(1, $this->contarJustificativas($gestorId));
    }

    #[TestDox('Primário e Master voltam para análise e lançam justificativa para si')]
    #[DataProvider('quemAnalisaAPropria')]
    public function testPrimarioEMasterRevertemELancamParaSi(bool $master): void
    {
        $pessoa = $master ? $this->pessoa('master', ['ROLE_USER', 'ROLE_SUPER_ADMIN']) : $this->pessoa('primario');
        $master ? $this->vincularColaborador($pessoa, $this->escritorio) : $this->vincularPrimario($pessoa, $this->escritorio);
        $id       = (int) $this->justificativa($pessoa, $this->escritorio, $this->dia, 'abonado')->getId();
        $pessoaId = (int) $pessoa->getId();
        $ficha    = sprintf('/tenant/%d/user/%d/edit-role?tab=justificativas', $this->escritorio->getId(), $pessoaId);

        $this->reverter($pessoa, $id);
        self::assertResponseRedirects($ficha);
        self::assertSame('pendente', $this->statusNoBanco($id));

        $this->lancarParaSi($this->recarregar($pessoaId));
        self::assertResponseRedirects($ficha);
        self::assertSame(2, $this->contarJustificativas($pessoaId));
    }

    /** @return iterable<string, array{bool}> */
    public static function quemAnalisaAPropria(): iterable
    {
        yield 'primário' => [false];
        yield 'Master' => [true];
    }

    #[TestDox('Ficha do gestor: na própria, o gestor pelo perfil não vê Abonar, Recusar, Aprovar todos, Voltar para análise nem Justificar Falta; o primário vê, e na de outra pessoa o gestor vê')]
    public function testFichaSegueARegra(): void
    {
        $primario = $this->pessoa('primario');
        $this->vincularPrimario($primario, $this->escritorio);
        $gestor = $this->pessoa('gestor');
        $this->vincularGestor($gestor, $this->escritorio);
        $colaborador = $this->pessoa('colaborador');
        $this->vincularColaborador($colaborador, $this->escritorio);
        foreach ([$primario, $gestor, $colaborador] as $dono) {
            $lote = 'lote-' . $dono->getId();
            $this->justificativa($dono, $this->escritorio, $this->dia, 'pendente', ['batchId' => $lote]);
            $this->justificativa($dono, $this->escritorio, $this->dia->modify('+1 day'), 'pendente', ['batchId' => $lote]);
            $this->justificativa($dono, $this->escritorio, $this->dia->modify('+2 days'), 'abonado');
        }
        $ids = ['primario' => (int) $primario->getId(), 'gestor' => (int) $gestor->getId(), 'colaborador' => (int) $colaborador->getId()];

        foreach ([['primario', 'primario', 1], ['gestor', 'gestor', 0], ['gestor', 'colaborador', 1]] as [$quem, $dono, $ve]) {
            $this->em()->clear();
            $this->logarComTenant($this->client, $this->recarregar($ids[$quem]), $this->escritorio);
            $ficha = $this->client->request('GET', sprintf('/tenant/%d/user/%d/edit-role?tab=justificativas&competencia=%s', $this->escritorio->getId(), $ids[$dono], $this->dia->format('Y-m')));
            self::assertResponseIsSuccessful();
            $caso = $quem . ' na ficha de ' . $dono;

            self::assertCount(2 * $ve, $ficha->filter('form[action$="/aprovar"]'), $caso . ': Abonar');
            self::assertCount(2 * $ve, $ficha->filter('form[action$="/rejeitar"]'), $caso . ': Recusar');
            self::assertCount($ve, $ficha->filter('form[action$="/aprovar-todos"]'), $caso . ': Aprovar todos');
            self::assertCount($ve, $ficha->filter('form[action$="/reverter"]'), $caso . ': Voltar para análise');
            self::assertCount($ve, $ficha->filter('[data-bs-target="#modalAdminNovaJustificativa"]'), $caso . ': Justificar Falta');
            self::assertSame(1 - $ve, substr_count($ficha->html(), AnaliseDaPropriaJustificativa::RECUSA), $caso . ': o porquê aparece uma vez');
        }
    }

    private function analisar(User $quem, JustificativaPonto|int $justificativa, string $acao, string $observacao, bool $volta = true): void
    {
        $id   = $justificativa instanceof JustificativaPonto ? (int) $justificativa->getId() : $justificativa;
        $dono = $justificativa instanceof JustificativaPonto ? (int) $justificativa->getUser()?->getId() : (int) $quem->getId();
        $this->logarComTenant($this->client, $quem, $this->escritorio);
        $this->em()->clear();

        $this->client->request('POST', sprintf('/tenant/%d/user/%d/justificativa/%d/%s', $this->escritorio->getId(), $dono, $id, $acao), [
            '_token'            => sprintf('TOKEN_justificativa_%s_%d', $acao, $id),
            'observacaoAnalise' => $observacao,
        ] + ($volta ? ['volta' => 'ponto', 'competencia' => $this->dia->format('Y-m')] : []));
    }

    private function reverter(User $quem, int $id): void
    {
        $this->logarComTenant($this->client, $quem, $this->escritorio);
        $this->em()->clear();

        $this->client->request('POST', sprintf('/tenant/%d/user/%d/justificativa/%d/reverter', $this->escritorio->getId(), $quem->getId(), $id), [
            '_token' => 'TOKEN_justificativa_reverter_' . $id,
        ]);
    }

    private function lancarParaSi(User $quem): void
    {
        $this->logarComTenant($this->client, $quem, $this->escritorio);
        $this->em()->clear();

        $this->client->request('POST', sprintf('/tenant/%d/user/%d/justificativa/nova', $this->escritorio->getId(), $quem->getId()), [
            '_token' => 'TOKEN_admin_nova_justificativa_' . $quem->getId(),
            'tipo'   => 'doacao_sangue',
            'datas'  => $this->dia->format('Y-m-d'),
        ]);
    }

    private function contarJustificativas(int $userId): int
    {
        return (int) $this->emSemFiltro()->getConnection()->fetchOne('SELECT COUNT(*) FROM justificativa_ponto WHERE user_id = ?', [$userId]);
    }

    private function recarregar(int $userId): User
    {
        return $this->em()->find(User::class, $userId) ?? throw new \LogicException('pessoa sumiu');
    }
}
