<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Functional;

use App\Controller\TarefaController;
use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tarefa\TarefaMensagem;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Tarefa\Service\AcessoAMeta;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\CriaCenarioDoCadeadoFuncionalTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Auditoria 3.12: a meta aberta por id exige VER a pasta (o `canAccessResource` completo, com o cadeado) ou
 * cuidar da meta (responsável ou criador). Quem só tem o módulo Tarefas e não tem ligação com a meta recebe 404
 * na página, nos anexos dos despachos e nas escritas por id; o responsável da meta continua abrindo e concluindo
 * a própria meta (SEC-015 segue no `AlternarMetaPorXhrTest`).
 *
 * Cada caso negativo tem o positivo ao lado (o primário, ou quem vê a pasta, com o mesmo id): sem isso o 404
 * poderia vir de rota errada ou arquivo ausente.
 */
#[CoversClass(AcessoAMeta::class)]
#[CoversClass(TarefaController::class)]
#[Group('tarefa')]
final class MetaSemVerAPastaTest extends JusPrimeWebTestCase
{
    use CriaCenarioDoCadeadoFuncionalTrait;

    private const TITULO = 'Meta sigilosa da pasta';
    private const PDF    = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    /** @var list<string> */
    private array $arquivos = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCliente();
    }

    protected function tearDown(): void
    {
        foreach ($this->arquivos as $arquivo) {
            if (is_file($arquivo)) {
                unlink($arquivo);
            }
        }
        $this->arquivos = [];

        parent::tearDown();
    }

    /** @return array{0: User, 1: Tenant, 2: Tarefa, 3: User} primário, escritório, a meta e quem só tem o módulo */
    private function cenario(): array
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $pasta = $this->criarPasta($tenant, 'JOANA RESTRITA');
        $pasta->setCriadoPor($primario);
        $meta = new Tarefa();
        $meta->setTitulo(self::TITULO);
        $meta->setDescricao('Demanda reservada');
        $meta->setPasta($pasta);
        $meta->setTenant($tenant);
        $meta->setCriadoPor($primario);
        $meta->addResponsavel($primario);
        $this->em()->persist($meta);
        $this->em()->flush();

        $soModulo = $this->criarColaborador($tenant, 'Sem Ver A Pasta', ['modules.tarefas.view']);

        return [$primario, $tenant, $meta, $soModulo];
    }

    private function despachoComAnexo(Tarefa $meta, User $autor): int
    {
        $diretorio = rtrim((string) static::getContainer()->getParameter('tarefas_uploads_dir'), '/') . '/chat';
        if (!is_dir($diretorio) && !mkdir($diretorio, 0775, true) && !is_dir($diretorio)) {
            self::fail('Não foi possível criar ' . $diretorio);
        }
        $nome = 'arquivo_teste_312_' . bin2hex(random_bytes(6)) . '.pdf';
        file_put_contents($diretorio . '/' . $nome, self::PDF);
        $this->arquivos[] = $diretorio . '/' . $nome;

        $mensagem = new TarefaMensagem();
        $mensagem->setTarefa($meta);
        $mensagem->setUsuario($autor);
        $mensagem->setMensagem('Segue o anexo');
        $mensagem->setTenant($meta->getTenant());
        $mensagem->setArquivoAnexo('/uploads/tarefas/chat/' . $nome);
        $this->em()->persist($mensagem);
        $this->em()->flush();

        return (int) $mensagem->getId();
    }

    private function contarDespachos(int $metaId): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM tarefa_mensagem WHERE tarefa_id = ?', [$metaId]);
    }

    /** @return list<int> */
    private function responsaveis(int $metaId): array
    {
        $ids = array_map('intval', $this->em()->getConnection()->fetchFirstColumn('SELECT user_id FROM tarefa_responsaveis WHERE tarefa_id = ? ORDER BY user_id', [$metaId]));

        return $ids;
    }

    #[TestDox('Página da meta: quem só tem o módulo Tarefas, sem ver a pasta nem cuidar da meta, recebe 404 sem o título; o primário e quem vê a pasta abrem')]
    public function testPaginaDaMeta(): void
    {
        [$primario, $tenant, $meta, $soModulo] = $this->cenario();
        $url = '/tarefas/' . $meta->getId();

        $this->entrar($soModulo, $tenant);
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString(self::TITULO, (string) $this->client->getResponse()->getContent());

        $veAPasta = $this->criarColaborador($tenant, 'Vê A Pasta', ['modules.tarefas.view', 'resources.pasta.view']);
        $this->entrar($veAPasta, $tenant);
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(self::TITULO, (string) $this->client->getResponse()->getContent());

        $this->entrar($primario, $tenant);
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
    }

    #[TestDox('Anexo do despacho: quem não vê a pasta nem cuida da meta recebe 404 ao baixar e ao visualizar; o primário baixa')]
    public function testAnexoDoDespacho(): void
    {
        [$primario, $tenant, $meta, $soModulo] = $this->cenario();
        $mensagemId = $this->despachoComAnexo($meta, $primario);

        $this->entrar($soModulo, $tenant);
        $this->client->request('GET', '/tarefas/mensagem/' . $mensagemId . '/arquivo/download');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/tarefas/mensagem/' . $mensagemId . '/arquivo/visualizar');
        self::assertResponseStatusCodeSame(404);

        $this->entrar($primario, $tenant);
        $this->client->request('GET', '/tarefas/mensagem/' . $mensagemId . '/arquivo/download');
        self::assertResponseIsSuccessful();
        self::assertSame(self::PDF, $this->client->getInternalResponse()->getContent());
    }

    #[TestDox('Escrita por id: trocar responsáveis e despachar por quem não vê a pasta nem cuida da meta dá 404 e nada muda no banco')]
    public function testEscritasPorId(): void
    {
        [$primario, $tenant, $meta, $soModulo] = $this->cenario();
        $id    = (int) $meta->getId();
        $antes = $this->responsaveis($id);
        self::assertSame([(int) $primario->getId()], $antes);

        $this->entrar($soModulo, $tenant);
        $this->client->request('POST', '/tarefas/' . $id . '/responsaveis', [
            '_token'       => 'TOKEN_update_responsaveis_' . $id,
            'responsaveis' => [(string) $soModulo->getId()],
        ]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame($antes, $this->responsaveis($id), 'os responsáveis não mudam');

        $this->client->request('POST', '/tarefas/' . $id . '/mensagem', [
            '_token'   => 'TOKEN_mensagem_tarefa_' . $id,
            'mensagem' => 'Despacho de quem não vê a pasta',
        ]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->contarDespachos($id), 'nenhum despacho gravado');
    }

    #[TestDox('Quem cuida da meta (responsável) sem ver a pasta continua abrindo e concluindo a própria meta')]
    public function testResponsavelSemVerAPastaContinua(): void
    {
        [, $tenant, $meta, $soModulo] = $this->cenario();
        $id = (int) $meta->getId();
        $meta->addResponsavel($soModulo);
        $this->em()->flush();

        $this->entrar($soModulo, $tenant);
        $this->client->request('GET', '/tarefas/' . $id);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(self::TITULO, (string) $this->client->getResponse()->getContent());

        $this->client->request('POST', '/tarefas/' . $id . '/concluir', ['_token' => 'TOKEN_concluir_tarefa_' . $id]);
        self::assertResponseRedirects();
        self::assertSame(Tarefa::STATUS_CONCLUIDA, $this->em()->getConnection()->fetchOne('SELECT status FROM tarefa WHERE id = ?', [$id]));
    }
}
