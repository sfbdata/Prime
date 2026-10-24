<?php

declare(strict_types=1);

namespace App\Tests\Auditoria\Functional;

use App\Auditoria\Service\EstadoDaTelaNoDesfazer;
use App\Auditoria\UseCase\DesfazerAlteracaoAuditLogUseCase;
use App\Cobranca\Enum\StatusCaso;
use App\Controller\AuditLogController;
use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tarefa\TarefaMensagem;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaChecklistItem;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Entity\PrioridadePasta;
use App\Pasta\EventListener\PastaSomenteLeituraListener;
use App\Pasta\UseCase\RenomearClienteDaPastaUseCase;
use App\Tarefa\Enum\AcaoDoDespacho;
use App\Tarefa\Enum\EtapaDaPasta;
use App\Tarefa\Exception\MetaJaSeguiuAdianteException;
use App\Tarefa\Exception\PassagemRecusadaException;
use App\Tarefa\UseCase\DesfazerReaberturaDaMetaUseCase;
use App\Tests\Factory\Cliente\ClientePJFactory;
use App\Tests\Factory\Cobranca\CarteiraFactory;
use App\Tests\Factory\Cobranca\CasoCobrancaFactory;
use App\Tests\Factory\Cobranca\ObjetoCobrancaFactory;
use App\Tests\Factory\Cobranca\PessoaFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Contracts\Translation\TranslatorInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * Spec `docs/specs/seguranca-d-desfazer-estado.md`: além de quem pode (a guarda da tela), o desfazer só grava o que a
 * tela deixaria gravar no estado atual. A recusa volta como aviso de erro e redirect para a auditoria (não é 403), e
 * nada muda. Cada regra tem o caso que recusa e o que passa.
 */
#[CoversClass(AuditLogController::class)]
#[CoversClass(EstadoDaTelaNoDesfazer::class)]
#[CoversClass(DesfazerAlteracaoAuditLogUseCase::class)]
final class DesfazerSegueOEstadoDaTelaTest extends JusPrimeWebTestCase
{
    use ApoioDoDesfazer;
    use Factories;

    private const AUDITORIA = 'admin.audit.view';
    private const MODULO_METAS = 'modules.tarefas.view';
    private const EDITAR_PASTA = 'resources.pasta.edit';
    private const VER_PASTA = 'resources.pasta.view';

    private const DATA_DA_CONCLUSAO = '2026-10-20 10:00:00';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $tenant;
    private User $colega;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->tenant = new Tenant();
        $this->tenant->setName('Desfazer estado da tela ' . uniqid());
        $this->em->persist($this->tenant);
        $this->colega = $this->pessoa('Colega');
        $this->em->flush();

