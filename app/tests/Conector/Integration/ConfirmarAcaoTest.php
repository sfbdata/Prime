<?php

declare(strict_types=1);

namespace App\Tests\Conector\Integration;

use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\ContextoDoConector;
use App\Conector\Entity\ClienteDoConector;
use App\Conector\Entity\ConsentimentoDoConector;
use App\Conector\Entity\PropostaDoConector;
use App\Conector\Enum\EstadoDaPropostaDoConector;
use App\Conector\Enum\TipoDeClienteDoConector;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Ferramenta\ConfirmarAcaoFerramenta;
use App\Conector\Repository\ConsentimentoDoConectorRepository;
use App\Conector\Repository\PropostaDoConectorRepository;
use App\Conector\Service\GuardaDaAcaoDoConector;
use App\Conector\Service\PropostasDoConector;
use App\Conector\Service\RequisicaoDoConector;
use App\Conector\Service\SegredoOpaco;
use App\Conector\UseCase\ConfirmarPropostaDoConectorUseCase;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRolePermission;
use App\Service\PermissionChecker;
use App\Tests\Conector\Support\CenarioDoConectorTrait;
use App\Tests\Conector\Support\ExecutorDePropostaDeTeste;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\ErrorHandler\BufferingLogger;

/**
 * Proposta e `confirmar_acao` com o banco (spec mcp-remoto-oauth 6.7 e 12.20): a proposta não grava nada e guarda só o
 * hash do id; a confirmação executa uma vez, zera os dados e guarda os ids; recusa, com a mesma mensagem, a proposta
 * usada, vencida, de outra pessoa, de outro escritório, de outro app e de consentimento revogado; resumo diferente,
 * permissão retirada e recusa da reconferência não gravam e deixam a proposta pendente; falha ao gravar desfaz tudo; a
 * varredura expira as vencidas só do escritório; e o teto próprio de confirmações.
 */
#[CoversClass(PropostasDoConector::class)]
#[CoversClass(ConfirmarPropostaDoConectorUseCase::class)]
#[CoversClass(ConfirmarAcaoFerramenta::class)]
final class ConfirmarAcaoTest extends KernelTestCase
{
    use CenarioDoConectorTrait;

    private const RESUMO = "Excluir 2 documentos da pasta 2024/118:\nprocuracao.pdf, rg.jpg";
    private const DADOS = ['pasta_id' => 118, 'documentos' => [7, 8], 'operacao' => 'excluir'];

    private MockClock $relogio;
    private ExecutorDePropostaDeTeste $executor;
    private User $pessoa;
    private Tenant $escritorio;
    private UserTenant $vinculo;
    private ClienteDoConector $app;
    private ConsentimentoDoConector $consentimento;

    protected function setUp(): void
    {
        self::bootKernel();
        // No fuso da aplicação: o banco devolve as datas nele, e um relógio em UTC desencontraria o vencimento.
        $this->relogio = new MockClock('2026-11-18 10:00:00', date_default_timezone_get());
        $this->executor = new ExecutorDePropostaDeTeste($this->em());
        $this->escritorio = $this->escritorio();
        $this->pessoa = $this->pessoa();
        $this->vinculo = $this->vinculo($this->pessoa, $this->escritorio, permissoes: ['modules.pastas.view']);
        $this->app = $this->app();
        $this->consentimento = $this->consentimento($this->pessoa, $this->escritorio, $this->app);
    }

