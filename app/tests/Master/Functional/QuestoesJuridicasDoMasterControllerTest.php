<?php

declare(strict_types=1);

namespace App\Tests\Master\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Inteligencia\Entity\EstadoDaQuestaoJuridica;
use App\Inteligencia\Entity\VersaoDeDocumentoJuridico;
use App\Inteligencia\Form\ConfiguracaoDeInteligenciaType;
use App\Inteligencia\Juridico\CatalogoDeQuestoesJuridicas;
use App\Inteligencia\Twig\QuestoesJuridicasExtension;
use App\Master\Controller\QuestoesJuridicasDoMasterController;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CatalogoJuridicoDeTeste;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Rotas das questões jurídicas do Master: baixar o documento atual, enviar e baixar a
 * versão ajustada (com histórico) e mudar o status (com histórico). Só o Master. A tela do bloco e o aviso do topo
 * saíram (25/10); as rotas ficam dormentes.
 *
 * O catálogo é o de teste ({@see CatalogoJuridicoDeTeste}: "primeira" pede a Política e o aceite, "segunda" os Termos),
 * para o teste não depender do texto real, que o jurídico troca. O armazém de CSRF é fixo ('TOKEN_<id>' sempre
 * válido): o 403 de quem não é Master vem da regra; o CSRF errado tem teste próprio.
 */