        $this->fingirOCsrf();
    }

    // ── O registro mudou depois do log ──────────────────────────────────────

    #[TestDox('Meta cujo título mudou depois do log: recusa com aviso, nada muda')]
    public function testMetaQueMudouDepoisRecusa(): void
    {
        $meta = $this->meta($this->pasta(), $this->entrarComAMeta());
        $logId = $this->criarLog(Tarefa::class, (int) $meta->getId(), ['titulo' => ['from' => 'Titulo anterior', 'to' => 'Outro titulo']]);

        $this->desfazer($logId);

        $this->assertRecusado(EstadoDaTelaNoDesfazer::MENSAGEM_REGISTRO_MUDOU);
        self::assertSame('Titulo novo', $this->colunaDaMeta($meta, 'titulo'));
    }

    #[TestDox('Pasta cujo nome da ação mudou depois do log: recusa; como o log deixou: desfaz')]
    public function testPastaQueMudouDepoisRecusaEAQueNaoMudouDesfaz(): void
    {
        $pasta = $this->pasta();
        $mudou = $this->criarLog(Pasta::class, (int) $pasta->getId(), ['nomeAcao' => ['from' => 'acao anterior', 'to' => 'OUTRA ACAO']]);
        $comoDeixou = $this->criarLog(Pasta::class, (int) $pasta->getId(), ['nomeAcao' => ['from' => 'acao anterior', 'to' => 'ACAO ATUAL']]);
        $this->entrarCom([self::AUDITORIA, self::EDITAR_PASTA]);

        $this->desfazer($mudou);
        $this->assertRecusado(EstadoDaTelaNoDesfazer::MENSAGEM_REGISTRO_MUDOU);
        self::assertSame('ACAO ATUAL', $this->colunaDaPasta($pasta, 'nome_acao'));

        $this->desfazer($comoDeixou);
        $this->assertDesfeito();
        self::assertSame('ACAO ANTERIOR', $this->colunaDaPasta($pasta, 'nome_acao'));
    }

    // ── a) Não reabrir a meta que seguiu adiante ────────────────────────────

    #[TestDox('a) Desfazer a conclusão da meta que a passagem levou adiante: recusa com a frase da tela')]
    public function testNaoReabreAMetaQueSeguiuAdiante(): void
    {
        $meta = $this->metaConcluida($this->entrarComAMeta(), seguiuAdiante: true);
        $logId = $this->criarLog(Tarefa::class, (int) $meta->getId(), $this->diffDaConclusao());

        $this->desfazer($logId);

        $frase = static::getContainer()->get(TranslatorInterface::class)->trans(MetaJaSeguiuAdianteException::CHAVE);
        self::assertNotSame(MetaJaSeguiuAdianteException::CHAVE, $frase, 'controle: a chave tem tradução');
        $this->assertRecusado($frase);
        self::assertSame(Tarefa::STATUS_CONCLUIDA, $this->colunaDaMeta($meta, 'status'));
    }

    #[TestDox('a) Desfazer a conclusão de meta concluída pelo menu (sem passagem): reabre')]
    public function testReabreAMetaConcluidaPeloMenu(): void
    {
        $meta = $this->metaConcluida($this->entrarComAMeta(), seguiuAdiante: false);
        $logId = $this->criarLog(Tarefa::class, (int) $meta->getId(), $this->diffDaConclusao());

        $this->desfazer($logId);

        $this->assertDesfeito();
        self::assertSame(Tarefa::STATUS_PENDENTE, $this->colunaDaMeta($meta, 'status'));
        self::assertSame('', $this->colunaDaMeta($meta, 'data_conclusao'));
    }

    // ── b) Data de conclusão válida ao reconcluir ───────────────────────────

    #[TestDox('b) Reconcluir com a data no futuro: recusa com a frase do desfazer da reabertura')]
    public function testReconcluirComDataNoFuturoRecusa(): void
    {
        $meta = $this->meta($this->pasta(), $this->colega, $this->entrarComAMeta());
        $logId = $this->criarLog(Tarefa::class, (int) $meta->getId(), $this->diffDaReabertura((new \DateTimeImmutable('+1 year'))->format(DATE_ATOM)));

        $this->desfazer($logId);

        $this->assertRecusado(DesfazerReaberturaDaMetaUseCase::MENSAGEM_DATA_INVALIDA);
        self::assertSame(Tarefa::STATUS_PENDENTE, $this->colunaDaMeta($meta, 'status'));
    }

    #[TestDox('b) Reconcluir com a data antes da criação da meta: recusa')]
    public function testReconcluirAntesDaCriacaoRecusa(): void
    {
        $meta = $this->meta($this->pasta(), $this->colega, $this->entrarComAMeta());
        $logId = $this->criarLog(Tarefa::class, (int) $meta->getId(), $this->diffDaReabertura('2025-06-01T10:00:00+00:00'));

        $this->desfazer($logId);

        $this->assertRecusado(DesfazerReaberturaDaMetaUseCase::MENSAGEM_DATA_INVALIDA);
        self::assertSame(Tarefa::STATUS_PENDENTE, $this->colunaDaMeta($meta, 'status'));
    }

    #[TestDox('b) Reconcluir com a data entre a criação e agora: conclui de novo com ela')]
    public function testReconcluirComDataValidaDesfaz(): void
    {
        $meta = $this->meta($this->pasta(), $this->colega, $this->entrarComAMeta());
        $logId = $this->criarLog(Tarefa::class, (int) $meta->getId(), $this->diffDaReabertura($this->atom(self::DATA_DA_CONCLUSAO)));

        $this->desfazer($logId);

        $this->assertDesfeito();
        self::assertSame(Tarefa::STATUS_CONCLUIDA, $this->colunaDaMeta($meta, 'status'));
        self::assertStringStartsWith('2026-10-20', $this->colunaDaMeta($meta, 'data_conclusao'));
    }

    // ── c) Etapa só em meta aberta e com a pasta não excluída ───────────────

    #[TestDox('c) A etapa de meta concluída: recusa com a frase da passagem')]
    public function testEtapaDeMetaConcluidaRecusa(): void
    {
        $meta = $this->metaConcluida($this->entrarComAMeta(), seguiuAdiante: false);
        $logId = $this->criarLog(Tarefa::class, (int) $meta->getId(), $this->diffDaEtapa());

        $this->desfazer($logId);

        $this->assertRecusado(PassagemRecusadaException::jaConcluida()->getMessage());
        self::assertSame(EtapaDaPasta::Controladoria->value, $this->colunaDaMeta($meta, 'etapa'));
    }

    #[TestDox('c) A etapa de meta aberta: desfaz')]
    public function testEtapaDeMetaAbertaDesfaz(): void
    {
        $meta = $this->meta($this->pasta(), $this->colega, $this->entrarComAMeta());
        $logId = $this->criarLog(Tarefa::class, (int) $meta->getId(), $this->diffDaEtapa());

        $this->desfazer($logId);

        $this->assertDesfeito();
        self::assertSame(EtapaDaPasta::Abertura->value, $this->colunaDaMeta($meta, 'etapa'));
    }

    // ── e) Coerência entre o status e a data de conclusão ───────────────────

    #[TestDox('e) Só o status volta a concluída, sem a data: recusa (ficaria concluída sem data)')]
    public function testStatusSemADataRecusa(): void
    {
        $meta = $this->meta($this->pasta(), $this->colega, $this->entrarComAMeta());
        $logId = $this->criarLog(Tarefa::class, (int) $meta->getId(), ['status' => ['from' => Tarefa::STATUS_CONCLUIDA, 'to' => Tarefa::STATUS_PENDENTE]]);

        $this->desfazer($logId);

        $this->assertRecusado(EstadoDaTelaNoDesfazer::MENSAGEM_META_INCOERENTE);
        self::assertSame(Tarefa::STATUS_PENDENTE, $this->colunaDaMeta($meta, 'status'));
    }

    #[TestDox('e) Só a data volta, com a meta aberta: recusa (ficaria aberta com data de conclusão)')]
    public function testDataSemOStatusRecusa(): void
    {
        $meta = $this->meta($this->pasta(), $this->colega, $this->entrarComAMeta());
        $logId = $this->criarLog(Tarefa::class, (int) $meta->getId(), ['dataConclusao' => ['from' => $this->atom(self::DATA_DA_CONCLUSAO), 'to' => null]]);

        $this->desfazer($logId);

        $this->assertRecusado(EstadoDaTelaNoDesfazer::MENSAGEM_META_INCOERENTE);
        self::assertSame('', $this->colunaDaMeta($meta, 'data_conclusao'));
    }

    // ── g) Pasta excluída (lápide) é somente leitura ────────────────────────

    #[TestDox('g) Pasta excluída: o desfazer na própria pasta recusa com a frase da lápide')]
    public function testPastaExcluidaRecusa(): void
    {
        $pasta = $this->pasta(excluida: true);
        $logId = $this->criarLog(Pasta::class, (int) $pasta->getId(), ['nomeAcao' => ['from' => 'acao anterior', 'to' => 'ACAO ATUAL']]);
        $this->entrarCom([self::AUDITORIA, self::EDITAR_PASTA]);

        $this->desfazer($logId);

        $this->assertRecusado(PastaSomenteLeituraListener::MENSAGEM);
        self::assertSame('ACAO ATUAL', $this->colunaDaPasta($pasta, 'nome_acao'));
    }

    #[TestDox('g) Meta de pasta excluída: recusa com a frase da lápide')]
    public function testMetaDePastaExcluidaRecusa(): void
    {
        $meta = $this->meta($this->pasta(excluida: true), $this->entrarComAMeta());
        $logId = $this->criarLog(Tarefa::class, (int) $meta->getId(), ['titulo' => ['from' => 'Titulo anterior', 'to' => 'Titulo novo']]);

        $this->desfazer($logId);

        $this->assertRecusado(PastaSomenteLeituraListener::MENSAGEM);
        self::assertSame('Titulo novo', $this->colunaDaMeta($meta, 'titulo'));
    }

    #[TestDox('g) Filha de pasta excluída (item do checklist): recusa; da pasta ativa: desfaz')]
    public function testFilhaDePastaExcluidaRecusa(): void
    {
        $naLapide = $this->itemDoChecklist($this->pasta(excluida: true));
        $naAtiva = $this->itemDoChecklist($this->pasta());
        $this->entrarCom([self::AUDITORIA, self::EDITAR_PASTA]);

        $this->desfazer($naLapide[1]);
        $this->assertRecusado(PastaSomenteLeituraListener::MENSAGEM);
        self::assertSame('ITEM NOVO', $this->tituloDoItem($naLapide[0]));

        $this->desfazer($naAtiva[1]);
        $this->assertDesfeito();
        self::assertSame('ITEM ANTERIOR', $this->tituloDoItem($naAtiva[0]));
    }

    #[TestDox('g) Mensagem de pasta excluída, pelo próprio autor: recusa com a frase da lápide')]
    public function testMensagemDePastaExcluidaRecusa(): void
    {
        $autor = $this->entrarCom([self::AUDITORIA, self::VER_PASTA]);
        $mensagem = (new PastaMensagem())->setPasta($this->pasta(excluida: true))->setTenant($this->tenant)->setAutor($autor)->setConteudo('Texto novo');
        $this->em->persist($mensagem);
        $this->em->flush();
        $logId = $this->criarLog(PastaMensagem::class, (int) $mensagem->getId(), ['conteudo' => ['from' => 'Texto anterior', 'to' => 'Texto novo']]);

        $this->desfazer($logId);

        $this->assertRecusado(PastaSomenteLeituraListener::MENSAGEM);
        self::assertSame('Texto novo', $this->em->getConnection()->fetchOne('SELECT conteudo FROM pasta_mensagem WHERE id = ?', [$mensagem->getId()]));
    }

    // ── h) Nome do cliente montado pela carteira ────────────────────────────

    #[TestDox('h) Nome do cliente de pasta judicializada pela cobrança: recusa com a frase do F2')]
    public function testNomeMontadoPelaCarteiraRecusa(): void
    {
        $pasta = $this->pasta(nomeCliente: 'TEXTO GRAVADO');
        $this->judicializar($pasta);
        $logId = $this->criarLog(Pasta::class, (int) $pasta->getId(), ['nomeCliente' => ['from' => 'NOME ANTIGO', 'to' => 'TEXTO GRAVADO']]);
        $this->entrarCom([self::AUDITORIA, self::EDITAR_PASTA]);

        $this->desfazer($logId);

        $this->assertRecusado(RenomearClienteDaPastaUseCase::MENSAGEM_NOME_MONTADO_PELA_CARTEIRA);
        self::assertSame('TEXTO GRAVADO', $this->colunaDaPasta($pasta, 'nome_cliente'));
    }

    #[TestDox('h) Nome do cliente de pasta sem cobrança: desfaz')]
    public function testNomeLivreDesfaz(): void
    {
        $pasta = $this->pasta(nomeCliente: 'TEXTO GRAVADO');
        $logId = $this->criarLog(Pasta::class, (int) $pasta->getId(), ['nomeCliente' => ['from' => 'NOME ANTIGO', 'to' => 'TEXTO GRAVADO']]);
        $this->entrarCom([self::AUDITORIA, self::EDITAR_PASTA]);

        $this->desfazer($logId);

        $this->assertDesfeito();
        self::assertSame('NOME ANTIGO', $this->colunaDaPasta($pasta, 'nome_cliente'));
    }

    // ── i) Situação da pasta só pela tela ───────────────────────────────────

    #[TestDox('i) A situação da pasta não se desfaz pela auditoria: recusa e aponta a tela da pasta')]
    public function testSituacaoDaPastaRecusa(): void
    {
        $pasta = $this->pasta();
        $logId = $this->criarLog(Pasta::class, (int) $pasta->getId(), ['situacao' => ['from' => Pasta::SITUACAO_ARQUIVADA, 'to' => Pasta::SITUACAO_ATIVA]]);
        $this->entrarCom([self::AUDITORIA, self::EDITAR_PASTA]);

        $this->desfazer($logId);

        $this->assertRecusado(EstadoDaTelaNoDesfazer::MENSAGEM_SO_PELA_TELA_DA_PASTA);
        self::assertSame(Pasta::SITUACAO_ATIVA, $this->colunaDaPasta($pasta, 'situacao'));
    }

    #[TestDox('i) A situação do contrato da pasta, junto com outro campo: recusa tudo')]
    public function testSituacaoDoContratoRecusa(): void
    {
        $pasta = $this->pasta();
        $logId = $this->criarLog(Pasta::class, (int) $pasta->getId(), [
            'nomeAcao' => ['from' => 'acao anterior', 'to' => 'ACAO ATUAL'],
            'situacaoContrato' => ['from' => 'ASSINADO', 'to' => 'PENDENTE'],
        ]);
        $this->entrarCom([self::AUDITORIA, self::EDITAR_PASTA]);

        $this->desfazer($logId);

        $this->assertRecusado(EstadoDaTelaNoDesfazer::MENSAGEM_SO_PELA_TELA_DA_PASTA);
        self::assertSame('PENDENTE', $this->colunaDaPasta($pasta, 'situacao_contrato'));
        self::assertSame('ACAO ATUAL', $this->colunaDaPasta($pasta, 'nome_acao'));
    }

    // ── Etapas saltadas: o log traz texto, o setter quer o enum ─────────────

    #[TestDox('Etapas saltadas: os valores do log voltam como etapas (antes dava erro 500)')]
    public function testEtapasSaltadasDesfaz(): void
    {
        $meta = $this->meta($this->pasta(), $this->colega, $this->entrarComAMeta());
        $logId = $this->criarLog(Tarefa::class, (int) $meta->getId(), ['etapasSaltadas' => ['from' => [EtapaDaPasta::Comercial->value], 'to' => []]]);

        $this->desfazer($logId);

        $this->assertDesfeito();
        self::assertSame([EtapaDaPasta::Comercial->value], json_decode($this->colunaDaMeta($meta, 'etapas_saltadas'), true));
    }

    #[TestDox('Etapas saltadas com valor que não é etapa: recusa como irrecuperável, nada muda')]
    public function testEtapasSaltadasComValorInvalidoRecusa(): void
    {
        $meta = $this->meta($this->pasta(), $this->colega, $this->entrarComAMeta());
        $logId = $this->criarLog(Tarefa::class, (int) $meta->getId(), ['etapasSaltadas' => ['from' => ['inexistente'], 'to' => []]]);

        $this->desfazer($logId);

        $this->assertRecusado(sprintf(DesfazerAlteracaoAuditLogUseCase::MENSAGEM_VALOR_IRRECUPERAVEL, 'etapasSaltadas'));
        self::assertSame([], json_decode($this->colunaDaMeta($meta, 'etapas_saltadas'), true));
    }

    // ── Apoio ───────────────────────────────────────────────────────────────

    /** Quem cuida da meta, com o módulo e a edição da pasta: a guarda da tela deixa desfazer qualquer campo. */
    private function entrarComAMeta(): User
    {
        return $this->entrarCom([self::AUDITORIA, self::MODULO_METAS, self::EDITAR_PASTA]);
    }

    private function pasta(bool $excluida = false, ?string $nomeCliente = null): Pasta
    {
        if (!$this->em->contains($this->colega)) {
            $this->colega = $this->em->find(User::class, $this->colega->getId()) ?? $this->colega;
        }
        $pasta = new Pasta();
        $pasta->setNup('TEST-ESTADO-' . uniqid());
        $pasta->setTenant($this->tenant);
        $pasta->setPrioridade(PrioridadePasta::Normal);
        $pasta->setNomeAcao('acao atual');
        $pasta->setNomeCliente($nomeCliente);
        $pasta->setCriadoPor($this->colega);
        if ($excluida) {
            $pasta->marcarExcluida($this->colega, new \DateTimeImmutable());
        }
        $this->em->persist($pasta);
        $this->em->flush();

        return $pasta;
    }

    /** A meta nasce em 01/01/2026, antes da data de conclusão dos logs. */
    private function meta(Pasta $pasta, User $criador, ?User $responsavel = null): Tarefa
    {
        $meta = new Tarefa();
        $meta->setTitulo('Titulo novo');
        $meta->setDescricao('Descrição da meta');
        $meta->setTenant($this->tenant);
        $meta->setPasta($pasta);
        $meta->setCriadoPor($criador);
        $meta->setPrazo(new \DateTimeImmutable('2026-12-01 00:00:00'));
        $meta->setStatus(Tarefa::STATUS_PENDENTE);
        $meta->setEtapa(EtapaDaPasta::Controladoria);
        if ($responsavel !== null) {
            $meta->addResponsavel($responsavel);
        }
        $this->em->persist($meta);
        $this->em->flush();
        $this->em->getConnection()->executeStatement("UPDATE tarefa SET data_criacao = '2026-01-01 00:00:00' WHERE id = ?", [$meta->getId()]);

        return $meta;
    }

    /** Concluída em 20/10; com `seguiuAdiante`, pela passagem de etapa (o ato "Etapa concluída" no histórico). */
    private function metaConcluida(User $responsavel, bool $seguiuAdiante): Tarefa
    {
        $meta = $this->meta($this->pasta(), $this->colega, $responsavel);
        $meta->setStatus(Tarefa::STATUS_CONCLUIDA);
        $meta->setDataConclusao(new \DateTimeImmutable(self::DATA_DA_CONCLUSAO));
        if ($seguiuAdiante) {
            $ato = (new TarefaMensagem())
                ->setTenant($this->tenant)
                ->setUsuario($responsavel)
                ->setMensagem('Controladoria concluída')
                ->setAcao(AcaoDoDespacho::Etapa)
                ->setCriadoEm(new \DateTimeImmutable(self::DATA_DA_CONCLUSAO));
            $meta->addMensagem($ato);
            $this->em->persist($ato);
        }
        $this->em->flush();

        return $meta;
    }

    /** O que concluir grava: o status vai a concluída e a data aparece. */
    private function diffDaConclusao(): array
    {
        return [
            'status' => ['from' => Tarefa::STATUS_PENDENTE, 'to' => Tarefa::STATUS_CONCLUIDA],
            'dataConclusao' => ['from' => null, 'to' => $this->atom(self::DATA_DA_CONCLUSAO)],
        ];
    }

    /** O que reabrir grava: o status volta a pendente e a data some. */
    private function diffDaReabertura(string $dataAnterior): array
    {
        return [
            'status' => ['from' => Tarefa::STATUS_CONCLUIDA, 'to' => Tarefa::STATUS_PENDENTE],
            'dataConclusao' => ['from' => $dataAnterior, 'to' => null],
        ];
    }

    private function diffDaEtapa(): array
    {
        return ['etapa' => ['from' => EtapaDaPasta::Abertura->value, 'to' => EtapaDaPasta::Controladoria->value]];
    }

    /** No fuso do servidor, como o `AuditLogSubscriber` grava. */
    private function atom(string $data): string
    {
        return (new \DateTimeImmutable($data))->format(DATE_ATOM);
    }

    /** @return array{0: int, 1: int} [id do item, id da linha de auditoria] */
    private function itemDoChecklist(Pasta $pasta): array
    {
        $item = (new PastaChecklistItem())->setPasta($pasta)->setTenant($this->tenant)->setTitulo('Item novo')->setOrdem(1);
        $this->em->persist($item);
        $this->em->flush();
        $id = (int) $item->getId();

        return [$id, $this->criarLog(PastaChecklistItem::class, $id, ['titulo' => ['from' => 'Item anterior', 'to' => 'ITEM NOVO']])];
    }

    /** O caso de cobrança judicializado nesta pasta: o identificador passa a ser montado do credor e do devedor. */
    private function judicializar(Pasta $pasta): void
    {
        $credor = ClientePJFactory::createOne(['tenant' => $this->tenant, 'nomeFantasia' => 'CREDOR DO TESTE']);
        $carteira = CarteiraFactory::createOne(['tenant' => $this->tenant, 'cliente' => $credor]);
        $objeto = ObjetoCobrancaFactory::createOne(['tenant' => $this->tenant, 'carteira' => $carteira]);
        $pessoa = PessoaFactory::createOne(['tenant' => $this->tenant, 'nome' => 'DEVEDOR DO TESTE']);
        CasoCobrancaFactory::createOne([
            'tenant' => $this->tenant,
            'objeto' => $objeto,
            'pessoaCobradaAtual' => $pessoa,
            'status' => StatusCaso::Judicializado,
            'pastaJudicial' => $pasta,
        ]);
    }

    private function tituloDoItem(int $id): string
    {
        return (string) $this->em->getConnection()->fetchOne('SELECT titulo FROM pasta_checklist_item WHERE id = ?', [$id]);
    }

    private function colunaDaMeta(Tarefa $meta, string $coluna): string
    {
        return (string) $this->em->getConnection()->fetchOne("SELECT {$coluna} FROM tarefa WHERE id = ?", [$meta->getId()]);
    }

    private function colunaDaPasta(Pasta $pasta, string $coluna): string
    {
        return (string) $this->em->getConnection()->fetchOne("SELECT {$coluna} FROM pasta WHERE id = ?", [$pasta->getId()]);
    }

    /** Recusa por estado: redirect para a auditoria com o aviso de erro, não 403, e sem aviso de sucesso. */
    private function assertRecusado(string $mensagem): void
    {
        self::assertResponseRedirects('/auditoria');
        $avisos = $this->client->getRequest()->getSession()->getFlashBag();
        self::assertSame([$mensagem], $avisos->peek('error'));
        self::assertSame([], $avisos->peek('success'));
        $this->consumirOsAvisos();
    }

    private function assertDesfeito(): void
    {
        self::assertResponseRedirects('/auditoria');
        $avisos = $this->client->getRequest()->getSession()->getFlashBag();
        self::assertSame([], $avisos->peek('error'));
        $this->consumirOsAvisos();
    }

    /** A página da auditoria mostra (e consome) os avisos: o próximo desfazer do mesmo teste começa sem eles. */
    private function consumirOsAvisos(): void
    {
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
    }
}
