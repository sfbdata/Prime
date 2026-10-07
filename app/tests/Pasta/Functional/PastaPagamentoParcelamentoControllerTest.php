<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\Controller\PastaPagamentoController;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaPagamento;
use App\Pasta\UseCase\RegistrarParcelamentoDaPastaUseCase;
use App\Shared\Service\ValorEmReais;
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
 * "Adicionar pagamento" com entrada, parcelas e juros (desenho 1.2.3, dc
 * L.1928-2012): `POST /pasta/{id}/pagamento/parcelamento`.
 *
 * O POST leva os CAMPOS do modal, e o servidor refaz a conta. Os testes conferem
 * o que ficou no BANCO (SQL direto, sem o mapa de identidade do Doctrine): N
 * linhas, com os valores calculados aqui — e nenhuma quando algo dá errado.
 */
#[CoversClass(PastaPagamentoController::class)]
#[CoversClass(RegistrarParcelamentoDaPastaUseCase::class)]
final class PastaPagamentoParcelamentoControllerTest extends JusPrimeWebTestCase
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
    private function criarUsuario(string $sufixo = '', array $roles = ['ROLE_SUPER_ADMIN']): array
    {
        $this->instalarCsrfStorage();

        $em     = $this->em();
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Parcelamento ' . $sufixo . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('test_parcelar_' . $sufixo . uniqid() . '@test.com');
        $user->setFullName('Admin Parcelamento');
        $user->setRoles($roles);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return [$user, $tenant];
    }

    private function criarPasta(Tenant $tenant, ?string $valorCausa = null): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup('TEST-PARC-' . uniqid());
        $pasta->setTenant($tenant);
        $pasta->setValorCausa($valorCausa);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    private function cliente(): KernelBrowser
    {
        $client = static::createClient();
        // O armazenamento de CSRF trocado some no reboot do kernel entre requisições.
        $client->disableReboot();

        return $client;
    }

    /** @param array<string, string> $campos */
    private function parcelar(KernelBrowser $client, int $pastaId, array $campos = [], ?string $token = null): void
    {
        $client->request('POST', "/pasta/{$pastaId}/pagamento/parcelamento", $campos + [
            '_token'      => $token ?? 'TOKEN_pasta_pagamento_' . $pastaId,
            'tipo'        => 'contrato',
            'base'        => 'valor',
            'total'       => '12.000,00',
            'percentual'  => '20',
            'entrada'     => '',
            'parcelas'    => '1',
            'vencimento'  => '2026-11-06',
            'juros'       => '0',
            'taxa'        => '1',
        ], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    }

    /**
     * Linhas gravadas da pasta, lidas por SQL: depois da requisição o TenantFilter
     * do EntityManager esconderia as de outro escritório.
     *
     * @return list<array<string, mixed>>
     */
    private function linhasGravadas(int $pastaId): array
    {
        return $this->em()->getConnection()->fetchAllAssociative(
            'SELECT descricao, valor, vencimento, pago_em, tenant_id, autor_id
               FROM pasta_pagamento WHERE pasta_id = :p ORDER BY id ASC',
            ['p' => $pastaId],
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

    #[TestDox('grava a entrada e as 10 parcelas de uma vez, TODAS pendentes, e devolve o card com as 11 linhas')]
    public function testGravaNLinhas(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuario();
        $pasta           = $this->criarPasta($tenant);
        $pastaId         = (int) $pasta->getId();
        $this->logarComTenant($client, $user, $tenant);

        $this->parcelar($client, $pastaId, ['entrada' => '2.000,00', 'parcelas' => '10']);

        self::assertResponseStatusCodeSame(201);
        $dados = $this->json($client);
        self::assertSame(11, $dados['quantidade']);
        self::assertCount(11, $dados['ids']);
        self::assertSame(11, $dados['resumo']['total']);
        self::assertSame(0, $dados['resumo']['quantidadePagos'], 'nada nasce recebido (decisão do dono, 07/10/2026)');
        self::assertSame('R$ 0,00', $dados['resumo']['recebido'], 'o recebido não muda ao parcelar');
        self::assertStringContainsString('10ª parcela · honorários', $dados['resumo']['html']);

        $linhas = $this->linhasGravadas($pastaId);
        self::assertCount(11, $linhas);
        $hoje = (new \DateTimeImmutable('today'))->format('Y-m-d');
        self::assertSame(['Entrada · honorários', '2000.00', $hoje, null], [$linhas[0]['descricao'], $linhas[0]['valor'], $linhas[0]['vencimento'], $linhas[0]['pago_em']], 'a entrada vence hoje e nasce PENDENTE');
        self::assertSame(['1ª parcela · honorários', '1000.00', '2026-11-06', null], [$linhas[1]['descricao'], $linhas[1]['valor'], $linhas[1]['vencimento'], $linhas[1]['pago_em']]);
        self::assertSame('2027-08-06', $linhas[10]['vencimento']);

        $soma = array_sum(array_map(static fn (array $l): int => ValorEmReais::paraCentavos((string) $l['valor']), $linhas));
        self::assertSame(1200000, $soma, 'a soma gravada é exatamente o total');

        foreach ($linhas as $l) {
            self::assertNull($l['pago_em'], $l['descricao'] . ' não pode nascer recebida');
            self::assertSame($tenant->getId(), (int) $l['tenant_id']);
            self::assertSame($user->getId(), (int) $l['autor_id']);
        }
    }

    /**
     * A prova de que o servidor é a fonte: o POST não leva valor de parcela
     * nenhum (e um `valor` forjado é ignorado). O que grava é a Price daqui.
     */
    /**
     * Regra financeira do dono (07/10/2026). O modal não manda mais o campo; um
     * `entradaPaga=1` que chegue (aba antiga, POST forjado) é RECUSADO — não
     * ignorado em silêncio — e nada é gravado: nem a entrada, nem as parcelas.
     */
    #[TestDox('POST com entradaPaga=1 forjado responde 422 e não grava recebimento nenhum')]
    public function testEntradaPagaForjadaRecusada(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuario();
        $pastaId         = (int) $this->criarPasta($tenant)->getId();
        $this->logarComTenant($client, $user, $tenant);

        $this->parcelar($client, $pastaId, ['entrada' => '2.000,00', 'parcelas' => '10', 'entradaPaga' => '1']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Marcar como recebido', (string) $this->json($client)['erro']);
        self::assertSame([], $this->linhasGravadas($pastaId), 'nem a entrada nem as parcelas ficam');
    }

    #[TestDox('qualquer entradaPaga diferente de 0/vazio (true, on, yes, 2) é recusado com 422 e nada é gravado')]
    #[\PHPUnit\Framework\Attributes\DataProvider('valoresVerdadeiros')]
    public function testEntradaPagaVerdadeiraRecusada(string $valor): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuario();
        $pastaId         = (int) $this->criarPasta($tenant)->getId();
        $this->logarComTenant($client, $user, $tenant);

        $this->parcelar($client, $pastaId, ['entrada' => '100', 'total' => '300', 'parcelas' => '2', 'entradaPaga' => $valor]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->linhasGravadas($pastaId));
    }

    /** @return iterable<string, array{string}> */
    public static function valoresVerdadeiros(): iterable
    {
        foreach (['true', 'on', 'yes', '2', '1 '] as $v) {
            yield $v => [$v];
        }
    }

    #[TestDox('entradaPaga=0 (ou ausente) segue normal: a entrada nasce pendente')]
    public function testEntradaPagaZeroAceita(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuario();
        $pastaId         = (int) $this->criarPasta($tenant)->getId();
        $this->logarComTenant($client, $user, $tenant);

        $this->parcelar($client, $pastaId, ['entrada' => '100', 'total' => '300', 'parcelas' => '2', 'entradaPaga' => '0']);

        self::assertResponseStatusCodeSame(201);
        self::assertSame([null, null, null], array_column($this->linhasGravadas($pastaId), 'pago_em'));
    }

    /**
     * O recebimento acontece só pelo fluxo existente "Marcar como recebido"
     * (`pasta_pagamento_alternar_quitacao`), uma linha por vez.
     */
    #[TestDox('depois de parcelar, "Marcar como recebido" quita UMA parcela e só ela')]
    public function testMarcarComoRecebidoQuitaSoUma(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuario();
        $pastaId         = (int) $this->criarPasta($tenant)->getId();
        $this->logarComTenant($client, $user, $tenant);

        $this->parcelar($client, $pastaId, ['total' => '300', 'entrada' => '100', 'parcelas' => '2']);
        self::assertResponseStatusCodeSame(201);
        $ids = $this->json($client)['ids'];
        self::assertCount(3, $ids);

        $alvo = (int) $ids[1]; // a 1ª parcela
        $client->request('POST', "/pasta/{$pastaId}/pagamento/{$alvo}/quitacao", [
            '_token' => 'TOKEN_pasta_pagamento_quitacao_' . $alvo,
        ], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertResponseIsSuccessful();
        $dados = $this->json($client);
        self::assertTrue($dados['pago']);
        self::assertSame(1, $dados['resumo']['quantidadePagos']);
        self::assertSame('R$ 100,00', $dados['resumo']['recebido']);

        $hoje   = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $linhas = $this->linhasGravadas($pastaId);
        self::assertSame([null, $hoje, null], array_column($linhas, 'pago_em'), 'só a parcela marcada ficou recebida');
    }

    #[TestDox('com juros o servidor recalcula a Price: 10 mil a 1% em 12x = 11 × 888,49 + 888,46')]
    public function testServidorRecalculaPrice(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuario();
        $pastaId         = (int) $this->criarPasta($tenant)->getId();
        $this->logarComTenant($client, $user, $tenant);

        $this->parcelar($client, $pastaId, [
            'total' => '10.000,00', 'parcelas' => '12', 'juros' => '1', 'taxa' => '1', 'valor' => '1,00',
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(
            array_merge(array_fill(0, 11, '888.49'), ['888.46']),
            array_column($this->linhasGravadas($pastaId), 'valor'),
        );
    }

    #[TestDox('60 parcelas (o teto) gravam 60 linhas')]
    public function testTetoDe60(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuario();
        $pastaId         = (int) $this->criarPasta($tenant)->getId();
        $this->logarComTenant($client, $user, $tenant);

        $this->parcelar($client, $pastaId, ['total' => '6.000,00', 'parcelas' => '60']);

        self::assertResponseStatusCodeSame(201);
        self::assertCount(60, $this->linhasGravadas($pastaId));
    }

    #[TestDox('a descrição livre opcional é gravada em todas as linhas geradas')]
    public function testDescricaoLivreGravada(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuario();
        $pastaId         = (int) $this->criarPasta($tenant)->getId();
        $this->logarComTenant($client, $user, $tenant);

        $this->parcelar($client, $pastaId, [
            'total' => '300', 'entrada' => '100', 'entradaPaga' => '0', 'parcelas' => '2', 'descricao' => 'Contrato de março',
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(
            ['Contrato de março · entrada', 'Contrato de março · 1/2', 'Contrato de março · 2/2'],
            array_column($this->linhasGravadas($pastaId), 'descricao'),
        );
    }

    #[TestDox('base % da causa usa o valor da causa gravado na pasta')]
    public function testPercentualDaCausa(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuario();
        $pastaId         = (int) $this->criarPasta($tenant, '12860.00')->getId();
        $this->logarComTenant($client, $user, $tenant);

        $this->parcelar($client, $pastaId, ['base' => 'pct', 'percentual' => '20', 'total' => '']);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(['2572.00'], array_column($this->linhasGravadas($pastaId), 'valor'));
        self::assertSame(['Honorários contratuais'], array_column($this->linhasGravadas($pastaId), 'descricao'));
    }

    #[TestDox('cada linha gravada entra no audit_log pelo mecanismo existente')]
    public function testAuditoria(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuario();
        $pastaId         = (int) $this->criarPasta($tenant)->getId();
        $this->logarComTenant($client, $user, $tenant);

        $this->parcelar($client, $pastaId, ['parcelas' => '3']);
        self::assertResponseStatusCodeSame(201);

        $auditorias = (int) $this->em()->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM audit_log
              WHERE entity_class = ? AND action = 'create' AND tenant_id = ? AND route = 'pasta_pagamento_parcelar'",
            [PastaPagamento::class, $tenant->getId()],
        );
        self::assertSame(3, $auditorias);
    }

    // =========================================================================
    // Tudo ou nada, validação
    // =========================================================================

    /** @return iterable<string, array{array<string, string>}> */
    public static function camposInvalidos(): iterable
    {
        // Com entrada nos casos em que ela seria válida: o erro está numa parcela
        // e nem a entrada pode ficar gravada sozinha.
        yield 'valor total não é dinheiro'  => [['total' => 'abc', 'parcelas' => '5', 'entrada' => '500,00']];
        yield 'valor total zero'            => [['total' => '0,00', 'parcelas' => '5', 'entrada' => '500,00']];
        yield 'parcelas acima do teto (61)' => [['parcelas' => '61', 'entrada' => '500,00']];
        yield 'taxa acima do teto'          => [['parcelas' => '5', 'juros' => '1', 'taxa' => '50', 'entrada' => '500,00']];
        yield 'vencimento inexistente'      => [['parcelas' => '5', 'vencimento' => '2027-02-31', 'entrada' => '500,00']];
        yield 'êxito ainda não grava'       => [['tipo' => 'exito']];
        yield 'pequeno demais'              => [['total' => '0,50', 'parcelas' => '60']];
    }

    /** @param array<string, string> $campos */
    #[DataProvider('camposInvalidos')]
    #[TestDox('campo inválido responde 422 com a mensagem e NENHUMA linha é gravada ($_dataName)')]
    public function testValorInvalido422(array $campos): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuario();
        $pastaId         = (int) $this->criarPasta($tenant)->getId();
        $this->logarComTenant($client, $user, $tenant);

        $this->parcelar($client, $pastaId, $campos);

        self::assertResponseStatusCodeSame(422);
        self::assertNotSame('', (string) ($this->json($client)['erro'] ?? ''));
        self::assertSame([], $this->linhasGravadas($pastaId), 'tudo ou nada: nem a entrada fica');
    }

    // =========================================================================
    // Guardas: CSRF, permissão, posse, lápide
    // =========================================================================

    #[TestDox('sem o token CSRF da pasta não grava — 403')]
    public function testSemCsrf(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuario();
        $pastaId         = (int) $this->criarPasta($tenant)->getId();
        $this->logarComTenant($client, $user, $tenant);

        $this->parcelar($client, $pastaId, ['parcelas' => '3'], 'token-errado');
        self::assertResponseStatusCodeSame(403);

        // O token de OUTRA pasta também não serve.
        $this->parcelar($client, $pastaId, ['parcelas' => '3'], 'TOKEN_pasta_pagamento_' . ($pastaId + 1));
        self::assertResponseStatusCodeSame(403);

        self::assertSame([], $this->linhasGravadas($pastaId));
    }

    #[TestDox('usuário sem permissão de editar a pasta recebe 403 e nada é gravado')]
    public function testSemPermissao(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuario('comum', ['ROLE_USER']);
        $pastaId         = (int) $this->criarPasta($tenant)->getId();
        $this->logarComTenant($client, $user, $tenant);

        $this->parcelar($client, $pastaId, ['parcelas' => '3']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->linhasGravadas($pastaId));
    }

    #[TestDox('pasta de OUTRO escritório responde 404, nunca 403, e nada é gravado nela')]
    public function testPastaDeOutroTenant(): void
    {
        $client            = $this->cliente();
        [$user, $tenant]   = $this->criarUsuario('a');
        [, $tenantVizinho] = $this->criarUsuario('b');
        $pastaDoVizinho    = (int) $this->criarPasta($tenantVizinho)->getId();
        $this->em()->clear(); // senão a pasta sai do mapa de identidade sem passar pelo TenantFilter
        $this->logarComTenant($client, $user, $tenant);

        $this->parcelar($client, $pastaDoVizinho, ['parcelas' => '3']);

        self::assertResponseStatusCodeSame(404, '403 confirmaria que a pasta existe em algum escritório');
        self::assertSame([], $this->linhasGravadas($pastaDoVizinho));
    }

    /**
     * Não há id de lançamento na URL — o furo da pasta irmã aqui seria a linha
     * cair em outra pasta. O destino é SÓ a pasta da URL: nenhum campo do corpo
     * escolhe pasta.
     */
    #[TestDox('pasta IRMÃ do mesmo escritório não recebe nada; um `pasta` forjado no corpo é ignorado')]
    public function testPastaIrmaNaoRecebe(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuario();
        $pastaA          = (int) $this->criarPasta($tenant)->getId();
        $pastaB          = (int) $this->criarPasta($tenant)->getId();
        $this->logarComTenant($client, $user, $tenant);

        $this->parcelar($client, $pastaA, ['parcelas' => '3', 'pasta' => (string) $pastaB, 'pastaId' => (string) $pastaB]);

        self::assertResponseStatusCodeSame(201);
        self::assertCount(3, $this->linhasGravadas($pastaA));
        self::assertSame([], $this->linhasGravadas($pastaB));
    }

    #[TestDox('o token CSRF da pasta irmã não abre o parcelamento desta — 403')]
    public function testTokenDaPastaIrma(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuario();
        $pastaA          = (int) $this->criarPasta($tenant)->getId();
        $pastaB          = (int) $this->criarPasta($tenant)->getId();
        $this->logarComTenant($client, $user, $tenant);

        $this->parcelar($client, $pastaA, ['parcelas' => '3'], 'TOKEN_pasta_pagamento_' . $pastaB);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->linhasGravadas($pastaA));
        self::assertSame([], $this->linhasGravadas($pastaB));
    }

    #[TestDox('pasta excluída (lápide) não aceita parcelamento')]
    public function testPastaExcluidaNaoAceita(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuario();
        $pasta           = $this->criarPasta($tenant);
        $pastaId         = (int) $pasta->getId();
        $pasta->marcarExcluida($user, new \DateTimeImmutable());
        $this->em()->flush();
        $this->logarComTenant($client, $user, $tenant);

        $this->parcelar($client, $pastaId, ['parcelas' => '3']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->linhasGravadas($pastaId));
    }

    // =========================================================================
    // Arranjo do modal (combinador de filho direto)
    // =========================================================================

    #[TestDox('o modal segue o desenho: cabeçalho, corpo com tipo/grade/prévia e rodapé, nesta ordem')]
    public function testArranjoDoModal(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuario();
        $pasta           = $this->criarPasta($tenant, '12860.00');
        $pastaId         = (int) $pasta->getId();
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pastaId}");
        self::assertResponseIsSuccessful();

        $modal = $crawler->filter('#modalNovoPagamento');
        self::assertCount(1, $modal);
        self::assertCount(0, $crawler->filter('.ps-paineis #modalNovoPagamento'), 'fora do painel animado');
        self::assertSame("/pasta/{$pastaId}/pagamento/parcelamento", $modal->attr('data-url-parcelar'));

        $form = '#modalNovoPagamento > .modal-dialog > .modal-content > form#formNovoPagamento';
        self::assertCount(1, $crawler->filter($form));
        self::assertSame(
            ['ps-np-cab', 'ps-np-corpo', 'ps-np-rodape'],
            $crawler->filter("{$form} > div")->each(static fn (Crawler $d): string => (string) $d->attr('class')),
            'cabeçalho, corpo e rodapé são os três blocos do diálogo',
        );
        // O gerenciador real mascara o valor (BREACH): a prova é ele aceitar o token desta pasta.
        self::assertTrue(
            static::getContainer()->get('security.csrf.token_manager')->isTokenValid(new \Symfony\Component\Security\Csrf\CsrfToken(
                'pasta_pagamento_' . $pastaId,
                (string) $crawler->filter("{$form} > input[name=\"_token\"]")->attr('value'),
            )),
            'o CSRF vai junto do formulário (o modal antigo não o mandava)',
        );

        // Cabeçalho (dc L.1932-1938).
        self::assertSame('Adicionar pagamento', trim($crawler->filter("{$form} > .ps-np-cab > .ps-np-cab-textos > .ps-np-titulo")->text()));
        self::assertSame(
            'Valor da causa R$ 12.860,00 · cálculo automático',
            trim((string) preg_replace('/\s+/u', ' ', $crawler->filter("{$form} > .ps-np-cab > .ps-np-cab-textos > .ps-np-sub")->text())),
        );
        self::assertCount(1, $crawler->filter("{$form} > .ps-np-cab > button.ps-np-fechar[data-bs-dismiss=\"modal\"]"));

        // Corpo: tipo → grade → descrição → prévia. SEM o controle "Entrada já
        // recebida hoje" do desenho (desvio por decisão do dono, 07/10/2026).
        $corpo = "{$form} > .ps-np-corpo";
        self::assertSame(
            ['ps-np-bloco', 'ps-np-grade', 'ps-np-campo ps-np-descricao', 'ps-np-previa'],
            $crawler->filter("{$corpo} > *")->each(static fn (Crawler $d): string => (string) $d->attr('class')),
        );
        self::assertCount(0, $crawler->filter('#modalNovoPagamento .js-np-entrada-paga'), 'sem o controle de entrada recebida');
        self::assertCount(0, $crawler->filter('#modalNovoPagamento input[name="entradaPaga"]'), 'o formulário não manda entradaPaga');
        self::assertStringNotContainsString('Entrada já recebida', $modal->text());

        $tipos = $crawler->filter("{$corpo} > .ps-np-bloco > .ps-np-tipos > button.ps-np-tipo");
        self::assertSame(['Contratuais', 'Êxito', 'Sucumbência', 'Custas'], $tipos->each(static fn (Crawler $b): string => trim($b->text())));
        self::assertSame(['contrato', 'exito', 'sucumbencia', 'custas'], $tipos->each(static fn (Crawler $b): string => (string) $b->attr('data-tipo')));
        self::assertSame(
            [false, true, true, false],
            $tipos->each(static fn (Crawler $b): bool => $b->attr('disabled') !== null),
            'êxito e sucumbência aparecem, mas não gravam nesta fatia',
        );
        self::assertSame('true', $tipos->first()->attr('aria-pressed'), 'abre em Contratuais');

        self::assertSame(
            ['Base do valor', 'Valor total (R$)', '% do valor da causa', 'Entrada (R$)', 'Parcelas', '1º vencimento', 'Juros'],
            $crawler->filter("{$corpo} > .ps-np-grade > .ps-np-campo > .ps-np-rotulo")->each(static fn (Crawler $s): string => trim($s->text())),
        );
        $parcelas = $crawler->filter("{$corpo} > .ps-np-grade > .ps-np-campo > input[name=\"parcelas\"]");
        self::assertSame(['1', '60', '1'], [$parcelas->attr('min'), $parcelas->attr('max'), $parcelas->attr('value')], 'campo de 1 a 60, como no desenho');
        self::assertSame(['Valor fixo', '% da causa'], $crawler->filter("{$corpo} > .ps-np-grade > .ps-np-campo > .ps-np-seg > [data-base]")->each(static fn (Crawler $b): string => trim($b->text())));
        self::assertSame(['Sem juros', 'Com juros'], $crawler->filter("{$corpo} > .ps-np-grade > .ps-np-campo > .ps-np-juros > .ps-np-seg > [data-juros]")->each(static fn (Crawler $b): string => trim($b->text())));
        self::assertCount(1, $crawler->filter("{$corpo} > .ps-np-grade > .ps-np-campo > .ps-np-juros > input.ps-np-taxa[name=\"taxa\"]"));

        // Descrição livre: desvio consciente, opcional, abaixo dos campos do dc.
        $descricao = $crawler->filter("{$corpo} > label.ps-np-descricao > input[name=\"descricao\"]");
        self::assertCount(1, $descricao);
        self::assertNull($descricao->attr('required'), 'opcional');
        self::assertSame('110', $descricao->attr('maxlength'));

        // Prévia (dc L.1990-1999).
        self::assertSame('Prévia', trim($crawler->filter("{$corpo} > .ps-np-previa > .ps-np-previa-cab > .ps-np-rotulo")->text()));
        self::assertSame(
            'Preencha o valor para ver as parcelas calculadas.',
            trim($crawler->filter("{$corpo} > .ps-np-previa > .ps-np-previa-vazio")->text()),
        );

        // Rodapé: Cancelar e o botão que vira "Gerar N lançamentos".
        self::assertSame(
            ['Cancelar', 'Adicionar'],
            $crawler->filter("{$form} > .ps-np-rodape > button")->each(static fn (Crawler $b): string => trim($b->text())),
        );
        self::assertSame('submit', $crawler->filter("{$form} > .ps-np-rodape > button.ps-np-gerar")->attr('type'));
    }

    #[TestDox('pasta sem valor da causa mostra o travessão no cabeçalho do modal')]
    public function testCabecalhoSemValorDaCausa(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuario();
        $pastaId         = (int) $this->criarPasta($tenant)->getId();
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pastaId}");

        self::assertStringStartsWith(
            'Valor da causa ',
            trim($crawler->filter('#formNovoPagamento > .ps-np-cab > .ps-np-cab-textos > .ps-np-sub')->text()),
        );
        self::assertStringNotContainsString('R$', $crawler->filter('#formNovoPagamento .js-np-causa')->text());
    }
}
