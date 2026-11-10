<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Sede;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\Feriado;
use App\Ponto\Entity\HomeOfficeConfig;
use App\Ponto\Repository\TrilhaDoPontoRepository;
use App\Ponto\Service\DetalheDaTrilhaDoPonto;
use App\Ponto\Service\TrilhaDoPonto;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Os eventos que entram na trilha de auditoria do Ponto (spec `ponto-desenho-0811.md`, E9), pelas rotas de verdade:
 * a batida, a justificativa enviada e excluída pelo colaborador, e a análise do gestor (abonar com a batida do
 * esquecimento, recusar, voltar para análise, aprovar todos e lançar já abonada). A batida recusada não escreve nada,
 * e sede e feriado ficam fora da trilha, no `audit_log` como sempre.
 */
#[CoversClass(TrilhaDoPonto::class)]
#[Group('ponto')]
final class TrilhaDoPontoEventosTest extends JusPrimeWebTestCase
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
        $this->escritorio = $this->escritorio('Escritório Trilha');
        $this->dia        = new \DateTimeImmutable('first day of this month');
    }

    #[TestDox('A batida entra na trilha: "batida_registrada" com o colaborador como autor e o tipo no detalhe')]
    public function testBatidaEntraNaTrilha(): void
    {
        $colaborador = $this->pessoa('colab');
        $this->vincularColaborador($colaborador, $this->escritorio);
        $this->liberarHomeOffice($colaborador);

        $this->bater($colaborador, ['tipo' => 'entrada']);

        self::assertResponseIsSuccessful();
        $eventos = $this->eventos();
        self::assertSame(['batida_registrada'], array_column($eventos, 'acao'));
        self::assertSame((int) $colaborador->getId(), $eventos[0]['autor_id']);
        self::assertSame('Pessoa colab', $eventos[0]['autor_nome']);
        self::assertSame(['entrada', true, false], [$eventos[0]['detalhe']['tipo'], $eventos[0]['detalhe']['home_office'], $eventos[0]['detalhe']['sem_internet']]);
        self::assertSame(1, $eventos[0]['sequencia']);
    }

    #[TestDox('A batida que veio da fila sem internet grava a marca no detalhe, e a aba Auditoria mostra " · sem internet" (dc L544)')]
    public function testBatidaDaFilaGravaSemInternet(): void
    {
        $colaborador = $this->pessoa('fila');
        $this->vincularColaborador($colaborador, $this->escritorio);
        $this->liberarHomeOffice($colaborador);
        $aparelho = new \DateTimeImmutable('-3 hours');

        $this->bater($colaborador, [
            'tipo'         => 'entrada',
            'idEnvio'      => '7c1e2d3f-4a5b-4c6d-8e7f-9a0b1c2d3e4f',
            'horaAparelho' => $aparelho->getTimestamp() * 1000,
            'semInternet'  => true,
            'usuarioId'    => $colaborador->getId(),
            'escritorioId' => $this->escritorio->getId(),
        ]);

        self::assertResponseIsSuccessful();
        $eventos = $this->eventos();
        self::assertSame(['batida_registrada'], array_column($eventos, 'acao'));
        self::assertTrue($eventos[0]['detalhe']['sem_internet']);
        self::assertStringEndsWith(' · home office · sem internet', DetalheDaTrilhaDoPonto::texto('batida_registrada', $eventos[0]['detalhe']));
    }

    #[TestDox('Batida recusada (fora da área da sede) não escreve na trilha')]
    public function testBatidaRecusadaNaoEscreve(): void
    {
        $colaborador = $this->pessoa('colab');
        $this->vincularColaborador($colaborador, $this->escritorio);
        $this->sede('Sede longe');

        $this->bater($colaborador, ['tipo' => 'entrada', 'latitude' => 0.0, 'longitude' => 0.0, 'precisaoGps' => 10.0]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->eventos());
    }

    #[TestDox('Atomicidade: a trilha falha ao gravar o evento da batida: a batida não fica gravada')]
    public function testTrilhaQueFalhaNaoDeixaABatida(): void
    {
        $colaborador = $this->pessoa('colab');
        $this->vincularColaborador($colaborador, $this->escritorio);
        $this->liberarHomeOffice($colaborador);
        $container = static::getContainer();
        $container->set(TrilhaDoPontoRepository::class, new class ($container->get(ManagerRegistry::class)) extends TrilhaDoPontoRepository {
            public function inserir(array $linha): void
            {
                throw new \RuntimeException('Falha simulada da trilha.');
            }
        });

        $this->bater($colaborador, ['tipo' => 'entrada']);

        self::assertResponseStatusCodeSame(500);
        self::assertSame(0, (int) $this->emSemFiltro()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM registro_ponto WHERE user_id = ? AND tenant_id = ?',
            [$colaborador->getId(), $this->escritorio->getId()],
        ), 'a batida voltou com a transação');
        self::assertSame([], $this->eventos());
    }

    #[TestDox('O colaborador envia e exclui a justificativa: "justificativa_enviada" e "justificativa_excluida", com ele como autor')]
    public function testEnviarEExcluir(): void
    {
        $colaborador = $this->pessoa('colab');
        $this->vincularColaborador($colaborador, $this->escritorio);
        $this->logarComTenant($this->client, $colaborador, $this->escritorio);

        $this->client->request('POST', '/ponto/justificativa/nova', [
            'justificativa_ponto' => ['_token' => 'TOKEN_justificativa_ponto', 'tipo' => 'doacao_sangue', 'datas' => $this->dia->format('Y-m-d'), 'motivo' => 'Doação de sangue.'],
            'competencia'         => $this->dia->format('Y-m'),
        ]);
        self::assertResponseRedirects();
        $id = (int) $this->emSemFiltro()->getConnection()->fetchOne('SELECT id FROM justificativa_ponto WHERE user_id = ?', [$colaborador->getId()]);
        self::assertGreaterThan(0, $id);

        $this->client->request('POST', '/ponto/justificativa/' . $id . '/excluir', [], [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_ajax']);
        self::assertResponseIsSuccessful();

        $eventos = $this->eventos();
        self::assertSame(['justificativa_enviada', 'justificativa_excluida'], array_column($eventos, 'acao'));
        self::assertSame([$id], $eventos[0]['detalhe']['justificativa_ids']);
        self::assertSame($id, $eventos[1]['detalhe']['justificativa_id']);
        self::assertSame([(int) $colaborador->getId(), (int) $colaborador->getId()], array_column($eventos, 'autor_id'));
        self::assertSame([1, 2], array_column($eventos, 'sequencia'));
    }

    #[TestDox('A análise do gestor entra na trilha: abonar (com a batida do esquecimento), recusar e voltar para análise')]
    public function testAnaliseEntraNaTrilha(): void
    {
        $gestor = $this->pessoa('gestor');
        $this->vincularGestor($gestor, $this->escritorio);
        $colaborador = $this->pessoa('colab');
        $this->vincularColaborador($colaborador, $this->escritorio);
        $esquecimento = $this->justificativa($colaborador, $this->escritorio, $this->dia, 'pendente', ['tipo' => 'esquecimento_registro', 'tipoRegistroEsquecido' => 'entrada', 'horaRegistroEsquecido' => '08:00']);
        $atestado = $this->justificativa($colaborador, $this->escritorio, $this->dia->modify('+1 day'), 'pendente');

        $this->analisar($gestor, $colaborador, (int) $esquecimento->getId(), 'aprovar');
        $this->analisar($gestor, $colaborador, (int) $atestado->getId(), 'rejeitar', 'Sem atestado.');
        $this->analisar($gestor, $colaborador, (int) $atestado->getId(), 'reverter');

        $eventos = $this->eventos();
        self::assertSame(['justificativa_abonada', 'batida_do_esquecimento', 'justificativa_recusada', 'justificativa_reaberta'], array_column($eventos, 'acao'));
        self::assertSame(array_fill(0, 4, (int) $gestor->getId()), array_column($eventos, 'autor_id'), 'o autor é quem analisou');
        self::assertSame('rejeitado', $eventos[3]['detalhe']['situacao_anterior']);
        self::assertSame('entrada', $eventos[1]['detalhe']['tipo']);
        self::assertSame([1, 2, 3, 4], array_column($eventos, 'sequencia'));
    }

    #[TestDox('"Aprovar todos" vira um "abono_em_lote" com as abonadas; "lançar já abonada" vira "justificativa_lancada"')]
    public function testLoteELancamento(): void
    {
        $gestor = $this->pessoa('gestor');
        $this->vincularGestor($gestor, $this->escritorio);
        $colaborador = $this->pessoa('colab');
        $this->vincularColaborador($colaborador, $this->escritorio);
        $a = $this->justificativa($colaborador, $this->escritorio, $this->dia, 'pendente', ['batchId' => 'lote-trilha']);
        $b = $this->justificativa($colaborador, $this->escritorio, $this->dia->modify('+1 day'), 'pendente', ['batchId' => 'lote-trilha']);

        $this->logarComTenant($this->client, $gestor, $this->escritorio);
        $this->em()->clear();
        $this->client->request('POST', sprintf('/tenant/%d/user/%d/justificativa/batch/lote-trilha/aprovar-todos', $this->escritorio->getId(), $colaborador->getId()), [
            '_token' => 'TOKEN_justificativa_aprovar_todos_lote-trilha',
        ]);
        self::assertResponseRedirects();
        $this->client->request('POST', sprintf('/tenant/%d/user/%d/justificativa/nova', $this->escritorio->getId(), $colaborador->getId()), [
            '_token' => 'TOKEN_admin_nova_justificativa_' . $colaborador->getId(),
            'tipo'   => 'doacao_sangue',
            'datas'  => $this->dia->modify('+2 days')->format('Y-m-d'),
        ]);
        self::assertResponseRedirects();

        $eventos = $this->eventos();
        self::assertSame(['abono_em_lote', 'justificativa_lancada'], array_column($eventos, 'acao'));
        $ids = $eventos[0]['detalhe']['justificativa_ids'];
        sort($ids);
        self::assertSame([(int) $a->getId(), (int) $b->getId()], $ids);
        self::assertSame(2, $eventos[0]['detalhe']['quantidade']);
        self::assertSame(1, $eventos[1]['detalhe']['quantidade']);
        self::assertSame(
            sprintf('Pessoa colab: %s a %s (2 dias) · Atestado Médico', $this->dia->format('d/m/Y'), $this->dia->modify('+1 day')->format('d/m/Y')),
            DetalheDaTrilhaDoPonto::texto($eventos[0]['acao'], $eventos[0]['detalhe']),
            'o "aprovar todos" da ficha continua com o texto dele, diferente do lote da aba Equipe',
        );
        self::assertSame([(int) $gestor->getId(), (int) $gestor->getId()], array_column($eventos, 'autor_id'));
    }

    #[TestDox('Lançar esquecimento já abonado: cada batida criada (inclusão ligada à justificativa, E10) entra como "batida_do_esquecimento", como na análise')]
    public function testLancamentoDeEsquecimentoGravaAsBatidas(): void
    {
        $gestor = $this->pessoa('gestor');
        $this->vincularGestor($gestor, $this->escritorio);
        $colaborador = $this->pessoa('colab');
        $this->vincularColaborador($colaborador, $this->escritorio);
        $dias = [new \DateTimeImmutable('-3 days'), new \DateTimeImmutable('-2 days')];

        $this->logarComTenant($this->client, $gestor, $this->escritorio);
        $this->client->request('POST', sprintf('/tenant/%d/user/%d/justificativa/nova', $this->escritorio->getId(), $colaborador->getId()), [
            '_token'                => 'TOKEN_admin_nova_justificativa_' . $colaborador->getId(),
            'tipo'                  => 'esquecimento_registro',
            'tipoRegistroEsquecido' => 'entrada',
            'horaRegistroEsquecido' => '08:00',
            'datas'                 => $dias[0]->format('Y-m-d') . ',' . $dias[1]->format('Y-m-d'),
        ]);
        self::assertResponseRedirects();

        $eventos = $this->eventos();
        self::assertSame(['justificativa_lancada', 'batida_do_esquecimento', 'batida_do_esquecimento'], array_column($eventos, 'acao'));
        $batidas = array_column(\array_slice($eventos, 1), 'detalhe');
        self::assertSame($eventos[0]['detalhe']['justificativa_ids'], array_column($batidas, 'justificativa_id'), 'cada batida aponta a justificativa dela');
        self::assertSame([$dias[0]->format('Y-m-d') . ' 08:00:00', $dias[1]->format('Y-m-d') . ' 08:00:00'], array_column($batidas, 'data_hora'));
        self::assertSame(['entrada', 'entrada'], array_column($batidas, 'tipo'));
        self::assertSame(array_fill(0, 3, (int) $gestor->getId()), array_column($eventos, 'autor_id'));
        // E10 (D18): cada batida é uma inclusão ligada à justificativa, e o evento aponta o ajuste; registro_ponto fica vazio.
        $conexao = $this->emSemFiltro()->getConnection();
        $inclusoes = $conexao->fetchAllAssociative(
            "SELECT id, justificativa_id FROM ponto_ajuste WHERE user_id = ? AND tenant_id = ? AND tipo = 'inclusao' ORDER BY id",
            [$colaborador->getId(), $this->escritorio->getId()],
        );
        self::assertSame(array_map('intval', array_column($inclusoes, 'id')), array_column($batidas, 'ajuste_id'));
        self::assertSame($eventos[0]['detalhe']['justificativa_ids'], array_map('intval', array_column($inclusoes, 'justificativa_id')));
        self::assertSame(0, (int) $conexao->fetchOne(
            'SELECT COUNT(*) FROM registro_ponto WHERE user_id = ? AND tenant_id = ?',
            [$colaborador->getId(), $this->escritorio->getId()],
        ));
    }

    #[TestDox('Sede e feriado ficam fora da trilha do Ponto e continuam no audit_log')]
    public function testSedeEFeriadoNoAuditLog(): void
    {
        $gestor = $this->pessoa('gestor');
        $this->vincularPrimario($gestor, $this->escritorio);
        $this->logarComTenant($this->client, $gestor, $this->escritorio);
        $this->client->request('GET', '/ponto/');   // sessão com o escritório, como numa gravação pela tela
        $antes = $this->auditadosDeSedeEFeriado();

        $this->sede('Sede nova');
        $feriado = (new Feriado())->setNome('Feriado da cidade')->setData(new \DateTime('2026-12-08'))->setRecorrente(false)->setTenant($this->escritorioGerenciado());
        $this->em()->persist($feriado);
        $this->em()->flush();

        self::assertSame([], $this->eventos());
        self::assertSame($antes + 2, $this->auditadosDeSedeEFeriado(), 'sede e feriado no audit_log');
    }

    /** O escritório pelo EntityManager de agora (a leitura sem filtro limpa o EntityManager). */
    private function escritorioGerenciado(): Tenant
    {
        return $this->em()->getReference(Tenant::class, (int) $this->escritorio->getId());
    }

    private function auditadosDeSedeEFeriado(): int
    {
        return (int) $this->emSemFiltro()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM audit_log WHERE entity_class IN (?, ?)',
            [Sede::class, Feriado::class],
        );
    }

    /** @param array<string, mixed> $corpo */
    private function bater(User $quem, array $corpo): void
    {
        $this->logarComTenant($this->client, $quem, $this->escritorio);
        $this->client->request('POST', '/ponto/batida', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_ajax'], (string) json_encode($corpo));
    }

    private function analisar(User $gestor, User $dono, int $id, string $acao, string $observacao = ''): void
    {
        $this->logarComTenant($this->client, $gestor, $this->escritorio);
        $this->em()->clear();
        $this->client->request('POST', sprintf('/tenant/%d/user/%d/justificativa/%d/%s', $this->escritorio->getId(), $dono->getId(), $id, $acao), [
            '_token'            => sprintf('TOKEN_justificativa_%s_%d', $acao, $id),
            'observacaoAnalise' => $observacao,
        ]);
        self::assertResponseRedirects();
    }

    private function liberarHomeOffice(User $quem): void
    {
        $config = new HomeOfficeConfig();
        $config->setUser($quem);
        $config->setTenant($this->escritorio);
        $config->setDiasSemana([1, 2, 3, 4, 5, 6, 7]);
        $config->setAtivo(true);
        $this->em()->persist($config);
        $this->em()->flush();
    }

    private function sede(string $nome): Sede
    {
        $sede = new Sede();
        $sede->setNome($nome . ' ' . uniqid());
        $sede->setLatitude('-23.55000000');
        $sede->setLongitude('-46.63000000');
        $sede->setRaioPermitido(100);
        $sede->setTimezone('America/Sao_Paulo');
        $sede->setTenant($this->escritorioGerenciado());
        $this->em()->persist($sede);
        $this->em()->flush();

        return $sede;
    }

    /** @return list<array{sequencia: int, acao: string, autor_id: ?int, autor_nome: ?string, detalhe: array<string, mixed>}> */
    private function eventos(): array
    {
        $linhas = $this->emSemFiltro()->getConnection()->fetchAllAssociative(
            'SELECT sequencia, acao, autor_id, autor_nome, detalhe FROM ponto_auditoria WHERE tenant_id = ? ORDER BY sequencia',
            [$this->escritorio->getId()],
        );

        return array_map(static fn (array $l): array => [
            'sequencia'  => (int) $l['sequencia'],
            'acao'       => (string) $l['acao'],
            'autor_id'   => $l['autor_id'] === null ? null : (int) $l['autor_id'],
            'autor_nome' => $l['autor_nome'],
            'detalhe'    => json_decode((string) $l['detalhe'], true),
        ], $linhas);
    }
}
