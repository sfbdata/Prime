<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\Controller\PastaPagamentoController;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaPagamento;
use App\Pasta\Repository\PastaPagamentoRepository;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * "Editar ou corrigir valores" do card Pagamentos (desenho 1.2.3, dc 3443-3462):
 * `POST /pasta/{id}/pagamento/{pagamentoId}/valor`.
 *
 * O histórico da correção não tem tabela própria: sai do `audit_log`, que o
 * flush do UseCase alimenta. Por isso os testes de histórico olham o banco
 * (a linha de auditoria existe, com de/para) e a tela (só as correções DESTE
 * pagamento, DESTE escritório).
 */
#[CoversClass(PastaPagamentoController::class)]
#[CoversClass(PastaPagamentoRepository::class)]
final class PastaPagamentoCorrigirValorControllerTest extends JusPrimeWebTestCase
{
    private bool $csrfInstalado = false;

    /** Token previsível, como no PastaPagamentoControllerTest; instalado uma vez só. */
    private function instalarCsrfStorage(): void
    {
        if ($this->csrfInstalado) {
            return;
        }
        $this->csrfInstalado = true;

        $storage = new class implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string { return 'TOKEN_' . $tokenId; }
            public function setToken(string $tokenId, string $token): void {}
            public function removeToken(string $tokenId): ?string { return null; }
            public function hasToken(string $tokenId): bool { return true; }
            public function clear(): void {}
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * @param list<string> $roles
     *
     * @return array{User, Tenant}
     */
    private function criarUsuario(string $sufixo = '', array $roles = ['ROLE_SUPER_ADMIN'], string $nome = 'Admin Correção'): array
    {
        $this->instalarCsrfStorage();

        $em     = $this->em();
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Correção ' . $sufixo . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('test_corrigir_' . $sufixo . uniqid() . '@test.com');
        $user->setFullName($nome);
        $user->setRoles($roles);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return [$user, $tenant];
    }

    private function criarPasta(Tenant $tenant): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup('TEST-CORR-' . uniqid());
        $pasta->setTenant($tenant);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    private function criarPagamento(Pasta $pasta, Tenant $tenant, string $valor = '1300.00', string $descricao = '2ª parcela — honorários'): PastaPagamento
    {
        $pagamento = new PastaPagamento();
        $pagamento->setPasta($pasta);
        $pagamento->setTenant($tenant);
        $pagamento->setDescricao($descricao);
        $pagamento->setValor($valor);
        $pagamento->setVencimento(new \DateTimeImmutable('+20 days'));
        $this->em()->persist($pagamento);
        $this->em()->flush();

        return $pagamento;
    }

    private function cliente(): KernelBrowser
    {
        $client = static::createClient();
        // O armazenamento de CSRF trocado some no reboot do kernel entre requisições.
        $client->disableReboot();

        return $client;
    }

    /**
     * @param string|false|null $valorAnterior o valor que a tela "mostrou": `null` usa o
     *                                         do banco (tela em dia), `false` omite o campo
     */
    private function corrigir(KernelBrowser $client, Pasta $pasta, int $pagamentoId, string $valor, ?string $token = null, string|false|null $valorAnterior = null): void
    {
        $campos = ['_token' => $token ?? 'TOKEN_pasta_pagamento_corrigir_' . $pagamentoId, 'valor' => $valor];
        if ($valorAnterior !== false) {
            $campos['valorAnterior'] = $valorAnterior ?? str_replace('.', ',', (string) $this->valorGravado($pagamentoId));
        }

        $client->request(
            'POST',
            "/pasta/{$pasta->getId()}/pagamento/{$pagamentoId}/valor",
            $campos,
            [],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
        );
    }

    private function valorGravado(int $id): ?string
    {
        // SQL direto: depois da requisição o TenantFilter do EM esconde o pagamento de outro escritório.
        $valor = $this->em()->getConnection()->fetchOne('SELECT valor FROM pasta_pagamento WHERE id = :id', ['id' => $id]);

        return $valor === false ? null : (string) $valor;
    }

    /** @return list<array<string, mixed>> */
    private function auditoriasDeValor(int $pagamentoId): array
    {
        return $this->em()->getConnection()->fetchAllAssociative(
            "SELECT action, actor_email, tenant_id, route, changes::text AS changes
               FROM audit_log
              WHERE entity_class = ? AND entity_id = ? AND action = 'update'
              ORDER BY id ASC",
            [PastaPagamento::class, (string) $pagamentoId],
        );
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        return json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    // =========================================================================
    // Caminho feliz
    // =========================================================================

    #[TestDox('corrige o valor, devolve o card com o total novo e a linha "corrigido · era"')]
    public function testCorrigeOValor(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuario('', ['ROLE_SUPER_ADMIN'], 'Mariana Costa');
        $pasta           = $this->criarPasta($tenant);
        $pagamento       = $this->criarPagamento($pasta, $tenant, '1300.00');
        $id              = (int) $pagamento->getId();
        $this->logarComTenant($client, $user, $tenant);

        $this->corrigir($client, $pasta, $id, '1.450,50');

        self::assertResponseIsSuccessful();
        $dados = $this->json($client);
        self::assertTrue($dados['alterado']);
        self::assertSame('R$ 1.450,50', $dados['resumo']['previsto'], 'o total do card sai do servidor, já corrigido');
        self::assertSame('1450.50', $this->valorGravado($id), 'dinheiro grava em decimal');

        $corpo = new Crawler((string) $dados['resumo']['html']);
        $linha = $corpo->filter('.ps-pag-linha[data-pagamento-id="' . $id . '"]');
        self::assertCount(1, $linha);
        self::assertStringContainsString('ps-pag-linha--corrigida', (string) $linha->attr('class'));

        $ajuste = $linha->filter('.ps-pag-direita > .ps-pag-ajuste');
        self::assertCount(1, $ajuste);
        self::assertSame('corrigido · era R$ 1.300,00', trim($ajuste->text()));
        self::assertMatchesRegularExpression(
            '#^\d{2}/\d{2}/\d{4} \d{2}:\d{2} · Mariana Costa: R\$ 1\.300,00 → R\$ 1\.450,50$#u',
            (string) $ajuste->attr('title'),
        );
        self::assertSame('1450,50', $linha->attr('data-valor'), 'o campo de correção abre com o valor atual');
        self::assertStringContainsString('já corrigido 1x', (string) $linha->filter('.ps-pag-valor-editar')->attr('title'));
    }

    #[TestDox('a correção entra no audit_log com de/para, autor, escritório e rota')]
    public function testCorrecaoFicaNaAuditoria(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuario();
        $pasta           = $this->criarPasta($tenant);
        $id              = (int) $this->criarPagamento($pasta, $tenant, '1300.00')->getId();
        $this->logarComTenant($client, $user, $tenant);

        $this->corrigir($client, $pasta, $id, '900,00');
        self::assertResponseIsSuccessful();

        $linhas = $this->auditoriasDeValor($id);
        self::assertCount(1, $linhas, 'uma correção, uma linha de auditoria');
        self::assertSame($user->getEmail(), $linhas[0]['actor_email']);
        self::assertSame($tenant->getId(), (int) $linhas[0]['tenant_id']);
        self::assertSame('pasta_pagamento_corrigir_valor', $linhas[0]['route']);

        $changes = json_decode((string) $linhas[0]['changes'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['from' => '1300.00', 'to' => '900.00'], $changes['diff']['changes']['valor']);
    }

    /**
     * Mandar o mesmo valor não pode virar linha "R$ a → R$ a" no histórico.
     */
    #[TestDox('mesmo valor responde 200 com alterado=false e não deixa rastro na auditoria')]
    public function testMesmoValorNaoAudita(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuario();
        $pasta           = $this->criarPasta($tenant);
        $id              = (int) $this->criarPagamento($pasta, $tenant, '1300.00')->getId();
        $this->logarComTenant($client, $user, $tenant);

        $this->corrigir($client, $pasta, $id, '1.300,00');

        self::assertResponseIsSuccessful();
        self::assertFalse($this->json($client)['alterado']);
        self::assertSame([], $this->auditoriasDeValor($id));
    }

    // =========================================================================
    // Validação
    // =========================================================================

    /** @return iterable<string, array{string}> */
    public static function valoresInvalidos(): iterable
    {
        yield 'zero'         => ['0,00'];
        yield 'vazio'        => [''];
        yield 'negativo'     => ['-50,00'];
        yield 'texto'        => ['abc'];
        yield 'três casas'   => ['10,505'];
    }

    #[DataProvider('valoresInvalidos')]
    #[TestDox('valor inválido ($valor) responde 422 e não grava nem audita')]
    public function testValorInvalido(string $valor): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuario();
        $pasta           = $this->criarPasta($tenant);
        $id              = (int) $this->criarPagamento($pasta, $tenant, '1300.00')->getId();
        $this->logarComTenant($client, $user, $tenant);

        $this->corrigir($client, $pasta, $id, $valor);

        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('erro', $this->json($client));
        self::assertSame('1300.00', $this->valorGravado($id));
        self::assertSame([], $this->auditoriasDeValor($id));
    }

    // =========================================================================
    // Perda de atualização: o POST leva o valor que a tela mostrou
    // =========================================================================

    /**
     * Duas abas abertas no mesmo valor: a primeira corrige; a segunda ainda
     * mostra o valor velho e não pode passar por cima — senão o confirm dela
     * teria dito "de R$ 1.300,00" quando o valor já era outro.
     */
    #[TestDox('valor exibido desatualizado responde 409 com o valor atual e não grava nem audita')]
    public function testValorAnteriorDesatualizadoDa409(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuario();
        $pasta           = $this->criarPasta($tenant);
        $id              = (int) $this->criarPagamento($pasta, $tenant, '1300.00')->getId();
        $this->logarComTenant($client, $user, $tenant);

        $this->corrigir($client, $pasta, $id, '1.450,00', null, '1.300,00');
        self::assertResponseIsSuccessful('a primeira aba estava em dia');

        $this->corrigir($client, $pasta, $id, '900,00', null, '1.300,00');

        self::assertResponseStatusCodeSame(409);
        $dados = $this->json($client);
        self::assertSame(
            'O valor foi alterado por outra pessoa (agora R$ 1.450,00). Recarregue e confira antes de corrigir.',
            $dados['erro'],
        );
        self::assertSame('1450,00', $dados['valorAtual']);
        $corpo = new Crawler((string) $dados['resumo']['html']);
        self::assertSame(
            '1450,00',
            $corpo->filter('.ps-pag-linha[data-pagamento-id="' . $id . '"]')->attr('data-valor'),
            'o card devolvido já mostra o valor que vale agora',
        );
        self::assertSame('1450.00', $this->valorGravado($id), 'a segunda correção não passou por cima');
        self::assertCount(1, $this->auditoriasDeValor($id), 'só a primeira correção entrou no histórico');
    }

    #[TestDox('valor exibido escrito de outro jeito ("1300" para 1.300,00) não é conflito')]
    public function testValorAnteriorComparaEmCentavos(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuario();
        $pasta           = $this->criarPasta($tenant);
        $id              = (int) $this->criarPagamento($pasta, $tenant, '1300.00')->getId();
        $this->logarComTenant($client, $user, $tenant);

        $this->corrigir($client, $pasta, $id, '900,00', null, '1300');

        self::assertResponseIsSuccessful();
        self::assertSame('900.00', $this->valorGravado($id));
    }

    /** @return iterable<string, array{string|false}> */
    public static function valoresAnterioresAusentes(): iterable
    {
        yield 'campo ausente' => [false];
        yield 'campo vazio'   => [''];
        yield 'não é valor'   => ['abc'];
    }

    #[DataProvider('valoresAnterioresAusentes')]
    #[TestDox('sem o valor exibido ($valorAnterior) responde 422 e não grava nem audita')]
    public function testSemValorAnteriorDa422(string|false $valorAnterior): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuario();
        $pasta           = $this->criarPasta($tenant);
        $id              = (int) $this->criarPagamento($pasta, $tenant, '1300.00')->getId();
        $this->logarComTenant($client, $user, $tenant);

        $this->corrigir($client, $pasta, $id, '900,00', null, $valorAnterior);

        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('erro', $this->json($client));
        self::assertSame('1300.00', $this->valorGravado($id));
        self::assertSame([], $this->auditoriasDeValor($id));
    }

    // =========================================================================
    // Guardas: CSRF, permissão, posse
    // =========================================================================

    #[TestDox('sem token CSRF do pagamento não grava — 403')]
    public function testSemCsrf(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuario();
        $pasta           = $this->criarPasta($tenant);
        $id              = (int) $this->criarPagamento($pasta, $tenant, '1300.00')->getId();
        $this->logarComTenant($client, $user, $tenant);

        $this->corrigir($client, $pasta, $id, '10,00', 'token-errado');
        self::assertResponseStatusCodeSame(403);

        // O token de OUTRO pagamento também não serve: o CSRF é por lançamento.
        $this->corrigir($client, $pasta, $id, '10,00', 'TOKEN_pasta_pagamento_corrigir_' . ($id + 1));
        self::assertResponseStatusCodeSame(403);

        self::assertSame('1300.00', $this->valorGravado($id));
    }

    #[TestDox('usuário sem permissão de editar a pasta recebe 403 e nada muda')]
    public function testSemPermissao(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuario('comum', ['ROLE_USER'], 'Usuário Comum');
        $pasta           = $this->criarPasta($tenant);
        $id              = (int) $this->criarPagamento($pasta, $tenant, '1300.00')->getId();
        $this->logarComTenant($client, $user, $tenant);

        $this->corrigir($client, $pasta, $id, '10,00');

        self::assertResponseStatusCodeSame(403);
        self::assertSame('1300.00', $this->valorGravado($id));
        self::assertSame([], $this->auditoriasDeValor($id));
    }

    #[TestDox('pagamento de OUTRO escritório responde 404, nunca 403 — e não é corrigido')]
    public function testPagamentoDeOutroTenant(): void
    {
        $client            = $this->cliente();
        [$user, $tenant]   = $this->criarUsuario('a');
        [, $tenantVizinho] = $this->criarUsuario('b');

        $minhaPasta = $this->criarPasta($tenant);
        $alheio     = (int) $this->criarPagamento($this->criarPasta($tenantVizinho), $tenantVizinho, '9999.00')->getId();
        $this->logarComTenant($client, $user, $tenant);

        $this->corrigir($client, $minhaPasta, $alheio, '1,00');

        self::assertResponseStatusCodeSame(404, '403 confirmaria que o id existe em algum escritório');
        self::assertSame('9999.00', $this->valorGravado($alheio));
    }

    #[TestDox('pasta de OUTRO escritório na URL também não abre a correção')]
    public function testPastaDeOutroTenant(): void
    {
        $client            = $this->cliente();
        [$user, $tenant]   = $this->criarUsuario('a');
        [, $tenantVizinho] = $this->criarUsuario('b');

        $pastaDoVizinho = $this->criarPasta($tenantVizinho);
        $alheio         = (int) $this->criarPagamento($pastaDoVizinho, $tenantVizinho, '9999.00')->getId();
        $this->em()->clear(); // senão a pasta sai do mapa de identidade sem passar pelo TenantFilter
        $this->logarComTenant($client, $user, $tenant);

        $this->corrigir($client, $pastaDoVizinho, $alheio, '1,00');

        self::assertFalse($client->getResponse()->isSuccessful());
        self::assertSame('9999.00', $this->valorGravado($alheio));
    }

    /**
     * O furo que o TenantFilter NÃO cobre: mesmo escritório, PASTA irmã. Só o
     * `p.pasta = :pasta` do repositório impede a URL da pasta A de corrigir o
     * dinheiro da pasta B.
     */
    #[TestDox('pagamento da pasta IRMÃ, no mesmo escritório, dá 404 e não é corrigido')]
    public function testPagamentoDaPastaIrma(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuario();
        $pastaA          = $this->criarPasta($tenant);
        $daB             = (int) $this->criarPagamento($this->criarPasta($tenant), $tenant, '500.00')->getId();
        $this->logarComTenant($client, $user, $tenant);

        $this->corrigir($client, $pastaA, $daB, '1,00');

        self::assertResponseStatusCodeSame(404);
        self::assertSame('500.00', $this->valorGravado($daB));
        self::assertSame([], $this->auditoriasDeValor($daB));
    }

    #[TestDox('pasta excluída (lápide) não aceita correção de valor')]
    public function testPastaExcluidaNaoAceita(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuario();
        $pasta           = $this->criarPasta($tenant);
        $id              = (int) $this->criarPagamento($pasta, $tenant, '1300.00')->getId();
        $pasta->marcarExcluida($user, new \DateTimeImmutable());
        $this->em()->flush();
        $this->logarComTenant($client, $user, $tenant);

        $this->corrigir($client, $pasta, $id, '10,00');

        self::assertResponseStatusCodeSame(403);
        self::assertSame('1300.00', $this->valorGravado($id));
    }

    // =========================================================================
    // Histórico na tela: só deste pagamento, deste escritório
    // =========================================================================

    #[TestDox('a tela mostra o histórico só do pagamento corrigido — nem de outro, nem de outro escritório')]
    public function testHistoricoSoDoPagamento(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuario('', ['ROLE_SUPER_ADMIN'], 'Samuel Freitas');
        [, $vizinho]     = $this->criarUsuario('b');
        $pasta           = $this->criarPasta($tenant);
        $corrigido       = (int) $this->criarPagamento($pasta, $tenant, '1000.00', 'Entrada')->getId();
        $intocado        = (int) $this->criarPagamento($pasta, $tenant, '2000.00', '1ª parcela')->getId();
        $this->logarComTenant($client, $user, $tenant);

        $this->corrigir($client, $pasta, $corrigido, '1.100,00');
        self::assertResponseIsSuccessful();
        $this->corrigir($client, $pasta, $corrigido, '1.200,00');
        self::assertResponseIsSuccessful();

        /* Uma linha de auditoria FORJADA com o id do pagamento intocado, mas de
           OUTRO escritório: a consulta do histórico tem de filtrar por tenant, e
           não só por entity_id. */
        $forjada = (new AuditLog())
            ->setAction('update')
            ->setEntityClass(PastaPagamento::class)
            ->setEntityId((string) $intocado)
            ->setTenantId((int) $vizinho->getId())
            ->setActorEmail('intruso@test.com')
            ->setChanges(['diff' => ['changes' => ['valor' => ['from' => '7777.00', 'to' => '2000.00']]]]);
        $this->em()->persist($forjada);
        $this->em()->flush();

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertResponseIsSuccessful();

        $linhaCorrigida = $crawler->filter('#psPagamentosCorpo > .ps-pag-linha[data-pagamento-id="' . $corrigido . '"]');
        $ajuste         = $linhaCorrigida->filter('.ps-pag-direita > .ps-pag-ajuste');
        self::assertCount(1, $ajuste);
        self::assertSame('corrigido · era R$ 1.000,00', trim($ajuste->text()), '"era" é o valor de antes da 1ª correção');

        $historico = explode("\n", (string) $ajuste->attr('title'));
        self::assertCount(2, $historico, 'duas correções, duas linhas, da mais antiga à mais recente');
        self::assertStringEndsWith('Samuel Freitas: R$ 1.000,00 → R$ 1.100,00', $historico[0]);
        self::assertStringEndsWith('Samuel Freitas: R$ 1.100,00 → R$ 1.200,00', $historico[1]);

        $linhaIntocada = $crawler->filter('#psPagamentosCorpo > .ps-pag-linha[data-pagamento-id="' . $intocado . '"]');
        self::assertCount(1, $linhaIntocada);
        self::assertCount(0, $linhaIntocada->filter('.ps-pag-ajuste'), 'auditoria de outro escritório não vira histórico aqui');
        self::assertStringNotContainsString('R$ 7.777,00', $crawler->filter('#psPagamentos')->html());
        self::assertStringNotContainsString('intruso@test.com', $crawler->filter('#psPagamentos')->html());
    }

    /**
     * Quitar também é `update` do pagamento no audit_log — e não é correção de
     * valor. Sem o filtro por `valor`, a linha ganharia "corrigido" ao ser paga.
     */
    #[TestDox('quitar não conta como correção de valor')]
    public function testQuitarNaoViraCorrecao(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuario();
        $pasta           = $this->criarPasta($tenant);
        $id              = (int) $this->criarPagamento($pasta, $tenant, '1300.00')->getId();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/pagamento/{$id}/quitacao", [
            '_token' => 'TOKEN_pasta_pagamento_quitacao_' . $id,
        ]);
        self::assertResponseIsSuccessful();