    #[TestDox('6.7, item 1: propor grava a proposta pendente com o hash do id, os dados, o hash deles e o resumo, e nada é executado')]
    public function testProporGravaPendente(): void
    {
        $resposta = $this->propostas()->propor($this->chamada(), 'atualizar_pasta', 'editar_dados', self::DADOS, "  " . str_replace("\n", "\r\n", self::RESUMO) . "\n");

        $proposta = $resposta->dados['proposta'];
        self::assertSame('aguardando_confirmacao', $resposta->dados['estado']);
        self::assertSame(self::RESUMO, $proposta['resumo'], 'o resumo sai normalizado: quebras \n e sem espaço nas pontas');
        self::assertSame((new \DateTimeImmutable('2026-11-18 10:10:00'))->format(\DateTimeInterface::ATOM), $proposta['expira_em']);
        self::assertSame(PropostasDoConector::INSTRUCAO, $resposta->dados['instrucao']);
        self::assertSame('confirmar_acao', $resposta->dados['como_confirmar']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $proposta['proposta_id']);
        self::assertSame([], $this->executor->executadas);

        $gravada = $this->propostaPeloId($proposta['proposta_id']);
        self::assertNotNull($gravada, 'a busca é pelo SHA-256 do id');
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM conector_ia_proposta WHERE identificador_hash = ?', [$proposta['proposta_id']]), 'o valor do id nunca vai ao banco');
        self::assertSame(EstadoDaPropostaDoConector::Pendente, $gravada->getEstado());
        self::assertSame(self::DADOS, $gravada->getDados());
        self::assertSame(PropostasDoConector::hashDosDados(self::DADOS), $gravada->getDadosHash());
        self::assertSame(self::RESUMO, $gravada->getResumo());
        self::assertSame($this->consentimento->getId(), $gravada->getConsentimento()->getId());
        self::assertSame($this->app->getId(), $gravada->getCliente()->getId());
        self::assertSame(['atualizar_pasta', 'editar_dados'], [$gravada->getFerramenta(), $gravada->getAcao()]);
    }

    #[TestDox('o complemento vai na resposta, em detalhes, e não fica na proposta')]
    public function testComplementoSoNaResposta(): void
    {
        $resposta = $this->propostas()->propor($this->chamada(), 'atualizar_pasta', 'editar_dados', self::DADOS, self::RESUMO, ['fora_da_lista' => [['indice' => 3, 'motivo' => 'ja_cadastrado']]]);

        self::assertSame(['fora_da_lista' => [['indice' => 3, 'motivo' => 'ja_cadastrado']]], $resposta->dados['detalhes']);
        self::assertSame(self::DADOS, $this->propostaPeloId($resposta->dados['proposta']['proposta_id'])?->getDados());
    }

