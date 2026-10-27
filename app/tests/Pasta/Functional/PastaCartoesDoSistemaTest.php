<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Pasta\UseCase\AlterarProBonoDaPastaUseCase;
use App\Pasta\UseCase\AlterarResponsavelDaPastaUseCase;
use App\Pasta\UseCase\AlterarSituacaoContratoUseCase;
use App\Pasta\UseCase\EditarPastaUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Support\CatalogoPtBr;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Cartões do sistema que faltavam no Registro da pasta (desenho 02, `autoReg` dc L6169-6172 e `Component.SYS`
 * dc L6168): Responsável, Pró-bono, Dados da pasta e Contrato pelo selo do Financeiro, com os ícones; e a tag fixa
 * "Observação" dos registros de Detalhes (dc L2458, L6275, L6320 e L6874).
 */
#[CoversClass(AlterarResponsavelDaPastaUseCase::class)]
#[CoversClass(AlterarProBonoDaPastaUseCase::class)]
#[CoversClass(EditarPastaUseCase::class)]
#[CoversClass(AlterarSituacaoContratoUseCase::class)]
final class PastaCartoesDoSistemaTest extends JusPrimeWebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->instalarCsrfStorage();
    }

    // ── Responsável ─────────────────────────────────────────────────────

    #[TestDox('trocar o responsável escreve "Responsável: alterado de "Admin Cartoes" para "Bruno Colega"." com o tenant da pasta e o autor')]
    public function testResponsavelMudando(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $bruno = $this->criarColega($tenant, 'Bruno Colega');
        $pasta = $this->criarPasta($tenant, $user);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->responsavel($pasta, (string) $bruno->getId());
        self::assertResponseIsSuccessful();
        $this->responsavel($pasta, '');
        self::assertResponseIsSuccessful();

        $cartoes = $this->cartoes($pasta, 'resp');
        self::assertCount(2, $cartoes);
        self::assertStringContainsString('<strong>Responsável:</strong> alterado de &quot;Admin Cartoes&quot; para &quot;Bruno Colega&quot;.', $cartoes[0]['conteudo']);
        self::assertStringContainsString('alterado de &quot;Bruno Colega&quot; para &quot;Sem responsável&quot;.', $cartoes[1]['conteudo']);
        foreach ($cartoes as $c) {
            self::assertSame($tenant->getId(), $c['tenant_id']);
            self::assertSame($user->getId(), $c['autor_id']);
        }
    }

    #[TestDox('escolher quem já é o responsável responde 200 e não escreve cartão')]
    public function testResponsavelSemMudanca(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant, $user);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->responsavel($pasta, (string) $user->getId());

        self::assertResponseIsSuccessful();
        self::assertSame($user->getId(), $this->resposta()['responsavelId']);
        self::assertCount(0, $this->cartoes($pasta, 'resp'));
    }

    // ── Pró-bono ────────────────────────────────────────────────────────

    #[TestDox('marcar e desmarcar pró-bono escrevem os dois cartões do desenho; repetir o valor não escreve')]
    public function testProBono(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant, $user);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->proBono($pasta, '1');
        self::assertResponseIsSuccessful();
        self::assertTrue($this->resposta()['proBono']);
        $this->proBono($pasta, '1');
        $this->proBono($pasta, '0');
        self::assertFalse($this->resposta()['proBono']);

        $cartoes = $this->cartoes($pasta, 'probono');
        self::assertCount(2, $cartoes);
        self::assertStringContainsString('<strong>Pró-bono:</strong> pasta marcada como pró-bono.', $cartoes[0]['conteudo']);
        self::assertStringContainsString('<strong>Pró-bono:</strong> marcação de pró-bono retirada.', $cartoes[1]['conteudo']);
        self::assertSame($tenant->getId(), $cartoes[0]['tenant_id']);
        self::assertSame($user->getId(), $cartoes[0]['autor_id']);
    }

    #[TestDox('pró-bono de pasta de outro escritório: 404 e nenhum cartão')]
    public function testProBonoOutroEscritorio(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        [$outroUser, $outro] = $this->criarAdmin();
        $alheia = $this->criarPasta($outro, $outroUser);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->proBono($alheia, '1');

        self::assertResponseStatusCodeSame(404);
        self::assertCount(0, $this->cartoes($alheia, 'probono'));
    }

    // ── Dados da pasta ──────────────────────────────────────────────────

    #[TestDox('"Editar dados" com o cliente trocado escreve "Dados da pasta: dados editados: cliente "ANA" → "ANA MARIA"." (o nome é gravado em maiúsculas)')]
    public function testDadosDaPastaComUmCampoMudado(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant, $user);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->editar($pasta, ['nup' => (string) $pasta->getNup(), 'nome_cliente' => 'Ana Maria']);

        $cartoes = $this->cartoes($pasta, 'pasta');
        self::assertCount(1, $cartoes);
        self::assertStringContainsString('<strong>Dados da pasta:</strong> dados editados: cliente &quot;ANA&quot; → &quot;ANA MARIA&quot;.', $cartoes[0]['conteudo']);
        self::assertSame($tenant->getId(), $cartoes[0]['tenant_id']);
        self::assertSame($user->getId(), $cartoes[0]['autor_id']);
    }

    #[TestDox('"Editar dados" sem nenhum campo mudado não escreve cartão')]
    public function testDadosDaPastaSemMudanca(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant, $user);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->editar($pasta, ['nup' => (string) $pasta->getNup(), 'nome_cliente' => 'Ana']);

        self::assertCount(0, $this->cartoes($pasta, 'pasta'));
    }

    // ── Contrato pelo selo ──────────────────────────────────────────────

    #[TestDox('selo do contrato: "Contrato: marcado como assinado." uma vez só, ao lado do cartão da fase; reabrir escreve "reaberto como pendente."')]
    public function testContratoPeloSeloSemDuplicar(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant, $user);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->contrato($pasta, 'REGULAR');
        self::assertResponseIsSuccessful();
        $cartoes = $this->cartoes($pasta, 'contrato');
        self::assertCount(1, $cartoes, 'um cartão de contrato por mudança, sem o "(fase Contrato concluída no andamento)"');
        self::assertStringContainsString('<strong>Contrato:</strong> marcado como assinado.</p>', $cartoes[0]['conteudo']);
        self::assertSame($tenant->getId(), $cartoes[0]['tenant_id']);
        self::assertSame($user->getId(), $cartoes[0]['autor_id']);
        $fase = $this->cartoes($pasta, 'fase');
        self::assertCount(1, $fase, 'a fase anda até Contrato e ganha o próprio cartão "Andamento"');
        self::assertStringContainsString('Motivo: contrato assinado no Financeiro.', $fase[0]['conteudo']);

        $this->contrato($pasta, 'REGULAR');
        self::assertCount(1, $this->cartoes($pasta, 'contrato'), 'repetir a situação não escreve');

        $this->contrato($pasta, 'PENDENTE');
        $cartoes = $this->cartoes($pasta, 'contrato');
        self::assertCount(2, $cartoes);
        self::assertStringContainsString('<strong>Contrato:</strong> reaberto como pendente.</p>', $cartoes[1]['conteudo']);
    }

    // ── Tela ────────────────────────────────────────────────────────────

    #[TestDox('o Registro mostra o ícone de cada cartão novo: bi-person-badge, bi-heart, bi-folder2-open, bi-pen e bi-lock-fill no cadeado')]
    public function testIconesDosCartoes(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant, $user);
        $ids = [];
        foreach (['resp' => 'Responsável', 'probono' => 'Pró-bono', 'pasta' => 'Dados da pasta', 'contrato' => 'Contrato', 'cadeado' => 'Cadeado'] as $tipo => $rotulo) {
            $m = new PastaMensagem();
            $m->setPasta($pasta);
            $m->setTenant($tenant);
            $m->setAutor($user);
            $m->setSistema($tipo);
            $m->setConteudo('<p><strong>' . $rotulo . ':</strong> teste.</p>');
            $this->em()->persist($m);
            $ids[$tipo] = $m;
        }
        $this->em()->flush();
        $this->logarComTenant($this->client, $user, $tenant);

        $c = $this->client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        $esperado = ['resp' => 'bi-person-badge', 'probono' => 'bi-heart', 'pasta' => 'bi-folder2-open', 'contrato' => 'bi-pen', 'cadeado' => 'bi-lock-fill'];
        foreach ($esperado as $tipo => $icone) {
            $sel = '#pasta-msg-' . $ids[$tipo]->getId() . ' > .ps-anotacao-corpo > i.ps-anotacao-icone.' . $icone;
            self::assertCount(1, $c->filter($sel), $tipo . ' deveria ter ' . $icone);
            self::assertCount(0, $c->filter('#pasta-msg-' . $ids[$tipo]->getId() . ' > .ps-anotacao-corpo > i.bi-megaphone-fill'), $tipo . ' não cai no megafone');
        }
    }

    #[TestDox('cartão de Detalhes do servidor: tag "Observação" logo depois do autor; cabeçalho do ⋮ e impressão com "· Observação"')]
    public function testTagObservacaoNoCartaoDoServidor(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant, $user);
        $obs = new PastaObservacaoDetalhes();
        $obs->setPasta($pasta);
        $obs->setTenant($tenant);
        $obs->setAutor($user);
        $obs->setConteudo('Relato do cliente.');
        $this->em()->persist($obs);
        $this->em()->flush();
        $this->logarComTenant($this->client, $user, $tenant);

        $c = $this->client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        $topo = '#detalhes-obs-' . $obs->getId() . ' > .ps-anotacao-topo';
        $tag = $c->filter($topo . ' > .ps-anotacao-autor + span.detalhes-obs-tag');
        self::assertCount(1, $tag, 'a tag é o irmão logo depois do autor');
        self::assertSame('Observação', trim($tag->text()));

        $titulo = $c->filter($topo . ' > #psObsMenu' . $obs->getId() . ' > .ps-processo-menu-titulo');
        self::assertSame('Admin Cartoes · ' . $obs->getCriadaEm()->format('d/m/Y, H:i') . ' · Observação', trim($titulo->text()));
        $imprimir = $c->filter($topo . ' > #psObsMenu' . $obs->getId() . ' > button.js-obs-imprimir');
        self::assertSame('Observação', $imprimir->attr('data-tipo'));
    }

    #[TestDox('cartão de Detalhes montado em JS: a mesma tag logo depois do autor e "· Observação" no cabeçalho do ⋮')]
    public function testTagObservacaoNoCartaoDoJs(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant, $user);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        $inicio = strpos($html, 'function htmlDoCartao(data, raiz)');
        self::assertNotFalse($inicio);
        $abre = strpos($html, '<article class="ps-anotacao detalhes-obs-item', $inicio);
        self::assertNotFalse($abre);
        $fecha = strpos($html, '</article>', $abre);
        self::assertNotFalse($fecha);
        $molde = new Crawler('<div id="molde">' . substr($html, $abre, $fecha - $abre + strlen('</article>')) . '</div>');

        // A classe do <article> no molde termina em `${ehResposta ? …}`: o seletor ancora em `.ps-anotacao`.
        $topo = '#molde > article.ps-anotacao > .ps-anotacao-topo';
        $tag = $molde->filter($topo . ' > .ps-anotacao-autor + span.detalhes-obs-tag');
        self::assertCount(1, $tag);
        // O texto vem do catálogo do idioma (`pasta_detalhes.js.tag`), lido na hora de montar o cartão.
        $doCatalogo = '${esc(BJ_T.t(\'pasta_detalhes.js.tag\'))}';
        self::assertSame($doCatalogo, trim($tag->text()));
        self::assertStringEndsWith(' · ' . $doCatalogo, trim($molde->filter($topo . ' > .ps-obs-menu > .ps-processo-menu-titulo')->text()));
        self::assertSame($doCatalogo, $molde->filter($topo . ' > .ps-obs-menu > button.js-obs-imprimir')->attr('data-tipo'));
        self::assertSame('Observação', CatalogoPtBr::carregar()['pasta_detalhes']['js']['tag']);
    }

    // ── helpers ─────────────────────────────────────────────────────────

    #[TestDox('marcador do sistema: cabeçalho branco com o título e "de → para"; a chave Marcadores do sistema e o rótulo da linha do tempo vêm do desenho 27.10')]
    public function testMarcadorTemCabecalhoEChave(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant, $user);
        $m = new PastaMensagem();
        $m->setPasta($pasta);
        $m->setTenant($tenant);
        $m->setAutor($user);
        $m->setSistema('fase');
        $m->setConteudo('<p>Fase da pasta alterada de "Petição" para "Citação".</p>');
        $this->em()->persist($m);
        $livre = new PastaMensagem();
        $livre->setPasta($pasta);
        $livre->setTenant($tenant);
        $livre->setAutor($user);
        $livre->setConteudo('<p>Comentário de gente.</p>');
        $this->em()->persist($livre);
        $this->em()->flush();
        $this->logarComTenant($this->client, $user, $tenant);

        $c = $this->client->request('GET', '/pasta/' . $pasta->getId());

        self::assertResponseIsSuccessful();
        $cartao = '#pasta-msg-' . $m->getId();
        self::assertCount(1, $c->filter($cartao . '.ps-anotacao--marcador[data-marcador-mes]'));
        $faixa = $c->filter($cartao . ' > .ps-anotacao-fase');
        self::assertCount(1, $faixa);
        self::assertSame('Andamento da pasta', trim($faixa->filter('span')->first()->text()));
        self::assertSame('Petição → Citação', trim($faixa->filter('.ps-anotacao-fase-rot')->text()));
        self::assertCount(0, $c->filter('#pasta-msg-' . $livre->getId() . '.ps-anotacao--marcador'), 'registro de gente não é marcador');
        self::assertCount(1, $c->filter('.ps-card-cab--painel > button#psMarcadoresTg[role="switch"]'));
        self::assertCount(1, $c->filter('script[src*="/js/pasta-registro-marcadores.js"]'));
        self::assertSame('Linha do tempo da pasta', trim($c->filter('.ps-and > .ps-and-rotulo')->text()));
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** @return list<array{conteudo: string, tenant_id: int, autor_id: int}> */
    private function cartoes(Pasta $pasta, string $tipo): array
    {
        $linhas = $this->em()->getConnection()->fetchAllAssociative(
            'SELECT conteudo, tenant_id, autor_id FROM pasta_mensagem WHERE pasta_id = ? AND sistema = ? ORDER BY id',
            [$pasta->getId(), $tipo],
        );

        return array_map(static fn (array $l): array => ['conteudo' => (string) $l['conteudo'], 'tenant_id' => (int) $l['tenant_id'], 'autor_id' => (int) $l['autor_id']], $linhas);
    }

    private function responsavel(Pasta $pasta, string $id): void
    {
        $this->em()->clear();
        $this->client->request('PATCH', '/pasta/' . $pasta->getId() . '/responsavel', ['_token' => 'TOKEN_pasta_responsavel_' . $pasta->getId(), 'responsavel_id' => $id]);
    }

    private function proBono(Pasta $pasta, string $valor): void
    {
        $this->em()->clear();
        $this->client->request('POST', '/pasta/' . $pasta->getId() . '/pro-bono', ['_token' => 'TOKEN_pasta_pro_bono_' . $pasta->getId(), 'pro_bono' => $valor]);
    }

    private function contrato(Pasta $pasta, string $situacao): void
    {
        $this->em()->clear();
        $this->client->request('POST', '/pasta/' . $pasta->getId() . '/situacao-contrato', ['_token' => 'TOKEN_pasta_situacao_contrato_' . $pasta->getId(), 'situacao' => $situacao]);
    }

    /** @param array<string, string> $dados */
    private function editar(Pasta $pasta, array $dados): void
    {
        $this->em()->clear();
        $this->client->request('POST', '/pasta/' . $pasta->getId() . '/editar', $dados + ['_token' => 'TOKEN_edit_pasta_' . $pasta->getId()]);
        self::assertResponseRedirects();
    }

    /** @return array<string, mixed> */
    private function resposta(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array{0: User, 1: Tenant} */
    private function criarAdmin(): array
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant Cartoes ' . uniqid());
        $this->em()->persist($tenant);
        $user = $this->novoUsuario('Admin Cartoes');
        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Administrador ' . uniqid());
        $role->setIsSystem(true);
        $this->em()->persist($role);
        $ut = new UserTenant($user, $tenant);
        $ut->setTenantRole($role);
        $this->em()->persist($ut);
        $this->em()->flush();

        return [$user, $tenant];
    }

    private function criarColega(Tenant $tenant, string $nome): User
    {
        $user = $this->novoUsuario($nome);
        $this->em()->persist(new UserTenant($user, $tenant));
        $this->em()->flush();

        return $user;
    }

    private function novoUsuario(string $nome): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('cartoes_' . uniqid() . '@test.com');
        $user->setFullName($nome);
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $this->em()->persist($user);

        return $user;
    }

    private function criarPasta(Tenant $tenant, User $responsavel): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup('CS' . strtoupper(substr(uniqid(), -6)));
        $pasta->setNomeCliente('Ana');
        $pasta->setTenant($tenant);
        $pasta->setCriadoPor($responsavel);
        $pasta->setResponsavel($responsavel);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    private function instalarCsrfStorage(): void
    {
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
}
