<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Functional;

use App\Controller\TarefaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tarefa\TarefaMensagem;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Pasta\Entity\CadeadoDaPasta;
use App\Pasta\Entity\ModoDoCadeado;
use App\Pasta\Entity\Pasta;
use App\Repository\TarefaMensagemRepository;
use App\Tarefa\Controller\DespachoDaMetaController;
use App\Tarefa\Enum\AcaoDoDespacho;
use App\Tarefa\Enum\NaturezaDoDespacho;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Despacho da meta, Fase 2a (spec `minhas-metas-esteira-2210.md`, C9 a C15): Despachar com validação no servidor e
 * código do registro, ciência ao abrir, certidão de cumprimento e validação do código só no escritório.
 */
#[CoversClass(TarefaController::class)]
#[CoversClass(DespachoDaMetaController::class)]
#[Group('tarefa')]
final class DespachoDaMetaTest extends JusPrimeWebTestCase
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
        // Um kernel só no teste inteiro: o armazenamento falso do CSRF e o EntityManager valem em todas as requisições.
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->instalarCsrfFalso();

        $this->escritorio = $this->criarEscritorio('Maceió');
        $this->criador = $this->criarPessoa($this->escritorio, 'Carla Dias');
        $this->responsavel = $this->criarPessoa($this->escritorio, 'Rui Prado');
        $this->colega = $this->criarPessoa($this->escritorio, 'Olga Lima');
        $this->meta = $this->criarMeta($this->escritorio, $this->criador, $this->responsavel);
    }

    #[TestDox('Despachar grava texto em HTML, natureza, prazo e um código XXXX.XXXX')]
    public function testDespacharGeraCodigo(): void
    {
        $this->logarComTenant($this->client, $this->criador, $this->escritorio);
        $prazo = (new \DateTimeImmutable('today'))->modify('+3 days')->format('Y-m-d');

        $this->despachar(['mensagem' => "Cumpra-se:\r\njuntar a procuração.", 'natureza' => 'cumpra_se', 'prazo' => $prazo]);

        self::assertResponseRedirects('/tarefas/' . $this->meta->getId());
        $despacho = $this->unicoDespacho();
        self::assertMatchesRegularExpression('/^\d{4}\.\d{4}$/', (string) $despacho->getCodigo());
        self::assertSame('<p>Cumpra-se:<br />juntar a procuração.</p>', $despacho->getMensagem());
        self::assertSame(NaturezaDoDespacho::CumpraSe, $despacho->getNatureza());
        self::assertSame($prazo, $despacho->getPrazoCumprimento()?->format('Y-m-d'));
    }

    #[TestDox('5.000 caracteres passam; 5.001 são recusados no servidor, sem despacho')]
    public function testLimiteDeCincoMil(): void
    {
        $this->logarComTenant($this->client, $this->criador, $this->escritorio);

        $this->despachar(['mensagem' => str_repeat('a', 5001)]);
        self::assertResponseRedirects();
        self::assertCount(0, $this->despachosDaMeta());

        $this->despachar(['mensagem' => str_repeat('a', 4998) . "\r\nb"]);
        self::assertCount(1, $this->despachosDaMeta(), 'a quebra de linha conta um caractere, não dois');
    }

    #[TestDox('Prazo antes de hoje é recusado, sem despacho')]
    public function testPrazoNoPassado(): void
    {
        $this->logarComTenant($this->client, $this->criador, $this->escritorio);

        $this->despachar(['mensagem' => 'Ok', 'prazo' => (new \DateTimeImmutable('yesterday'))->format('Y-m-d')]);

        self::assertResponseRedirects();
        self::assertCount(0, $this->despachosDaMeta());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'não pode ser antes de hoje');
    }

    #[TestDox('A tela mostra o despacho com número, natureza, local e data, registro e link de validação')]
    public function testTelaMostraORegistro(): void
    {
        $this->logarComTenant($this->client, $this->criador, $this->escritorio);
        $this->despachar(['mensagem' => 'Para ciência: segue.', 'natureza' => 'ciencia']);
        $codigo = (string) $this->unicoDespacho()->getCodigo();

        $crawler = $this->client->request('GET', '/tarefas/' . $this->meta->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.despacho-numero', 'DESPACHO 1');
        self::assertSelectorTextContains('.despacho-tipo', 'Para ciência');
        self::assertSelectorTextContains('.despacho-local', 'Maceió,');
        self::assertSelectorTextContains('.despacho-registro', 'Registrado por CARLA DIAS');
        self::assertSelectorTextNotContains('.despacho-registro', 'Assinado');
        self::assertCount(1, $crawler->filter('.despacho-registro a[href="/tarefas/registro/' . $codigo . '"]'));
        self::assertSelectorExists('form[data-despacho] input[type="date"][name="prazo"][min]');
    }

    #[TestDox('Ciência: o responsável abre e marca o despacho do criador; quem só olha não marca')]
    public function testCiencia(): void
    {
        $this->logarComTenant($this->client, $this->criador, $this->escritorio);
        $this->despachar(['mensagem' => 'Para ciência.']);
        $id = (int) $this->unicoDespacho()->getId();

        $this->logarComTenant($this->client, $this->colega, $this->escritorio);
        $this->client->request('GET', '/tarefas/' . $this->meta->getId());
        self::assertResponseIsSuccessful();
        self::assertNull($this->despacho($id)->getCienteEm(), 'colega que só olha não dá ciência');

        $this->logarComTenant($this->client, $this->responsavel, $this->escritorio);
        $this->client->request('GET', '/tarefas/' . $this->meta->getId());
        self::assertSame($this->responsavel->getId(), $this->despacho($id)->getCientePor()?->getId());
        self::assertSelectorTextContains('.despacho-ciente', 'Ciente · Rui,');
    }

    #[TestDox('Certidão: responsável certifica; colega recebe 403; outro escritório recebe 404')]
    public function testCertidao(): void
    {
        $despacho = $this->despachoGravado($this->criador);
        $id = (int) $despacho->getId();

        $outroEscritorio = $this->criarEscritorio(null);
        $estranho = $this->criarPessoa($outroEscritorio, 'Ivo Reis');
        $this->logarComTenant($this->client, $estranho, $outroEscritorio);
        $this->certificar($id, 'Certifico que fiz.');
        self::assertResponseStatusCodeSame(404);

        $this->logarComTenant($this->client, $this->colega, $this->escritorio);
        $this->certificar($id, 'Certifico que fiz.');
        self::assertResponseStatusCodeSame(403);

        $this->logarComTenant($this->client, $this->responsavel, $this->escritorio);
        $this->certificar($id, 'Começo errado.');
        self::assertNull($this->despacho($id)->getCertidao(), 'sem "Certifico que" não grava');

        $this->certificar($id, 'Certifico que juntei a procuração.');
        self::assertResponseRedirects();
        self::assertSame('Certifico que juntei a procuração.', $this->despacho($id)->getCertidao());
        self::assertSame($this->responsavel->getId(), $this->despacho($id)->getCertificadoPor()?->getId());
    }

    #[TestDox('Validação do código: confere no escritório, sem o texto; outro escritório e código inexistente dão 404')]
    public function testValidacaoDoCodigo(): void
    {
        $despacho = $this->despachoGravado($this->criador, NaturezaDoDespacho::Providencia);
        $codigo = (string) $despacho->getCodigo();

        $this->logarComTenant($this->client, $this->colega, $this->escritorio);
        $this->client->request('GET', '/tarefas/registro/' . $codigo);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-registro-encontrado]', 'Carla Dias');
        self::assertSelectorTextContains('[data-registro-encontrado]', 'Para providência');
        self::assertSelectorTextContains('[data-registro-encontrado]', 'Sócia');
        self::assertSelectorTextNotContains('[data-registro-encontrado]', 'Texto secreto do despacho');

        $this->client->request('GET', '/tarefas/registro/0000.0000');
        self::assertResponseStatusCodeSame(404);
        self::assertSelectorExists('[data-registro-nao-encontrado]');

        $outroEscritorio = $this->criarEscritorio(null);
        $estranho = $this->criarPessoa($outroEscritorio, 'Ivo Reis');
        $this->logarComTenant($this->client, $estranho, $outroEscritorio);
        $this->client->request('GET', '/tarefas/registro/' . $codigo);
        self::assertResponseStatusCodeSame(404);
        self::assertSelectorExists('[data-registro-nao-encontrado]');
        self::assertSelectorTextNotContains('body', 'Carla Dias');
    }

    #[TestDox('O registro congela o nome: renomear quem despachou não muda a linha nem a validação')]
    public function testRegistroCongelaONome(): void
    {
        $this->logarComTenant($this->client, $this->criador, $this->escritorio);
        $this->despachar(['mensagem' => 'Ok.']);
        $codigo = (string) $this->unicoDespacho()->getCodigo();
        $criador = $this->em->find(User::class, $this->criador->getId());
        $criador?->setFullName('Carla Dias Mendes');
        $this->em->flush();

        $this->client->request('GET', '/tarefas/' . $this->meta->getId());
        self::assertSelectorTextContains('.despacho-registro', 'Registrado por CARLA DIAS em');

        $this->client->request('GET', '/tarefas/registro/' . $codigo);
        self::assertSelectorTextContains('[data-registro-encontrado]', 'Carla Dias');
        self::assertSelectorTextNotContains('[data-registro-encontrado]', 'Mendes');
    }

    #[TestDox('Despacho registrado não tem botão de editar na página')]
    public function testRegistradoSemBotaoDeEditar(): void
    {
        $despacho = $this->despachoGravado($this->criador);
        $this->logarComTenant($this->client, $this->criador, $this->escritorio);

        $crawler = $this->client->request('GET', '/tarefas/' . $this->meta->getId());
        self::assertCount(1, $crawler->filter('#despacho-' . $despacho->getId()));
        self::assertCount(0, $crawler->filter('#despacho-' . $despacho->getId() . ' .btn-editar-msg, #despacho-' . $despacho->getId() . ' .bi-pencil'));
    }

    #[TestDox('Mensagem antiga do próprio autor: sem botão nem link de edição (decisão do dono de 22/10), o "(editado)" continua, e a rota antiga não existe mais')]
    public function testAntigaSemEdicaoComEditado(): void
    {
        $antiga = (new TarefaMensagem())->setUsuario($this->criador)->setTenant($this->escritorio)->setMensagem('Antiga')->setEditadoEm(new \DateTimeImmutable('-1 day'));
        $this->meta->addMensagem($antiga);
        $this->em->persist($antiga);
        $this->em->flush();
        $id = (int) $antiga->getId();
        $this->logarComTenant($this->client, $this->criador, $this->escritorio);

        $crawler = $this->client->request('GET', '/tarefas/' . $this->meta->getId());
        $item    = $crawler->filter('#despacho-' . $id);
        self::assertCount(1, $item);
        self::assertCount(0, $item->filter('.btn-editar-msg, .bi-pencil, [data-url*="/editar"]'));
        self::assertStringNotContainsString('/tarefas/mensagem/' . $id . '/editar', (string) $this->client->getResponse()->getContent());
        self::assertSame('(editado)', trim($item->filter('.despacho-em > .tarefa-msg-editado')->text()));

        $this->client->request('POST', '/tarefas/mensagem/' . $id . '/editar', ['conteudo' => 'Texto trocado']);
        self::assertContains($this->client->getResponse()->getStatusCode(), [404, 405]);
        self::assertSame('Antiga', $this->em->find(TarefaMensagem::class, $id)?->getMensagem());
    }

    #[TestDox('Em revisão, o criador despacha "Para providência: falta X" e a meta volta pendente, com o histórico e o aviso')]
    public function testCriadorDespachaEmRevisaoDevolve(): void
    {
        $this->meta->setStatus(Tarefa::STATUS_EM_REVISAO);
        $this->em->flush();
        $this->logarComTenant($this->client, $this->criador, $this->escritorio);

        $this->despachar(['mensagem' => 'Para providência: falta a procuração.', 'natureza' => 'providencia']);

        $despacho = $this->unicoDespacho();
        self::assertSame(AcaoDoDespacho::Devolucao, $despacho->getAcao());
        self::assertSame(NaturezaDoDespacho::Providencia, $despacho->getNatureza());
        self::assertMatchesRegularExpression('/^\d{4}\.\d{4}$/', (string) $despacho->getCodigo());
        self::assertSame(Tarefa::STATUS_PENDENTE, $this->em->find(Tarefa::class, $this->meta->getId())?->getStatus());
        self::assertCount(1, $this->em->getRepository(\App\Entity\Notificacao::class)->findBy(['usuario' => $this->responsavel->getId(), 'tipo' => \App\Entity\Notificacao::TIPO_TAREFA_PENDENTE]));
        self::assertCount(0, $this->em->getRepository(\App\Entity\Notificacao::class)->findBy(['usuario' => $this->criador->getId(), 'tipo' => \App\Entity\Notificacao::TIPO_TAREFA_PENDENTE]));
    }

    #[TestDox('Na meta pendente em que não é responsável, o criador despacha e o status não muda')]
    public function testCriadorDespachaNaPendente(): void
    {
        $this->logarComTenant($this->client, $this->criador, $this->escritorio);

        $this->despachar(['mensagem' => 'Acompanhando.']);

        self::assertSame(AcaoDoDespacho::Despacho, $this->unicoDespacho()->getAcao());
        self::assertSame(Tarefa::STATUS_PENDENTE, $this->em->find(Tarefa::class, $this->meta->getId())?->getStatus());
    }

    #[TestDox('Envio para revisão e devolução: cabeçalho com o rótulo da ação (L127), marcador âmbar e sem Certificar')]
    public function testEnvioEDevolucaoNaLinhaDoTempo(): void
    {
        // Devolver é o modal da lateral desde a Fase 3: o responsável devolve a meta pendente para quem enviou.
        $this->logarComTenant($this->client, $this->responsavel, $this->escritorio);
        $id = (int) $this->meta->getId();
        $this->client->request('POST', '/tarefas/' . $id . '/devolver', ['_token' => 'TOKEN_devolver_meta_' . $id, 'motivo' => 'Falta documento', 'o_que_falta' => 'Ajustar a peça.']);
        self::assertResponseIsSuccessful();
        $this->logarComTenant($this->client, $this->criador, $this->escritorio);
        $this->despachar(['mensagem' => 'Pronto para revisão.', 'acao' => 'enviar']);
        // Em revisão, o despacho do criador devolveria a meta: o comentário solto é de outra pessoa.
        $this->logarComTenant($this->client, $this->colega, $this->escritorio);
        $this->despachar(['mensagem' => 'Comentário solto.']);
        [$devolucao, $envio, $comentario] = $this->despachosDaMeta();
        self::assertSame(AcaoDoDespacho::Envio, $envio->getAcao());
        self::assertSame(AcaoDoDespacho::Devolucao, $devolucao->getAcao());
        self::assertSame(AcaoDoDespacho::Despacho, $comentario->getAcao());

        // Quem certifica é o responsável ou o criador: a tela é a do criador.
        $this->logarComTenant($this->client, $this->criador, $this->escritorio);
        $crawler = $this->client->request('GET', '/tarefas/' . $this->meta->getId());

        $linha = static fn (TarefaMensagem $d): string => '#despacho-' . $d->getId();
        self::assertStringContainsString('Enviado para revisão', $crawler->filter($linha($envio) . ' .despacho-tipo')->text());
        self::assertCount(1, $crawler->filter($linha($envio) . '.despacho-item--ambar .despacho-icone .bi-send'));
        self::assertCount(0, $crawler->filter($linha($envio) . ' .despacho-certificar'));
        self::assertStringContainsString('Devolvida', $crawler->filter($linha($devolucao) . ' .despacho-tipo')->text());
        self::assertCount(1, $crawler->filter($linha($devolucao) . '.despacho-item--ambar .despacho-icone .bi-arrow-return-left'));
        self::assertCount(0, $crawler->filter($linha($devolucao) . ' .despacho-certificar'));
        self::assertStringContainsString('Comentário', $crawler->filter($linha($comentario) . ' .despacho-tipo')->text());
        self::assertCount(1, $crawler->filter($linha($comentario) . ' .despacho-certificar'));
    }

    #[TestDox('Mensagem antiga (sem ação nem código): rótulo "Comentário", sem Certificar e sem ciência ao abrir')]
    public function testAntigaSemCertificarESemCiencia(): void
    {
        $antiga = (new TarefaMensagem())->setUsuario($this->criador)->setTenant($this->escritorio)->setMensagem('Antiga');
        $this->meta->addMensagem($antiga);
        $this->em->persist($antiga);
        $this->em->flush();
        $id = (int) $antiga->getId();
        $this->logarComTenant($this->client, $this->responsavel, $this->escritorio);

        $crawler = $this->client->request('GET', '/tarefas/' . $this->meta->getId());

        self::assertStringContainsString('Comentário', $crawler->filter('#despacho-' . $id . ' .despacho-tipo')->text());
        self::assertCount(0, $crawler->filter('#despacho-' . $id . ' .despacho-certificar'));
        self::assertNull($this->despacho($id)->getCienteEm());
        $this->certificar($id, 'Certifico que fiz.');
        self::assertNull($this->despacho($id)->getCertidao(), 'mensagem antiga não se certifica');
    }

    #[TestDox('Segunda certidão no mesmo despacho: recusa com "Este despacho já tem certidão." e mantém a primeira')]
    public function testSegundaCertidaoRecusada(): void
    {
        $id = (int) $this->despachoGravado($this->criador)->getId();
        $this->logarComTenant($this->client, $this->responsavel, $this->escritorio);
        $this->certificar($id, 'Certifico que fiz primeiro.');

        $this->logarComTenant($this->client, $this->criador, $this->escritorio);
        $this->certificar($id, 'Certifico que fiz depois.');
        $this->client->followRedirect();

        self::assertSelectorTextContains('body', 'Este despacho já tem certidão.');
        self::assertSame('Certifico que fiz primeiro.', $this->despacho($id)->getCertidao());
    }

    #[TestDox('Validação do código: pasta com cadeado que esconde dela dá 404 a quem consulta; o primário vê')]
    public function testValidacaoComPastaOculta(): void
    {
        $codigo = (string) $this->despachoGravado($this->criador)->getCodigo();
        $semPrimazia = $this->criarPessoa($this->escritorio, 'Lia Moura', false);
        $pasta = $this->meta->getPasta();
        $this->em->persist(new CadeadoDaPasta($this->escritorio, $pasta, ModoDoCadeado::Todos, $this->criador, new \DateTimeImmutable()));
        $this->em->flush();

        $this->logarComTenant($this->client, $semPrimazia, $this->escritorio);
        $this->client->request('GET', '/tarefas/registro/' . $codigo);
        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextNotContains('body', 'Carla Dias');

        $this->logarComTenant($this->client, $this->colega, $this->escritorio);
        $this->client->request('GET', '/tarefas/registro/' . $codigo);
        self::assertResponseIsSuccessful();
    }

    #[TestDox('Pasta excluída: abrir a meta não dá ciência')]
    public function testPastaExcluidaSemCiencia(): void
    {
        $id = (int) $this->despachoGravado($this->criador)->getId();
        $this->meta->getPasta()->marcarExcluida($this->criador, new \DateTimeImmutable());
        $this->em->flush();
        $this->logarComTenant($this->client, $this->responsavel, $this->escritorio);

        $this->client->request('GET', '/tarefas/' . $this->meta->getId());

        self::assertNull($this->despacho($id)->getCienteEm());
    }

    #[TestDox('Repositório: ciência e certidão por UPDATE condicional; a entidade desatualizada não sobrescreve a primeira')]
    public function testCienciaECertidaoAtomicas(): void
    {
        $repositorio = $this->em->getRepository(TarefaMensagem::class);
        self::assertInstanceOf(TarefaMensagemRepository::class, $repositorio);
        $id = (int) $this->despachoGravado($this->criador)->getId();
        $outroId = (int) $this->despachoGravado($this->criador)->getId();
        $desatualizado = $this->despacho($id);
        $conexao = $this->em->getConnection();
        // Outra requisição gravou primeiro; a entidade em memória ainda não sabe.
        $conexao->executeStatement('UPDATE tarefa_mensagem SET ciente_em = NOW(), ciente_por_id = :p, certidao = :c WHERE id = :id', [
            'p' => $this->responsavel->getId(), 'c' => 'Certifico que fiz primeiro.', 'id' => $id,
        ]);
        self::assertFalse($desatualizado->temCiencia());

        $colega = $this->em->find(User::class, $this->colega->getId()) ?? throw new \LogicException('Colega sumiu.');
        self::assertFalse($repositorio->darCienciaSeAinda($desatualizado, $colega, new \DateTimeImmutable()));
        self::assertFalse($repositorio->certificarSeAinda($desatualizado, $colega, 'Certifico que fiz depois.', new \DateTimeImmutable()));
        self::assertSame($this->responsavel->getId(), $this->despacho($id)->getCientePor()?->getId());
        self::assertSame('Certifico que fiz primeiro.', $this->despacho($id)->getCertidao());

        $outro = $this->despacho($outroId);
        $colega = $this->em->find(User::class, $this->colega->getId()) ?? throw new \LogicException('Colega sumiu.');
        self::assertTrue($repositorio->darCienciaSeAinda($outro, $colega, new \DateTimeImmutable()));
        self::assertSame($this->colega->getId(), $outro->getCientePor()?->getId(), 'a entidade volta atualizada');
    }

    #[TestDox('Repositório: código já usado no escritório não grava (índice único) e a transação segue viva')]
    public function testCodigoColididoNaoGrava(): void
    {
        $repositorio = $this->em->getRepository(TarefaMensagem::class);
        self::assertInstanceOf(TarefaMensagemRepository::class, $repositorio);
        $codigo = (string) $this->despachoGravado($this->criador)->getCodigo();
        $novo = (new TarefaMensagem())->setUsuario($this->criador)->setTenant($this->escritorio)->setMensagem('Novo');
        $this->meta->addMensagem($novo);
        $this->em->persist($novo);
        $this->em->flush();

        self::assertFalse($repositorio->gravarCodigoSeLivre($novo, $codigo));
        self::assertNull($novo->getCodigo());
        self::assertTrue($repositorio->gravarCodigoSeLivre($novo, '0000.0001'));
        self::assertSame('0000.0001', $novo->getCodigo());
        self::assertFalse($repositorio->gravarCodigoSeLivre($novo, '0000.0002'), 'quem já tem código não troca');
    }

    /** @param array<string, string> $campos */
    private function despachar(array $campos): void
    {
        $id = (int) $this->meta->getId();
        $this->client->request('POST', '/tarefas/' . $id . '/mensagem', $campos + ['_token' => 'TOKEN_mensagem_tarefa_' . $id, 'acao' => '']);
        self::assertResponseRedirects();
    }

    private function certificar(int $despachoId, string $texto): void
    {
        $this->client->request('POST', '/tarefas/mensagem/' . $despachoId . '/certificar', [
            '_token'   => 'TOKEN_certificar_despacho_' . $despachoId,
            'certidao' => $texto,
        ]);
    }

    private function despachoGravado(User $autor, ?NaturezaDoDespacho $natureza = null): TarefaMensagem
    {
        $despacho = (new TarefaMensagem())->setUsuario($autor)->setTenant($this->escritorio)->setMensagem('<p>Texto secreto do despacho</p>');
        $despacho->setNatureza($natureza)->setAcao(AcaoDoDespacho::Despacho)->registrar(sprintf('%04d.%04d', random_int(0, 9999), random_int(0, 9999)), (string) $autor->getFullName(), 'Sócia');
        $this->meta->addMensagem($despacho);
        $this->em->persist($despacho);
        $this->em->flush();

        return $despacho;
    }

    /** @return list<TarefaMensagem> */
    private function despachosDaMeta(): array
    {
        $this->em->clear();

        return $this->em->getRepository(TarefaMensagem::class)->findBy(['tarefa' => $this->meta->getId()], ['id' => 'ASC']);
    }

    private function unicoDespacho(): TarefaMensagem
    {
        $despachos = $this->despachosDaMeta();
        self::assertCount(1, $despachos);

        return $despachos[0];
    }

    private function despacho(int $id): TarefaMensagem
    {
        $this->em->clear();

        return $this->em->find(TarefaMensagem::class, $id) ?? throw new \LogicException('Despacho sumiu.');
    }

    private function criarEscritorio(?string $cidade): Tenant
    {
        $tenant = (new Tenant())->setCidade($cidade)->setEstado('AL');
        $tenant->setName('Escritório Despacho ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function criarPessoa(Tenant $tenant, string $nome, bool $primario = true): User
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
        if (!$primario) {
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