#[CoversClass(QuestoesJuridicasDoMasterController::class)]
#[CoversClass(QuestoesJuridicasExtension::class)]
final class QuestoesJuridicasDoMasterControllerTest extends JusPrimeWebTestCase
{
    private const TOKEN = 'TOKEN_' . QuestoesJuridicasDoMasterController::TOKEN;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    /** @var list<string> */
    private array $arquivosGravados = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->instalarCsrfFixo();
        static::getContainer()->set(CatalogoDeQuestoesJuridicas::class, CatalogoJuridicoDeTeste::criar([
            ['problema' => "Primeiro parágrafo.\n\n<script>alert(1)</script>"] + CatalogoJuridicoDeTeste::questao('primeira', 'alto', ['politica_privacidade', 'aceite_ia']),
            CatalogoJuridicoDeTeste::questao('segunda', 'baixo', ['termos_uso']),
            CatalogoJuridicoDeTeste::questao('escondida', 'baixo', ['termos_uso'], oculta: true),
        ]));
    }

    protected function tearDown(): void
    {
        $dir = (string) static::getContainer()->getParameter('documentos_juridicos_dir');
        foreach ($this->arquivosGravados as $nome) {
            @unlink($dir . '/' . $nome);
        }
        parent::tearDown();
    }

    /** @return iterable<string, array{string, string}> */
    public static function rotas(): iterable
    {
        yield 'GET documento atual' => ['GET', '/master/ia/juridico/documentos/termos_uso'];
        yield 'POST versão' => ['POST', '/master/ia/juridico/documentos/termos_uso/versoes'];
        yield 'GET versão' => ['GET', '/master/ia/juridico/versoes/1'];
        yield 'POST status' => ['POST', '/master/ia/juridico/questoes/primeira/status'];
    }

    #[DataProvider('rotas')]
    #[TestDox('quem não é Master (primário de escritório) recebe 403 em $metodo $url, e nada muda')]
    public function testNaoMaster403(string $metodo, string $url): void
    {
        $this->entrarComoPrimarioDeEscritorio();

        if ($metodo === 'GET') {
            $this->client->request('GET', $url);
        } elseif (str_ends_with($url, '/status')) {
            $this->postarJson($url, ['status' => 'resolvida']);
        } else {
            $this->enviar($url, $this->arquivo("Termos ajustados.\n", 'termos.txt'));
        }

        self::assertResponseStatusCodeSame(403);
        self::assertSame([0, 0, 0], $this->contagens());
    }

    #[TestDox('sem login: não chega às rotas')]
    public function testSemLogin(): void
    {
        $this->client->request('GET', '/master/ia/juridico/documentos/termos_uso');

        self::assertTrue($this->client->getResponse()->isRedirect(), 'vai para o login');
    }

    #[TestDox('CSRF errado: 403 nos dois POST e nada muda')]
    public function testCsrfErrado(): void
    {
        $this->entrarComoMaster();

        $this->postarJson('/master/ia/juridico/questoes/primeira/status', ['status' => 'resolvida'], 'errado');
        self::assertResponseStatusCodeSame(403);
        self::assertSame('Token CSRF inválido. Recarregue a página.', $this->json()['erro']);
        $this->enviar('/master/ia/juridico/documentos/termos_uso/versoes', $this->arquivo("Termos ajustados.\n", 'termos.txt'), token: 'errado');
        self::assertResponseStatusCodeSame(403);

        self::assertSame([0, 0, 0], $this->contagens());
    }

    #[TestDox('status: muda, responde o rótulo, o histórico e quantas seguem em aberto; registra quem e quando; auditoria sem escritório')]
    public function testStatusComHistorico(): void
    {
        $master = $this->entrarComoMaster();

        $this->postarJson('/master/ia/juridico/questoes/primeira/status', ['status' => 'em_analise']);
        self::assertResponseIsSuccessful();
        $d = $this->json();
        self::assertSame(['primeira', 'em_analise', 'Em análise', 2], [$d['chave'], $d['status'], $d['rotulo'], $d['emAberto']]);
        self::assertCount(1, $d['historico']);
        self::assertSame(['Pendente', 'Em análise', 'Dra. Master'], [$d['historico'][0]['de'], $d['historico'][0]['para'], $d['historico'][0]['por']]);
        self::assertMatchesRegularExpression('#^\d{2}/\d{2}/\d{4} \d{2}:\d{2}$#', $d['historico'][0]['quando']);

        $this->postarJson('/master/ia/juridico/questoes/primeira/status', ['status' => 'resolvida']);
        $d = $this->json();
        self::assertSame(1, $d['emAberto']);
        self::assertSame(['Resolvida', 'Em análise'], array_column($d['historico'], 'para'), 'a mais recente primeiro');

        $this->em->clear();
        $estado = $this->em->getRepository(EstadoDaQuestaoJuridica::class)->findOneBy(['chave' => 'primeira']);
        self::assertSame('resolvida', $estado?->getStatus()->value);
        self::assertSame($master->getId(), $estado?->getAlteradaPor()?->getId());
        $auditoria = $this->em->getConnection()->fetchFirstColumn('SELECT tenant_id FROM audit_log WHERE entity_class = ? AND actor_user_id = ?', [EstadoDaQuestaoJuridica::class, $master->getId()]);
        self::assertNotEmpty($auditoria);
        self::assertSame([null], array_values(array_unique($auditoria)));
    }

    #[TestDox('status: o mesmo status não cria histórico; fora da lista é 422; questão fora do catálogo é 404')]
    public function testStatusInvalido(): void
    {
        $this->entrarComoMaster();

        $this->postarJson('/master/ia/juridico/questoes/primeira/status', ['status' => 'pendente']);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->json()['historico']);

        foreach (['arquivada', '', null, 'RESOLVIDA'] as $invalido) {
            $this->postarJson('/master/ia/juridico/questoes/primeira/status', ['status' => $invalido]);
            self::assertResponseStatusCodeSame(422);
            self::assertSame('Escolha um status da lista.', $this->json()['erro']);
        }
        $this->postarJson('/master/ia/juridico/questoes/nao_existe/status', ['status' => 'resolvida']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('Questão jurídica não encontrada.', $this->json()['erro']);
        self::assertSame([0, 0, 0], $this->contagens());
    }

    #[TestDox('upload válido: grava a versão com histórico (quem, quando, nome original, questão) e o download devolve o mesmo arquivo com o nome original')]
    public function testUploadValidoEDownload(): void
    {
        $master = $this->entrarComoMaster();
        $conteudo = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n";

        $this->enviar('/master/ia/juridico/documentos/termos_uso/versoes', $this->arquivo($conteudo, 'Termos de Uso v2.pdf'), 'segunda');

        self::assertResponseIsSuccessful();
        $d = $this->json();
        self::assertSame('termos_uso', $d['documento']);
        self::assertCount(1, $d['versoes']);
        self::assertSame(['Termos de Uso v2.pdf', 'Dra. Master', 'Questão segunda'], [$d['versoes'][0]['nome'], $d['versoes'][0]['por'], $d['versoes'][0]['questao']]);
        $this->em->clear();
        $versao = $this->em->getRepository(VersaoDeDocumentoJuridico::class)->findOneBy([]);
        self::assertNotNull($versao);
        $this->arquivosGravados[] = $versao->getNomeArquivo();
        self::assertSame(['segunda', 'application/pdf', strlen($conteudo)], [$versao->getQuestao(), $versao->getMimeType(), $versao->getTamanhoBytes()]);
        self::assertSame('/master/ia/juridico/versoes/' . $versao->getId(), $d['versoes'][0]['url']);
        $auditoria = $this->em->getConnection()->fetchFirstColumn('SELECT tenant_id FROM audit_log WHERE entity_class = ? AND actor_user_id = ?', [VersaoDeDocumentoJuridico::class, $master->getId()]);
        self::assertNotEmpty($auditoria, 'o envio fica na auditoria');
        self::assertSame([null], array_values(array_unique($auditoria)), 'sem escritório: é da plataforma');

        $this->client->request('GET', $d['versoes'][0]['url']);
        self::assertResponseIsSuccessful();
        $resposta = $this->client->getResponse();
        self::assertInstanceOf(BinaryFileResponse::class, $resposta);
        self::assertSame($conteudo, $resposta->getFile()->getContent());
        self::assertStringStartsWith('attachment;', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringContainsString('Termos de Uso v2.pdf', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
    }

    /** @return iterable<string, array{string, string, ?string, int, string}> */
    public static function uploadsInvalidos(): iterable
    {
        yield 'HTML disfarçado de .pdf' => ['<html><body><script>alert(1)</script></body></html>', 'termos.pdf', null, 422, 'Envie um arquivo .docx, .pdf, .md, .txt ou .odt; o conteúdo precisa ser do tipo da extensão.'];
        yield 'formato antigo .doc' => ["texto qualquer\n", 'termos.doc', null, 422, 'Envie um arquivo .docx, .pdf, .md, .txt ou .odt; o conteúdo precisa ser do tipo da extensão.'];
        yield 'extensão .exe' => ["MZ texto qualquer\n", 'termos.exe', null, 422, 'Envie um arquivo .docx, .pdf, .md, .txt ou .odt; o conteúdo precisa ser do tipo da extensão.'];
        yield 'questão que não pede os Termos' => ["Termos ajustados.\n", 'termos.txt', 'primeira', 422, 'Esta questão não pede este documento.'];
        yield 'questão fora do catálogo' => ["Termos ajustados.\n", 'termos.txt', 'nao_existe', 404, 'Questão jurídica não encontrada.'];
    }

    #[DataProvider('uploadsInvalidos')]
    #[TestDox('upload inválido ($_dataName): recusa com a mensagem e nada é gravado')]
    public function testUploadInvalido(string $conteudo, string $nome, ?string $questao, int $status, string $erro): void
    {
        $this->entrarComoMaster();

        $this->enviar('/master/ia/juridico/documentos/termos_uso/versoes', $this->arquivo($conteudo, $nome), $questao);

        self::assertResponseStatusCodeSame($status);
        self::assertSame($erro, $this->json()['erro']);
        self::assertSame([0, 0, 0], $this->contagens());
    }

    #[TestDox('upload sem arquivo é 422; documento desconhecido é 404; versão que não existe é 404')]
    public function testUploadSemArquivoEDocumentoDesconhecido(): void
    {
        $this->entrarComoMaster();

        $this->client->request('POST', '/master/ia/juridico/documentos/termos_uso/versoes', [], [], ['HTTP_X_CSRF_TOKEN' => self::TOKEN, 'HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Escolha o arquivo a enviar.', $this->json()['erro']);
        $this->enviar('/master/ia/juridico/documentos/contrato_social/versoes', $this->arquivo("Texto.\n", 'a.txt'));
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/master/ia/juridico/versoes/999999999');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/master/ia/juridico/documentos/contrato_social');
        self::assertResponseStatusCodeSame(404);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function documentosAtuais(): iterable
    {
        yield 'Política de Privacidade' => ['politica_privacidade', 'politica-de-privacidade.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
        yield 'Termos de Uso' => ['termos_uso', 'termos-de-uso.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
        yield 'rascunho da política da IA' => ['rascunho_politica_ia', 'rascunho-politica-ia-anthropic.md', 'text/markdown; charset=UTF-8'];
    }

    #[DataProvider('documentosAtuais')]
    #[TestDox('download da versão atual ($_dataName): o arquivo de resources/legal, igual ao de docs/legal, como anexo')]
    public function testDownloadDoAtual(string $documento, string $arquivo, string $tipo): void
    {
        $this->entrarComoMaster();
        $raiz = (string) static::getContainer()->getParameter('kernel.project_dir');

        $this->client->request('GET', '/master/ia/juridico/documentos/' . $documento);

        self::assertResponseIsSuccessful();
        $resposta = $this->client->getResponse();
        self::assertSame((string) file_get_contents($raiz . '/resources/legal/' . $arquivo), $resposta->getContent());
        if (is_file($raiz . '/../docs/legal/' . $arquivo)) {
            self::assertSame(sha1_file($raiz . '/../docs/legal/' . $arquivo), sha1((string) $resposta->getContent()), 'a cópia servida é a mesma de docs/legal');
        }
        self::assertSame($tipo, $resposta->headers->get('Content-Type'));
        self::assertSame('nosniff', $resposta->headers->get('X-Content-Type-Options'));
        self::assertStringStartsWith('attachment;', (string) $resposta->headers->get('Content-Disposition'));
        self::assertStringContainsString($arquivo, (string) $resposta->headers->get('Content-Disposition'));
    }

    #[TestDox('download do texto do aceite: .txt gerado com o rótulo, o aviso de envio e as seções que a tela do aceite mostra')]
    public function testDownloadDoAceite(): void
    {
        $this->entrarComoMaster();

        $this->client->request('GET', '/master/ia/juridico/documentos/aceite_ia');

        self::assertResponseIsSuccessful();
        $texto = (string) $this->client->getResponse()->getContent();
        self::assertSame('text/plain; charset=UTF-8', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('texto-do-aceite-da-ia.txt', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringStartsWith("Ligar a BlueJus IA neste escritório\n", $texto);
        self::assertStringContainsString('Ao ligar, o escritório aceita enviar a um provedor externo de IA, nos termos do Anexo I da Política de Privacidade:', $texto);
        self::assertStringContainsString('- dados da pasta: número, situação, prioridade, data de abertura, ação, responsável e nomes da equipe;', $texto);
        self::assertStringContainsString('documentos (só nomes e datas)', $texto);
        self::assertStringContainsString('Processos em segredo de justiça nunca são enviados.', $texto);
        $rotuloDoForm = static::getContainer()->get('form.factory')->create(ConfiguracaoDeInteligenciaType::class)->get('habilitada')->getConfig()->getOption('label');
        self::assertStringStartsWith($rotuloDoForm . "\n", $texto, 'o rótulo baixado é o mesmo do form do admin');
    }

    #[TestDox('upload sem questão (bloco único): grava a versão sem questão')]
    public function testUploadSemQuestao(): void
    {
        $this->entrarComoMaster();
        $this->enviar('/master/ia/juridico/documentos/politica_privacidade/versoes', $this->arquivo("Política ajustada.\n", 'politica-v2.txt'));
        $this->guardarArquivosGravados();

        self::assertResponseIsSuccessful();
        self::assertNull($this->json()['versoes'][0]['questao']);
    }

    #[TestDox('questão oculta no catálogo: o status dela dá 404')]
    public function testQuestaoOcultaNaTela(): void
    {
        $this->entrarComoMaster();

        $this->postarJson('/master/ia/juridico/questoes/escondida/status', ['status' => 'resolvida']);
        self::assertResponseStatusCodeSame(404);
    }

    private function entrarComoMaster(): User
    {
        $master = $this->usuario(['ROLE_SUPER_ADMIN', 'ROLE_MASTER_PRIMARIO']);
        $this->client->loginUser($master);
        $this->marcarTermosAceitos($this->client);

        return $master;
    }

    private function entrarComoPrimarioDeEscritorio(): void
    {
        $tenant = (new Tenant())->setName('Escritório comum ' . uniqid());
        $this->em->persist($tenant);
        $papel = (new TenantRole())->setTenant($tenant)->setName('Administrador do Escritório')->setIsSystem(true);
        $this->em->persist($papel);
        $user = $this->usuario(['ROLE_USER']);
        $this->em->persist((new UserTenant($user, $tenant))->setTenantRole($papel));
        $tenant->setCriadoPor($user);
        $this->em->flush();
        $this->logarComTenant($this->client, $user, $tenant);
    }

    /** @param list<string> $papeis */
    private function usuario(array $papeis): User
    {
        $u = (new User())->setEmail('juridico_' . uniqid() . '@teste.com')->setFullName('Dra. Master')->setRoles($papeis)->setIsActive(true);
        $u->setPassword('x');
        $this->em->persist($u);
        $this->em->flush();

        return $u;
    }

    /** @return array{int, int, int} linhas de estado, de histórico e de versão */
    private function contagens(): array
    {
        $c = $this->em->getConnection();

        return [
            (int) $c->fetchOne('SELECT COUNT(*) FROM inteligencia_questao_juridica'),
            (int) $c->fetchOne('SELECT COUNT(*) FROM inteligencia_questao_juridica_historico'),
            (int) $c->fetchOne('SELECT COUNT(*) FROM inteligencia_documento_juridico_versao'),
        ];
    }

    private function guardarArquivosGravados(): void
    {
        foreach ($this->em->getConnection()->fetchFirstColumn('SELECT nome_arquivo FROM inteligencia_documento_juridico_versao') as $nome) {
            $this->arquivosGravados[] = (string) $nome;
        }
    }

    private function arquivo(string $conteudo, string $nome): UploadedFile
    {
        $caminho = tempnam(sys_get_temp_dir(), 'juridico-');
        file_put_contents($caminho, $conteudo);

        return new UploadedFile($caminho, $nome, null, null, true);
    }

    private function enviar(string $url, UploadedFile $arquivo, ?string $questao = null, string $token = self::TOKEN): void
    {
        $this->client->request('POST', $url, $questao === null ? [] : ['questao' => $questao], ['arquivo' => $arquivo], [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $token,
        ]);
    }

    /** @param array<string, mixed> $corpo */
    private function postarJson(string $url, array $corpo, string $token = self::TOKEN): void
    {
        $this->client->request('POST', $url, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $token,
        ], content: (string) json_encode($corpo));
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 8, \JSON_THROW_ON_ERROR);
    }

    private function instalarCsrfFixo(): void
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
