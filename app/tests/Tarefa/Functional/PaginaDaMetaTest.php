<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Functional;

use App\Controller\TarefaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tarefa\TarefaMensagem;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Pasta\Entity\Pasta;
use App\Tarefa\Entity\CaixaDaEsteira;
use App\Tarefa\Enum\AcaoDoDespacho;
use App\Tarefa\Enum\EtapaDaPasta;
use App\Tarefa\Enum\TipoDeDemanda;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Página da meta, itens do desenho 04B que faltavam na Fase 2a (spec `minhas-metas-esteira-2210.md`, 3.2, L2 a L5 e
 * L8): faixa "Aguardando", painel de situação, DETALHES DA META, seta e trilha, e o envio para revisão sem texto.
 * O arranjo é conferido por combinador de filho direto.
 */
#[CoversClass(TarefaController::class)]
#[Group('tarefa')]
final class PaginaDaMetaTest extends JusPrimeWebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $escritorio;
    private User $criador;
    private User $responsavel;
    private Tarefa $meta;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->instalarCsrfFalso();

        $this->escritorio = (new Tenant())->setCidade('Maceió')->setEstado('AL');
        $this->escritorio->setName('Escritório Página da Meta ' . uniqid());
        $this->em->persist($this->escritorio);
        $this->criador = $this->criarPessoa('FARLEI ASSIS DA ROCHA');
        $this->responsavel = $this->criarPessoa('Rui Prado');
        $this->meta = $this->criarMeta();
    }

    #[TestDox('L5: seta de voltar às metas e trilha "Expediente / Pasta X / Meta" no cabeçalho')]
    public function testCabecalhoComSetaETrilha(): void
    {
        $c = $this->abrir($this->criador);

        self::assertCount(1, $c->filter('.app-content > .container-fluid > .meta-moldura > .meta-cabeca > a.meta-voltar[href="/tarefas/minhas"][title="Voltar às metas"] > i.bi-arrow-left'));
        self::assertCount(1, $c->filter('.meta-cabeca > a.meta-voltar + h1.meta-titulo'));
        $trilha = $c->filter('.meta-cabeca > nav.meta-trilha');
        self::assertSame('Expediente / Pasta ' . $this->meta->getPasta()->getNup() . ' / Meta', self::texto($trilha));
        self::assertCount(1, $trilha->filter('nav.meta-trilha > a[href="/expediente"]'));
        self::assertCount(1, $trilha->filter('nav.meta-trilha > a[href="/pasta/' . $this->meta->getPasta()->getId() . '"]'));
    }

    #[TestDox('Moldura do 04B (L34, L47-48): blocos soltos (cabeçalho, trilho, colunas), meta e lateral lado a lado, sem card do AdminLTE nem colunas do Bootstrap; fundo só com .meta-body')]
    public function testMolduraDoDesenho(): void
    {
        $c = $this->abrir($this->criador);

        self::assertStringContainsString('meta-body', (string) $c->filter('body')->attr('class'));
        $blocos = $c->filter('.app-content > .container-fluid > .meta-moldura')->children()->each(static fn (Crawler $n): string => (string) $n->attr('class'));
        self::assertSame(['meta-cabeca', 'mp-trilho', 'meta-colunas'], $blocos);
        self::assertCount(1, $c->filter('.meta-moldura > .meta-colunas > section.meta-principal + aside.meta-lateral'), 'a lateral vem depois: desce na tela estreita');
        $principal = $c->filter('.meta-colunas > section.meta-principal')->children()->each(static fn (Crawler $n): string => explode(' ', (string) $n->attr('class'))[0]);
        self::assertSame(['meta-principal-titulo', 'meta-aguardando', 'meta-demanda', 'meta-historico'], $principal, 'título, aguardando, demanda e DESPACHO no mesmo cartão');
        self::assertCount(0, $c->filter('.card, .card-outline, .row, [class*="col-lg"], .content-header, .meta-moldura .flex-grow-1, .meta-moldura .d-none'), 'nada do card, das colunas nem dos utilitários do sistema');
        self::assertCount(1, $c->filter('.meta-historico > .despacho-cabeca-bloco + .despacho-bloco > form[data-despacho]'), 'DESPACHO é faixa do cartão, com o formulário logo abaixo');
        self::assertCount(0, $c->filter('.meta-num-global'), 'sem o selo Nº (decisão do dono)');
    }

    #[TestDox('Janelas de prazo, responsáveis e certidão no componente do desenho (L106), sem o modal do Bootstrap; abrem pelo data-md-abrir')]
    public function testJanelasDoDesenho(): void
    {
        $this->meta->setPrazo(new \DateTimeImmutable('+5 days'));
        $this->em->flush();
        $c = $this->abrir($this->criador);

        foreach (['modalEditarPrazo', 'modalEditarResponsaveis', 'modalCertificarDespacho'] as $id) {
            $janela = $c->filter('body div.md-modal#' . $id . '[hidden]');
            self::assertCount(1, $janela, $id);
            self::assertCount(1, $janela->filter('.md-modal > .md-modal-fundo[data-md-fechar] + .md-modal-centro > form.md-modal-caixa[role="dialog"] > .md-modal-cabeca + .md-modal-corpo + .md-modal-pe'), $id);
            self::assertCount(1, $janela->filter('.md-modal-cabeca > i.bi + b + button.md-modal-x[data-md-fechar]'), $id);
            self::assertCount(1, $janela->filter('.md-modal-pe > button.md-cancelar[data-md-fechar] + button.md-confirmar[type="submit"]'), $id);
        }
        self::assertCount(0, $c->filter('.meta-moldura .modal, .meta-moldura [data-bs-toggle], .meta-moldura .btn, .meta-moldura .form-control, .md-modal .btn, .md-modal .form-control, .md-modal.modal'), 'nada do Bootstrap na página nem nas janelas');
        self::assertCount(1, $c->filter('.meta-detalhes > .meta-detalhes-prazo[data-md-abrir="modalEditarPrazo"][role="button"]'));
        self::assertCount(1, $c->filter('.meta-detalhes > .meta-detalhes-responsaveis[data-md-abrir="modalEditarResponsaveis"][role="button"]'));
        self::assertCount(1, $c->filter('#modalEditarPrazo .md-modal-corpo > input#campoPrazo.md-campo[name="prazo"]'));
        self::assertCount(1, $c->filter('#modalEditarResponsaveis .md-modal-corpo > #listaResponsaveis.md-lista > label.md-pessoa.is-ativo > input.md-pessoa-marca[name="responsaveis[]"][checked]'));
        self::assertCount(1, $c->filter('#modalCertificarDespacho .md-modal-corpo > textarea#certidaoTexto.md-campo-texto[name="certidao"]'));
        self::assertCount(1, $c->filter('script[src$="/js/meta-detalhe.js"]'));
    }

    #[TestDox('Acompanhar (L83-84) no botão do desenho, não no do Bootstrap; marcado, ganha .is-ativo e o ícone cheio')]
    public function testAcompanharDoDesenho(): void
    {
        $c = $this->abrir($this->criador);
        $botao = $c->filter('.meta-lateral > button#btnAcompanharMeta.meta-acompanhar[aria-pressed="false"]');
        self::assertCount(1, $botao);
        self::assertSame('meta-acompanhar', $botao->attr('class'));
        self::assertCount(1, $botao->filter('button > i.bi-bookmark + span'));
        self::assertSame('Só você vê esta marcação.', self::texto($c->filter('.meta-lateral > #btnAcompanharMeta + .meta-so-voce')));

        $this->meta->alternarAcompanhamento($this->criador);
        $this->em->flush();
        $c = $this->client->request('GET', '/tarefas/' . $this->meta->getId());
        self::assertCount(1, $c->filter('.meta-lateral > button#btnAcompanharMeta.meta-acompanhar.is-ativo[aria-pressed="true"] > i.bi-bookmark-fill'));
    }

    #[TestDox('L2: faixa AGUARDANDO DESPACHO DE com iniciais, nome curto e "hoje / com a demanda"; vermelha se atrasada')]
    public function testFaixaAguardandoDespacho(): void
    {
        $this->meta->setPrazo(new \DateTimeImmutable('-3 days'));
        $this->em->flush();

        $c = $this->abrir($this->criador);

        $faixa = $c->filter('.meta-principal > .meta-principal-titulo + .meta-aguardando.meta-aguardando--atrasada');
        self::assertCount(1, $faixa);
        self::assertSame('AGUARDANDO DESPACHO DE', self::texto($faixa->filter('.meta-aguardando > .meta-aguardando-quem > .meta-aguardando-rotulo')));
        self::assertSame('RP', self::texto($faixa->filter('.meta-aguardando-pessoas > .meta-aguardando-pessoa > .meta-aguardando-iniciais')));
        self::assertSame('RPRui Prado', self::texto($faixa->filter('.meta-aguardando-pessoas > .meta-aguardando-pessoa')));
        self::assertSame('hoje', self::texto($faixa->filter('.meta-aguardando > .meta-aguardando-tempo > b.meta-aguardando-dias')));
        self::assertSame('com a demanda', self::texto($faixa->filter('.meta-aguardando-tempo > .meta-aguardando-desde')));
        self::assertSame('Lembrar', self::texto($faixa->filter('.meta-aguardando > form.meta-lembrar-form > button.meta-lembrar')), 'o Lembrar da Fase 2b fecha a faixa (L50)');
    }

    #[TestDox('L2 e L8: enviar para revisão sem texto grava a mensagem do desenho e a faixa passa a aguardar o criador')]
    public function testEnviarSemTextoEFaixaDeRevisao(): void
    {
        $this->logarComTenant($this->client, $this->responsavel, $this->escritorio);
        $id = (int) $this->meta->getId();
        $this->client->request('POST', '/tarefas/' . $id . '/mensagem', ['_token' => 'TOKEN_mensagem_tarefa_' . $id, 'acao' => 'enviar', 'mensagem' => '']);
        self::assertResponseRedirects();

        $this->em->clear();
        $mensagens = $this->em->getRepository(TarefaMensagem::class)->findBy(['tarefa' => $id]);
        self::assertCount(1, $mensagens);
        self::assertSame('<p>Meta enviada para revisão do autor.</p>', $mensagens[0]->getMensagem());
        self::assertSame(AcaoDoDespacho::Envio, $mensagens[0]->getAcao());
        self::assertNotNull($mensagens[0]->getCodigo());

        $c = $this->client->request('GET', '/tarefas/' . $id);
        $faixa = $c->filter('.meta-principal > .meta-aguardando.meta-aguardando--revisao');
        self::assertSame('AGUARDANDO REVISÃO DE', self::texto($faixa->filter('.meta-aguardando-quem > .meta-aguardando-rotulo')));
        self::assertSame('FAFarlei Rocha', self::texto($faixa->filter('.meta-aguardando-pessoas > .meta-aguardando-pessoa')));
        self::assertSame('desde o envio para revisão', self::texto($faixa->filter('.meta-aguardando-tempo > .meta-aguardando-desde')));
        self::assertStringContainsString('Meta enviada para revisão do autor.', self::texto($c->filter('#despacho-' . $mensagens[0]->getId())));
        self::assertSame('Aguardando revisão', self::texto($c->filter('.meta-lateral > .meta-situacao.meta-situacao--revisao > .meta-situacao-texto > b.meta-situacao-titulo')));
    }

    #[TestDox('L3: painel de situação "Atrasada · N dia(s)" vermelho e "Etapa i/n · Nome"')]
    public function testPainelDeSituacao(): void
    {
        $this->meta->setPrazo(new \DateTimeImmutable('-2 days'));
        $this->em->flush();

        $c = $this->abrir($this->criador);

        $painel = $c->filter('.meta-lateral > .meta-situacao.meta-situacao--atrasada');
        self::assertCount(1, $painel);
        self::assertCount(1, $painel->filter('.meta-situacao > i.bi-exclamation-octagon.meta-situacao-icone + .meta-situacao-texto'));
        self::assertSame('Atrasada · 2 dia(s)', self::texto($painel->filter('.meta-situacao-texto > b.meta-situacao-titulo')));
        self::assertSame('Etapa 5/8 · Jurídico', self::texto($painel->filter('.meta-situacao-texto > b.meta-situacao-titulo + .meta-situacao-etapa')));
    }

    #[TestDox('L4: DETALHES DA META na lateral, no lugar das 4 caixas: prazo atrasado com Alterar, responsáveis, criada em e despachante')]
    public function testDetalhesDaMeta(): void
    {
        $this->meta->setPrazo(new \DateTimeImmutable('-4 days'));
        $this->em->flush();

        $c = $this->abrir($this->criador);

        self::assertCount(0, $c->filter('.tarefa-meta-box'), 'as 4 caixas do topo saíram');
        $d = $c->filter('.meta-lateral > .meta-detalhes');
        self::assertCount(1, $d);
        self::assertSame('DETALHES DA META', self::texto($d->filter('.meta-detalhes > .meta-detalhes-cabeca')));
        $prazo = $d->filter('.meta-detalhes > .meta-detalhes-cabeca + .meta-detalhes-item.meta-detalhes-prazo.meta-detalhes-prazo--atrasada[data-md-abrir="modalEditarPrazo"]');
        self::assertCount(1, $prazo);
        self::assertSame('PRAZOAlterar', self::texto($prazo->filter('.meta-detalhes-prazo > .meta-detalhes-rotulo')));
        self::assertSame('Atrasada venceu em ' . (new \DateTimeImmutable('-4 days'))->format('d/m/Y'), self::texto($prazo->filter('.meta-detalhes-atraso > .meta-detalhes-atraso-texto')));
        self::assertSame('4 dias', self::texto($prazo->filter('.meta-detalhes-atraso > .meta-detalhes-atraso-dias')));
        $itens = $d->filter('.meta-detalhes > .meta-detalhes-item');
        self::assertCount(4, $itens);
        self::assertSame('RESPONSÁVEISAlterar Rui Prado', self::texto($itens->eq(1)));
        self::assertSame('CRIADA EM ' . (new \DateTimeImmutable())->format('d/m/Y') . '· hoje', self::texto($itens->eq(2)));
        self::assertSame('DESPACHANTE FARLEI ASSIS DA ROCHA', self::texto($itens->eq(3)));
    }

    #[TestDox('L4: no prazo, o quadro mostra só a data, sem vermelho')]
    public function testDetalhesNoPrazo(): void
    {
        $this->meta->setPrazo(new \DateTimeImmutable('+5 days'));
        $this->em->flush();

        $c = $this->abrir($this->criador);

        $prazo = $c->filter('.meta-detalhes > .meta-detalhes-item.meta-detalhes-prazo');
        self::assertStringNotContainsString('meta-detalhes-prazo--atrasada', (string) $prazo->attr('class'));
        self::assertSame((new \DateTimeImmutable('+5 days'))->format('d/m/Y'), self::texto($prazo->filter('.meta-detalhes-prazo > .meta-detalhes-prazo-data')));
        self::assertSame('Pendente', self::texto($c->filter('.meta-situacao > .meta-situacao-texto > b.meta-situacao-titulo')));
    }

    #[TestDox('Cartão (dc L49-54): sem selo de status; "Pasta: X · cliente" num link só; DEMANDA; sem hr nem caixa de concluída')]
    public function testCartaoDaMeta(): void
    {
        $this->meta->getPasta()->setNomeCliente('Maria da Silva');
        $this->meta->setDescricao('Juntar a procuração.');
        $this->em->flush();

        $c = $this->abrir($this->criador);

        self::assertCount(0, $c->filter('.meta-principal-titulo .badge'), 'o status está no painel, não no cabeçalho');
        $pasta = $c->filter('.meta-principal > .meta-demanda > a.meta-pasta[title="Abrir a pasta"]');
        self::assertCount(1, $pasta);
        self::assertSame('/pasta/' . $this->meta->getPasta()->getId(), $pasta->attr('href'));
        self::assertCount(1, $pasta->filter('a.meta-pasta > i.bi-folder2-open'));
        self::assertSame('Pasta: ' . $this->meta->getPasta()->getNup() . '· MARIA DA SILVA', self::texto($pasta));
        self::assertSame('DEMANDA', self::texto($c->filter('.meta-demanda > a.meta-pasta + .meta-demanda-rotulo')));
        self::assertSame('Juntar a procuração.', self::texto($c->filter('.meta-demanda > .meta-demanda-rotulo + .meta-demanda-texto')));
        self::assertCount(0, $c->filter('.meta-principal hr'));
    }

    #[TestDox('Cartão: descrição vazia mostra "Sem descrição."')]
    public function testSemDescricao(): void
    {
        $this->meta->setDescricao('');
        $this->em->flush();

        self::assertSame('Sem descrição.', self::texto($this->abrir($this->criador)->filter('.meta-demanda > .meta-demanda-texto')));
    }

    #[TestDox('Meta concluída: o bloco do despacho continua, com Enviar para revisão; despachar registra sem mudar o status; enviar é recusado com aviso')]
    public function testDespachoNaMetaConcluida(): void
    {
        $this->meta->setStatus(Tarefa::STATUS_CONCLUIDA);
        $this->em->flush();
        $id = (int) $this->meta->getId();

        $c = $this->abrir($this->criador);
        self::assertCount(1, $c->filter('#tarefa-timeline-card form[data-despacho] button.despacho-botao--principal[data-acao="enviar"]'));
        self::assertCount(0, $c->filter('.meta-lateral form[action$="/concluir"]'), 'concluída não se conclui de novo');

        $this->client->request('POST', '/tarefas/' . $id . '/mensagem', ['_token' => 'TOKEN_mensagem_tarefa_' . $id, 'acao' => '', 'mensagem' => 'Arquivado na pasta.']);
        $this->client->request('POST', '/tarefas/' . $id . '/mensagem', ['_token' => 'TOKEN_mensagem_tarefa_' . $id, 'acao' => 'enviar', 'mensagem' => 'Revisar de novo.']);
        $c = $this->client->followRedirect();

        self::assertStringContainsString('Só a meta pendente pode ser enviada para revisão. Nada foi gravado.', self::texto($c->filter('.alert-danger')));
        $this->em->clear();
        $mensagens = $this->em->getRepository(TarefaMensagem::class)->findBy(['tarefa' => $id]);
        self::assertCount(1, $mensagens, 'só o despacho; o envio recusado não grava');
        self::assertSame(AcaoDoDespacho::Despacho, $mensagens[0]->getAcao());
        self::assertSame(Tarefa::STATUS_CONCLUIDA, $this->em->find(Tarefa::class, $id)?->getStatus());
    }

    #[TestDox('Erro de prazo ("Prazo inválido.") fica até fechar: alert-danger com o X, sem a classe do aviso de sucesso (o timer é só do sucesso, em meta-detalhe.js)')]
    public function testErroDePrazoNaoEhToast(): void
    {
        $id = (int) $this->meta->getId();
        $this->logarComTenant($this->client, $this->criador, $this->escritorio);
        $this->client->request('POST', '/tarefas/' . $id . '/prazo', ['_token' => 'TOKEN_atualizar_prazo_tarefa_' . $id, 'prazo' => 'não é data']);
        $c = $this->client->followRedirect();

        $aviso = $c->filter('.app-content-header > .container-fluid > .alert.alert-danger');
        self::assertCount(1, $aviso);
        self::assertSame('Prazo inválido.', self::texto($aviso));
        self::assertCount(1, $aviso->filter('.alert > button.btn-close'), 'o erro tem o X para fechar');
        self::assertStringNotContainsString('is-toast', (string) $aviso->attr('class'));
        self::assertCount(0, $c->filter('.app-content-header .alert-success'));
    }

    #[TestDox('Lateral na ordem do dc (L77-85): situação, Concluir e passar (Fase 3), Acompanhar e DETALHES; sem Excluir meta nem Voltar à Pasta')]
    public function testLateralNaOrdemDoDesenho(): void
    {
        $c = $this->abrir($this->criador);

        $filhos = $c->filter('.meta-colunas > aside.meta-lateral')->children()->each(static fn (Crawler $n): string => (string) $n->attr('class'));
        self::assertSame(['meta-situacao meta-situacao--pendente', 'mp-lateral', 'meta-acompanhar', 'meta-so-voce', 'meta-detalhes'], $filhos, 'blocos soltos, sem invólucro mb-3');
        self::assertStringStartsWith('Concluir', self::texto($c->filter('.meta-lateral > .meta-situacao + .mp-lateral > button.mp-concluir')));
        self::assertCount(1, $c->filter('.meta-lateral > .mp-lateral + button#btnAcompanharMeta.meta-acompanhar + .meta-so-voce + .meta-detalhes'));
        self::assertCount(0, $c->filter('form[action$="/excluir"]'), 'Excluir meta saiu da página (decisão do dono)');
        self::assertCount(0, $c->filter('a[href$="#tarefas"]'), 'Voltar à Pasta saiu; o voltar é pela seta e pela trilha');
        self::assertStringNotContainsString('Excluir meta', $c->filter('.meta-lateral')->text());
    }

    #[TestDox('Linha do tempo (L71): traço vertical em todo marcador menos o último; data "dd/mm/aaaa, hh:mm" com vírgula')]
    public function testTracoEDataDaLinhaDoTempo(): void
    {
        $id = (int) $this->meta->getId();
        $this->logarComTenant($this->client, $this->criador, $this->escritorio);
        $this->client->request('POST', '/tarefas/' . $id . '/mensagem', ['_token' => 'TOKEN_mensagem_tarefa_' . $id, 'acao' => '', 'mensagem' => 'Primeiro.']);
        $this->client->request('POST', '/tarefas/' . $id . '/mensagem', ['_token' => 'TOKEN_mensagem_tarefa_' . $id, 'acao' => '', 'mensagem' => 'Segundo.']);

        $c = $this->client->request('GET', '/tarefas/' . $id);

        $itens = $c->filter('#timelineList > .despacho-item');
        self::assertGreaterThanOrEqual(2, $itens->count());
        self::assertCount($itens->count() - 1, $c->filter('#timelineList > .despacho-item > .despacho-marca > .despacho-icone + .despacho-traco'));
        self::assertCount(0, $itens->last()->filter('.despacho-traco'), 'o último não tem traço');
        self::assertMatchesRegularExpression('/^\d{2}\/\d{2}\/\d{4}, \d{2}:\d{2}$/', self::texto($itens->eq(0)->filter('.despacho-cabeca > .despacho-em')));
        self::assertCount(0, $c->filter('#timelineList .despacho-vazio'));
    }

    #[TestDox('Linha do tempo no formato único: todo item é cartão "DESPACHO N · tipo", sem separador de data nem "Início do histórico"')]
    public function testLinhaDoTempoNoFormatoUnico(): void
    {
        $id = (int) $this->meta->getId();
        $this->logarComTenant($this->client, $this->criador, $this->escritorio);
        $outro = $this->criarPessoa('Olga Lima');
        $this->client->request('POST', '/tarefas/' . $id . '/responsaveis', [
            '_token'        => 'TOKEN_update_responsaveis_' . $id,
            'responsaveis'  => [$this->responsavel->getId(), $outro->getId()],
        ]);
        $this->client->request('POST', '/tarefas/' . $id . '/mensagem', ['_token' => 'TOKEN_mensagem_tarefa_' . $id, 'acao' => '', 'mensagem' => 'Segue.']);

        $c = $this->client->request('GET', '/tarefas/' . $id);

        $lista = $c->filter('.meta-principal > #tarefa-timeline-card.meta-historico > .despacho-bloco + #timelineList.despacho-lista');
        self::assertCount(1, $lista);
        self::assertNull($lista->attr('style'), 'sem max-height');
        $itens = $lista->filter('#timelineList > .despacho-item');
        self::assertSame($lista->children()->count(), $itens->count(), 'só cartões, sem separador por data');
        self::assertStringNotContainsString('Início do histórico', $lista->text());
        $numeros = $itens->each(static fn (Crawler $i): string => trim($i->filter('.despacho-cabeca > b.despacho-numero')->text()));
        $esperado = array_map(static fn (int $n): string => 'DESPACHO ' . $n, range(count($numeros), 1));
        self::assertSame($esperado, $numeros, 'do mais novo (topo) ao DESPACHO 1');
        self::assertCount(1, $itens->eq(0)->filter('.despacho-cabeca > .despacho-recente'));
        self::assertCount(1, $lista->filter('.despacho-cabeca > .despacho-recente'));
        $responsaveis = $itens->reduce(static fn (Crawler $i): bool => str_contains($i->filter('.despacho-tipo')->text(), 'Responsáveis alterados'));
        self::assertCount(1, $responsaveis);
        self::assertCount(1, $responsaveis->filter('.despacho-item.despacho-item--roxo > .despacho-marca > .despacho-icone > i.bi-people'));
        self::assertStringEndsWith('Rui Prado, Olga Lima', self::texto($responsaveis->filter('.despacho-corpo')), 'a lista nova, como no dc L147');
    }

    #[TestDox('Painel: o nome de etapa da caixa de outro escritório não aparece; o do próprio escritório, sim')]
    public function testNomeDaCaixaSoDoProprioEscritorio(): void
    {
        $alheio = (new Tenant())->setCidade('Recife')->setEstado('PE');
        $alheio->setName('Escritório Alheio ' . uniqid());
        $this->em->persist($alheio);
        $caixaAlheia = new CaixaDaEsteira($alheio, EtapaDaPasta::Juridico);
        $caixaAlheia->configurar(null, 'Peças do vizinho', []);
        $this->em->persist($caixaAlheia);
        $this->em->flush();

        $etapa = static fn (Crawler $c): string => self::texto($c->filter('.meta-situacao-texto > .meta-situacao-etapa'));
        self::assertSame('Etapa 5/8 · Jurídico', $etapa($this->abrir($this->criador)));

        $propria = new CaixaDaEsteira($this->escritorio, EtapaDaPasta::Juridico);
        $propria->configurar(null, 'Peças', []);
        $this->em->persist($propria);
        $this->em->flush();
        self::assertSame('Etapa 5/8 · Peças', $etapa($this->abrir($this->criador)));
    }

    private function abrir(User $quem): Crawler
    {
        $this->logarComTenant($this->client, $quem, $this->escritorio);
        $c = $this->client->request('GET', '/tarefas/' . $this->meta->getId());
        self::assertResponseIsSuccessful();

        return $c;
    }

    private static function texto(Crawler $no): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $no->text()));
    }

    private function criarPessoa(string $nome): User
    {
        $user = new User();
        $user->setEmail('pagina_meta_' . uniqid() . '@test.com');
        $user->setFullName($nome);
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('x');
        $this->em->persist($user);

        $role = new TenantRole();
        $role->setTenant($this->escritorio);
        $role->setName('Papel ' . uniqid());
        $role->setIsSystem(true);
        $this->em->persist($role);

        $vinculo = new UserTenant($user, $this->escritorio);
        $vinculo->setTenantRole($role);
        $this->em->persist($vinculo);
        $this->em->flush();

        return $user;
    }

    private function criarMeta(): Tarefa
    {
        $pasta = new Pasta();
        $pasta->setNup('TEST-PAG-' . uniqid());
        $pasta->setTenant($this->escritorio);
        $pasta->setCriadoPor($this->criador);
        $this->em->persist($pasta);

        $meta = new Tarefa();
        $meta->setTitulo('Meta da página');
        $meta->setDescricao('Descrição');
        $meta->setStatus(Tarefa::STATUS_PENDENTE);
        $meta->setEtapa(EtapaDaPasta::Juridico);
        $meta->setTipoDeDemanda(TipoDeDemanda::Judicial);
        $meta->setPasta($pasta);
        $meta->setTenant($this->escritorio);
        $meta->setCriadoPor($this->criador);
        $meta->addResponsavel($this->responsavel);
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
