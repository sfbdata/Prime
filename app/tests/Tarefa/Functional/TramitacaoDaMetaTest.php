<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Functional;

use App\Controller\TarefaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Notificacao;
use App\Entity\Permission\Permission;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tarefa\TarefaMensagem;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\CadeadoDaPasta;
use App\Pasta\Entity\ModoDoCadeado;
use App\Repository\TarefaMensagemRepository;
use App\Shared\Doctrine\Transacao\DestinoDaTransacao;
use App\Shared\Doctrine\Transacao\TransacaoComArquivoNovo;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoriaNoContainer;
use App\Tests\Shared\Doubles\ConsultaDeDestinoFixa;
use App\Tests\Shared\Doubles\FalhaDeCommitArmavel;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\NullLogger;
use App\Pasta\Entity\PastaDocumento;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\Tarefa\Controller\DespachoDaMetaController;
use App\Tarefa\Controller\TramitacaoDaMetaController;
use App\Tarefa\Enum\AcaoDoDespacho;
use App\Tarefa\Service\AcessoAMeta;
use App\Tarefa\Enum\NaturezaDoDespacho;
use App\Tarefa\UseCase\LembrarMetaUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Fase 2b da página da meta (spec `minhas-metas-esteira-2210.md`, C3, C4, L9 e L10; desenho 04B de 22/10): @menção no
 * despacho com aviso na Central, anexo juntado aos documentos da pasta, Extrato de tramitação e Lembrar.
 */
#[CoversClass(TarefaController::class)]
#[CoversClass(TramitacaoDaMetaController::class)]
#[CoversClass(DespachoDaMetaController::class)]
#[Group('tarefa')]
final class TramitacaoDaMetaTest extends JusPrimeWebTestCase
{
    private const PDF_MINIMO = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $escritorio;
    private User $criador;
    private User $responsavel;
    private User $colega;
    private Tarefa $meta;
    /** @var list<string> */
    private array $temporarios = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->instalarCsrfFalso();

