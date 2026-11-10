<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Controller\TenantController;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Repository\TrilhaDoPontoRepository;
use App\Ponto\Service\NomesDasSituacoesDaJustificativa;
use App\Ponto\Service\SaidaDeEsquecimento;
use App\Ponto\UseCase\AnalisarJustificativaUseCase;
use App\Ponto\UseCase\RegistrarBatidaDoEsquecimentoUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Ponto\Support\TrilhaQueAnotaAsTravasDasPessoas;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\BrowserKit\Exception\BadMethodCallException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * E10 (D18), pela ficha do colaborador: o esquecimento abonado e o lançado já abonado viram INCLUSÃO em `ponto_ajuste`
 * ligada à justificativa, e voltar a abonada para análise desconsidera essa inclusão (o registro fica guardado). O abono
 * de antes da E10 (batida em `registro_ponto`) fica, com o aviso do caminho para tirá-la da conta (decisão 3). Tudo
 * contado no banco: quem faz a folha enxergar a inclusão é outro bloco.
 */
#[Group('ponto')]
#[CoversClass(TenantController::class)]
final class EsquecimentoViraInclusaoControllerTest extends JusPrimeWebTestCase
{
    use CenarioDeJustificativaTrait;

    private KernelBrowser $client;
    private Tenant $escritorio;
    private User $gestor;
    private User $colaborador;
    private \DateTimeImmutable $dia;

