<?php

declare(strict_types=1);

namespace App\Tests\Expediente\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Notificacao;
use App\Entity\Permission\Permission;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tarefa\TarefaMensagem;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Expediente\Controller\ExpedienteController;
use App\Expediente\Entity\Marcador;
use App\Pasta\Entity\CadeadoDaPasta;
use App\Pasta\Entity\EstadoDoPedidoDeAcesso;
use App\Pasta\Entity\PedidoDeAcessoAPasta;
use App\Pasta\Entity\ModoDoCadeado;
use App\Pasta\Entity\NivelDeAcesso;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDestaque;
use App\Pasta\Entity\PastaMensagem;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Rotas novas da Lista do Expediente integral contra o desenho 1.1 (E12, spec
 * `docs/specs/fidelidade-designer-1310/E12-LISTA-INTEGRAL.md`): reordenar marcador, editar nome rápido
 * (F2), trancar, destacar, pedir acesso, filtros EXPEDIENTES, pastas por página, CSV e sugestões da Nova
 * Pasta. Cada uma com o caso feliz, a recusa e o isolamento entre escritórios.
 */
#[CoversClass(ExpedienteController::class)]
final class ExpedienteListaIntegralControllerTest extends JusPrimeWebTestCase
{
    private const TOKEN = 'TOKEN_' . ExpedienteController::CSRF_LISTA;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        // Várias requisições por teste: sem reiniciar o kernel, o armazenamento de CSRF do teste continua valendo.
        $this->client->disableReboot();
        $this->instalarCsrfStorage();
    }

    // ── Reordenar marcador ──────────────────────────────────────────────

    #[TestDox('reordenar leva o marcador para antes do alvo e renumera o nível')]
    public function testReordenarAntesDoAlvo(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $a = $this->criarMarcador($tenant, $user, 'A');
        $b = $this->criarMarcador($tenant, $user, 'B');
        $c = $this->criarMarcador($tenant, $user, 'C');
        $this->logarComTenant($this->client, $user, $tenant);

        $this->post('/expediente/marcador/' . $c->getId() . '/reordenar', ['alvo_id' => $a->getId(), 'depois' => '0']);

        self::assertResponseIsSuccessful();
        $this->em()->clear();
        $ordem = array_map(
            fn (int $id): int => $this->em()->find(Marcador::class, $id)->getOrdem(),
            [$c->getId(), $a->getId(), $b->getId()],
        );
        self::assertSame([0, 1, 2], $ordem);
    }

    #[TestDox('reordenar entre níveis diferentes é recusado com a mensagem do desenho')]
    public function testReordenarEntreNiveisRecusa(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pai = $this->criarMarcador($tenant, $user, 'Pai');
        $sub = $this->criarMarcador($tenant, $user, 'Sub', $pai);
        $outro = $this->criarMarcador($tenant, $user, 'Outro');
        $this->logarComTenant($this->client, $user, $tenant);

        $this->post('/expediente/marcador/' . $sub->getId() . '/reordenar', ['alvo_id' => $outro->getId(), 'depois' => '1']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('dentro do mesmo marcador', $this->resposta()['erro']);
    }

    #[TestDox('reordenar com marcador de outro escritório responde não encontrado')]
    public function testReordenarIsolamento(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        [$outroUser, $outroTenant] = $this->criarAdmin();
        $meu  = $this->criarMarcador($tenant, $user, 'Meu');
        $dele = $this->criarMarcador($outroTenant, $outroUser, 'Dele');
        $this->logarComTenant($this->client, $user, $tenant);

        $this->post('/expediente/marcador/' . $dele->getId() . '/reordenar', ['alvo_id' => $meu->getId(), 'depois' => '0']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Marcador não encontrado.', $this->resposta()['erro']);
    }

    #[TestDox('reordenar sem o token da Lista é recusado')]
    public function testReordenarSemToken(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $a = $this->criarMarcador($tenant, $user, 'A');
        $b = $this->criarMarcador($tenant, $user, 'B');
        $this->logarComTenant($this->client, $user, $tenant);

        $this->client->xmlHttpRequest('POST', '/expediente/marcador/' . $a->getId() . '/reordenar', ['alvo_id' => $b->getId(), '_token' => 'errado']);

        self::assertResponseStatusCodeSame(403);
    }

    // ── Editar marcador: descrição, pai e nome único ───────────────────

    #[TestDox('editar marcador grava descrição e leva para dentro de outro marcador')]
    public function testEditarMarcadorDescricaoEPai(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pai = $this->criarMarcador($tenant, $user, 'Pai');
        $m   = $this->criarMarcador($tenant, $user, 'Filho');
        $this->logarComTenant($this->client, $user, $tenant);

        $this->client->xmlHttpRequest('PATCH', '/expediente/marcador/' . $m->getId(), [
            '_token'    => 'TOKEN_editar_marcador_' . $m->getId(),
            'nome'      => $m->getNome(),
            'cor'       => '#5b4fb5',
            'descricao' => 'Para que serve',
            'pai_id'    => (string) $pai->getId(),
        ]);

        self::assertResponseIsSuccessful();
        $dados = $this->resposta();
        self::assertSame('Para que serve', $dados['descricao']);
        self::assertSame($pai->getId(), $dados['paiId']);
        self::assertSame('#5b4fb5', $dados['cor']);
    }

    #[TestDox('criar marcador com nome já usado no escritório é recusado, sem diferenciar maiúsculas')]
    public function testCriarMarcadorNomeRepetido(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $existente = $this->criarMarcador($tenant, $user, 'Repetido');
        $this->logarComTenant($this->client, $user, $tenant);

        $this->post('/expediente/marcador', ['nome' => mb_strtoupper($existente->getNome())], 'TOKEN_marcador');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Já existe um marcador com esse nome', $this->resposta()['erro']);
    }

    // ── Editar nome rápido (F2) ─────────────────────────────────────────

    #[TestDox('F2 grava o nome em caixa alta, sem espaços repetidos, e não mexe na situação')]
    public function testRenomearCliente(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $pasta->setSituacao(Pasta::SITUACAO_ARQUIVADA);
        $this->em()->flush();
        $this->logarComTenant($this->client, $user, $tenant);

        $this->post('/expediente/pasta/' . $pasta->getId() . '/nome', ['nome' => '  maria   da silva ']);

        self::assertResponseIsSuccessful();
        self::assertSame('MARIA DA SILVA', $this->resposta()['nome']);
        $this->em()->clear();
        $recarregada = $this->em()->find(Pasta::class, $pasta->getId());
        self::assertSame('MARIA DA SILVA', $recarregada->getNomeCliente());
        self::assertSame(Pasta::SITUACAO_ARQUIVADA, $recarregada->getSituacao());
    }

    #[TestDox('F2 com nome vazio é recusado')]
    public function testRenomearVazio(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->post('/expediente/pasta/' . $pasta->getId() . '/nome', ['nome' => '   ']);

        self::assertResponseStatusCodeSame(422);
    }

    #[TestDox('F2 em pasta de outro escritório não encontra a pasta e não grava')]
    public function testRenomearIsolamento(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        [, $outroTenant] = $this->criarAdmin();
        $dele = $this->criarPasta($outroTenant);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->post('/expediente/pasta/' . $dele->getId() . '/nome', ['nome' => 'INVASOR']);

        self::assertResponseStatusCodeSame(404);
        // Pelo banco: o TenantFilter da sessão esconderia a pasta do outro escritório.
        self::assertNull($this->em()->getConnection()->fetchOne('SELECT nome_cliente FROM pasta WHERE id = ?', [$dele->getId()]));
    }

    // ── Trancar ─────────────────────────────────────────────────────────

    #[TestDox('o administrador tranca e destranca a pasta')]
    public function testAdministradorTrancaEDestranca(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->post('/expediente/pasta/' . $pasta->getId() . '/trancar');
        self::assertResponseIsSuccessful();
        self::assertTrue($this->resposta()['trancada']);
        self::assertSame($user->getFullName(), $this->resposta()['por']);

        $this->post('/expediente/pasta/' . $pasta->getId() . '/trancar');
        self::assertFalse($this->resposta()['trancada']);
    }

    #[TestDox('quem não é administrador não tranca')]
    public function testNaoAdministradorNaoTranca(): void
    {
        [, $tenant] = $this->criarAdmin();
        $colaborador = $this->criarColaborador($tenant);
        $pasta = $this->criarPasta($tenant);
        $this->logarComTenant($this->client, $colaborador, $tenant);

        $this->post('/expediente/pasta/' . $pasta->getId() . '/trancar');

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM pasta_cadeado WHERE pasta_id = ?', [$pasta->getId()]));
    }

    // ── Destacar ────────────────────────────────────────────────────────

    #[TestDox('destacar grava a cor do usuário, troca e tira')]
    public function testDestacar(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->post('/expediente/pasta/' . $pasta->getId() . '/destaque', ['cor' => 'amarelo']);
        self::assertSame('amarelo', $this->resposta()['cor']);
        $this->post('/expediente/pasta/' . $pasta->getId() . '/destaque', ['cor' => 'lilas']);
        self::assertSame(1, $this->contarDestaques($pasta));

        $this->post('/expediente/pasta/' . $pasta->getId() . '/destaque', ['cor' => '']);
        self::assertNull($this->resposta()['cor']);
        self::assertSame(0, $this->contarDestaques($pasta));
    }

    #[TestDox('destacar com cor fora das 6 do desenho é recusado')]
    public function testDestacarCorInvalida(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->post('/expediente/pasta/' . $pasta->getId() . '/destaque', ['cor' => 'preto']);

        self::assertResponseStatusCodeSame(422);
    }

    // ── Pedir acesso ────────────────────────────────────────────────────

    #[TestDox('o pedido de acesso da Lista (rota pasta_acesso_pedir, token da pasta) cria o pedido e notifica o primário')]
    public function testPedirAcessoNotificaAdministrador(): void
    {
        [$admin, $tenant] = $this->criarAdmin();
        $colaborador = $this->criarColaborador($tenant, ['resources.pasta.view']);
        $pasta = $this->criarPasta($tenant);
        $this->trancar($pasta, $admin);
        $this->em()->flush();
        $this->logarComTenant($this->client, $colaborador, $tenant);

        $this->pedirPelaLista($pasta);

        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->resposta()['avisados']);
        $pedidos = $this->em()->getRepository(PedidoDeAcessoAPasta::class)->findBy(['pasta' => $pasta, 'solicitante' => $colaborador]);
        self::assertCount(1, $pedidos, 'o pedido da Lista cria o PedidoDeAcessoAPasta');
        self::assertSame(EstadoDoPedidoDeAcesso::Pendente, $pedidos[0]->getEstado());
        $notificacoes = $this->em()->getRepository(Notificacao::class)->findBy([
            'usuario' => $admin,
            'tipo'    => Notificacao::TIPO_PASTA_PEDIDO_ACESSO,
        ]);
        self::assertCount(1, $notificacoes);
        self::assertSame($tenant->getId(), $notificacoes[0]->getTenant()->getId());
    }

    #[TestDox('pedir acesso a pasta que não está trancada é recusado')]
    public function testPedirAcessoPastaAberta(): void
    {
        [, $tenant] = $this->criarAdmin();
        $colaborador = $this->criarColaborador($tenant, ['resources.pasta.view']);
        $pasta = $this->criarPasta($tenant);
        $this->logarComTenant($this->client, $colaborador, $tenant);

        $this->pedirPelaLista($pasta);

        self::assertResponseStatusCodeSame(422);
    }

    // ── Recusas: CSRF, isolamento e permissão nas rotas da pasta ───────

    /** @return iterable<string, array{0: string, 1: array<string, string>}> */
    public static function rotasDaPasta(): iterable
    {
        yield 'F2' => ['nome', ['nome' => 'NOVO']];
        yield 'trancar' => ['trancar', []];
        yield 'destaque' => ['destaque', ['cor' => 'azul']];
    }

    #[DataProvider('rotasDaPasta')]
    #[TestDox('rota da pasta sem o token da Lista é recusada')]
    public function testRotaDaPastaSemToken(string $acao, array $dados): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $this->trancar($pasta, $user);
        $this->em()->flush();
        $this->logarComTenant($this->client, $user, $tenant);

        $this->post('/expediente/pasta/' . $pasta->getId() . '/' . $acao, $dados, 'errado');

        self::assertResponseStatusCodeSame(403);
    }

    #[DataProvider('rotasDaPasta')]
    #[TestDox('rota da pasta com pasta de outro escritório não encontra e não grava')]
    public function testRotaDaPastaOutroEscritorio(string $acao, array $dados): void
    {
        [$user, $tenant] = $this->criarAdmin();
        [$outroAdmin, $outroTenant] = $this->criarAdmin();
        $dele = $this->criarPasta($outroTenant);
        $this->trancar($dele, $outroAdmin);
        $this->em()->flush();
        $this->logarComTenant($this->client, $user, $tenant);

        $this->post('/expediente/pasta/' . $dele->getId() . '/' . $acao, $dados);

        self::assertResponseStatusCodeSame(404);
        $conn = $this->em()->getConnection();
        self::assertSame(1, (int) $conn->fetchOne('SELECT COUNT(*) FROM pasta_cadeado WHERE pasta_id = ?', [$dele->getId()]), 'continua trancada');
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM pasta_destaque WHERE pasta_id = ?', [$dele->getId()]));
        self::assertSame(0, (int) $conn->fetchOne("SELECT COUNT(*) FROM notificacao WHERE tipo = 'pasta_pedido_acesso' AND usuario_id = ?", [$outroAdmin->getId()]));
    }

    #[TestDox('colaborador oculto pelo cadeado não usa o F2 nem destaca, mas pede acesso')]
    public function testColaboradorSoVe(): void
    {
        [$admin, $tenant] = $this->criarAdmin();
        $leitor = $this->criarColaborador($tenant, ['resources.pasta.view']);
        $pasta = $this->criarPasta($tenant);
        $this->trancar($pasta, $admin);
        $this->em()->flush();
        $this->logarComTenant($this->client, $leitor, $tenant);

        $this->post('/expediente/pasta/' . $pasta->getId() . '/nome', ['nome' => 'NAO PODE']);
        self::assertResponseStatusCodeSame(403);
        $this->post('/expediente/pasta/' . $pasta->getId() . '/destaque', ['cor' => 'rosa']);
        self::assertResponseStatusCodeSame(403);
        $this->pedirPelaLista($pasta);
        self::assertResponseIsSuccessful();
    }

    #[TestDox('colaborador que só visualiza pelo cadeado destaca a linha (preferência pessoal), mas não usa o F2')]
    public function testColaboradorQueVisualizaDestaca(): void
    {
        [$admin, $tenant] = $this->criarAdmin();
        $leitor = $this->criarColaborador($tenant, ['resources.pasta.view', 'resources.pasta.edit']);
        $pasta = $this->criarPasta($tenant);
        $cadeado = new CadeadoDaPasta($tenant, $pasta, ModoDoCadeado::PorPessoa, $admin, new \DateTimeImmutable());
        $cadeado->definirPorPessoa([[$leitor, NivelDeAcesso::Visualizar]], $admin, new \DateTimeImmutable());
        $this->em()->persist($cadeado);
        $this->em()->flush();
        $this->logarComTenant($this->client, $leitor, $tenant);

        $this->post('/expediente/pasta/' . $pasta->getId() . '/nome', ['nome' => 'NAO PODE']);
        self::assertResponseStatusCodeSame(403);
        $this->post('/expediente/pasta/' . $pasta->getId() . '/destaque', ['cor' => 'rosa']);
        self::assertResponseIsSuccessful();
    }

    #[TestDox('colaborador que nem vê a pasta não destaca nem pede acesso')]
    public function testColaboradorSemVer(): void
    {
        [$admin, $tenant] = $this->criarAdmin();
        $estranho = $this->criarColaborador($tenant);
        $pasta = $this->criarPasta($tenant);
        $this->trancar($pasta, $admin);
        $this->em()->flush();
        $this->logarComTenant($this->client, $estranho, $tenant);

        $this->post('/expediente/pasta/' . $pasta->getId() . '/destaque', ['cor' => 'rosa']);
        self::assertResponseStatusCodeSame(403);
        $this->pedirPelaLista($pasta);
        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('CSV com marcador de outro escritório não encontra')]
    public function testCsvMarcadorDeOutroEscritorio(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        [$outro, $outroTenant] = $this->criarAdmin();
        $dele = $this->criarMarcador($outroTenant, $outro, 'Dele');
        $this->logarComTenant($this->client, $user, $tenant);

        $this->client->request('GET', '/expediente/exportar.csv?marcador=' . $dele->getId());

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('CSV neutraliza texto que viraria fórmula na planilha')]
    public function testCsvSemFormula(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant, 'FORM');
        $pasta->setNomeCliente('=HYPERLINK("http://x";"abrir")');
        $this->em()->flush();
        $this->logarComTenant($this->client, $user, $tenant);

        $this->client->request('GET', '/expediente/exportar.csv?colunas=cliente');

        self::assertStringContainsString('"\'=HYPERLINK(""HTTP://X"";""ABRIR"")"', (string) $this->client->getResponse()->getContent(), 'o setter grava em maiúsculas');
    }

    #[TestDox('painel de um marcador de topo traz também as pastas dos submarcadores, sem repetir')]
    public function testPainelDoMarcadorDeTopoTrazOsSubs(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pai = $this->criarMarcador($tenant, $user, 'Pai');
        $sub = $this->criarMarcador($tenant, $user, 'Sub', $pai);
        $soPai = $this->criarPasta($tenant, 'SOPAI');
        $soSub = $this->criarPasta($tenant, 'SOSUB');
        $nosDois = $this->criarPasta($tenant, 'DOIS');
        $fora = $this->criarPasta($tenant, 'FORA');
        $soPai->addMarcador($pai);
        $soSub->addMarcador($sub);
        $nosDois->addMarcador($pai);
        $nosDois->addMarcador($sub);
        $this->em()->flush();
        $this->logarComTenant($this->client, $user, $tenant);

        $this->client->xmlHttpRequest('GET', '/expediente/marcador/' . $pai->getId() . '/pastas');
        $html = (string) $this->client->getResponse()->getContent();

        foreach ([$soPai, $soSub, $nosDois] as $p) {
            self::assertSame(1, substr_count($html, 'data-pasta-id="' . $p->getId() . '" data-nup='), $p->getNup());
        }
        self::assertStringNotContainsString($fora->getNup(), $html);

        $this->client->xmlHttpRequest('GET', '/expediente/marcador/' . $sub->getId() . '/pastas');
        self::assertStringNotContainsString($soPai->getNup(), (string) $this->client->getResponse()->getContent(), 'o sub não traz as do pai');
    }

    #[TestDox('sugestões da Nova Pasta não trazem clientes de outro escritório')]
    public function testSugestoesIsolamento(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        [, $outroTenant] = $this->criarAdmin();
        $dele = $this->criarPasta($outroTenant);
        $dele->setNomeCliente('ZEFERINO SIGILOSO');
        $this->em()->flush();
        $this->logarComTenant($this->client, $user, $tenant);

        $this->client->request('GET', '/expediente/nova-pasta/sugestoes?q=zeferino');

        self::assertSame([], $this->resposta()['clientes']);
    }

    #[TestDox('criar submarcador dentro de submarcador é recusado (o trilho tem dois níveis)')]
    public function testSubDeSubRecusado(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pai = $this->criarMarcador($tenant, $user, 'Pai');
        $sub = $this->criarMarcador($tenant, $user, 'Sub', $pai);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->post('/expediente/marcador', ['nome' => 'Neto ' . uniqid(), 'pai_id' => (string) $sub->getId()], 'TOKEN_marcador');

        self::assertResponseStatusCodeSame(422);
    }

    // ── Filtros EXPEDIENTES ─────────────────────────────────────────────

    #[TestDox('"Metas não respondidas" lista a pasta com meta aberta sem resposta e deixa de fora a respondida')]
    public function testFiltroMetasNaoRespondidas(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $semResposta = $this->criarPasta($tenant, 'SEMRESP');
        $respondida  = $this->criarPasta($tenant, 'RESPOND');
        $this->criarMeta($semResposta, $user);
        $meta = $this->criarMeta($respondida, $user);
        $this->responderMeta($meta, $user, new \DateTimeImmutable('-20 days'));
        $this->logarComTenant($this->client, $user, $tenant);

        $html = $this->painel('?expediente=metas');

        self::assertStringContainsString($semResposta->getNup(), $html);
        self::assertStringNotContainsString($respondida->getNup(), $html);
    }

    #[TestDox('"Expediente com prazo" lista meta aberta com prazo hoje ou depois; a concluída fica de fora')]
    public function testFiltroComPrazo(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $comPrazo  = $this->criarPasta($tenant, 'COMPRAZO');
        $concluida = $this->criarPasta($tenant, 'CONCLUIDA');
        $this->criarMeta($comPrazo, $user, new \DateTimeImmutable('+5 days'));
        $this->criarMeta($concluida, $user, new \DateTimeImmutable('+5 days'), Tarefa::STATUS_CONCLUIDA);
        $this->logarComTenant($this->client, $user, $tenant);

        $html = $this->painel('?expediente=prazo');

        self::assertStringContainsString($comPrazo->getNup(), $html);
        self::assertStringNotContainsString($concluida->getNup(), $html);
    }

    #[TestDox('"Prazo findou" pega o prazo vencido sem atividade depois e solta quando alguém responde')]
    public function testFiltroPrazoFindou(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $parada     = $this->criarPasta($tenant, 'PARADA');
        $respondida = $this->criarPasta($tenant, 'MEXIDA');
        $meta1 = $this->criarMeta($parada, $user, new \DateTimeImmutable('-3 days'));
        $meta2 = $this->criarMeta($respondida, $user, new \DateTimeImmutable('-3 days'));
        $this->recuarCriacao($meta1, '-20 days');
        $this->recuarCriacao($meta2, '-20 days');
        $this->responderMeta($meta2, $user, new \DateTimeImmutable('-1 day'));
        $this->logarComTenant($this->client, $user, $tenant);

        $html = $this->painel('?expediente=findou');

        self::assertStringContainsString($parada->getNup(), $html);
        self::assertStringNotContainsString($respondida->getNup(), $html);
    }

    #[TestDox('"Respondidos nos últimos 10 dias" pega a mensagem recente e solta a antiga')]
    public function testFiltroRespondidos10Dias(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $recente = $this->criarPasta($tenant, 'RECENTE');
        $antiga  = $this->criarPasta($tenant, 'ANTIGA');
        $this->criarMensagem($recente, $user, new \DateTimeImmutable('-2 days'));
        $this->criarMensagem($antiga, $user, new \DateTimeImmutable('-30 days'));
        $this->logarComTenant($this->client, $user, $tenant);

        $html = $this->painel('?expediente=resp10');

        self::assertStringContainsString($recente->getNup(), $html);
        self::assertStringNotContainsString($antiga->getNup(), $html);
    }

    #[TestDox('filtro EXPEDIENTES desconhecido é ignorado (lista tudo)')]
    public function testFiltroDesconhecidoIgnorado(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant, 'QUALQUER');
        $this->logarComTenant($this->client, $user, $tenant);

        self::assertStringContainsString($pasta->getNup(), $this->painel('?expediente=audiencia'));
    }

    // ── Pastas por página ───────────────────────────────────────────────

    #[TestDox('por_pagina=25 corta a página em 25; valor fora da lista volta para 50')]
    public function testPorPagina(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        for ($i = 0; $i < 30; ++$i) {
            $this->criarPasta($tenant, 'PP' . $i, false);
        }
        $this->em()->flush();
        $this->logarComTenant($this->client, $user, $tenant);

        self::assertSame(25, substr_count($this->painel('?por_pagina=25'), 'class="pasta-row-link'));
        self::assertSame(30, substr_count($this->painel('?por_pagina=7'), 'class="pasta-row-link'));
    }

    // ── CSV ─────────────────────────────────────────────────────────────

    #[TestDox('o CSV traz as colunas pedidas, NUP primeiro, e só as pastas do escritório')]
    public function testExportarCsv(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        [, $outroTenant] = $this->criarAdmin();
        $minha = $this->criarPasta($tenant, 'CSVMINHA');
        $minha->setNomeCliente('CLIENTE "ASPAS"');
        $this->em()->flush();
        $dele = $this->criarPasta($outroTenant, 'CSVDELE');
        $this->logarComTenant($this->client, $user, $tenant);

        $this->client->request('GET', '/expediente/exportar.csv?colunas=cliente,sit,metas');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('text/csv', (string) $this->client->getResponse()->headers->get('Content-Type'));
        $csv = (string) $this->client->getResponse()->getContent();
        self::assertStringStartsWith("\u{FEFF}NUP;Cliente;Situação\r\n", $csv);
        self::assertStringContainsString('"' . $minha->getNup() . '";"CLIENTE ""ASPAS""";"Ativo"', $csv);
        self::assertStringNotContainsString($dele->getNup(), $csv);
    }

    // ── Nova Pasta ──────────────────────────────────────────────────────

    #[TestDox('sugestões da Nova Pasta trazem o próximo número e os clientes parecidos com o total de pastas')]
    public function testSugestoesNovaPasta(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        foreach (['10', '11'] as $nup) {
            $p = $this->criarPasta($tenant, '', false);
            $p->setNup($nup);
            $p->setNomeCliente('JOANA PRADO');
        }
        $this->em()->flush();
        $this->logarComTenant($this->client, $user, $tenant);

        $this->client->request('GET', '/expediente/nova-pasta/sugestoes?q=joana');

        self::assertResponseIsSuccessful();
        $dados = $this->resposta();
        self::assertSame('12', $dados['proximoNumero']);
        self::assertSame([['nome' => 'JOANA PRADO', 'total' => 2]], $dados['clientes']);
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** @param array<string, mixed> $dados */
    private function post(string $url, array $dados = [], string $token = self::TOKEN): void
    {
        $this->client->xmlHttpRequest('POST', $url, $dados + ['_token' => $token]);
    }

    /** @return array<string, mixed> */
    private function resposta(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function painel(string $query): string
    {
        $this->client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral' . $query);
        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }

    private function contarDestaques(Pasta $pasta): int
    {
        return count($this->em()->getRepository(PastaDestaque::class)->findBy(['pasta' => $pasta]));
    }

    /** @return array{0: User, 1: Tenant} */
    private function criarAdmin(): array
    {
        $em     = $this->em();
        $tenant = new Tenant();
        $tenant->setName('Tenant E12 ' . uniqid());
        $em->persist($tenant);

        $user = $this->novoUsuario('Admin E12');

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Administrador ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        $ut = new UserTenant($user, $tenant);
        $ut->setTenantRole($role);
        $em->persist($ut);
        $em->flush();

        return [$user, $tenant];
    }

    /**
     * Colaborador que entra no Expediente, mas não é administrador. `$extras`: outras permissões do perfil
     * (ex.: `resources.pasta.view`).
     *
     * @param string[] $extras
     */
    private function criarColaborador(Tenant $tenant, array $extras = []): User
    {
        $em   = $this->em();
        $user = $this->novoUsuario('Colaborador E12');

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Colaborador ' . uniqid());
        $role->setIsSystem(false);
        $em->persist($role);

        foreach (array_merge(['modules.expediente.view'], $extras) as $codigo) {
            $perm = $em->getRepository(Permission::class)->findOneBy(['code' => $codigo]);
            if ($perm === null) {
                $perm = new Permission();
                $perm->setCode($codigo);
                $perm->setDescription($codigo);
                $perm->setGroup(explode('.', $codigo)[0]);
                $em->persist($perm);
            }
            $vinculo = new TenantRolePermission();
            $vinculo->setTenantRole($role);
            $vinculo->setPermission($perm);
            $em->persist($vinculo);
            $role->getTenantRolePermissions()->add($vinculo);
        }

        $ut = new UserTenant($user, $tenant);
        $ut->setTenantRole($role);
        $em->persist($ut);
        $em->flush();

        return $user;
    }

    private function novoUsuario(string $nome): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user   = new User();
        $user->setEmail('e12_' . uniqid() . '@test.com');
        $user->setFullName($nome);
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $this->em()->persist($user);

        return $user;
    }

    private function criarPasta(Tenant $tenant, string $rotulo = '', bool $flush = true): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup('E12' . $rotulo . strtoupper(substr(uniqid(), -6)));
        $pasta->setTenant($tenant);
        $this->em()->persist($pasta);
        if ($flush) {
            $this->em()->flush();
        }

        return $pasta;
    }

    private function criarMarcador(Tenant $tenant, User $user, string $nome, ?Marcador $pai = null): Marcador
    {
        $marcador = new Marcador($nome . ' ' . uniqid(), $tenant, $user, $pai);
        $this->em()->persist($marcador);
        $this->em()->flush();

        return $marcador;
    }

    private function criarMeta(Pasta $pasta, User $user, ?\DateTimeImmutable $prazo = null, string $status = Tarefa::STATUS_PENDENTE): Tarefa
    {
        $meta = new Tarefa();
        $meta->setTitulo('Meta E12');
        $meta->setDescricao('');
        $meta->setPrazo($prazo);
        $meta->setStatus($status);
        $meta->setPasta($pasta);
        $meta->setTenant($pasta->getTenant());
        $meta->setCriadoPor($user);
        $this->em()->persist($meta);
        $this->em()->flush();

        return $meta;
    }

    private function recuarCriacao(Tarefa $meta, string $quando): void
    {
        $this->em()->getConnection()->executeStatement(
            'UPDATE tarefa SET data_criacao = :d WHERE id = :id',
            ['d' => (new \DateTimeImmutable($quando))->format('Y-m-d H:i:s'), 'id' => $meta->getId()],
        );
    }

    private function responderMeta(Tarefa $meta, User $user, \DateTimeImmutable $em): void
    {
        $resposta = new TarefaMensagem();
        $resposta->setTarefa($meta);
        $resposta->setTenant($meta->getTenant());
        $resposta->setUsuario($user);
        $resposta->setMensagem('Resposta');
        $resposta->setCriadoEm($em);
        $this->em()->persist($resposta);
        $this->em()->flush();
    }

    private function criarMensagem(Pasta $pasta, User $user, \DateTimeImmutable $em): void
    {
        $mensagem = new PastaMensagem();
        $mensagem->setPasta($pasta);
        $mensagem->setAutor($user);
        $mensagem->setTenant($pasta->getTenant());
        $mensagem->setConteudo('Comentário');
        $this->em()->persist($mensagem);
        $this->em()->flush();
        $this->em()->getConnection()->executeStatement(
            'UPDATE pasta_mensagem SET criada_em = :d WHERE id = :id',
            ['d' => $em->format('Y-m-d H:i:s'), 'id' => $mensagem->getId()],
        );
    }

    private function instalarCsrfStorage(): void
    {
        $storage = new class implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string
            {
                return 'TOKEN_' . $tokenId;
            }

            public function setToken(string $tokenId, string $token): void {}

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function hasToken(string $tokenId): bool
            {
                return true;
            }

            public function clear(): void {}
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }

    /** A tranca da Lista é o cadeado "Ocultar para todos" (spec pasta-cadeado K7). */
    private function trancar(Pasta $pasta, User $por): void
    {
        $this->em()->persist(new CadeadoDaPasta($pasta->getTenant(), $pasta, ModoDoCadeado::Todos, $por, new \DateTimeImmutable()));
    }

    /** O "Pedir acesso" da janela Pasta N protegida: a rota da pasta, com o token da própria pasta (data-csrf-pedir). */
    private function pedirPelaLista(Pasta $pasta): void
    {
        $this->client->xmlHttpRequest('POST', '/pasta/' . $pasta->getId() . '/acesso/pedidos', ['nivel' => 'editar', '_token' => 'TOKEN_pasta_acesso_' . $pasta->getId()]);
    }
}
