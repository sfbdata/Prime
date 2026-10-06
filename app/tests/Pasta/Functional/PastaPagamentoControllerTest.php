<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\Controller\PastaPagamentoController;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaPagamento;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * Pagamentos a receber da pasta: lançar, quitar, desfazer, excluir.
 *
 * O que mais importa aqui é o ISOLAMENTO: pagamento de outro escritório tem de
 * responder 404, nunca 403 — um 403 confirmaria que o id existe.
 */
#[CoversClass(PastaPagamentoController::class)]
final class PastaPagamentoControllerTest extends JusPrimeWebTestCase
{
    use Factories;

    private bool $csrfInstalado = false;

    /**
     * Troca o armazenamento do token CSRF por um previsível — a mesma receita do
     * `PastaValorCausaControllerTest`. Sem isso o teste teria de raspar o token
     * da tela a cada requisição.
     *
     * Uma vez só: o contêiner recusa substituir serviço já inicializado, e os
     * testes de isolamento montam DOIS escritórios.
     */
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

    private function csrf(string $tokenId): string
    {
        return 'TOKEN_' . $tokenId;
    }

    /** @return array{User, Tenant} */
    private function criarUsuarioAdmin(string $sufixo = ''): array
    {
        $this->instalarCsrfStorage();

        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Pagamento ' . $sufixo . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('test_pag_' . $sufixo . uniqid() . '@test.com');
        $user->setFullName('Admin Pagamento');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return [$user, $tenant];
    }

    private function criarPasta(Tenant $tenant): Pasta
    {
        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $pasta = new Pasta();
        $pasta->setNup('TEST-PAG-' . uniqid());
        $pasta->setTenant($tenant);
        $em->persist($pasta);
        $em->flush();

        return $pasta;
    }

    private function criarPagamento(Pasta $pasta, Tenant $tenant, string $valor = '1300.00', ?string $pagoEm = null): PastaPagamento
    {
        $em        = static::getContainer()->get(EntityManagerInterface::class);
        $pagamento = new PastaPagamento();
        $pagamento->setPasta($pasta);
        $pagamento->setTenant($tenant);
        $pagamento->setDescricao('2ª parcela — honorários');
        $pagamento->setValor($valor);
        $pagamento->setVencimento(new \DateTimeImmutable('2026-09-10'));

        if ($pagoEm !== null) {
            $pagamento->alternarQuitacao(new \DateTimeImmutable($pagoEm));
        }

        $em->persist($pagamento);
        $em->flush();

        return $pagamento;
    }