    /** @var array<string, list<string>> */
    private array $vistos = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->instalarCsrfPrevisivel();
        $this->escritorio  = $this->escritorio('Escritório do esquecimento');
        $this->gestor      = $this->pessoa('gestor');
        $this->colaborador = $this->pessoa('colaborador');
        $this->vincularGestor($this->gestor, $this->escritorio);
        $this->vincularColaborador($this->colaborador, $this->escritorio);
        $this->dia = new \DateTimeImmutable('-3 days');
    }

    #[TestDox('Abonar o esquecimento grava a inclusão ligada; voltar para análise a desconsidera (desfazendo a inclusão), com o texto novo de sucesso')]
    public function testAbonarEVoltarDesconsideraAInclusao(): void
    {
        $id = (int) $this->esquecimento('pendente')->getId();

        $this->postar('aprovar', $id);
        self::assertResponseRedirects();
        self::assertSame([['inclusao', $id, null]], $this->ajustes());

        $this->postar('reverter', $id);

        self::assertResponseRedirects();
        self::assertSame([NomesDasSituacoesDaJustificativa::texto('reverter.feito_desconsiderada', $this->dia->format('d/m/Y'))], $this->flashes('success'));
        self::assertSame([], $this->flashes('warning'));
        self::assertSame('pendente', $this->statusNoBanco($id));
        $ajustes = $this->ajustes();
        self::assertSame(['inclusao', 'desconsideracao'], array_column($ajustes, 0));
        self::assertSame([$id, $id], array_column($ajustes, 1), 'as duas ligadas à justificativa');
        self::assertSame($this->idDaInclusao(), $ajustes[1][2], 'a desconsideração desfaz a inclusão do abono');
        self::assertSame(0, $this->registros(), 'registro_ponto não ganha linha nem perde');
    }

    #[TestDox('Abono de antes da E10 (batida em registro_ponto): voltar para análise deixa a batida e avisa como tirá-la da conta')]
    public function testVoltarAbonoDeAntesAvisaComOCaminho(): void
    {
        $id = (int) $this->esquecimento('abonado')->getId();
        $antiga = new RegistroPonto();
        $antiga->setUser($this->colaborador);
        $antiga->setTenant($this->escritorio);
        $antiga->setTipo('saida');
        $antiga->setDataHora(new \DateTime($this->dia->format('Y-m-d') . ' 18:00:00'));
        $antiga->setObservacao(RegistrarBatidaDoEsquecimentoUseCase::OBSERVACAO);
        $this->em()->persist($antiga);
        $this->em()->flush();

        $this->postar('reverter', $id);

        self::assertResponseRedirects();
        self::assertSame([NomesDasSituacoesDaJustificativa::texto('reverter.feito_com_batida', $this->dia->format('d/m/Y'))], $this->flashes('warning'));
        self::assertStringContainsString('use Desconsiderar na aba Batidas de Ponto', $this->flashes('warning')[0]);
        self::assertSame('pendente', $this->statusNoBanco($id));
        self::assertSame(1, $this->registros(), 'a batida de antes da E10 fica');
        self::assertSame([], $this->ajustes());
    }

    #[TestDox('Lançar esquecimento já abonado grava uma inclusão por dia, ligada à justificativa do dia, com quem lançou como autor e o motivo do lançamento; registro_ponto fica vazio')]
    public function testLancamentoJaAbonadoViraInclusao(): void
    {
        $dias = [$this->dia, $this->dia->modify('-1 day')];

        $this->lancarEsquecimento($dias);

        self::assertResponseRedirects();
        $conexao = $this->emSemFiltro()->getConnection();
        $linhas  = $conexao->fetchAllAssociative(
            "SELECT a.data_hora_nova, a.tipo_marcacao_nova, a.motivo, a.autor_id, j.data, j.status
               FROM ponto_ajuste a JOIN justificativa_ponto j ON j.id = a.justificativa_id
              WHERE a.user_id = ? AND a.tenant_id = ? AND a.tipo = 'inclusao' ORDER BY a.data_hora_nova DESC",
            [$this->colaborador->getId(), $this->escritorio->getId()],
        );
        self::assertCount(2, $linhas);
        foreach ($linhas as $i => $linha) {
            self::assertSame($dias[$i]->format('Y-m-d') . ' 18:10:00', $linha['data_hora_nova']);
            self::assertSame($dias[$i]->format('Y-m-d'), $linha['data'], 'cada inclusão aponta a justificativa do seu dia');
            self::assertSame('saida', $linha['tipo_marcacao_nova']);
            self::assertSame(SaidaDeEsquecimento::OBSERVACAO_LANCAMENTO_DO_ADMIN, $linha['motivo']);
            self::assertSame((int) $this->gestor->getId(), (int) $linha['autor_id']);
            self::assertSame('abonado', $linha['status']);
        }
        self::assertSame(0, $this->registros());

        $lancada = (int) $conexao->fetchOne(
            'SELECT id FROM justificativa_ponto WHERE user_id = ? AND data = ?',
            [$this->colaborador->getId(), $dias[0]->format('Y-m-d')],
        );
        $this->postar('reverter', $lancada);
        self::assertSame([NomesDasSituacoesDaJustificativa::texto('reverter.feito_desconsiderada', $dias[0]->format('d/m/Y'))], $this->flashes('success'));
    }

    #[TestDox('E10, ordem das travas, lançamento já abonado (confirmarLancamento): a linhagem da pessoa, ainda livre antes, já está travada (4210) no primeiro evento da trilha (4203)')]
    public function testLancamentoTravaAPessoaAntesDaTrilha(): void
    {
        $container = static::getContainer();
        $pessoa    = [(int) $this->colaborador->getId()];
        $espiao    = new TrilhaQueAnotaAsTravasDasPessoas($container->get(ManagerRegistry::class), $pessoa);
        $container->set(TrilhaDoPontoRepository::class, $espiao);
        self::assertSame([], TrilhaQueAnotaAsTravasDasPessoas::travadas($this->em()->getConnection(), $pessoa), 'a pessoa ainda não foi travada neste teste');

        $this->lancarEsquecimento([$this->dia]);

        self::assertResponseRedirects();
        self::assertSame(['justificativa_lancada', 'batida_do_esquecimento'], array_map('strval', $this->emSemFiltro()->getConnection()->fetchFirstColumn(
            'SELECT acao FROM ponto_auditoria WHERE tenant_id = ? ORDER BY sequencia',
            [$this->escritorio->getId()],
        )));
        self::assertSame([$pessoa, $pessoa], $espiao->travadasPorEvento, 'no primeiro evento (o lançamento) a pessoa já estava travada');
    }

    #[TestDox('Voltar para análise pela URL de outro escritório: 404, a justificativa de lá segue abonada e a inclusão dela na conta')]
    public function testReverterDeOutroEscritorioNaoMexeNaFolhaDeLa(): void
    {
        $outro = $this->escritorio('Outro escritório');
        $this->vincularColaborador($this->colaborador, $outro);
        $deLa = $this->justificativa($this->colaborador, $outro, $this->dia, 'pendente', ['tipo' => 'esquecimento_registro', 'tipoRegistroEsquecido' => 'entrada', 'horaRegistroEsquecido' => '08:00']);
        $gestorDeLa = $this->pessoa('gestor_de_la');
        $this->vincularGestor($gestorDeLa, $outro);
        static::getContainer()->get(AnalisarJustificativaUseCase::class)->abonar($deLa, $outro, $gestorDeLa, '');

        $this->postar('reverter', (int) $deLa->getId());

        self::assertResponseStatusCodeSame(404);
        self::assertSame('abonado', $this->statusNoBanco((int) $deLa->getId()));
        self::assertSame(['inclusao'], array_column($this->emSemFiltro()->getConnection()->fetchAllAssociative(
            'SELECT tipo FROM ponto_ajuste WHERE justificativa_id = ?',
            [$deLa->getId()],
        ), 'tipo'), 'nenhuma desconsideração gravada lá');
    }

    private function esquecimento(string $status): JustificativaPonto
    {
        return $this->justificativa($this->colaborador, $this->escritorio, $this->dia, $status, [
            'tipo'                  => 'esquecimento_registro',
            'tipoRegistroEsquecido' => 'saida',
            'horaRegistroEsquecido' => '18:00',
        ]);
    }

    /**
     * O gestor lança o esquecimento de saída às 18:10, já abonado, nos dias informados (novaJustificativaAdmin).
     *
     * @param list<\DateTimeImmutable> $dias
     */
    private function lancarEsquecimento(array $dias): void
    {
        $this->logarComTenant($this->client, $this->gestor, $this->escritorio);
        $this->em()->clear();
        $this->client->request('POST', sprintf('/tenant/%d/user/%d/justificativa/nova', $this->escritorio->getId(), $this->colaborador->getId()), [
            '_token'                => 'TOKEN_admin_nova_justificativa_' . $this->colaborador->getId(),
            'tipo'                  => 'esquecimento_registro',
            'tipoRegistroEsquecido' => 'saida',
            'horaRegistroEsquecido' => '18:10',
            'datas'                 => implode(',', array_map(static fn (\DateTimeImmutable $dia): string => $dia->format('Y-m-d'), $dias)),
        ]);
    }

    /** POST de uma ação da análise na ficha do colaborador deste escritório, como o gestor. */
    private function postar(string $acao, int $id): void
    {
        $this->marcarAvisosVistos();
        $this->logarComTenant($this->client, $this->gestor, $this->escritorio);
        $this->em()->clear();
        $this->client->request('POST', sprintf(
            '/tenant/%d/user/%d/justificativa/%d/%s',
            $this->escritorio->getId(),
            $this->colaborador->getId(),
            $id,
            $acao,
        ), ['_token' => sprintf('TOKEN_justificativa_%s_%d', $acao, $id)]);
    }

    /**
     * Os avisos do tipo deixados pelo último POST (o redirecionamento não é seguido, então os de antes se acumulam).
     *
     * @return list<string>
     */
    private function flashes(string $tipo): array
    {
        $bag = $this->client->getRequest()->getSession()->getFlashBag();
        $todos = $bag->peek($tipo);

        return array_values(array_diff_key($todos, $this->vistos[$tipo] ?? []));
    }

    /** Os avisos já na sessão antes do próximo POST ficam de fora da conferência dele. */
    private function marcarAvisosVistos(): void
    {
        try {
            $request = $this->client->getRequest();
        } catch (BadMethodCallException) {
            return;   // nenhum POST antes deste
        }
        foreach (['success', 'warning'] as $tipo) {
            $this->vistos[$tipo] = $request->getSession()->getFlashBag()->peek($tipo);
        }
    }

    /** @return list<array{string, int|null, int|null}> tipo, justificativa e o ajuste desfeito, em ordem de gravação */
    private function ajustes(): array
    {
        $linhas = $this->emSemFiltro()->getConnection()->fetchAllAssociative(
            'SELECT tipo, justificativa_id, desfaz_id FROM ponto_ajuste WHERE user_id = ? AND tenant_id = ? ORDER BY id',
            [$this->colaborador->getId(), $this->escritorio->getId()],
        );

        return array_map(static fn (array $l): array => [
            (string) $l['tipo'],
            $l['justificativa_id'] === null ? null : (int) $l['justificativa_id'],
            $l['desfaz_id'] === null ? null : (int) $l['desfaz_id'],
        ], $linhas);
    }

    private function idDaInclusao(): int
    {
        return (int) $this->emSemFiltro()->getConnection()->fetchOne(
            "SELECT id FROM ponto_ajuste WHERE user_id = ? AND tenant_id = ? AND tipo = 'inclusao'",
            [$this->colaborador->getId(), $this->escritorio->getId()],
        );
    }

    private function registros(): int
    {
        return (int) $this->emSemFiltro()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM registro_ponto WHERE user_id = ? AND tenant_id = ?',
            [$this->colaborador->getId(), $this->escritorio->getId()],
        );
    }
}
