<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Controller\AcaoDaPastaController;
use App\Pasta\Entity\CadeadoDaPasta;
use App\Pasta\Entity\ModoDoCadeado;
use App\Pasta\Entity\NivelDeAcesso;
use App\Pasta\Entity\Pasta;
use App\Pasta\UseCase\EditarAcaoDaPastaUseCase;
use App\Processo\Entity\Processo;
use App\Sync\Entity\TenantDriveConexao;
use App\Sync\Message\SincronizarPastaNoDrive;
use App\Sync\Service\CifradorDeSegredo;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * "Editar ação da pasta" na aba Processo (`pasta_acao_salvar`; decisão do dono de 23/10/2026, desenho 02: "Ação ·
 * vem do menu Processo").
 *
 * Guardas: pasta do escritório (404), CSRF (403), EDIT na pasta (403), cadeado em Visualizar (403 da barreira).
 * O par "só ver" (403) × "ver + editar" (grava) é no MESMO escritório, para o 403 não ser outra barreira. O
 * cross-tenant usa o admin de outro escritório: o 404 vem do filtro do escritório ou da conferência de dono.
 */
#[CoversClass(AcaoDaPastaController::class)]
#[CoversClass(EditarAcaoDaPastaUseCase::class)]
#[Group('pasta')]
final class AcaoDaPastaControllerTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;
    use CriaUsuarioComPermissoesTrait;

    private const NUMERO = '07011345720258070007';
    private const XHR    = ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'];
    private const EDITOR = ['resources.pasta.view', 'resources.pasta.edit'];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $storage = new class () implements ClearableTokenStorageInterface {
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
        };
        static::getContainer()->set('security.csrf.token_storage', $storage);
    }

    // ── Cenário ─────────────────────────────────────────────────────────

    /** @return array{0: Pasta, 1: Processo} pasta com a Ação "COBRANÇA" e um processo principal */
    private function pastaComProcesso(Tenant $tenant): array
    {
        $pasta = $this->criarPasta($tenant);
        $pasta->setNomeAcao('Cobrança');
        $processo = $this->criarProcesso($tenant, self::NUMERO);
        $this->vincular($pasta, $processo);

        return [$pasta, $processo];
    }

    /**
     * @param array<string, string> $campos
     * @param array<string, string> $servidor
     */
    private function salvar(Pasta $pasta, array $campos, array $servidor = []): void
    {
        $this->em()->clear();
        $this->client->request('POST', '/pasta/' . $pasta->getId() . '/acao', $campos + ['_token' => 'TOKEN_pasta_acao_' . $pasta->getId()], [], $servidor);
    }

    private function abrir(Pasta $pasta): Crawler
    {
        $this->em()->clear();
        $crawler = $this->client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /** Do BANCO, não da entidade em memória. */
    private function acaoNoBanco(Pasta $pasta): ?string
    {
        $valor = $this->em()->getConnection()->fetchOne('SELECT nome_acao FROM pasta WHERE id = ?', [$pasta->getId()]);

        return $valor === false || $valor === null ? null : (string) $valor;
    }

    /** @return list<string> os cartões "Dados da pasta" do Registro */
    private function cartoes(Pasta $pasta): array
    {
        return array_map('strval', $this->em()->getConnection()->fetchFirstColumn(
            "SELECT conteudo FROM pasta_mensagem WHERE pasta_id = ? AND sistema = 'pasta' ORDER BY id",
            [$pasta->getId()],
        ));
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function trancarEmVisualizar(Pasta $pasta, User $quem, User $por): void
    {
        $agora   = new \DateTimeImmutable();
        $cadeado = new CadeadoDaPasta($pasta->getTenant(), $pasta, ModoDoCadeado::PorPessoa, $por, $agora);
        $cadeado->definirPorPessoa([[$quem, NivelDeAcesso::Visualizar]], $por, $agora);
        $this->em()->persist($cadeado);
        $this->em()->flush();
    }

    // ── Tela ────────────────────────────────────────────────────────────

    #[TestDox('Com processo principal: o ⋮ do cartão e o do cabeçalho têm "Editar ação da pasta", e o formulário mora no cartão principal (filho direto), com o campo nome_acao preenchido')]
    public function testTelaComProcessoPrincipal(): void
    {
        [$user, $tenant]    = $this->criarAdmin();
        [$pasta, $processo] = $this->pastaComProcesso($tenant);
        $this->logarComTenant($this->client, $user, $tenant);

        $crawler = $this->abrir($pasta);
        $formId  = 'psAcaoEdicao' . $pasta->getId();
        $cartao  = '#processo .ps-processos > .ps-registro > article.ps-processo.ps-processo--principal[data-processo-id="' . $processo->getId() . '"]';

        $itemCartao = $crawler->filter($cartao . ' > #psProcMenu' . $processo->getId() . ' > .ps-processo-menu-sep + button.ps-processo-menu-item.js-proc-editar-acao');
        self::assertCount(1, $itemCartao, 'primeiro item do grupo do vínculo, logo depois do separador');
        self::assertSame('#' . $formId, $itemCartao->attr('data-edicao'));
        self::assertSame('Editar ação da pasta', trim($itemCartao->text()));
        self::assertCount(1, $itemCartao->filter('i.bi.bi-pencil-square'));

        $itemCabecalho = $crawler->filter('#processo .ps-processos > #psProcHdMenu' . $pasta->getId() . ' > button.js-proc-hd-adm + button.ps-processo-menu-item.js-proc-editar-acao');
        self::assertCount(1, $itemCabecalho, 'no ⋮ do cabeçalho, depois de "Administrativo sem processo"');
        self::assertSame('#' . $formId, $itemCabecalho->attr('data-edicao'));

        $form = $crawler->filter($cartao . ' > form.ps-processo-edicao.js-proc-edicao-acao#' . $formId);
        self::assertCount(1, $form);
        self::assertNotNull($form->attr('hidden'), 'escondido até o ⋮ abrir');
        self::assertSame('/pasta/' . $pasta->getId() . '/acao', $form->attr('action'));
        self::assertSame('post', strtolower((string) $form->attr('method')));
        self::assertNotSame('', (string) $form->filter('#' . $formId . ' > input[type="hidden"][name="_token"]')->attr('value'));

        $campo = $crawler->filter($cartao . ' > form#' . $formId . ' > .ps-processo-edicao-grade > .ps-processo-edicao-campo > input[type="text"][name="nome_acao"]');
        self::assertCount(1, $campo);
        self::assertSame('COBRANÇA', $campo->attr('value'));
        self::assertSame('255', $campo->attr('maxlength'));
        self::assertSame('Ação', trim($crawler->filter($cartao . ' > form#' . $formId . ' > .ps-processo-edicao-grade > .ps-processo-edicao-campo > label.ps-rotulo[for="' . $campo->attr('id') . '"]')->text()));
        self::assertCount(1, $crawler->filter($cartao . ' > form#' . $formId . ' > .ps-processo-edicao-acoes > button.js-proc-edicao-cancelar[type="button"] + button.ps-processo-edicao-salvar[type="submit"]'));
        self::assertCount(1, $crawler->filter('form.js-proc-edicao-acao'), 'um formulário só por pasta');
    }

    #[TestDox('Pasta sem processo: o formulário fica no fim da aba (filho direto da seção) e abre pelo ⋮ do cabeçalho')]
    public function testTelaSemProcesso(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->logarComTenant($this->client, $user, $tenant);

        $crawler = $this->abrir($pasta);
        $formId  = 'psAcaoEdicao' . $pasta->getId();

        self::assertCount(1, $crawler->filter('#processo .ps-processos > .ps-vazio + form.js-proc-edicao-acao#' . $formId . ' + .ps-processos-nota'));
        self::assertCount(1, $crawler->filter('#processo .ps-processos > #psProcHdMenu' . $pasta->getId() . ' > button.js-proc-editar-acao[data-edicao="#' . $formId . '"]'));
    }

    #[TestDox('Quem só VÊ a pasta não recebe o item nem o formulário')]
    public function testTelaSoVer(): void
    {
        [, $tenant] = $this->criarAdmin();
        [$pasta]    = $this->pastaComProcesso($tenant);
        $leitor     = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view']);
        $this->logarComTenant($this->client, $leitor, $tenant);

        $crawler = $this->abrir($pasta);

        self::assertCount(0, $crawler->filter('.js-proc-editar-acao'));
        self::assertCount(0, $crawler->filter('form.js-proc-edicao-acao'));
    }

    // ── Gravar ──────────────────────────────────────────────────────────

    #[TestDox('Quem EDITA a pasta grava (sem XHR volta à aba Processo); o Registro ganha "Dados da pasta: ação alterada pela aba Processo" e o cartão aparece no histórico da pasta')]
    public function testEditorGravaERegistra(): void
    {
        [, $tenant] = $this->criarAdmin();
        [$pasta]    = $this->pastaComProcesso($tenant);
        $editor     = $this->criarUsuarioComPermissoes($tenant, self::EDITOR);
        $this->logarComTenant($this->client, $editor, $tenant);

        $this->salvar($pasta, ['nome_acao' => 'Ação de despejo']);

        self::assertResponseRedirects('/pasta/' . $pasta->getId() . '#processo');
        self::assertSame('AÇÃO DE DESPEJO', $this->acaoNoBanco($pasta));
        $cartoes = $this->cartoes($pasta);
        self::assertCount(1, $cartoes);
        self::assertSame('<p><strong>Dados da pasta:</strong> ação alterada pela aba Processo: &quot;COBRANÇA&quot; → &quot;AÇÃO DE DESPEJO&quot;.</p>', $cartoes[0]);
        self::assertSame($editor->getId(), (int) $this->em()->getConnection()->fetchOne(
            "SELECT autor_id FROM pasta_mensagem WHERE pasta_id = ? AND sistema = 'pasta'",
            [$pasta->getId()],
        ));

        $crawler = $this->abrir($pasta);
        self::assertStringContainsString('ação alterada pela aba Processo', $crawler->filter('body')->text());
    }

    #[TestDox('Com XHR responde {sucesso, aviso, html}; o html traz a Ação nova na faixa do cartão principal e no campo')]
    public function testXhr(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        [$pasta]         = $this->pastaComProcesso($tenant);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->salvar($pasta, ['nome_acao' => 'Ação de despejo'], self::XHR);

        self::assertResponseIsSuccessful();
        $dados = $this->json();
        self::assertTrue($dados['sucesso']);
        self::assertSame('Ação da pasta salva.', $dados['aviso']);
        $html = new Crawler('<div id="processoTabContent">' . $dados['html'] . '</div>');
        self::assertSame('AÇÃO DE DESPEJO', trim($html->filter('#processoTabContent > .ps-processos > .ps-registro > article.ps-processo--principal > .ps-processo-dados > [data-proc-campo="acao"] > .ps-processo-valor')->text()));
        self::assertSame('AÇÃO DE DESPEJO', $html->filter('input[name="nome_acao"]')->attr('value'));
    }

    #[TestDox('A troca entra no audit_log da pasta, com nome_acao no diff')]
    public function testAuditoria(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        [$pasta]         = $this->pastaComProcesso($tenant);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->salvar($pasta, ['nome_acao' => 'Ação de despejo']);
        self::assertResponseRedirects();

        $linha = $this->em()->getConnection()->fetchAssociative(
            'SELECT action, route, changes::text AS changes FROM audit_log WHERE entity_class = ? AND entity_id = ? ORDER BY id DESC LIMIT 1',
            [Pasta::class, (string) $pasta->getId()],
        );
        self::assertNotFalse($linha);
        self::assertSame('update', $linha['action']);
        self::assertSame('pasta_acao_salvar', $linha['route']);
        self::assertStringContainsString('nomeAcao', (string) $linha['changes']);
    }

    #[TestDox('Campo vazio limpa a Ação; mesma Ação não escreve cartão')]
    public function testLimparESemMudanca(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        [$pasta]         = $this->pastaComProcesso($tenant);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->salvar($pasta, ['nome_acao' => ' cobrança '], self::XHR);
        self::assertResponseIsSuccessful();
        self::assertSame('A ação da pasta não mudou.', $this->json()['aviso']);
        self::assertSame([], $this->cartoes($pasta));

        $this->salvar($pasta, ['nome_acao' => '']);
        self::assertResponseRedirects();
        self::assertNull($this->acaoNoBanco($pasta));
        self::assertCount(1, $this->cartoes($pasta));
    }

    // ── Recusas ─────────────────────────────────────────────────────────

    #[TestDox('Quem só VÊ a pasta leva 403 (com e sem XHR) e nada é gravado')]
    public function testSoVerDa403(): void
    {
        [, $tenant] = $this->criarAdmin();
        [$pasta]    = $this->pastaComProcesso($tenant);
        $leitor     = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view']);
        $this->logarComTenant($this->client, $leitor, $tenant);

        $this->salvar($pasta, ['nome_acao' => 'OUTRA']);
        self::assertResponseStatusCodeSame(403);
        $this->salvar($pasta, ['nome_acao' => 'OUTRA'], self::XHR);
        self::assertResponseStatusCodeSame(403);
        self::assertArrayNotHasKey('html', $this->json());

        self::assertSame('COBRANÇA', $this->acaoNoBanco($pasta));
        self::assertSame([], $this->cartoes($pasta));
    }

    #[TestDox('Cadeado em Visualizar: quem tem EDIT pelo papel leva 403 e nada é gravado')]
    public function testCadeadoEmVisualizarDa403(): void
    {
        [$admin, $tenant] = $this->criarAdmin();
        [$pasta]          = $this->pastaComProcesso($tenant);
        $editor           = $this->criarUsuarioComPermissoes($tenant, self::EDITOR);
        $this->trancarEmVisualizar($pasta, $editor, $admin);
        $this->logarComTenant($this->client, $editor, $tenant);

        $this->salvar($pasta, ['nome_acao' => 'OUTRA'], self::XHR);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('COBRANÇA', $this->acaoNoBanco($pasta));
        self::assertSame([], $this->cartoes($pasta));
    }

    #[TestDox('Token CSRF inválido (ou ausente) dá 403 e nada é gravado')]
    public function testCsrfInvalido(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        [$pasta]         = $this->pastaComProcesso($tenant);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->salvar($pasta, ['nome_acao' => 'OUTRA', '_token' => 'token_invalido']);
        self::assertResponseStatusCodeSame(403);
        $this->salvar($pasta, ['nome_acao' => 'OUTRA', '_token' => ''], self::XHR);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('Token de segurança inválido.', $this->json()['erro'] ?? null);

        self::assertSame('COBRANÇA', $this->acaoNoBanco($pasta));
        self::assertSame([], $this->cartoes($pasta));
    }

    #[TestDox('Pasta de OUTRO escritório dá 404 para o admin de outro escritório, e nada é gravado')]
    public function testOutroEscritorioDa404(): void
    {
        [$userA, $tenantA] = $this->criarAdmin();
        [, $tenantB]       = $this->criarAdmin();
        [$pastaB]          = $this->pastaComProcesso($tenantB);
        $this->logarComTenant($this->client, $userA, $tenantA);

        $this->salvar($pastaB, ['nome_acao' => 'OUTRA'], self::XHR);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('COBRANÇA', $this->acaoNoBanco($pastaB));
    }

    #[TestDox('Campo nome_acao ausente dá 422 e não limpa a Ação')]
    public function testCampoAusente(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        [$pasta]         = $this->pastaComProcesso($tenant);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->salvar($pasta, [], self::XHR);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('COBRANÇA', $this->acaoNoBanco($pasta));
    }

    #[TestDox('Lápide: sem item nem formulário, e o POST (XHR) é recusado com 403')]
    public function testLapide(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        [$pasta]         = $this->pastaComProcesso($tenant);
        $pasta->marcarExcluida($user, new \DateTimeImmutable());
        $this->em()->flush();
        $this->logarComTenant($this->client, $user, $tenant);

        $crawler = $this->abrir($pasta);
        self::assertCount(0, $crawler->filter('.js-proc-editar-acao, form.js-proc-edicao-acao'));

        $this->salvar($pasta, ['nome_acao' => 'OUTRA'], self::XHR);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('COBRANÇA', $this->acaoNoBanco($pasta));
    }

    // ── Drive ───────────────────────────────────────────────────────────

    private function conectarDrive(Tenant $tenant, User $user): void
    {
        $cifrador = static::getContainer()->get(CifradorDeSegredo::class);
        $conexao  = new TenantDriveConexao($tenant);
        $conexao->registrarCredenciais($cifrador->cifrar('tok'), 'a@b.com', 'drive', $user);
        $conexao->definirRootFolder('root-abcdefghij');
        $this->em()->persist($conexao);
        $this->em()->flush();
    }

    private function transporteAsync(): InMemoryTransport
    {
        return static::getContainer()->get('messenger.transport.async');
    }

    #[TestDox('A Ação compõe o nome da pasta no Drive: trocá-la enfileira a renomeação')]
    public function testTrocarAcaoEnfileiraRenomeacao(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        [$pasta]         = $this->pastaComProcesso($tenant);
        $this->conectarDrive($tenant, $user);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->salvar($pasta, ['nome_acao' => 'Ação de despejo']);
        self::assertResponseRedirects();

        $enviadas = $this->transporteAsync()->getSent();
        self::assertCount(1, $enviadas);
        $msg = $enviadas[0]->getMessage();
        self::assertInstanceOf(SincronizarPastaNoDrive::class, $msg);
        self::assertSame($pasta->getId(), $msg->pastaId);
        self::assertTrue($msg->renomear);
    }

    #[TestDox('A mesma Ação (só caixa e espaços diferentes) não enfileira nada')]
    public function testMesmaAcaoNaoEnfileira(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        [$pasta]         = $this->pastaComProcesso($tenant);
        $this->conectarDrive($tenant, $user);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->salvar($pasta, ['nome_acao' => ' cobrança ']);
        self::assertResponseRedirects();

        self::assertCount(0, $this->transporteAsync()->getSent());
    }
}