    /**
     * Relê o pagamento do BANCO. Depois de uma requisição a instância que o
     * teste guardava fica destacada do EntityManager, e `refresh()` nela estoura
     * — o que se quer aqui é o estado gravado, não o objeto antigo.
     */
    private function relerPagamento(int $id): ?PastaPagamento
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->getRepository(PastaPagamento::class)->find($id);
    }

    /** @return array<string, mixed> */
    private function json(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): array
    {
        return json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    // =========================================================================
    // Lançar
    // =========================================================================

    #[TestDox('lança o pagamento e devolve o card já renderizado com os totais novos')]
    public function testRegistrar(): void
    {
        $client          = static::createClient();
        // Sem isto o kernel reinicia entre requisições e leva junto o
        // armazenamento de CSRF trocado acima: a 2ª requisição tomaria 403.
        $client->disableReboot();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/pagamento", [
            '_token'     => $this->csrf('pasta_pagamento_' . $pasta->getId()),
            'descricao'  => 'Entrada de honorários',
            'valor'      => '1.500,00',
            'vencimento' => '2026-09-10',
        ]);

        self::assertResponseStatusCodeSame(201);
        $dados = $this->json($client);

        self::assertSame(1, $dados['resumo']['total']);
        self::assertSame('R$ 0,00', $dados['resumo']['recebido'], 'nasce pendente: nada recebido ainda');
        self::assertSame('R$ 1.500,00', $dados['resumo']['previsto']);
        self::assertStringContainsString('Entrada de honorários', $dados['resumo']['html']);

        $em         = static::getContainer()->get(EntityManagerInterface::class);
        $pagamentos = $em->getRepository(PastaPagamento::class)->findBy(['pasta' => $pasta]);
        self::assertCount(1, $pagamentos);
        self::assertSame('1500.00', $pagamentos[0]->getValor(), 'dinheiro grava em decimal');
        self::assertSame($tenant->getId(), $pagamentos[0]->getTenant()?->getId());
        self::assertSame($user->getId(), $pagamentos[0]->getAutor()?->getId());
    }

    #[TestDox('valor zero é recusado com 422 e nada é gravado')]
    public function testRegistrarValorZero(): void
    {
        $client          = static::createClient();
        // Sem isto o kernel reinicia entre requisições e leva junto o
        // armazenamento de CSRF trocado acima: a 2ª requisição tomaria 403.
        $client->disableReboot();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/pagamento", [
            '_token'     => $this->csrf('pasta_pagamento_' . $pasta->getId()),
            'descricao'  => 'Parcela vazia',
            'valor'      => '0,00',
            'vencimento' => '2026-09-10',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('erro', $this->json($client));

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertCount(0, $em->getRepository(PastaPagamento::class)->findBy(['pasta' => $pasta]));
    }

    #[TestDox('sem token CSRF não grava — 403')]
    public function testRegistrarSemCsrf(): void
    {
        $client          = static::createClient();
        // Sem isto o kernel reinicia entre requisições e leva junto o
        // armazenamento de CSRF trocado acima: a 2ª requisição tomaria 403.
        $client->disableReboot();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/pagamento", [
            '_token'     => 'token-errado',
            'descricao'  => 'Entrada',
            'valor'      => '100,00',
            'vencimento' => '2026-09-10',
        ]);

        self::assertResponseStatusCodeSame(403);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertCount(0, $em->getRepository(PastaPagamento::class)->findBy(['pasta' => $pasta]));
    }

    // =========================================================================
    // Quitar / desfazer
    // =========================================================================

    #[TestDox('quitar marca a data de hoje e o recebido sobe; clicar de novo desfaz')]
    public function testAlternarQuitacao(): void
    {
        $client          = static::createClient();
        // Sem isto o kernel reinicia entre requisições e leva junto o
        // armazenamento de CSRF trocado acima: a 2ª requisição tomaria 403.
        $client->disableReboot();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $pagamento       = $this->criarPagamento($pasta, $tenant, '1300.00');
        $pagamentoId     = (int) $pagamento->getId();
        $this->logarComTenant($client, $user, $tenant);

        $url = "/pasta/{$pasta->getId()}/pagamento/{$pagamentoId}/quitacao";

        $client->request('POST', $url, [
            '_token' => $this->csrf('pasta_pagamento_quitacao_' . $pagamentoId),
        ]);

        self::assertResponseIsSuccessful();
        $dados = $this->json($client);
        self::assertTrue($dados['pago']);
        self::assertSame('R$ 1.300,00', $dados['resumo']['recebido']);
        self::assertSame(100, $dados['resumo']['percentual']);

        self::assertSame(
            (new \DateTimeImmutable('today'))->format('Y-m-d'),
            $this->relerPagamento($pagamentoId)?->getPagoEm()?->format('Y-m-d'),
            'quitar grava a data de hoje'
        );

        // Desfazer é o MESMO gesto: quem clica errado numa lista de parcelas
        // parecidas não pode ficar sem saída a não ser apagar a linha.
        $client->request('POST', $url, [
            '_token' => $this->csrf('pasta_pagamento_quitacao_' . $pagamentoId),
        ]);

        self::assertResponseIsSuccessful();
        $dados = $this->json($client);
        self::assertFalse($dados['pago']);
        self::assertSame('R$ 0,00', $dados['resumo']['recebido']);

        self::assertNull($this->relerPagamento($pagamentoId)?->getPagoEm());
    }

    // =========================================================================
    // Excluir
    // =========================================================================

    #[TestDox('excluir tira a linha e devolve o card sem ela')]
    public function testExcluir(): void
    {
        $client          = static::createClient();
        // Sem isto o kernel reinicia entre requisições e leva junto o
        // armazenamento de CSRF trocado acima: a 2ª requisição tomaria 403.
        $client->disableReboot();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $pagamento       = $this->criarPagamento($pasta, $tenant);
        $pagamentoId     = (int) $pagamento->getId();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/pagamento/{$pagamentoId}/excluir", [
            '_token' => $this->csrf('pasta_pagamento_excluir_' . $pagamentoId),
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->json($client)['resumo']['total']);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertNull($em->getRepository(PastaPagamento::class)->find($pagamentoId));
    }

    // =========================================================================
    // Isolamento entre escritórios
    // =========================================================================

    #[TestDox('pagamento de OUTRO escritório responde 404, nunca 403 — e não é quitado')]
    public function testNaoAlcancaPagamentoDeOutroTenant(): void
    {
        $client                = static::createClient();
        $client->disableReboot();
        [$user, $tenant]       = $this->criarUsuarioAdmin('a');
        [, $tenantVizinho]     = $this->criarUsuarioAdmin('b');

        $minhaPasta      = $this->criarPasta($tenant);
        $pastaDoVizinho  = $this->criarPasta($tenantVizinho);
        $pagamentoAlheio = $this->criarPagamento($pastaDoVizinho, $tenantVizinho, '9999.00');

        $this->logarComTenant($client, $user, $tenant);

        // Id alheio pendurado numa pasta MINHA: é a tentativa que o guarda de
        // posse existe para barrar. Aqui há DUAS barreiras — o `tenant` do
        // repositório e o TenantFilter global, que alcança toda entidade
        // TenantAware. Removida qualquer uma delas isolada, este teste continua
        // verde; o que ele prova é o comportamento do sistema, não a linha.
        // O furo que SÓ o repositório fecha é o da pasta errada, no teste abaixo.
        $client->request(
            'POST',
            "/pasta/{$minhaPasta->getId()}/pagamento/{$pagamentoAlheio->getId()}/quitacao",
            ['_token' => $this->csrf('pasta_pagamento_quitacao_' . $pagamentoAlheio->getId())]
        );

        self::assertResponseStatusCodeSame(
            404,
            '403 confirmaria que o id existe em algum escritório'
        );

        self::assertNull(
            $this->relerPagamento((int) $pagamentoAlheio->getId())?->getPagoEm(),
            'o pagamento do vizinho não pode ter sido tocado'
        );
    }

    /**
     * O furo que o TenantFilter NÃO cobre: mesmo escritório, PASTA errada. Sem o
     * `p.pasta = :pasta` do repositório, a URL da pasta A quitaria um pagamento
     * da pasta B — e o card da A passaria a somar dinheiro que não é dela.
     */
    #[TestDox('pagamento de OUTRA PASTA do mesmo escritório também dá 404')]
    public function testNaoAlcancaPagamentoDeOutraPastaDoMesmoTenant(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarUsuarioAdmin();

        $pastaA    = $this->criarPasta($tenant);
        $pastaB    = $this->criarPasta($tenant);
        $pagamento = $this->criarPagamento($pastaB, $tenant);
        $idDaB     = (int) $pagamento->getId();

        $this->logarComTenant($client, $user, $tenant);

        $client->request(
            'POST',
            "/pasta/{$pastaA->getId()}/pagamento/{$idDaB}/quitacao",
            ['_token' => $this->csrf('pasta_pagamento_quitacao_' . $idDaB)]
        );

        self::assertResponseStatusCodeSame(404);
        self::assertNull(
            $this->relerPagamento($idDaB)?->getPagoEm(),
            'o pagamento da outra pasta não pode ter sido quitado pela URL errada'
        );
    }

    #[TestDox('excluir pagamento de outro escritório também dá 404 e não apaga nada')]
    public function testNaoExcluiPagamentoDeOutroTenant(): void
    {
        $client            = static::createClient();
        $client->disableReboot();
        [$user, $tenant]   = $this->criarUsuarioAdmin('a');
        [, $tenantVizinho] = $this->criarUsuarioAdmin('b');

        $minhaPasta      = $this->criarPasta($tenant);
        $pastaDoVizinho  = $this->criarPasta($tenantVizinho);
        $pagamentoAlheio = $this->criarPagamento($pastaDoVizinho, $tenantVizinho);
        $idAlheio        = (int) $pagamentoAlheio->getId();

        $this->logarComTenant($client, $user, $tenant);

        $client->request(
            'POST',
            "/pasta/{$minhaPasta->getId()}/pagamento/{$idAlheio}/excluir",
            ['_token' => $this->csrf('pasta_pagamento_excluir_' . $idAlheio)]
        );

        self::assertResponseStatusCodeSame(404);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertNotNull($em->getRepository(PastaPagamento::class)->find($idAlheio));
    }

    #[TestDox('a tela só mostra os pagamentos DA pasta aberta')]
    public function testCardMostraSoOsPagamentosDaPasta(): void
    {
        $client          = static::createClient();
        // Sem isto o kernel reinicia entre requisições e leva junto o
        // armazenamento de CSRF trocado acima: a 2ª requisição tomaria 403.
        $client->disableReboot();
        [$user, $tenant] = $this->criarUsuarioAdmin();

        $pastaA = $this->criarPasta($tenant);
        $pastaB = $this->criarPasta($tenant);

        $this->criarPagamento($pastaA, $tenant, '1000.00');
        $this->criarPagamento($pastaB, $tenant, '7777.00');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pastaA->getId()}");

        $card = $crawler->filter('[data-trilho="pagamentos"]');
        self::assertCount(1, $card);
        self::assertStringContainsString('R$ 1.000,00', $card->text());
        self::assertStringNotContainsString(
            'R$ 7.777,00',
            $card->text(),
            'pagamento de outra pasta não pode aparecer aqui'
        );
    }

    // =========================================================================
    // Tela
    // =========================================================================

    /**
     * Desenho 1.2.3 (dc 1873-1920, auditoria 2 F5/F14): sem lançamento o card
     * NÃO troca de cara. O total zerado e a barra vazia continuam, "nenhum pago"
     * aparece no lugar da contagem de pagos e a lista vira uma linha simples.
     * Antes era um `.ps-vazio` com ícone que escondia total e "Próximos".
     */
    #[TestDox('pasta sem pagamento mostra o total zerado, "nenhum pago" e a linha de vazio do desenho')]
    public function testEstadoVazio(): void
    {
        $client          = static::createClient();
        // Sem isto o kernel reinicia entre requisições e leva junto o
        // armazenamento de CSRF trocado acima: a 2ª requisição tomaria 403.
        $client->disableReboot();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        $corpo   = $crawler->filter('[data-trilho="pagamentos"] > #psPagamentosCorpo');
        self::assertCount(1, $corpo);

        self::assertCount(0, $corpo->filter('.ps-vazio'), 'o vazio com ícone saiu: o desenho mantém o card');
        self::assertSame(
            'R$ 0,00 recebidos de R$ 0,00',
            trim(preg_replace('/\s+/', ' ', $crawler->filter('#psPagamentosCorpo > .ps-pag-total > .ps-pag-total-linha')->text()) ?? '')
        );
        $barra = $crawler->filter('#psPagamentosCorpo > .ps-pag-total > .ps-pag-barra');
        self::assertCount(1, $barra, 'a barra continua, vazia');
        self::assertSame('0', $barra->attr('aria-valuenow'), '0 de 0 não é barra cheia');
        self::assertSame('nenhum pago', trim($crawler->filter('#psPagamentosCorpo > .ps-pag-secao > .ps-pag-nota')->text()));
        self::assertSame(
            'Nenhum lançamento. Use Adicionar pagamento.',
            trim($crawler->filter('#psPagamentosCorpo > .ps-pag-vazio-linha')->text())
        );
        self::assertCount(0, $corpo->filter('.ps-pag-linha'));
        self::assertCount(0, $corpo->filter('#psExtratoFolha'), 'sem lançamento não há extrato');
    }

    private function criarPagamentoEm(
        Pasta $pasta,
        Tenant $tenant,
        string $descricao,
        \DateTimeImmutable $vencimento,
        ?\DateTimeImmutable $pagoEm = null,
    ): PastaPagamento {
        $em        = static::getContainer()->get(EntityManagerInterface::class);
        $pagamento = new PastaPagamento();
        $pagamento->setPasta($pasta);
        $pagamento->setTenant($tenant);
        $pagamento->setDescricao($descricao);
        $pagamento->setValor('100.00');
        $pagamento->setVencimento($vencimento);

        if ($pagoEm !== null) {
            $pagamento->alternarQuitacao($pagoEm);
        }

        $em->persist($pagamento);
        $em->flush();

        return $pagamento;
    }

    /**
     * Desenho 1.2.3 (dc 3512, auditoria 2 F17): à vista ficam só os TRÊS
     * próximos não pagos; o resto (pendentes além do 3º e os pagos) fica no HTML
     * e aparece pelo "Ver todos os lançamentos" do ⋮. Ordem do desenho: não
     * pagos por vencimento, pagos no fim. As datas são relativas a hoje — data
     * fixa vira vencida sozinha e muda o selo.
     */
    #[TestDox('o card mostra os três próximos não pagos; os demais e os pagos ficam para o "ver todos"')]
    public function testSoTresProximosAVista(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);

        $hoje = new \DateTimeImmutable('today');
        $pago = $this->criarPagamentoEm($pasta, $tenant, 'Entrada', $hoje->modify('-30 days'), $hoje->modify('-30 days'));
        $p1   = $this->criarPagamentoEm($pasta, $tenant, '1ª parcela', $hoje->modify('+10 days'));
        $p2   = $this->criarPagamentoEm($pasta, $tenant, '2ª parcela', $hoje->modify('+40 days'));
        $p3   = $this->criarPagamentoEm($pasta, $tenant, '3ª parcela', $hoje->modify('+70 days'));
        $p4   = $this->criarPagamentoEm($pasta, $tenant, '4ª parcela', $hoje->modify('+100 days'));
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        $linhas  = $crawler->filter('#psPagamentosCorpo > .ps-pag-linha');

        self::assertSame(
            [(string) $p1->getId(), (string) $p2->getId(), (string) $p3->getId(), (string) $p4->getId(), (string) $pago->getId()],
            $linhas->each(static fn ($l) => (string) $l->attr('data-pagamento-id')),
            'não pagos por vencimento primeiro, pagos no fim'
        );

        $classes = $linhas->each(static fn ($l) => (string) $l->attr('class'));
        foreach ([0, 1, 2] as $i) {
            self::assertStringNotContainsString('ps-pag-linha--alem', $classes[$i], "a linha {$i} é um dos três próximos");
            self::assertStringNotContainsString('ps-pag-linha--pago', $classes[$i]);
        }
        self::assertStringContainsString('ps-pag-linha--alem', $classes[3], 'o 4º pendente fica para o "ver todos"');
        self::assertStringContainsString('ps-pag-linha--pago', $classes[4]);
        self::assertStringNotContainsString('ps-pag-linha--alem', $classes[4], 'pago já se esconde pela própria classe');

        self::assertSame('1 já pago', trim($crawler->filter('#psPagamentosCorpo > .ps-pag-secao > .ps-pag-nota')->text()));

        // O "ver todos" saiu do cabeçalho: só o ⋮ fica lá (dc 1865-1871).
        $cab = '#financeiro .ps-trilho > [data-trilho="pagamentos"] > .ps-card-cab';
        self::assertCount(0, $crawler->filter('#psPagamentosVerTodos'));
        self::assertCount(0, $crawler->filter("{$cab} > .ps-fin-link"));
        self::assertCount(1, $crawler->filter('#psPagamentosMenu .js-pag-ver-todos'));
    }

    /**
     * Desenho 1.2.3 (dc 3440, auditoria 2 F6/F18): pagamento vencido e não pago
     * ganha o selo "Vencida" (tom urgente) e a linha "venceu … · há N dias".
     * Vence hoje continua Pendente.
     */
    #[TestDox('pagamento vencido mostra o selo Vencida e "venceu … · há N dias"; vence hoje é Pendente')]
    public function testVencidoMostraSeloVencida(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);

        $hoje    = new \DateTimeImmutable('today');
        $vencido = $this->criarPagamentoEm($pasta, $tenant, 'Parcela vencida', $hoje->modify('-3 days'));
        $deHoje  = $this->criarPagamentoEm($pasta, $tenant, 'Parcela de hoje', $hoje);
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        $linhaVencida = $crawler->filter('#psPagamentosCorpo > .ps-pag-linha[data-pagamento-id="' . $vencido->getId() . '"]');
        $selo         = $linhaVencida->filter('.ps-pag-direita > button.ps-pag-selo');
        self::assertSame('Vencida', trim($selo->text()));
        self::assertStringContainsString('ps-selo--urgente', (string) $selo->attr('class'));
        self::assertSame('quitacao', $selo->attr('data-acao'), 'o selo segue sendo o botão de quitar');
        self::assertSame(
            'venceu ' . $hoje->modify('-3 days')->format('d/m/Y') . ' · há 3 dias',
            trim($linhaVencida->filter('.ps-pag-quando')->text())
        );

        $linhaHoje = $crawler->filter('#psPagamentosCorpo > .ps-pag-linha[data-pagamento-id="' . $deHoje->getId() . '"]');
        $seloHoje  = $linhaHoje->filter('.ps-pag-direita > button.ps-pag-selo');
        self::assertSame('Pendente', trim($seloHoje->text()));
        self::assertStringContainsString('ps-selo--proximo', (string) $seloHoje->attr('class'));
        self::assertSame('vence ' . $hoje->format('d/m/Y') . ' · hoje', trim($linhaHoje->filter('.ps-pag-quando')->text()));
    }

    /**
     * O modal de novo pagamento NÃO pode nascer dentro de `.ps-paineis`: um
     * ancestral com `transform` (a animação de entrada dos painéis) vira bloco de
     * contenção de `position: fixed`, e o modal cai abaixo do backdrop — sintoma
     * que esta tela já teve com os três modais do gerenciador de arquivos.
     */
    #[TestDox('o modal de novo pagamento vive FORA do painel animado')]
    public function testModalForaDoPainelAnimado(): void
    {
        $client          = static::createClient();
        // Sem isto o kernel reinicia entre requisições e leva junto o
        // armazenamento de CSRF trocado acima: a 2ª requisição tomaria 403.
        $client->disableReboot();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        self::assertCount(1, $crawler->filter('#modalNovoPagamento'), 'o modal tem de existir');
        self::assertCount(
            0,
            $crawler->filter('.ps-paineis #modalNovoPagamento'),
            'modal dentro do painel animado fica preso abaixo do backdrop'
        );
    }
}
