<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Functional;

use App\Controller\TarefaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Notificacao;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tarefa\TarefaMensagem;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Pasta\Entity\Pasta;
use App\Tarefa\Controller\PassagemDeEtapaController;
use App\Tarefa\Enum\AcaoDoDespacho;
use App\Tarefa\Enum\EtapaDaPasta;
use App\Tarefa\Enum\TipoDeDemanda;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Passagem de etapa e devolução pela tela (desenhos 04 e 04B de 22/10; spec `minhas-metas-esteira-2210.md`, Fase 3):
 * rotas JSON com CSRF, D3, isolamento entre escritórios, a gravação de verdade (número, código, aviso) e o arranjo do
 * trilho, da lateral, dos modais e do botão do cartão por combinador de filho direto.
 */
#[CoversClass(PassagemDeEtapaController::class)]
#[CoversClass(TarefaController::class)]
#[Group('tarefa')]
final class PassagemDeEtapaTest extends JusPrimeWebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $escritorio;
    private User $criador;
    private User $responsavel;
    private User $colega;
    private Tarefa $meta;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->instalarCsrfFalso();

        $this->escritorio = $this->criarEscritorio();
        $this->criador = $this->criarPessoa($this->escritorio, 'Thâmara Thays Silva');
        $this->responsavel = $this->criarPessoa($this->escritorio, 'Rui Prado');
        $this->colega = $this->criarPessoa($this->escritorio, 'Olga Lima');
        $this->meta = $this->criarMeta($this->escritorio, $this->criador, $this->responsavel);
    }

    #[TestDox('Dados do modal: JSON com a meta, as etapas, os tipos, a equipe, o prazo, a rota e o token')]
    public function testDadosDoModal(): void
    {
        $this->logarComTenant($this->client, $this->responsavel, $this->escritorio);

        $this->client->request('GET', $this->rota('passagem'));

        self::assertResponseIsSuccessful();
        $dados = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('controladoria', $dados['meta']['etapa']);
        self::assertSame('Documentação', $dados['etapas']['documentacao']['nome']);
        self::assertCount(3, $dados['tipos']);
        self::assertContains('Olga Lima', array_column($dados['membros'], 'nome'));
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $dados['prazo']);
        self::assertSame($this->rota('passar'), $dados['url']);
        self::assertNotSame('', $dados['token'], 'o token do CSRF vem junto (mascarado a cada pedido)');
    }

    #[TestDox('Passar: conclui, cria a meta numerada da próxima etapa, registra com código e avisa o responsável novo')]
    public function testPassar(): void
    {
        $this->logarComTenant($this->client, $this->responsavel, $this->escritorio);

        $this->passar(['responsavel' => (string) $this->colega->getId(), 'pendencias' => "Falta procuração\nRG"]);

        self::assertResponseIsSuccessful();
        $resposta = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($resposta['ok']);
        self::assertSame('Documentação', $resposta['proxima']);
        $this->em->clear();
        $meta = $this->em->find(Tarefa::class, $this->meta->getId());
        self::assertSame(Tarefa::STATUS_CONCLUIDA, $meta->getStatus());
        $nova = $this->em->find(Tarefa::class, $resposta['novaMeta']);
        self::assertSame('Documentação: Falta procuração', $nova->getTitulo());
        self::assertSame(EtapaDaPasta::Documentacao, $nova->getEtapa());
        self::assertNotNull($nova->getNumero());
        self::assertSame([$this->colega->getId()], $nova->getResponsaveis()->map(static fn (User $u) => $u->getId())->getValues());
        $ato = $this->em->getRepository(TarefaMensagem::class)->findOneBy(['tarefa' => $meta, 'acao' => AcaoDoDespacho::Etapa]);
        self::assertMatchesRegularExpression('/^\d{4}\.\d{4}$/', (string) $ato?->getCodigo());
        self::assertSame($this->colega->getId(), $ato->getDestinatario()?->getId());
        self::assertCount(1, $this->em->getRepository(Notificacao::class)->findBy(['usuario' => $this->colega->getId(), 'tarefa' => $nova]));
    }

    #[TestDox('A meta concluída mostra a passagem no histórico: "Etapa concluída" e o "quem → para"')]
    public function testHistoricoNaTela(): void
    {
        $this->logarComTenant($this->client, $this->responsavel, $this->escritorio);
        $this->passar(['responsavel' => (string) $this->colega->getId()]);

        $crawler = $this->client->request('GET', '/tarefas/' . $this->meta->getId());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Etapa concluída', $crawler->filter('.despacho-tipo')->text());
        self::assertStringContainsString('Controladoria concluída → Documentação (Olga Lima)', $crawler->filter('#timelineList')->text());
        self::assertStringContainsString('Olga Lima', $crawler->filter('#timelineList')->text());
        self::assertCount(0, $crawler->filter('.mp-lateral'), 'meta concluída: sem Concluir nem Devolver');
    }

    #[TestDox('CSRF inválido: 403 e nada muda')]
    public function testCsrf(): void
    {
        $this->logarComTenant($this->client, $this->responsavel, $this->escritorio);

        $this->passar(['_token' => 'errado']);
        self::assertResponseStatusCodeSame(403);
        $this->devolver(['_token' => 'errado']);
        self::assertResponseStatusCodeSame(403);

        $this->em->clear();
        self::assertSame(Tarefa::STATUS_PENDENTE, $this->em->find(Tarefa::class, $this->meta->getId())->getStatus());
    }

    #[TestDox('D3: colega que não é responsável nem criador recebe 403 nos dados e na passagem; o criador não devolve')]
    public function testD3(): void
    {
        $this->logarComTenant($this->client, $this->colega, $this->escritorio);
        $this->client->request('GET', $this->rota('passagem'));
        self::assertResponseStatusCodeSame(403);
        $this->passar([]);
        self::assertResponseStatusCodeSame(403);

        $this->logarComTenant($this->client, $this->criador, $this->escritorio);
        $this->devolver([]);
        self::assertResponseStatusCodeSame(403);

        $this->em->clear();
        self::assertSame(Tarefa::STATUS_PENDENTE, $this->em->find(Tarefa::class, $this->meta->getId())->getStatus());
    }

    #[TestDox('Outro escritório: a meta é 404 e um responsável de fora é recusado (422)')]
    public function testOutroEscritorio(): void
    {
        $outro = $this->criarEscritorio();
        $deFora = $this->criarPessoa($outro, 'Pessoa De Fora');

        $this->logarComTenant($this->client, $deFora, $outro);
        $this->client->request('GET', $this->rota('passagem'));
        self::assertResponseStatusCodeSame(404);
        $this->passar([]);
        self::assertResponseStatusCodeSame(404);

        $this->logarComTenant($this->client, $this->responsavel, $this->escritorio);
        $this->passar(['responsavel' => (string) $deFora->getId()]);
        self::assertResponseStatusCodeSame(422);
        $this->em->clear();
        self::assertSame(Tarefa::STATUS_PENDENTE, $this->em->find(Tarefa::class, $this->meta->getId())->getStatus());
    }

    #[TestDox('Recusa do UseCase volta em JSON com a mensagem (422)')]
    public function testRecusaEmJson(): void
    {
        $this->logarComTenant($this->client, $this->responsavel, $this->escritorio);

        $this->passar(['destino' => 'comercial']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Ao voltar de etapa, escreva o motivo nas pendências.', json_decode((string) $this->client->getResponse()->getContent(), true)['erro']);
    }

    #[TestDox('Devolver: a meta volta para quem criou e ele é avisado; erro do desenho sem motivo')]
    public function testDevolver(): void
    {
        $this->logarComTenant($this->client, $this->responsavel, $this->escritorio);

        $this->devolver(['motivo' => '']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Escolha o motivo da devolução.', json_decode((string) $this->client->getResponse()->getContent(), true)['erro']);

        $this->devolver([]);
        self::assertResponseIsSuccessful();
        $resposta = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('Thâmara Thays Silva', $resposta['para']);
        self::assertTrue($resposta['avisado'], 'a tela só diz que avisou quando o aviso foi mandado');
        $this->em->clear();
        $meta = $this->em->find(Tarefa::class, $this->meta->getId());
        self::assertSame([$this->criador->getId()], $meta->getResponsaveis()->map(static fn (User $u) => $u->getId())->getValues());
        self::assertCount(1, $this->em->getRepository(Notificacao::class)->findBy(['usuario' => $this->criador->getId(), 'tipo' => Notificacao::TIPO_TAREFA_PENDENTE]));
    }

    #[TestDox('Corrida: outra aba concluiu a meta e esta ainda a tem velha em memória; passar e devolver são recusados sem criar meta')]
    public function testCorridaComAMetaVelhaEmMemoria(): void
    {
        $this->logarComTenant($this->client, $this->responsavel, $this->escritorio);
        $antes = $this->em->getRepository(Tarefa::class)->count(['tenant' => $this->escritorio]);
        // A outra requisição gravou direto no banco; a entidade gerenciada aqui continua "pendente".
        $this->em->getConnection()->executeStatement('UPDATE tarefa SET status = ? WHERE id = ?', [Tarefa::STATUS_CONCLUIDA, $this->meta->getId()]);
        self::assertSame(Tarefa::STATUS_PENDENTE, $this->meta->getStatus());

        $this->passar(['responsavel' => (string) $this->colega->getId()]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Esta meta já está concluída.', json_decode((string) $this->client->getResponse()->getContent(), true)['erro']);
        $this->devolver([]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Esta meta já está concluída.', json_decode((string) $this->client->getResponse()->getContent(), true)['erro']);

        $this->em->clear();
        self::assertSame($antes, $this->em->getRepository(Tarefa::class)->count(['tenant' => $this->escritorio]));
        self::assertSame(0, $this->em->getRepository(TarefaMensagem::class)->count(['tarefa' => $this->meta->getId()]));
    }

    #[TestDox('D3 no concluir da aba Metas da pasta: um terceiro com acesso é recusado (403, a recusa da passagem) e não vê o botão; responsável e criador concluem')]
    public function testConcluirDaPastaSoQuemCuida(): void
    {
        $outra = $this->criarMeta($this->escritorio, $this->criador, $this->responsavel);
        $this->logarComTenant($this->client, $this->colega, $this->escritorio);
        $pagina = $this->client->request('GET', '/pasta/' . $this->meta->getPasta()->getId());
        self::assertResponseIsSuccessful();
        self::assertCount(0, $pagina->filter('#psMetaMenu' . $this->meta->getId() . ' form.ps-meta-concluir'));
        self::assertCount(0, $pagina->filter('form.ps-mrel-alternar-form[action="' . $this->rota('concluir') . '"]'));

        $this->concluirPelaPasta($this->meta);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('Só o responsável pela etapa ou quem criou a meta conclui e passa adiante.', json_decode((string) $this->client->getResponse()->getContent(), true)['erro']);
        $this->em->clear();
        self::assertSame(Tarefa::STATUS_PENDENTE, $this->em->find(Tarefa::class, $this->meta->getId())->getStatus());

        $this->logarComTenant($this->client, $this->responsavel, $this->escritorio);
        $pagina = $this->client->request('GET', '/pasta/' . $this->meta->getPasta()->getId());
        self::assertCount(1, $pagina->filter('#psMetaMenu' . $this->meta->getId() . ' form.ps-meta-concluir'));
        $this->concluirPelaPasta($this->meta);
        self::assertResponseIsSuccessful();

        $this->logarComTenant($this->client, $this->criador, $this->escritorio);
        $this->concluirPelaPasta($outra);
        self::assertResponseIsSuccessful();

        $this->em->clear();
        self::assertSame(Tarefa::STATUS_CONCLUIDA, $this->em->find(Tarefa::class, $this->meta->getId())->getStatus());
        self::assertSame(Tarefa::STATUS_CONCLUIDA, $this->em->find(Tarefa::class, $outra->getId())->getStatus());
    }

    #[TestDox('Pasta na lápide: a página da meta não mostra Concluir nem Devolver, e o POST é recusado com a mensagem em JSON')]
    public function testLapide(): void
    {
        $this->meta->getPasta()->marcarExcluida($this->criador, new \DateTimeImmutable());
        $this->em->flush();
        $this->logarComTenant($this->client, $this->responsavel, $this->escritorio);

        $crawler = $this->client->request('GET', '/tarefas/' . $this->meta->getId());
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.mp-lateral > .mp-concluir, .mp-lateral > .mp-devolver'));

        // Como o meta-passagem.js manda: com X-Requested-With, o listener da lápide responde JSON.
        $this->client->xmlHttpRequest('POST', $this->rota('passar'), ['_token' => 'TOKEN_passar_etapa_' . $this->meta->getId(), 'tipo' => 'judicial']);
        self::assertResponseStatusCodeSame(403);
        self::assertNotSame('', (string) (json_decode((string) $this->client->getResponse()->getContent(), true)['mensagem'] ?? ''), 'o JS mostra este texto');
    }

    #[TestDox('Página da meta: trilho de largura total antes do cartão, com as etapas como filhas diretas')]
    public function testArranjoDoTrilho(): void
    {
        $this->logarComTenant($this->client, $this->responsavel, $this->escritorio);

        $crawler = $this->client->request('GET', '/tarefas/' . $this->meta->getId());

        self::assertResponseIsSuccessful();
        $trilho = 'section.content > .container-fluid > section.mp-trilho';
        self::assertCount(1, $crawler->filter($trilho . ' + .card'));
        self::assertSame('ETAPA DA PASTA', $crawler->filter($trilho . ' > b.mp-trilho-rotulo')->text());
        self::assertCount(8, $crawler->filter($trilho . ' > span.mp-trilho-etapa'));
        self::assertSame(['Comercial', 'Abertura e relatório'], $crawler->filter($trilho . ' > .mp-trilho-etapa.is-passada')->each(static fn ($n) => $n->text()));
        self::assertSame('Controladoria', $crawler->filter($trilho . ' > .mp-trilho-etapa.is-atual')->text());
        self::assertCount(1, $crawler->filter($trilho . ' > .mp-trilho-etapa.is-atual > i.bi-record-circle'));
        self::assertSame('Documentação', $crawler->filter($trilho . ' > .mp-trilho-etapa.is-proxima')->text());
        self::assertCount(1, $crawler->filter($trilho . ' > .mp-trilho-etapa.is-proxima > i.bi-arrow-right'));
        self::assertCount(0, $crawler->filter($trilho . ' > .mp-trilho-saltadas'));
    }

    #[TestDox('Trilho com etapa saltada: riscada e a linha "1 etapa saltada: X"')]
    public function testTrilhoComSaltada(): void
    {
        $this->meta->setEtapa(EtapaDaPasta::Juridico)->setEtapasSaltadas([EtapaDaPasta::Documentacao]);
        $this->em->flush();
        $this->logarComTenant($this->client, $this->responsavel, $this->escritorio);

        $crawler = $this->client->request('GET', '/tarefas/' . $this->meta->getId());

        $trilho = 'section.content > .container-fluid > section.mp-trilho';
        self::assertSame('Documentação', $crawler->filter($trilho . ' > .mp-trilho-etapa.is-saltada')->text());
        self::assertSame('Documentação · Secretaria · etapa saltada, sem registro', $crawler->filter($trilho . ' > .mp-trilho-etapa.is-saltada')->attr('title'));
        self::assertSame('1 etapa saltada: Documentação', $crawler->filter($trilho . ' > .mp-trilho-saltadas')->text());
    }

    #[TestDox('Lateral na ordem do desenho: situação, Concluir, Próximo passo, Devolver, Acompanhar, DETALHES')]
    public function testArranjoDaLateral(): void
    {
        $this->logarComTenant($this->client, $this->responsavel, $this->escritorio);

        $crawler = $this->client->request('GET', '/tarefas/' . $this->meta->getId());

        $lateral = '.row > .col-lg-4';
        self::assertSame('Concluir e passar para Documentação', trim($crawler->filter($lateral . ' > .mp-lateral > button.mp-concluir')->text()));
        self::assertSame('Próximo passo: Documentação (Secretaria). Você escolhe o responsável, o prazo e as pendências.', $crawler->filter($lateral . ' > .mp-lateral > .mp-concluir + .mp-lateral-proximo')->text());
        self::assertSame('Devolver para Thâmara Thays', trim($crawler->filter($lateral . ' > .mp-lateral > .mp-lateral-proximo + button.mp-devolver')->text()));
        self::assertCount(1, $crawler->filter($lateral . ' > .mp-lateral > .mp-devolver > i.bi-arrow-return-left'));
        self::assertCount(1, $crawler->filter($lateral . ' > .mb-3 + .mp-lateral + .mb-3 #btnAcompanharMeta'), 'situação antes, Acompanhar depois');
        self::assertCount(0, $crawler->filter('form[action$="/concluir"]'), 'o Concluir meta antigo saiu da lateral');
        self::assertCount(0, $crawler->filter('[data-acao="devolver"]'), 'Devolver saiu do formulário de despacho');
    }

    #[TestDox('Modais: esqueleto do "Concluir etapa" e do "Devolver para" com os textos do desenho')]
    public function testArranjoDosModais(): void
    {
        $this->logarComTenant($this->client, $this->responsavel, $this->escritorio);

        $crawler = $this->client->request('GET', '/tarefas/' . $this->meta->getId());

        // 04B L92-102: cabeçalho só com o título, sem faixa, sem tipo e sem o que foi feito; menu salta/volta.
        $caixa = '#mpPassagem.mp-passagem--meta > .mp-modal-centro > .mp-modal-caixa';
        self::assertSame('meta', $crawler->filter('#mpPassagem')->attr('data-contexto'));
        self::assertSame('Concluir etapa e passar adiante', $crawler->filter($caixa . ' > .mp-modal-cabeca > b.mp-modal-cabeca-titulo')->text());
        self::assertCount(0, $crawler->filter('#mpPassagem [data-mp="pasta"]'), 'sem "Pasta X · Cliente"');
        self::assertCount(0, $crawler->filter('#mpPassagem .mp-seq'));
        self::assertCount(0, $crawler->filter('#mpPassagem .mp-tipo'));
        self::assertCount(0, $crawler->filter('#mpPassagem [data-mp="feito"]'));
        self::assertCount(0, $crawler->filter('#mpPassagem [data-mp="titulo"], #mpPassagem [data-mp="descProxima"]'), 'CONCLUÍDA sem o título; próxima sem descrição');
        self::assertCount(1, $crawler->filter($caixa . ' > .mp-modal-corpo > .mp-caixas > .mp-caixa-concluida + i.bi-arrow-right + .mp-caixa-proxima > .mp-menu-botao'));
        self::assertSame('PASSAR PARA A ETAPA', $crawler->filter($caixa . ' > .mp-modal-corpo > .mp-caixas > .mp-caixa-proxima > .mp-menu > .mp-menu-caixa > .mp-menu-titulo')->text());
        self::assertCount(1, $crawler->filter($caixa . ' > .mp-modal-corpo > .mp-caixas + .mp-salto + .mp-com-proxima'));
        $resp = $crawler->filter($caixa . ' > .mp-modal-corpo > .mp-com-proxima > .mp-resp-prazo > .mp-campo-resp > input + .mp-membros')->previousAll();
        self::assertSame('Membro da equipe', $resp->attr('placeholder'));
        self::assertSame('PRAZO', trim($crawler->filter($caixa . ' > .mp-modal-corpo > .mp-com-proxima > .mp-resp-prazo > .mp-campo-resp + .mp-campo')->text()));
        self::assertSame('Uma por linha', $crawler->filter($caixa . ' > .mp-modal-corpo > .mp-com-proxima > .mp-campo > textarea')->attr('placeholder'));
        self::assertCount(1, $crawler->filter($caixa . ' > .mp-modal-corpo > .mp-com-proxima + .mp-erro'));
        self::assertCount(1, $crawler->filter($caixa . ' > .mp-modal-pe > .mp-cancelar + .mp-confirmar'));

        $dev = '#mpDevolucao > .mp-modal-centro > .mp-modal-caixa';
        self::assertSame('Devolver para Thâmara Thays', $crawler->filter($dev . ' > .mp-modal-cabeca > b')->text());
        self::assertSame(
            ['Faltam informações', 'Falta documento', 'Não é da minha competência', 'Prazo inviável', 'Outro motivo'],
            $crawler->filter($dev . ' > .mp-modal-corpo > .mp-dev-motivos > button.mp-dev-motivo')->each(static fn ($n) => $n->text()),
        );
        self::assertSame('A meta volta para quem enviou, com o motivo registrado no histórico. A etapa da pasta não muda.', $crawler->filter($dev . ' > .mp-modal-corpo > .mp-dev-texto')->text());
        self::assertNotSame('', (string) $crawler->filter('#mpDevolucao')->attr('data-token'));
        self::assertSame($this->rota('devolver'), $crawler->filter('#mpDevolucao')->attr('data-url'));
    }

    #[TestDox('Criador na meta: Concluir sem Devolver e sem o modal de devolução')]
    public function testCriadorSemDevolver(): void
    {
        $this->logarComTenant($this->client, $this->criador, $this->escritorio);

        $crawler = $this->client->request('GET', '/tarefas/' . $this->meta->getId());

        self::assertCount(1, $crawler->filter('.mp-lateral > .mp-concluir'));
        self::assertCount(0, $crawler->filter('.mp-lateral > .mp-devolver'));
        self::assertCount(0, $crawler->filter('#mpDevolucao'));
    }

    #[TestDox('Lista: "Concluir etapa" nas ações do cartão de quem cuida da meta, com a dica do desenho, e o modal da lista')]
    public function testBotaoDoCartao(): void
    {
        $this->logarComTenant($this->client, $this->responsavel, $this->escritorio);

        $crawler = $this->client->request('GET', '/tarefas/minhas');

        self::assertResponseIsSuccessful();
        $botao = $crawler->filter('article.mm-meta > .mm-meta-acoes > .mm-marcador + button.mp-cartao-concluir');
        self::assertCount(1, $botao);
        self::assertSame('Concluir etapa', trim($botao->text()));
        self::assertSame('Concluir esta etapa e passar para Documentação', $botao->attr('title'));
        self::assertSame($this->rota('passagem'), $botao->attr('data-mp-abrir'));
        self::assertSame('lista', $crawler->filter('#mpPassagem')->attr('data-contexto'));

        // 04 L131: faixa, CONCLUÍDA com o título, próxima com a descrição, tipo, prazo da próxima etapa e o que foi
        // feito; "Pasta X · Cliente" no cabeçalho; sem o menu salta/volta.
        $caixa = '#mpPassagem.mp-passagem--lista > .mp-modal-centro > .mp-modal-caixa';
        self::assertCount(1, $crawler->filter($caixa . ' > .mp-modal-cabeca > .mp-modal-cabeca-texto > b + span[data-mp="pasta"]'));
        self::assertCount(1, $crawler->filter($caixa . ' > .mp-modal-corpo > .mp-seq + .mp-caixas + .mp-tipo + .mp-com-proxima + .mp-campo + .mp-erro'));
        self::assertCount(1, $crawler->filter($caixa . ' > .mp-modal-corpo > .mp-caixas > .mp-caixa-concluida > b + .mp-caixa-texto[data-mp="titulo"]'));
        self::assertCount(1, $crawler->filter($caixa . ' > .mp-modal-corpo > .mp-caixas > .mp-caixa-proxima > b + .mp-caixa-texto[data-mp="descProxima"]'));
        self::assertCount(0, $crawler->filter('#mpPassagem .mp-menu-botao, #mpPassagem .mp-menu, #mpPassagem .mp-salto'));
        self::assertSame('TIPO DE DEMANDA', $crawler->filter($caixa . ' > .mp-modal-corpo > .mp-tipo > .mp-rotulo')->text());
        self::assertSame('PRAZO DA PRÓXIMA ETAPA', trim($crawler->filter($caixa . ' > .mp-modal-corpo > .mp-com-proxima > .mp-resp-prazo > .mp-campo-resp + .mp-campo')->text()));
        self::assertSame('Uma por linha: falta procuração, comprovante de residência...', $crawler->filter($caixa . ' > .mp-modal-corpo > .mp-com-proxima > .mp-campo > textarea')->attr('placeholder'));
        self::assertSame('Resumo para o registro da pasta', $crawler->filter($caixa . ' > .mp-modal-corpo > .mp-com-proxima + .mp-campo > textarea')->attr('placeholder'));
    }

    /** @param array<string, string> $campos */
    private function passar(array $campos): void
    {
        $this->client->request('POST', $this->rota('passar'), $campos + ['_token' => 'TOKEN_passar_etapa_' . $this->meta->getId(), 'tipo' => 'judicial']);
    }

    /** @param array<string, string> $campos */
    private function devolver(array $campos): void
    {
        $this->client->request('POST', $this->rota('devolver'), $campos + ['_token' => 'TOKEN_devolver_meta_' . $this->meta->getId(), 'motivo' => 'Falta documento', 'o_que_falta' => 'Preciso do contrato assinado.']);
    }

    private function concluirPelaPasta(Tarefa $meta): void
    {
        $this->client->request('POST', '/tarefas/' . $meta->getId() . '/concluir', ['_token' => 'TOKEN_concluir_tarefa_' . $meta->getId()], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    }

    private function rota(string $acao): string
    {
        return '/tarefas/' . $this->meta->getId() . '/' . $acao;
    }

    private function criarEscritorio(): Tenant
    {
        $tenant = (new Tenant())->setCidade('Maceió')->setEstado('AL');
        $tenant->setName('Escritório Passagem ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function criarPessoa(Tenant $tenant, string $nome): User
    {
        $user = new User();
        $user->setEmail('passagem_' . uniqid() . '@test.com');
        $user->setFullName($nome);
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('x');
        $this->em->persist($user);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Papel ' . uniqid());
        $role->setIsSystem(true);
        $this->em->persist($role);

        $vinculo = new UserTenant($user, $tenant);
        $vinculo->setTenantRole($role);
        $this->em->persist($vinculo);
        $this->em->flush();

        return $user;
    }

    private function criarMeta(Tenant $tenant, User $criador, User $responsavel): Tarefa
    {
        $pasta = new Pasta();
        $pasta->setNup('TEST-PASS-' . uniqid());
        $pasta->setTenant($tenant);
        $pasta->setCriadoPor($criador);
        $this->em->persist($pasta);

        $meta = new Tarefa();
        $meta->setTitulo('Análise de pasta');
        $meta->setDescricao('');
        $meta->setStatus(Tarefa::STATUS_PENDENTE);
        $meta->setPasta($pasta);
        $meta->setTenant($tenant);
        $meta->setCriadoPor($criador);
        $meta->setEtapa(EtapaDaPasta::Controladoria);
        $meta->setTipoDeDemanda(TipoDeDemanda::Judicial);
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