        $corpo = new Crawler((string) $this->json($client)['resumo']['html']);
        self::assertCount(0, $corpo->filter('.ps-pag-ajuste'));
    }

    // =========================================================================
    // Tela: modo de edição e menu da linha
    // =========================================================================

    #[TestDox('o card tem o modo de edição do desenho: item no ⋮, "Concluir edição" e o menu da linha')]
    public function testTelaDoModoDeEdicao(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuario();
        $pasta           = $this->criarPasta($tenant);
        $id              = (int) $this->criarPagamento($pasta, $tenant, '1300.00')->getId();
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        $cartao = $crawler->filter('#financeiro [data-trilho="pagamentos"]#psPagamentos');
        self::assertSame(
            "/pasta/{$pasta->getId()}/pagamento/__ID__/valor",
            $cartao->attr('data-base-url-corrigir'),
        );

        $concluir = $crawler->filter('#psPagamentos > .ps-card-cab > #psPagamentosConcluir');
        self::assertCount(1, $concluir, '"Concluir edição" mora no cabeçalho, ao lado do ⋮');
        self::assertNotNull($concluir->attr('hidden'), 'fora do modo de edição ele não aparece');

        self::assertCount(1, $crawler->filter('#psPagamentosMenu > .ps-fin-menu-item.js-pag-editar'));
        self::assertCount(1, $crawler->filter('#psPagamentosCorpo > .ps-pag-total > .ps-pag-total-linha > button.ps-pag-recebido.js-pag-entrar-edicao'));

        $linha = $crawler->filter('#psPagamentosCorpo > .ps-pag-linha[data-pagamento-id="' . $id . '"]');
        // O gerenciador real mascara o valor (BREACH): a prova é ele aceitar o token para este pagamento.
        self::assertTrue(static::getContainer()->get('security.csrf.token_manager')->isTokenValid(
            new \Symfony\Component\Security\Csrf\CsrfToken('pasta_pagamento_corrigir_' . $id, (string) $linha->attr('data-csrf-corrigir')),
        ));
        self::assertSame('1300,00', $linha->attr('data-valor'));
        self::assertCount(1, $linha->filter('.ps-pag-direita > button.ps-pag-valor-editar[data-acao="corrigir"]'));
        self::assertCount(0, $linha->filter('.ps-pag-ajuste'), 'nunca corrigido: sem "corrigido · era"');

        $menu = $crawler->filter('#psPagamentosCorpo > .ps-pag-linha[data-pagamento-id="' . $id . '"] > #psPagMenu-' . $id . '.ps-fin-menu[role="menu"]');
        self::assertCount(1, $menu);
        self::assertSame('psPagMenu-' . $id, $linha->attr('aria-controls'), 'a linha é o gatilho do menu dela');
        self::assertSame(
            ['Corrigir valor', 'Marcar como recebido', 'Excluir lançamento'],
            $menu->filter('.ps-fin-menu-item')->each(static fn (Crawler $i) => trim(preg_replace('/\s+/', ' ', $i->text()) ?? '')),
        );
        self::assertSame(['corrigir', 'quitacao', 'excluir'], $menu->filter('.ps-fin-menu-item')->each(static fn (Crawler $i) => (string) $i->attr('data-acao')));
    }

    /**
     * O `AuditLogSubscriber` grava `$entity::class` sem normalizar: pagamento
     * alterado como REFERÊNCIA preguiçosa do Doctrine fica no audit_log com o
     * nome do proxy (`Proxies\__CG__\...`). A linha forjada usa a classe que o
     * Doctrine de fato gerou nesta instalação, não uma string montada à mão.
     */
    #[TestDox('correção auditada com o nome de PROXY da entidade também vira "corrigido · era"')]
    public function testHistoricoAceitaNomeDeProxy(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuario();
        $pasta           = $this->criarPasta($tenant);
        $id              = (int) $this->criarPagamento($pasta, $tenant, '1300.00')->getId();

        $this->em()->clear();
        $referencia    = $this->em()->getReference(PastaPagamento::class, $id);
        $classeDoProxy = $referencia::class;
        self::assertSame(
            PastaPagamentoRepository::nomeDoProxy($this->em()->getConfiguration()->getProxyNamespace()),
            $classeDoProxy,
            'o nome aceito pela consulta é o que o Doctrine gera para o proxy',
        );
        $this->em()->clear();

        $tenantId = (int) $tenant->getId();
        $this->em()->persist((new AuditLog())
            ->setAction('update')
            ->setEntityClass($classeDoProxy)
            ->setEntityId((string) $id)
            ->setTenantId($tenantId)
            ->setActorEmail('proxy@test.com')
            ->setChanges(['diff' => ['changes' => ['valor' => ['from' => '1000.00', 'to' => '1300.00']]]]));
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertResponseIsSuccessful();

        $ajuste = $crawler->filter('#psPagamentosCorpo > .ps-pag-linha[data-pagamento-id="' . $id . '"] .ps-pag-direita > .ps-pag-ajuste');
        self::assertCount(1, $ajuste);
        self::assertSame('corrigido · era R$ 1.000,00', trim($ajuste->text()));
    }

    /**
     * Desenho dc 3462: o "corrigido · era" aparece com `temAjuste && !ed` — no
     * modo de edição some de TODAS as linhas, não só da que está sendo
     * corrigida. O modo é a classe `is-editando` no CARTÃO, então a prova é de
     * arranjo (o aviso mora dentro do cartão `#psPagamentos`) + folha (a regra
     * que o esconde é escopada pelo cartão em modo, e não pela linha).
     */
    #[TestDox('no modo de edição o "corrigido · era" some de todas as linhas (arranjo + folha)')]
    public function testModoDeEdicaoEscondeAjusteDeTodasAsLinhas(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuario();
        $pasta           = $this->criarPasta($tenant);
        $a               = (int) $this->criarPagamento($pasta, $tenant, '1000.00', 'Entrada')->getId();
        $b               = (int) $this->criarPagamento($pasta, $tenant, '2000.00', '1ª parcela')->getId();
        $this->logarComTenant($client, $user, $tenant);

        $this->corrigir($client, $pasta, $a, '1.100,00');
        self::assertResponseIsSuccessful();
        $this->corrigir($client, $pasta, $b, '2.100,00');
        self::assertResponseIsSuccessful();

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertCount(
            2,
            $crawler->filter('#psPagamentos > #psPagamentosCorpo > .ps-pag-linha .ps-pag-direita > .ps-pag-ajuste'),
            'os dois avisos moram dentro do cartão que recebe `is-editando`',
        );

        $css = (string) file_get_contents(\dirname(__DIR__, 3) . '/public/css/pasta-show.css');
        self::assertMatchesRegularExpression(
            '/#psPagamentos\.is-editando \.ps-pag-ajuste\s*\{\s*display:\s*none;?\s*\}/',
            $css,
            'a regra do modo de edição esconde o aviso em todas as linhas do cartão',
        );
    }
}