        $this->escritorio = $this->criarEscritorio('Maceió');
        $this->criador = $this->criarPessoa($this->escritorio, 'Carla Dias');
        $this->responsavel = $this->criarPessoa($this->escritorio, 'Rui Prado');
        $this->colega = $this->criarPessoa($this->escritorio, 'Olga Lima', false);
        $this->meta = $this->criarMeta($this->escritorio, $this->criador, $this->responsavel);
    }

    protected function tearDown(): void
    {
        FalhaDeCommitArmavel::desarmar();
        foreach ($this->temporarios as $caminho) {
            @unlink($caminho);
        }
        parent::tearDown();
    }

    // ── @menção (C3) ──

    #[TestDox('@menção: o mencionado é avisado na Central com link ao despacho; a tela mostra "quem → para" e o destaque, nunca o token')]
    public function testMencaoAvisaEMostra(): void
    {
        $this->logarComTenant($this->client, $this->criador, $this->escritorio);
        $ruiId = (int) $this->responsavel->getId();

        $this->despachar(['mensagem' => "Veja @[Rui](user:{$ruiId}), por favor."]);

        $despacho = $this->unicoDespacho();
        $avisos = $this->avisos($this->responsavel, Notificacao::TIPO_PASTA_MENCAO_REGISTRO);
        self::assertCount(1, $avisos);
        self::assertSame('/tarefas/' . $this->meta->getId() . '#despacho-' . $despacho->getId(), $avisos[0]->getUrl());
        self::assertStringStartsWith('Carla mencionou você na Pasta ', $avisos[0]->getTitulo());
        self::assertStringEndsWith(' · Meta #' . ($this->meta->getNumero() ?? $this->meta->getId()), $avisos[0]->getTitulo());
        self::assertSame('Veja @Rui Prado, por favor.', $avisos[0]->getMensagem());

        $crawler = $this->client->request('GET', '/tarefas/' . $this->meta->getId());
        self::assertResponseIsSuccessful();
        $item = $crawler->filter('#despacho-' . $despacho->getId());
        self::assertSame('Carla Dias Rui Prado', trim((string) preg_replace('/\s+/u', ' ', $item->filter('.despacho-quem')->text())));
        self::assertSame('@Rui Prado', $item->filter('.despacho-texto .ps-mencao')->text());
        self::assertStringNotContainsString('(user:', $item->html());
        self::assertCount(1, $crawler->filter('.despacho-campo[data-mencionaveis-url="/pasta/' . $this->meta->getPasta()->getId() . '/mencionaveis"] > textarea[data-mencoes-simples]'));
        self::assertSame('Escreva o despacho. Digite @ para marcar alguém da equipe.', $crawler->filter('#tarefaMensagemConteudo')->attr('placeholder'));
    }

    #[TestDox('@menção de alguém de outro escritório: vira texto comum e ninguém de lá é avisado (multi-tenant)')]
    public function testMencaoDeOutroEscritorio(): void
    {
        $outro = $this->criarEscritorio(null);
        $estranho = $this->criarPessoa($outro, 'Ivo Reis');
        $this->logarComTenant($this->client, $this->criador, $this->escritorio);

        $this->despachar(['mensagem' => 'Para @[Ivo](user:' . $estranho->getId() . ').']);

        self::assertStringNotContainsString('user:', $this->unicoDespacho()->getMensagem());
        self::assertCount(0, $this->avisos($estranho, Notificacao::TIPO_PASTA_MENCAO_REGISTRO));
        $crawler = $this->client->request('GET', '/tarefas/' . $this->meta->getId());
        self::assertCount(0, $crawler->filter('.despacho-para'));
    }

    #[TestDox('@menção de nome com "&" no campo simples: vira token, o mencionado é avisado e a tela mostra o nome do banco')]
    public function testMencaoDeNomeComEComercial(): void
    {
        $socios = $this->criarPessoa($this->escritorio, 'Ana & Bia <Sócias>');
        $this->logarComTenant($this->client, $this->criador, $this->escritorio);

        // O que o pasta-mencoes.js manda do campo simples: o rótulo sem "<>" e sem escape (o servidor escapa).
        $this->despachar(['mensagem' => 'Veja @[Ana & Bia Sócias](user:' . $socios->getId() . ').']);

        self::assertCount(1, $this->avisos($socios, Notificacao::TIPO_PASTA_MENCAO_REGISTRO));
        $crawler = $this->client->request('GET', '/tarefas/' . $this->meta->getId());
        self::assertSame('@Ana & Bia Sócias', $crawler->filter('#timelineList .despacho-texto .ps-mencao')->text());
    }

    #[TestDox('XSS: o texto do despacho sai escapado; marcação no rótulo da menção é trocada pelo nome do banco')]
    public function testTextoEscapado(): void
    {
        $this->logarComTenant($this->client, $this->criador, $this->escritorio);

        $this->despachar(['mensagem' => '<script>alert(1)</script> @[<img src=x onerror=alert(1)>](user:' . $this->responsavel->getId() . ')']);

        $crawler = $this->client->request('GET', '/tarefas/' . $this->meta->getId());
        $html = (string) $crawler->filter('#timelineList')->html();
        self::assertStringNotContainsString('<script>alert(1)', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString('onerror', $html, 'o rótulo digitado não vale: vale o nome do banco');
        self::assertSame('@Rui Prado', $crawler->filter('#timelineList .despacho-texto .ps-mencao')->text());
        self::assertStringContainsString('<script>alert(1)</script>', $crawler->filter('#timelineList .despacho-texto')->text(), 'aparece como texto');
    }

    // ── Anexo juntado à pasta (C4) ──

    #[TestDox('Anexo: vira documento da pasta ("Despacho · Meta #N", Demais documentos, quem enviou) e a tela mostra "nome · juntado à pasta"')]
    public function testAnexoJuntadoAPasta(): void
    {
        $this->logarComTenant($this->client, $this->criador, $this->escritorio);

        $this->despachar(['mensagem' => ''], $this->arquivo('peticao.pdf', self::PDF_MINIMO, 'application/pdf'));

        $despacho = $this->unicoDespacho();
        $documento = $this->em->find(PastaDocumento::class, (int) $despacho->getDocumentoJuntadoId());
        self::assertInstanceOf(PastaDocumento::class, $documento);
        try {
            self::assertSame('[Arquivo anexado]', $despacho->getMensagem());
            self::assertSame($this->meta->getPasta()->getId(), $documento->getPasta()?->getId());
            self::assertSame($this->escritorio->getId(), $documento->getTenant()?->getId());
            self::assertSame(PastaDocumento::CATEGORIA_DEMAIS, $documento->getCategoria());
            self::assertSame('Despacho · Meta #' . ($this->meta->getNumero() ?? $this->meta->getId()), $documento->getDescricao());
            self::assertSame('peticao.pdf', $documento->getNomeOriginal());
            self::assertSame($this->criador->getId(), $documento->getEnviadoPor()?->getId());
            self::assertMatchesRegularExpression('/^\d{4}\.\d{4}$/', (string) $despacho->getCodigo());

            $crawler = $this->client->request('GET', '/tarefas/' . $this->meta->getId());
            $juntado = $crawler->filter('#despacho-' . $despacho->getId() . ' .despacho-juntado');
            self::assertCount(1, $juntado);
            self::assertStringContainsString('peticao.pdf', $juntado->text());
            self::assertStringContainsString('juntado à pasta', $juntado->text());
            self::assertSame('/pasta/documento/' . $documento->getId() . '/visualizar', $juntado->filter('a')->attr('href'));
            self::assertCount(0, $crawler->filter('#despacho-' . $despacho->getId() . ' .tarefa-anexo-chip'), 'o chip é só do anexo antigo');
        } finally {
            static::getContainer()->get(RemocaoAposTransacao::class)->remover([ChavesDePasta::documento($documento)], 'teste');
        }
    }

    #[TestDox('Anexo: falha dentro do upload DEPOIS de gravar o arquivo (o flush do documento): nada no banco e o arquivo sai do storage')]
    public function testFalhaDentroDoUploadApagaOArquivo(): void
    {
        $duble = ArmazenamentoEmMemoriaNoContainer::instalarEm(static::getContainer());
        $this->bancoRecusaDocumentos();
        $this->logarComTenant($this->client, $this->criador, $this->escritorio);

        $this->postarDespacho(['mensagem' => 'Segue.'], $this->arquivo('peticao.pdf', self::PDF_MINIMO, 'application/pdf'));

        self::assertResponseStatusCodeSame(500);
        self::assertCount(1, $duble->memoria->chavesGravadas, 'o arquivo chegou a ser gravado antes da falha');
        self::assertFalse($duble->memoria->existe($duble->memoria->ultimaGravada()), 'falha antes do COMMIT: o arquivo novo sai');
        self::assertSame(0, $this->contarNoBanco('pasta_documento', 'pasta_id', (int) $this->meta->getPasta()->getId()));
        self::assertSame(0, $this->contarNoBanco('tarefa_mensagem', 'tarefa_id', (int) $this->meta->getId()));
    }

    #[TestDox('Anexo: COMMIT recusado e o banco PROVA que não confirmou: o arquivo sai')]
    public function testCommitRecusadoComProvaApagaOArquivo(): void
    {
        $duble = ArmazenamentoEmMemoriaNoContainer::instalarEm(static::getContainer());
        $consulta = $this->bancoResponde(DestinoDaTransacao::NaoConfirmada);
        FalhaDeCommitArmavel::recusarProximoCommit();
        $this->logarComTenant($this->client, $this->criador, $this->escritorio);

        $this->postarDespacho(['mensagem' => 'Segue.'], $this->arquivo('peticao.pdf', self::PDF_MINIMO, 'application/pdf'));

        self::assertResponseStatusCodeSame(500);
        self::assertSame(1, FalhaDeCommitArmavel::$disparos, 'o COMMIT do despacho é que falhou');
        self::assertCount(1, $consulta->perguntados, 'o destino foi perguntado ao banco');
        self::assertFalse($duble->memoria->existe($duble->memoria->ultimaGravada()));
        self::assertSame(0, $this->contarNoBanco('pasta_documento', 'pasta_id', (int) $this->meta->getPasta()->getId()));
    }

    #[TestDox('Anexo: COMMIT com a resposta perdida e sem prova do destino: o documento pode ter ficado, e o arquivo FICA')]
    public function testCommitIncertoPreservaOArquivo(): void
    {
        $duble = ArmazenamentoEmMemoriaNoContainer::instalarEm(static::getContainer());
        FalhaDeCommitArmavel::perderRespostaDoProximoCommit();
        $this->logarComTenant($this->client, $this->criador, $this->escritorio);

        $this->postarDespacho(['mensagem' => 'Segue.'], $this->arquivo('peticao.pdf', self::PDF_MINIMO, 'application/pdf'));

        self::assertResponseStatusCodeSame(500);
        self::assertSame(1, FalhaDeCommitArmavel::$disparos);
        self::assertTrue($duble->memoria->existe($duble->memoria->ultimaGravada()), 'sem prova de que o COMMIT não aconteceu, o arquivo não sai');
        self::assertSame(1, $this->contarNoBanco('pasta_documento', 'pasta_id', (int) $this->meta->getPasta()->getId()), 'o banco confirmou: o documento aponta para o arquivo');
    }

    #[TestDox('Anexo: com o despacho gravado, o arquivo continua no storage (nada é apagado no caminho feliz)')]
    public function testAnexoGravadoFicaNoStorage(): void
    {
        $duble = ArmazenamentoEmMemoriaNoContainer::instalarEm(static::getContainer());
        $this->logarComTenant($this->client, $this->criador, $this->escritorio);

        $this->despachar(['mensagem' => ''], $this->arquivo('peticao.pdf', self::PDF_MINIMO, 'application/pdf'));

        $documento = $this->em->find(PastaDocumento::class, (int) $this->unicoDespacho()->getDocumentoJuntadoId());
        self::assertInstanceOf(PastaDocumento::class, $documento);
        self::assertTrue($duble->memoria->existe(ChavesDePasta::documento($documento)));
        self::assertSame([], $duble->memoria->excluidas);
    }

    #[TestDox('Anexo de tipo recusado pela regra dos documentos: aviso, nenhum despacho e nenhum documento')]
    public function testAnexoRecusado(): void
    {
        $this->logarComTenant($this->client, $this->criador, $this->escritorio);
        $antes = $this->documentosDaPasta();

        $this->despachar(['mensagem' => 'Segue.', 'acao' => 'enviar'], $this->arquivo('virus.exe', "MZ\x90\x00\x03" . str_repeat("\x00", 64), 'application/x-msdownload'));

        self::assertCount(0, $this->despachosDaMeta());
        self::assertSame($antes, $this->documentosDaPasta());
        self::assertSame(Tarefa::STATUS_PENDENTE, $this->em->find(Tarefa::class, $this->meta->getId())?->getStatus());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Nada foi gravado.');
    }

    // ── Extrato (L9) ──

    #[TestDox('Extrato: cabeçalho, DEMANDA e cada item numerado com natureza, quem → para, Cumprir até, ciência e certidão')]
    public function testExtrato(): void
    {
        $prazo = (new \DateTimeImmutable('today'))->modify('+3 days');
        $despacho = $this->despachoGravado($this->criador, NaturezaDoDespacho::Providencia, 'Juntar a procuração de &#64;[Rui Prado](user:' . $this->responsavel->getId() . ').', $prazo);
        $despacho->darCiencia($this->responsavel, new \DateTimeImmutable('2026-10-20 10:00'));
        $despacho->certificar($this->responsavel, 'Certifico que juntei.', new \DateTimeImmutable('2026-10-21 11:00'));
        $this->em->flush();

        $this->logarComTenant($this->client, $this->colega, $this->escritorio);
        $crawler = $this->client->request('GET', '/tarefas/' . $this->meta->getId() . '/extrato');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Extrato de tramitação');
        $cabeca = 'Meta #' . ($this->meta->getNumero() ?? $this->meta->getId()) . ' · Meta do despacho · Pasta ' . $this->meta->getPasta()->getNup();
        self::assertStringContainsString($cabeca, $crawler->filter('.ext-topo')->text());
        self::assertStringContainsString('Emitido em', $crawler->filter('.ext-topo')->text());
        $blocos = $crawler->filter('body > .ext-d');
        self::assertStringContainsString('DEMANDA', $blocos->eq(0)->text());
        self::assertStringContainsString('Carla Dias', $blocos->eq(0)->text());
        self::assertStringContainsString('Descrição', $blocos->eq(0)->text());
        $ultimo = $blocos->last();
        self::assertStringContainsString('Para providência', $ultimo->text());
        self::assertMatchesRegularExpression('/DESPACHO \d+/', $ultimo->text());
        self::assertStringContainsString('Carla Dias → Rui Prado', (string) preg_replace('/\s+/u', ' ', $ultimo->text()));
        self::assertStringContainsString('@Rui Prado', $ultimo->text());
        self::assertStringNotContainsString('(user:', $ultimo->html());
        $ciente = $ultimo->filter('.ext-m')->reduce(static fn ($n): bool => str_starts_with(trim($n->text()), 'Ciente:'));
        self::assertCount(1, $ciente);
        self::assertMatchesRegularExpression('/^Ciente: Rui( Prado)?$/u', trim($ciente->text()), 'só o nome, sem a hora (04B L150)');
        self::assertCount(0, $crawler->filter('button, [data-imprimir]'), 'sem botão Imprimir: a impressão é automática');
        self::assertStringNotContainsString('juntado à pasta', $crawler->filter('body')->text(), 'sem a linha do anexo');
        self::assertStringContainsString('Certifico que juntei. · Rui Prado,', $ultimo->filter('.ext-c')->text());
        self::assertCount(0, $ultimo->filter('.ext-m:contains("Cumprir até")'), 'com certidão o "Cumprir até" some, como na tela');
    }

    #[TestDox('Extrato: só leitura (abrir não dá ciência); outro escritório 404; sem o módulo Metas 403; a página tem o botão no cabeçalho DESPACHO')]
    public function testExtratoGuardas(): void
    {
        $despacho = $this->despachoGravado($this->criador);
        $outro = $this->criarEscritorio(null);
        $estranho = $this->criarPessoa($outro, 'Ivo Reis');
        $semModulo = $this->criarPessoa($this->escritorio, 'Sem Metas', false, false);
        $this->logarComTenant($this->client, $this->responsavel, $this->escritorio);
        $this->client->request('GET', '/tarefas/' . $this->meta->getId() . '/extrato');
        self::assertResponseIsSuccessful();
        self::assertNull(
            $this->em->getConnection()->fetchOne('SELECT ciente_em FROM tarefa_mensagem WHERE id = :id', ['id' => $despacho->getId()]),
            'o extrato não dá ciência',
        );

        $this->logarComTenant($this->client, $estranho, $outro);
        $this->client->request('GET', '/tarefas/' . $this->meta->getId() . '/extrato');
        self::assertResponseStatusCodeSame(404);

        $this->logarComTenant($this->client, $semModulo, $this->escritorio);
        $this->client->request('GET', '/tarefas/' . $this->meta->getId() . '/extrato');
        self::assertResponseStatusCodeSame(403);

        $this->logarComTenant($this->client, $this->criador, $this->escritorio);
        $crawler = $this->client->request('GET', '/tarefas/' . $this->meta->getId());
        $link = $crawler->filter('#tarefa-timeline-card > .despacho-cabeca-bloco > a.despacho-extrato[data-extrato]');
        self::assertCount(1, $link);
        self::assertSame('/tarefas/' . $this->meta->getId() . '/extrato', $link->attr('href'));
        self::assertSame('Imprimir ou salvar a tramitação em PDF', $link->attr('title'));
        self::assertCount(1, $link->filter('i.bi-printer'));
    }

    // ── Lembrar (L10) ──

    #[TestDox('Lembrar: avisa o responsável na Central, grava "Lembrete enviado para ..." no histórico e volta com o aviso do desenho')]
    public function testLembrar(): void
    {
        $this->logarComTenant($this->client, $this->criador, $this->escritorio);

        $this->lembrar();

        self::assertResponseRedirects('/tarefas/' . $this->meta->getId() . '?lembrete=enviado');
        $avisos = $this->avisos($this->responsavel, LembrarMetaUseCase::TIPO_NOTIFICACAO);
        self::assertCount(1, $avisos);
        self::assertSame('Lembrete: meta aguardando despacho · Pasta ' . $this->meta->getPasta()->getNup(), $avisos[0]->getTitulo());
        self::assertSame('Meta do despacho está com você há 0 dia(s).', $avisos[0]->getMensagem());
        $lembrete = $this->unicoDespacho();
        self::assertSame(AcaoDoDespacho::Lembrete, $lembrete->getAcao());
        self::assertSame('<p>Lembrete enviado para Rui Prado.</p>', $lembrete->getMensagem());

        $crawler = $this->client->followRedirect();
        self::assertSame('Lembrete enviado no Chat I.A.', trim($crawler->filter('.meta-toast[data-meta-toast]')->text()));
        $item = $crawler->filter('#despacho-' . $lembrete->getId());
        self::assertStringContainsString('Lembrete enviado para Rui Prado.', $item->text());
        self::assertCount(0, $item->filter('.btn-editar-msg'), 'lembrete não se edita');
        self::assertCount(0, $item->filter('.despacho-certificar'));
        self::assertCount(0, $item->filter('.despacho-registro'));
    }

    #[TestDox('Lembrar: o segundo em menos de uma hora é recusado com aviso, sem novo aviso nem novo registro')]
    public function testLembrarUmaVezPorHora(): void
    {
        $this->logarComTenant($this->client, $this->criador, $this->escritorio);
        $this->lembrar();
        $this->lembrar();

        self::assertCount(1, $this->avisos($this->responsavel, LembrarMetaUseCase::TIPO_NOTIFICACAO));
        self::assertCount(1, $this->despachosDaMeta());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Aguarde uma hora para lembrar de novo.');
    }

    #[TestDox('Lembrar: o trabalho roda sob a trava advisory da meta (4718)')]
    public function testLembrarRodaSobATravaDaMeta(): void
    {
        $repositorio = static::getContainer()->get(TarefaMensagemRepository::class);
        $metaId = (int) $this->meta->getId();

        self::assertSame(0, $this->travasDoLembrete($metaId));
        $durante = $repositorio->emTransacaoTravadaPelaMeta($metaId, fn (): int => $this->travasDoLembrete($metaId));

        // Sob o DAMA o COMMIT não acontece de verdade, então a soltura da trava não é observável aqui.
        self::assertSame(1, $durante);
    }

    #[TestDox('Lembrar: CSRF inválido 403; outro escritório 404; nada gravado. O botão fica na faixa Aguardando')]
    public function testLembrarGuardas(): void
    {
        $this->logarComTenant($this->client, $this->criador, $this->escritorio);
        $this->client->request('POST', '/tarefas/' . $this->meta->getId() . '/lembrar', ['_token' => 'errado']);
        self::assertResponseStatusCodeSame(403);

        $outro = $this->criarEscritorio(null);
        $estranho = $this->criarPessoa($outro, 'Ivo Reis');
        $this->logarComTenant($this->client, $estranho, $outro);
        $this->lembrar();
        self::assertResponseStatusCodeSame(404);
        self::assertCount(0, $this->despachosDaMeta());
        self::assertCount(0, $this->avisos($this->responsavel, LembrarMetaUseCase::TIPO_NOTIFICACAO));

        $this->logarComTenant($this->client, $this->criador, $this->escritorio);
        $crawler = $this->client->request('GET', '/tarefas/' . $this->meta->getId());
        $botao = $crawler->filter('.meta-aguardando > form.meta-lembrar-form[action="/tarefas/' . $this->meta->getId() . '/lembrar"] > button.meta-lembrar');
        self::assertCount(1, $botao);
        self::assertSame('Lembrar', trim($botao->text()));
        self::assertSame('Enviar lembrete no Chat I.A', $botao->attr('title'));
        self::assertCount(1, $botao->filter('i.bi-bell'));
    }

    // ── Cadeado da pasta ──

    #[TestDox('Cadeado: pasta da meta oculta para quem acessa recusa a página, o Extrato, o Lembrar, o despacho com anexo e o certificar; nada gravado')]
    public function testCadeadoEscondeAMeta(): void
    {
        $duble = ArmazenamentoEmMemoriaNoContainer::instalarEm(static::getContainer());
        $despacho = $this->despachoGravado($this->criador, NaturezaDoDespacho::Providencia);
        $this->trancarAPasta();
        $id = (int) $this->meta->getId();
        $trancada = '/pasta/' . $this->meta->getPasta()->getId();
        $this->logarComTenant($this->client, $this->colega, $this->escritorio);

        // A barreira do cadeado (`BarreiraDoCadeadoListener`) chega antes do controller: leitura 403, escrita volta
        // para a tela da pasta trancada. O 404 do `AcessoAMeta` é a segunda camada (teste abaixo).
        $this->client->request('GET', '/tarefas/' . $id);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/tarefas/' . $id . '/extrato');
        self::assertResponseStatusCodeSame(403);
        $this->lembrar();
        self::assertResponseRedirects($trancada);
        $this->postarDespacho(['mensagem' => 'Segue.'], $this->arquivo('peticao.pdf', self::PDF_MINIMO, 'application/pdf'));
        self::assertResponseRedirects($trancada);
        $this->client->request('POST', '/tarefas/mensagem/' . $despacho->getId() . '/certificar', ['_token' => 'TOKEN_certificar_despacho_' . $despacho->getId(), 'certidao' => 'Certifico que fiz.']);
        self::assertResponseRedirects($trancada);

        self::assertSame([], $duble->memoria->chavesGravadas, 'o anexo nem chegou ao storage');
        self::assertSame(1, $this->contarNoBanco('tarefa_mensagem', 'tarefa_id', $id), 'só o despacho de antes');
        self::assertSame(0, $this->contarNoBanco('pasta_documento', 'pasta_id', (int) $this->meta->getPasta()->getId()));
        self::assertNull($this->em->getConnection()->fetchOne('SELECT certidao FROM tarefa_mensagem WHERE id = :id', ['id' => $despacho->getId()]));
        self::assertCount(0, $this->avisos($this->responsavel, LembrarMetaUseCase::TIPO_NOTIFICACAO));

        $this->logarComTenant($this->client, $this->criador, $this->escritorio);
        $this->client->request('GET', '/tarefas/' . $id);
        self::assertResponseIsSuccessful('o primário segue vendo');
    }

    #[TestDox('Cadeado: a guarda da meta (AcessoAMeta) responde 404 para a pasta oculta, como a lista; quem vê a pasta passa')]
    public function testGuardaDaMetaDa404NaPastaOculta(): void
    {
        $this->trancarAPasta();
        $guarda = static::getContainer()->get(AcessoAMeta::class);

        $guarda->exigir($this->meta, $this->escritorio, $this->criador);
        $this->expectException(NotFoundHttpException::class);
        $guarda->exigir($this->meta, $this->escritorio, $this->colega);
    }

    private function trancarAPasta(): void
    {
        $this->em->persist(new CadeadoDaPasta($this->escritorio, $this->meta->getPasta(), ModoDoCadeado::Todos, $this->criador, new \DateTimeImmutable()));
        $this->em->flush();
    }

    // ── Apoio ──

    /** @param array<string, string> $campos */
    private function postarDespacho(array $campos, ?UploadedFile $arquivo = null): void
    {
        $id = (int) $this->meta->getId();
        $this->client->request('POST', '/tarefas/' . $id . '/mensagem', $campos + ['_token' => 'TOKEN_mensagem_tarefa_' . $id, 'acao' => ''], $arquivo === null ? [] : ['arquivo_mensagem' => $arquivo]);
    }

    /** Pela conexão: depois de uma transação que falhou, o EntityManager está fechado. */
    private function contarNoBanco(string $tabela, string $coluna, int $valor): int
    {
        return (int) $this->em->getConnection()->fetchOne(sprintf('SELECT count(*) FROM %s WHERE %s = :v', $tabela, $coluna), ['v' => $valor]);
    }

    private function travasDoLembrete(int $metaId): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            "SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid() AND granted AND classid = :classe AND objid::bigint = :objid AND objsubid = 2",
            ['classe' => TarefaMensagemRepository::CLASSE_TRAVA_DO_LEMBRETE, 'objid' => $metaId],
        );
    }

    /** O flush que insere o documento da pasta (dentro do upload, depois de gravar o arquivo) falha. */
    private function bancoRecusaDocumentos(): void
    {
        $listener = new class () {
            public function onFlush(OnFlushEventArgs $args): void
            {
                foreach ($args->getObjectManager()->getUnitOfWork()->getScheduledEntityInsertions() as $entidade) {
                    if ($entidade instanceof PastaDocumento) {
                        throw new \RuntimeException('banco recusou o documento');
                    }
                }
            }
        };
        $this->em->getEventManager()->addEventListener([Events::onFlush], $listener);
    }

    private function bancoResponde(DestinoDaTransacao $destino): ConsultaDeDestinoFixa
    {
        $container = static::getContainer();
        $consulta = new ConsultaDeDestinoFixa($destino);
        $container->set(TransacaoComArquivoNovo::class, new TransacaoComArquivoNovo(
            $container->get(EntityManagerInterface::class),
            $consulta,
            $container->get(RemocaoAposTransacao::class),
            new NullLogger(),
        ));

        return $consulta;
    }

    /** @param array<string, string> $campos */
    private function despachar(array $campos, ?UploadedFile $arquivo = null): void
    {
        $id = (int) $this->meta->getId();
        $this->client->request('POST', '/tarefas/' . $id . '/mensagem', $campos + ['_token' => 'TOKEN_mensagem_tarefa_' . $id, 'acao' => ''], $arquivo === null ? [] : ['arquivo_mensagem' => $arquivo]);
        self::assertResponseRedirects();
    }

    private function lembrar(): void
    {
        $id = (int) $this->meta->getId();
        $this->client->request('POST', '/tarefas/' . $id . '/lembrar', ['_token' => 'TOKEN_lembrar_meta_' . $id]);
    }

    private function arquivo(string $nome, string $conteudo, string $mime): UploadedFile
    {
        $caminho = sys_get_temp_dir() . '/despacho_' . bin2hex(random_bytes(6));
        file_put_contents($caminho, $conteudo);
        $this->temporarios[] = $caminho;

        return new UploadedFile($caminho, $nome, $mime, null, true);
    }

    /** @return list<Notificacao> */
    private function avisos(User $para, string $tipo): array
    {
        $this->em->clear();

        return $this->em->getRepository(Notificacao::class)->findBy(['usuario' => $para->getId(), 'tipo' => $tipo]);
    }

    /** @return list<int> */
    private function documentosDaPasta(): array
    {
        return array_map('intval', $this->em->getConnection()->fetchFirstColumn('SELECT id FROM pasta_documento WHERE pasta_id = :p ORDER BY id', ['p' => $this->meta->getPasta()->getId()]));
    }

    private function despachoGravado(User $autor, ?NaturezaDoDespacho $natureza = null, string $texto = 'Texto do despacho', ?\DateTimeImmutable $prazo = null): TarefaMensagem
    {
        $despacho = (new TarefaMensagem())->setUsuario($autor)->setTenant($this->escritorio)->setMensagem('<p>' . $texto . '</p>');
        $despacho->setNatureza($natureza)->setPrazoCumprimento($prazo)->setAcao(AcaoDoDespacho::Despacho)->registrar(sprintf('%04d.%04d', random_int(0, 9999), random_int(0, 9999)), (string) $autor->getFullName(), 'Sócia');
        $this->meta->addMensagem($despacho);
        $this->em->persist($despacho);
        $this->em->flush();

        return $despacho;
    }

    /** @return list<TarefaMensagem> */
    private function despachosDaMeta(): array
    {
        $this->em->clear();

        return $this->em->getRepository(TarefaMensagem::class)->findBy(['tarefa' => $this->meta->getId()]);
    }

    private function unicoDespacho(): TarefaMensagem
    {
        $despachos = $this->despachosDaMeta();
        self::assertCount(1, $despachos);

        return $despachos[0];
    }

    private function criarEscritorio(?string $cidade): Tenant
    {
        $tenant = (new Tenant())->setCidade($cidade)->setEstado('AL');
        $tenant->setName('Escritório Despacho ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function criarPessoa(Tenant $tenant, string $nome, bool $primario = true, bool $comModulo = true): User
    {
        $user = new User();
        $user->setEmail('despacho_' . uniqid() . '@test.com');
        $user->setFullName($nome);
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('x');
        $this->em->persist($user);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Papel ' . uniqid());
        $role->setIsSystem($primario);
        $this->em->persist($role);
        if (!$primario && $comModulo) {
            $this->darPermissao($role, 'modules.tarefas.view');
        }

        $vinculo = new UserTenant($user, $tenant);
        $vinculo->setTenantRole($role);
        $this->em->persist($vinculo);
        $this->em->flush();

        return $user;
    }

    private function darPermissao(TenantRole $role, string $codigo): void
    {
        $permissao = $this->em->getRepository(Permission::class)->findOneBy(['code' => $codigo]);
        if ($permissao === null) {
            $permissao = new Permission();
            $permissao->setCode($codigo);
            $permissao->setDescription($codigo);
            $permissao->setGroup('modules');
            $this->em->persist($permissao);
        }
        $vinculo = new TenantRolePermission();
        $vinculo->setTenantRole($role);
        $vinculo->setPermission($permissao);
        $this->em->persist($vinculo);
        $role->getTenantRolePermissions()->add($vinculo);
    }

    private function criarMeta(Tenant $tenant, User $criador, User $responsavel): Tarefa
    {
        $pasta = new Pasta();
        $pasta->setNup('TEST-DESP-' . uniqid());
        $pasta->setTenant($tenant);
        $pasta->setCriadoPor($criador);
        $this->em->persist($pasta);

        $meta = new Tarefa();
        $meta->setTitulo('Meta do despacho');
        $meta->setDescricao('Descrição');
        $meta->setStatus(Tarefa::STATUS_PENDENTE);
        $meta->setPasta($pasta);
        $meta->setTenant($tenant);
        $meta->setCriadoPor($criador);
        $meta->addResponsavel($responsavel);
        $this->em->persist($meta);
        $this->em->flush();

        return $meta;
    }

    private function instalarCsrfFalso(): void
    {
        static::getContainer()->set('security.csrf.token_storage', new class () implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string
            {
                return 'TOKEN_' . $tokenId;
            }
            public function setToken(string $tokenId, string $token): void
            {
            }
            public function removeToken(string $tokenId): ?string
            {
                return null;
            }
            public function hasToken(string $tokenId): bool
            {
                return true;
            }
            public function clear(): void
            {
            }
        });
    }
}