    #[TestDox('proposta que ninguém confirmaria nunca nasce: ferramenta de leitura, a própria confirmar_acao, ação fora da tabela, ferramenta sem executor, resumo vazio')]
    public function testProporRecusaErroDeProgramacao(): void
    {
        $casos = [
            ['ler_pasta', 'resumo', self::RESUMO],
            ['confirmar_acao', 'unica', self::RESUMO],
            ['atualizar_pasta', 'apagar_tudo', self::RESUMO],
            ['gravar_na_agenda', 'salvar', self::RESUMO],
            ['atualizar_pasta', 'editar_dados', "  \n "],
        ];
        foreach ($casos as [$ferramenta, $acao, $resumo]) {
            try {
                $this->propostas()->propor($this->chamada(), $ferramenta, $acao, self::DADOS, $resumo);
                self::fail(sprintf('%s.%s deveria recusar', $ferramenta, $acao));
            } catch (\LogicException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame(0, $this->contarPropostas());
    }

    #[TestDox('12.20: confirmar executa uma vez, a proposta vira confirmada, guarda só os ids e perde dados e resumo')]
    public function testConfirmarExecutaUmaVez(): void
    {
        $id = $this->proporAgora();
        $requisicao = new RequisicaoDoConector();

        $resposta = $this->ferramenta($requisicao)->executar(['proposta_id' => $id, 'resumo' => self::RESUMO], $this->chamada());

        self::assertCount(1, $this->executor->reconferidas);
        self::assertSame([['acao' => 'editar_dados', 'dados' => self::DADOS]], $this->executor->executadas);
        $gravadoId = $resposta->dados['resultado']['excluidos'][0]['id'];
        self::assertSame(['estado' => 'confirmada', 'ferramenta' => 'atualizar_pasta', 'acao' => 'editar_dados', 'resultado' => ['excluidos' => [['id' => $gravadoId]]]], $resposta->dados);
        self::assertSame(['excluidos' => 1], $resposta->contagens);
        self::assertSame('atualizar_pasta', $requisicao->anotacao('ferramenta_confirmada'));
        self::assertSame('editar_dados', $requisicao->anotacao('acao'));

        $proposta = $this->propostaPeloId($id);
        self::assertSame(EstadoDaPropostaDoConector::Confirmada, $proposta?->getEstado());
        self::assertNull($proposta->getDados());
        self::assertNull($proposta->getResumo());
        self::assertSame([$gravadoId], $proposta->getResultado());
        self::assertEquals($this->relogio->now(), $proposta->getDecididaEm());
    }

    #[TestDox('12.20: a mesma proposta confirmada de novo recusa com a mensagem fixa e não executa outra vez')]
    public function testJaUsadaRecusa(): void
    {
        $id = $this->proporAgora();
        $this->ferramenta()->executar(['proposta_id' => $id, 'resumo' => self::RESUMO], $this->chamada());

        $this->assertNaoVale($id, $this->chamada());
        self::assertCount(1, $this->executor->executadas);
    }

    #[TestDox('12.20: resumo diferente recusa, não executa e a proposta continua pendente; o resumo certo (com \r\n e espaços nas pontas) confirma depois')]
    public function testResumoDiferente(): void
    {
        $id = $this->proporAgora();

        $erro = $this->recusa(fn () => $this->ferramenta()->executar(['proposta_id' => $id, 'resumo' => 'Excluir 2 documentos'], $this->chamada()));

        self::assertSame(ConfirmarPropostaDoConectorUseCase::RESUMO_DIFERENTE, $erro->getMessage());
        self::assertSame([], $this->executor->reconferidas);
        self::assertSame(EstadoDaPropostaDoConector::Pendente, $this->propostaPeloId($id)?->getEstado());

        $this->ferramenta()->executar(['proposta_id' => $id, 'resumo' => ' ' . str_replace("\n", "\r\n", self::RESUMO) . " \n"], $this->chamada());
        self::assertCount(1, $this->executor->executadas);
    }

    #[TestDox('12.20: vencida (10 minutos) recusa com a mensagem fixa, vira expirada, perde os dados e isso fica gravado')]
    public function testVencida(): void
    {
        $id = $this->proporAgora();
        $this->relogio->sleep(600);

        $this->assertNaoVale($id, $this->chamada());

        $this->em()->clear();
        $proposta = $this->propostaPeloId($id);
        self::assertSame(EstadoDaPropostaDoConector::Expirada, $proposta?->getEstado());
        self::assertNull($proposta->getDados());
        self::assertNull($proposta->getResumo());
        self::assertSame([], $this->executor->executadas);
    }

    #[TestDox('12.20: outra pessoa do mesmo escritório, com o id e o resumo certos, recebe a mesma recusa; nada muda')]
    public function testOutraPessoa(): void
    {
        $id = $this->proporAgora();
        $colega = $this->pessoa();
        $vinculo = $this->vinculo($colega, $this->escritorio, permissoes: ['modules.pastas.view']);
        $consentimento = $this->consentimento($colega, $this->escritorio, $this->app);

        $this->assertNaoVale($id, $this->chamada($colega, $this->escritorio, $vinculo, $consentimento));
        self::assertSame(EstadoDaPropostaDoConector::Pendente, $this->propostaPeloId($id)?->getEstado());
    }

    #[TestDox('12.1 e 12.20: a mesma pessoa, pelo escritório B, não acha a proposta feita no A')]
    public function testOutroEscritorio(): void
    {
        $id = $this->proporAgora();
        $b = $this->escritorio('Escritório B');
        $vinculoB = $this->vinculo($this->pessoa, $b, permissoes: ['modules.pastas.view']);
        $consentimentoB = $this->consentimento($this->pessoa, $b, $this->app);

        $this->assertNaoVale($id, $this->chamada($this->pessoa, $b, $vinculoB, $consentimentoB));
        self::assertSame([], $this->executor->executadas);
    }

    #[TestDox('12.20: a mesma pessoa e escritório, por outro app de I.A. (outro consentimento), recebe a mesma recusa')]
    public function testOutroApp(): void
    {
        $id = $this->proporAgora();
        $outro = $this->consentimento($this->pessoa, $this->escritorio, $this->app());

        $this->assertNaoVale($id, $this->chamada(consentimento: $outro));
        self::assertSame([], $this->executor->executadas);
    }

    #[TestDox('12.20: consentimento revogado depois da proposta recusa com a mesma mensagem')]
    public function testConsentimentoRevogado(): void
    {
        $id = $this->proporAgora();
        $this->consentimento->revogar(new \DateTimeImmutable('2026-11-18 10:01:00'));
        $this->em()->flush();

        $this->assertNaoVale($id, $this->chamada());
        self::assertSame([], $this->executor->executadas);
    }

    #[TestDox('12.1: a proposta do escritório A não é achada por uma chamada no B, nem com o consentimento do A (só o filtro do escritório na busca barra)')]
    public function testOutroEscritorioComOMesmoConsentimento(): void
    {
        $id = $this->proporAgora();
        $b = $this->escritorio('Escritório B');
        $vinculoB = $this->vinculo($this->pessoa, $b, permissoes: ['modules.pastas.view']);

        $this->assertNaoVale($id, $this->chamada($this->pessoa, $b, $vinculoB, $this->consentimento));
        self::assertSame([], $this->executor->executadas);
    }

    #[TestDox('12.20: outra pessoa com o mesmo consentimento na chamada recusa (só a conferência da pessoa barra)')]
    public function testOutraPessoaComOMesmoConsentimento(): void
    {
        $id = $this->proporAgora();
        $colega = $this->pessoa();
        $vinculo = $this->vinculo($colega, $this->escritorio, permissoes: ['modules.pastas.view']);

        $this->assertNaoVale($id, $this->chamada($colega, $this->escritorio, $vinculo, $this->consentimento));
        self::assertSame([], $this->executor->executadas);
    }

    #[TestDox('6.7, item 4: dados trocados no banco sem o hash recusam com a mesma mensagem e não executam')]
    public function testDadosAdulterados(): void
    {
        $id = $this->proporAgora();
        $this->em()->getConnection()->executeStatement("UPDATE conector_ia_proposta SET dados = '{\"pasta_id\":999}' WHERE identificador_hash = ?", [hash('sha256', $id)]);

        $this->assertNaoVale($id, $this->chamada());
        self::assertSame([], $this->executor->reconferidas);
    }

    #[TestDox('6.7, item 4: vale o estado do banco lido com a trava, não o da memória (a proposta decidida por outra requisição recusa)')]
    public function testEstadoDoBancoNaoDaMemoria(): void
    {
        $id = $this->proporAgora();
        self::assertSame(EstadoDaPropostaDoConector::Pendente, $this->propostaPeloId($id)?->getEstado(), 'a proposta pendente fica na memória');
        $this->em()->getConnection()->executeStatement("UPDATE conector_ia_proposta SET estado = 'cancelada', dados = NULL, resumo = NULL WHERE identificador_hash = ?", [hash('sha256', $id)]);

        $this->assertNaoVale($id, $this->chamada());
        self::assertSame([], $this->executor->executadas);
    }

    #[TestDox('a falha antes do flush do executor não deixa nada na memória: um flush depois não grava o que ele persistiu')]
    public function testFalhaLimpaAMemoria(): void
    {
        $id = $this->proporAgora();
        $this->executor->nomeDoEscritorioGravado = 'Só na memória ' . uniqid();
        $this->executor->flushAntesDeFalhar = false;
        $this->executor->falhaAoExecutar = new \RuntimeException('banco caiu');

        try {
            $this->ferramenta()->executar(['proposta_id' => $id, 'resumo' => self::RESUMO], $this->chamada());
            self::fail('a falha deveria seguir');
        } catch (\RuntimeException $erro) {
            self::assertSame('banco caiu', $erro->getMessage());
        }
        $this->em()->flush();

        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM tenant WHERE name = ?', [$this->executor->nomeDoEscritorioGravado]));
        self::assertSame(EstadoDaPropostaDoConector::Pendente, $this->propostaPeloId($id)?->getEstado());
    }

    #[TestDox('a recusa da reconferência desfaz o que o executor tenha gravado ao reconferir')]
    public function testRecusaDesfazOQueAReconferenciaGravou(): void
    {
        $id = $this->proporAgora();
        $nomeAntes = (string) $this->escritorio->getName();
        $escritorioId = $this->escritorio->getId();
        $this->executor->aoReconferir = function (): void {
            $this->escritorio->setName('Mudado na reconferência');
            $this->em()->flush();
        };
        $this->executor->recusaAoReconferir = ErroDaFerramenta::naoEncontrado();

        $this->recusa(fn () => $this->ferramenta()->executar(['proposta_id' => $id, 'resumo' => self::RESUMO], $this->chamada()));

        self::assertSame($nomeAntes, $this->em()->getConnection()->fetchOne('SELECT name FROM tenant WHERE id = ?', [$escritorioId]));
    }

    #[TestDox('6.2, regra 7: resultado que não cabe na resposta desfaz a gravação antes de confirmar, e a proposta fica pendente')]
    public function testResultadoGrandeDemaisDesfaz(): void
    {
        $id = $this->proporAgora();
        $this->executor->nomeDoEscritorioGravado = 'Grande demais ' . uniqid();
        $this->executor->dadosDoResultado = ['texto' => str_repeat('a', ConfirmarPropostaDoConectorUseCase::TETO_DO_RESULTADO)];

        $erro = $this->recusa(fn () => $this->ferramenta()->executar(['proposta_id' => $id, 'resumo' => self::RESUMO], $this->chamada()));

        self::assertSame(ConfirmarPropostaDoConectorUseCase::RESULTADO_GRANDE_DEMAIS, $erro->getMessage());
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM tenant WHERE name = ?', [$this->executor->nomeDoEscritorioGravado]));
        self::assertSame(EstadoDaPropostaDoConector::Pendente, $this->propostaPeloId($id)?->getEstado());
    }

    #[TestDox('12.20: permissão retirada entre a proposta e a confirmação recusa pela linha da tabela, antes do executor; a proposta fica pendente')]
    public function testPermissaoRetirada(): void
    {
        $id = $this->proporAgora();
        foreach ($this->vinculo->getTenantRole()?->getTenantRolePermissions() ?? [] as $ligacao) {
            \assert($ligacao instanceof TenantRolePermission);
            $this->em()->remove($ligacao);
        }
        $this->em()->flush();
        $this->em()->refresh($this->vinculo->getTenantRole());
        static::getContainer()->get(PermissionChecker::class)->reset();

        $erro = $this->recusa(fn () => $this->ferramenta()->executar(['proposta_id' => $id, 'resumo' => self::RESUMO], $this->chamada()));

        self::assertSame(ErroDaFerramenta::SEM_PERMISSAO, $erro->getMessage());
        self::assertSame([], $this->executor->reconferidas);
        self::assertSame(EstadoDaPropostaDoConector::Pendente, $this->propostaPeloId($id)?->getEstado());
    }

    #[TestDox('12.20: a reconferência do executor recusa (pasta excluída, aba ocultada nesse meio tempo): nada é executado e a proposta fica pendente')]
    public function testReconferenciaRecusa(): void
    {
        $id = $this->proporAgora();
        $this->executor->recusaAoReconferir = ErroDaFerramenta::naoEncontrado();

        $erro = $this->recusa(fn () => $this->ferramenta()->executar(['proposta_id' => $id, 'resumo' => self::RESUMO], $this->chamada()));

        self::assertSame(ErroDaFerramenta::NAO_ENCONTRADO, $erro->getMessage());
        self::assertSame([], $this->executor->executadas);
        self::assertSame(EstadoDaPropostaDoConector::Pendente, $this->propostaPeloId($id)?->getEstado());
    }

    #[TestDox('6.7, item 4 (PR 14): o passo depois do COMMIT roda uma vez, fora da transação em que o executor gravou')]
    public function testPassoDepoisDoCommitRodaDepoisDoCommit(): void
    {
        $id = $this->proporAgora();
        $niveis = [];
        $this->executor->depoisDoCommit = function () use (&$niveis): void {
            $niveis[] = $this->em()->getConnection()->getTransactionNestingLevel();
        };

        $resposta = $this->ferramenta()->executar(['proposta_id' => $id, 'resumo' => self::RESUMO], $this->chamada());

        self::assertSame('confirmada', $resposta->dados['estado']);
        self::assertCount(1, $niveis, 'o passo roda uma vez');
        self::assertLessThan($this->executor->nivelAoExecutar, $niveis[0], 'quando o passo roda, a transação da confirmação já confirmou');
        self::assertSame(EstadoDaPropostaDoConector::Confirmada, $this->propostaPeloId($id)?->getEstado());
    }

    #[TestDox('6.7, item 4 (PR 14): o passo depois do COMMIT não roda se o executor recusar ou o resultado passar do teto')]
    public function testPassoDepoisDoCommitNaoRodaSemCommit(): void
    {
        $rodou = 0;
        $this->executor->depoisDoCommit = function () use (&$rodou): void {
            ++$rodou;
        };

        $recusada = $this->proporAgora();
        $this->executor->falhaAoExecutar = new ErroDaFerramenta('teste', 'Recusa de última hora.');
        $this->recusa(fn () => $this->ferramenta()->executar(['proposta_id' => $recusada, 'resumo' => self::RESUMO], $this->chamada()));

        $grande = $this->proporAgora();
        $this->executor->falhaAoExecutar = null;
        $this->executor->dadosDoResultado = ['texto' => str_repeat('a', ConfirmarPropostaDoConectorUseCase::TETO_DO_RESULTADO)];
        $this->recusa(fn () => $this->ferramenta()->executar(['proposta_id' => $grande, 'resumo' => self::RESUMO], $this->chamada()));

        self::assertSame(0, $rodou);
        self::assertSame(EstadoDaPropostaDoConector::Pendente, $this->propostaPeloId($recusada)?->getEstado());
        self::assertSame(EstadoDaPropostaDoConector::Pendente, $this->propostaPeloId($grande)?->getEstado());
    }

    #[TestDox('6.7, item 4 (PR 14): falha no passo depois do COMMIT não muda a resposta nem desfaz nada; vira registro só com a classe do erro')]
    public function testFalhaNoPassoDepoisDoCommitNaoMudaAResposta(): void
    {
        $id = $this->proporAgora();
        $this->executor->nomeDoEscritorioGravado = 'Gravado antes do passo ' . uniqid();
        $this->executor->depoisDoCommit = static function (): void {
            throw new \RuntimeException('disco caiu: segredo-do-arquivo.pdf');
        };
        $logger = new BufferingLogger();

        $resposta = $this->ferramenta(logger: $logger)->executar(['proposta_id' => $id, 'resumo' => self::RESUMO], $this->chamada());

        self::assertSame('confirmada', $resposta->dados['estado']);
        self::assertSame(EstadoDaPropostaDoConector::Confirmada, $this->propostaPeloId($id)?->getEstado());
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM tenant WHERE name = ?', [$this->executor->nomeDoEscritorioGravado]));
        $registros = $logger->cleanLogs();
        self::assertCount(1, $registros);
        self::assertSame('error', $registros[0][0]);
        self::assertSame(['ferramenta' => 'atualizar_pasta', 'acao' => 'editar_dados', 'classe' => \RuntimeException::class], $registros[0][2]);
        self::assertStringNotContainsString('segredo', (string) json_encode($registros));
    }

    #[TestDox('6.7, item 4 (PR 14): executor que limpa o EntityManager (a purga da lixeira) não deixa a proposta desanexada: ela é relida e sai confirmada')]
    public function testPropostaRelidaDepoisDeExecutorQueLimpa(): void
    {
        $id = $this->proporAgora();
        $this->executor->limparAoExecutar = true;

        $resposta = $this->ferramenta()->executar(['proposta_id' => $id, 'resumo' => self::RESUMO], $this->chamada());

        $this->em()->clear();
        $proposta = $this->propostaPeloId($id);
        self::assertSame(EstadoDaPropostaDoConector::Confirmada, $proposta?->getEstado());
        self::assertNull($proposta->getDados());
        self::assertSame([$resposta->dados['resultado']['excluidos'][0]['id']], $proposta->getResultado());
    }

    #[TestDox('6.7, item 4 (PR 14): o que ficou pendente na memória antes do executor que limpa o EntityManager é gravado, não descartado')]
    public function testPendenteAntesDoExecutorQueLimpaEGravado(): void
    {
        $id = $this->proporAgora();
        $escritorioId = $this->escritorio->getId();
        $this->executor->aoReconferir = function (): void {
            $this->escritorio->setName('Pendente antes do executor');
        };
        $this->executor->limparAoExecutar = true;

        $this->ferramenta()->executar(['proposta_id' => $id, 'resumo' => self::RESUMO], $this->chamada());

        self::assertSame('Pendente antes do executor', $this->em()->getConnection()->fetchOne('SELECT name FROM tenant WHERE id = ?', [$escritorioId]));
    }

    #[TestDox('6.7: falha ao gravar desfaz tudo o que o executor gravou, e a proposta continua pendente com os dados')]
    public function testFalhaAoGravarDesfazTudo(): void
    {
        $id = $this->proporAgora();
        $this->executor->nomeDoEscritorioGravado = 'Gravado e desfeito ' . uniqid();
        $this->executor->falhaAoExecutar = new ErroDaFerramenta('teste', 'Recusa de última hora.');

        $erro = $this->recusa(fn () => $this->ferramenta()->executar(['proposta_id' => $id, 'resumo' => self::RESUMO], $this->chamada()));

        self::assertSame('Recusa de última hora.', $erro->getMessage());
        self::assertCount(1, $this->executor->executadas);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM tenant WHERE name = ?', [$this->executor->nomeDoEscritorioGravado]));
        $proposta = $this->propostaPeloId($id);
        self::assertSame(EstadoDaPropostaDoConector::Pendente, $proposta?->getEstado());
        self::assertSame(self::DADOS, $proposta->getDados());
    }

    #[TestDox('6.7, item 6: propor varre as pendentes vencidas do escritório (viram expiradas, sem dados), e não toca as de outro escritório nem as no prazo')]
    public function testVarreduraDasVencidas(): void
    {
        $vencida = $this->proporAgora();
        $b = $this->escritorio('Escritório B');
        $vinculoB = $this->vinculo($this->pessoa, $b, permissoes: ['modules.pastas.view']);
        $doB = $this->propostas()->propor($this->chamada($this->pessoa, $b, $vinculoB, $this->consentimento($this->pessoa, $b, $this->app)), 'atualizar_pasta', 'editar_dados', self::DADOS, self::RESUMO)->dados['proposta']['proposta_id'];
        $this->relogio->sleep(600);
        $noPrazo = $this->proporAgora();

        $this->em()->clear();
        $proposta = $this->propostaPeloId($vencida);
        self::assertSame(EstadoDaPropostaDoConector::Expirada, $proposta?->getEstado());
        self::assertNull($proposta->getDados());
        self::assertNull($proposta->getResumo());
        self::assertSame(EstadoDaPropostaDoConector::Pendente, $this->propostaPeloId($noPrazo)?->getEstado());
        $outra = $this->em()->getRepository(PropostaDoConector::class)->findOneBy(['identificadorHash' => hash('sha256', $doB)]);
        self::assertSame(EstadoDaPropostaDoConector::Pendente, $outra?->getEstado(), 'a varredura é só do escritório da proposta nova');
        self::assertSame(self::DADOS, $outra->getDados());
    }

    #[TestDox('id fora do formato recusa com a mesma mensagem, e campo que não é texto é pedido inválido')]
    public function testEntradaInvalida(): void
    {
        $this->assertNaoVale('nao-e-um-id', $this->chamada());
        $erro = $this->recusa(fn () => $this->ferramenta()->executar(['proposta_id' => 12, 'resumo' => self::RESUMO], $this->chamada()));
        self::assertSame(ErroDaFerramenta::ENTRADA_INVALIDA, $erro->getMessage());
    }

    #[TestDox('5.1, segunda camada: sem mcp:escrita na chamada, a confirmação recusa sem olhar a proposta e a proposta não nasce')]
    public function testSemEscritaNaChamada(): void
    {
        $id = $this->proporAgora();
        $soLeitura = $this->chamada(escopos: ['mcp:leitura']);

        $erro = $this->recusa(fn () => $this->ferramenta()->executar(['proposta_id' => $id, 'resumo' => self::RESUMO], $soLeitura));

        self::assertSame(ErroDaFerramenta::SEM_PERMISSAO, $erro->getMessage());
        self::assertSame([], $this->executor->reconferidas);
        $this->expectException(\LogicException::class);
        $this->propostas()->propor($soLeitura, 'atualizar_pasta', 'editar_dados', self::DADOS, self::RESUMO);
    }

    #[TestDox('8: o teto conector_confirmacao (10 por minuto, por pessoa e escritório) recusa a 11ª confirmação, antes de olhar a proposta')]
    public function testLimiteDeConfirmacoes(): void
    {
        $id = $this->proporAgora();
        for ($i = 0; $i < 10; ++$i) {
            $this->recusa(fn () => $this->ferramenta()->executar(['proposta_id' => $id, 'resumo' => 'errado'], $this->chamada()));
        }

        $erro = $this->recusa(fn () => $this->ferramenta()->executar(['proposta_id' => $id, 'resumo' => self::RESUMO], $this->chamada()));

        self::assertSame(ConfirmarAcaoFerramenta::LIMITE, $erro->getMessage());
        self::assertSame([], $this->executor->executadas);
    }

    private function proporAgora(): string
    {
        return $this->propostas()->propor($this->chamada(), 'atualizar_pasta', 'editar_dados', self::DADOS, self::RESUMO)->dados['proposta']['proposta_id'];
    }

    private function assertNaoVale(string $id, ChamadaDoConector $chamada): void
    {
        $erro = $this->recusa(fn () => $this->ferramenta()->executar(['proposta_id' => $id, 'resumo' => self::RESUMO], $chamada));
        self::assertSame(ConfirmarPropostaDoConectorUseCase::MOTIVO_NAO_VALE, $erro->motivo);
        self::assertSame(ConfirmarPropostaDoConectorUseCase::NAO_VALE, $erro->getMessage());
    }

    private function recusa(callable $chamar): ErroDaFerramenta
    {
        try {
            $chamar();
        } catch (ErroDaFerramenta $erro) {
            return $erro;
        }
        self::fail('a chamada deveria recusar');
    }

    private function propostaPeloId(string $id): ?PropostaDoConector
    {
        return static::getContainer()->get(PropostaDoConectorRepository::class)->buscarPorIdentificadorHash(hash('sha256', $id), $this->escritorio);
    }

    private function contarPropostas(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM conector_ia_proposta WHERE tenant_id = ?', [$this->escritorio->getId()]);
    }

    private function propostas(): PropostasDoConector
    {
        $c = static::getContainer();

        return new PropostasDoConector(
            $c->get(PropostaDoConectorRepository::class),
            $c->get(ConsentimentoDoConectorRepository::class),
            new SegredoOpaco(),
            $this->em(),
            $this->relogio,
            [$this->executor],
        );
    }

    private function ferramenta(?RequisicaoDoConector $requisicao = null, ?LoggerInterface $logger = null): ConfirmarAcaoFerramenta
    {
        $c = static::getContainer();
        $useCase = new ConfirmarPropostaDoConectorUseCase(
            $c->get(PropostaDoConectorRepository::class),
            $c->get(GuardaDaAcaoDoConector::class),
            new SegredoOpaco(),
            $this->em(),
            $this->relogio,
            [$this->executor],
            $logger ?? new NullLogger(),
        );

        return new ConfirmarAcaoFerramenta($useCase, $c->get('limiter.conector_confirmacao'), $requisicao ?? new RequisicaoDoConector());
    }

    /** @param list<string> $escopos */
    private function chamada(?User $pessoa = null, ?Tenant $escritorio = null, ?UserTenant $vinculo = null, ?ConsentimentoDoConector $consentimento = null, array $escopos = ['mcp:leitura', 'mcp:escrita']): ChamadaDoConector
    {
        return new ChamadaDoConector(
            new ContextoDoConector($pessoa ?? $this->pessoa, $escritorio ?? $this->escritorio, $vinculo ?? $this->vinculo),
            concessaoId: 1,
            consentimentoId: (int) ($consentimento ?? $this->consentimento)->getId(),
            hostDoCliente: 'claude.ai',
            escopos: $escopos,
            conexaoValeAte: new \DateTimeImmutable('2026-11-25 10:00:00'),
        );
    }

    private function app(): ClienteDoConector
    {
        $app = new ClienteDoConector('https://claude.ai/oauth/' . uniqid() . '/client.json', TipoDeClienteDoConector::Cimd, 'Claude', ['https://claude.ai/api/mcp/auth_callback'], new \DateTimeImmutable('2026-11-18 09:00:00'));
        $this->em()->persist($app);
        $this->em()->flush();

        return $app;
    }

    private function consentimento(User $pessoa, Tenant $escritorio, ClienteDoConector $app): ConsentimentoDoConector
    {
        $consentimento = new ConsentimentoDoConector($pessoa, $escritorio, $app, ['mcp:leitura', 'mcp:escrita'], new \DateTimeImmutable('2026-11-18 09:00:00'));
        $this->em()->persist($consentimento);
        $this->em()->flush();

        return $consentimento;
    }
}
