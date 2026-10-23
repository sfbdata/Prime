<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\Controller\PastaPagamentoController;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaPagamento;
use App\Pasta\Entity\TipoDePagamento;
use App\Pasta\UseCase\AlternarQuitacaoDoPagamentoUseCase;
use App\Pasta\UseCase\CorrigirValorDoPagamentoUseCase;
use App\Pasta\UseCase\RegistrarParcelamentoDaPastaUseCase;
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
 * Êxito e sucumbência pelo POST do modal (spec pasta-exito-sucumbencia): a linha prevista, a
 * validação do percentual, a posse (404) e o congelamento do valor ao quitar e ao corrigir.
 *
 * O que vale é o BANCO (SQL direto, sem o mapa de identidade do Doctrine).
 */
#[CoversClass(PastaPagamentoController::class)]
#[CoversClass(RegistrarParcelamentoDaPastaUseCase::class)]
#[CoversClass(AlternarQuitacaoDoPagamentoUseCase::class)]
#[CoversClass(CorrigirValorDoPagamentoUseCase::class)]
final class PastaPagamentoPrevistoControllerTest extends JusPrimeWebTestCase
{
    private bool $csrfInstalado = false;

    private function instalarCsrfStorage(): void
    {
        if ($this->csrfInstalado) {
            return;
        }
        $this->csrfInstalado = true;

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

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** @return array{User, Tenant} */
    private function criarUsuario(string $sufixo = ''): array
    {
        $this->instalarCsrfStorage();

        $em     = $this->em();
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Previsto ' . $sufixo . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('test_previsto_' . $sufixo . uniqid() . '@test.com');
        $user->setFullName('Admin Previsto');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return [$user, $tenant];
    }

    /** Pasta com um contrato de R$ 10.000,00 e custas de R$ 2.860,00: Total contratado R$ 12.860,00. */
    private function criarPasta(Tenant $tenant): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup('TEST-PREV-' . uniqid());
        $pasta->setTenant($tenant);
        $pasta->setValorCausa('99999.00'); // C8: o valor da causa NÃO é a base
        $this->em()->persist($pasta);

        foreach ([[TipoDePagamento::Contrato, '10000.00'], [TipoDePagamento::Custas, '2860.00']] as [$tipo, $valor]) {
            $p = new PastaPagamento();
            $p->setPasta($pasta);
            $p->setTenant($tenant);
            $p->setTipo($tipo);
            $p->setDescricao('Lançado');
            $p->setValor($valor);
            $p->setVencimento(new \DateTimeImmutable('2026-12-10'));
            $this->em()->persist($p);
        }
        $this->em()->flush();

        return $pasta;
    }

    private function cliente(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        return $client;
    }

    /** @param array<string, string> $campos */
    private function lancar(KernelBrowser $client, int $pastaId, array $campos): void
    {
        $client->request('POST', "/pasta/{$pastaId}/pagamento/parcelamento", $campos + [
            '_token'     => 'TOKEN_pasta_pagamento_' . $pastaId,
            'tipo'       => 'exito',
            'percentual' => '20',
        ], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    }

    /** @return list<array<string, mixed>> */
    private function previstos(int $pastaId): array
    {
        return $this->em()->getConnection()->fetchAllAssociative(
            "SELECT id, tipo, percentual, valor, vencimento, pago_em, descricao, tenant_id
               FROM pasta_pagamento WHERE pasta_id = :p AND tipo IN ('exito', 'sucumbencia') ORDER BY id ASC",
            ['p' => $pastaId],
        );
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        return json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    #[TestDox('êxito grava UMA linha prevista (tipo, %, sem vencimento) e fica fora do contratado')]
    public function testLancaExito(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuario();
        $pastaId         = (int) $this->criarPasta($tenant)->getId();
        $this->logarComTenant($client, $user, $tenant);

        $this->lancar($client, $pastaId, ['tipo' => 'exito', 'percentual' => '20']);

        self::assertResponseStatusCodeSame(201);
        $dados = $this->json($client);
        self::assertSame(1, $dados['quantidade']);
        self::assertSame('R$ 12.860,00', $dados['resumo']['contratado'], 'o previsto não entra no contratado');
        self::assertSame(1286000, $dados['resumo']['contratadoCentavos']);
        self::assertStringContainsString('Previsto', $dados['resumo']['html']);
        self::assertStringContainsString('20% sobre o total contratado · condicionado ao resultado', $dados['resumo']['html']);
        self::assertStringContainsString('R$ 2.572,00', $dados['resumo']['html']);
        self::assertStringContainsString('previsto em êxito', $dados['resumo']['html']);

        // Arranjo por filho direto: o "+ R$ X previsto" mora no bloco do total, com o ícone do
        // desenho (dc L.2011-2013); o selo azul fica à direita da linha.
        $corpo = new Crawler('<div id="c">' . $dados['resumo']['html'] . '</div>');
        $previsto = $corpo->filter('#c > .ps-pag-total > .ps-pag-previsto-em');
        self::assertCount(1, $previsto);
        self::assertCount(1, $previsto->filter('i.bi-graph-up-arrow'));
        self::assertSame('+ R$ 2.572,00 previsto em êxito', trim($previsto->filter('span')->text()));
        self::assertCount(1, $corpo->filter('#c > .ps-pag-linha > .ps-pag-direita > .ps-selo--previsto'));

        $linhas = $this->previstos($pastaId);
        self::assertCount(1, $linhas);
        self::assertSame(['exito', '20.00', null, null, 'Honorários de êxito (20%)', $tenant->getId()], [
            $linhas[0]['tipo'], $linhas[0]['percentual'], $linhas[0]['vencimento'], $linhas[0]['pago_em'], $linhas[0]['descricao'], (int) $linhas[0]['tenant_id'],
        ]);
    }

    #[TestDox('sucumbência: "Sucumbência estimada (10%)", tipo gravado')]
    public function testLancaSucumbencia(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuario();
        $pastaId         = (int) $this->criarPasta($tenant)->getId();
        $this->logarComTenant($client, $user, $tenant);

        $this->lancar($client, $pastaId, ['tipo' => 'sucumbencia', 'percentual' => '10']);

        self::assertResponseStatusCodeSame(201);
        $linhas = $this->previstos($pastaId);
        self::assertSame(['sucumbencia', '10.00', 'Sucumbência estimada (10%)'], [$linhas[0]['tipo'], $linhas[0]['percentual'], $linhas[0]['descricao']]);
        self::assertStringContainsString('previsto em sucumbência', $this->json($client)['resumo']['html']);
    }

    /** @return iterable<string, array{string}> */
    public static function percentuaisInvalidos(): iterable
    {
        yield 'zero'         => ['0'];
        yield 'acima de 100' => ['101'];
        yield 'vazio'        => [''];
        yield 'texto'        => ['vinte'];
    }

    #[DataProvider('percentuaisInvalidos')]
    #[TestDox('percentual fora de 0 a 100: 422 com a mensagem do desenho e nada gravado ($_dataName)')]
    public function testPercentualInvalido(string $percentual): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuario();
        $pastaId         = (int) $this->criarPasta($tenant)->getId();
        $this->logarComTenant($client, $user, $tenant);

        $this->lancar($client, $pastaId, ['percentual' => $percentual]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Informe um percentual entre 0 e 100.', $this->json($client)['erro']);
        self::assertSame([], $this->previstos($pastaId));
    }

    #[TestDox('pasta de OUTRO escritório: 404, nunca 403, e nada gravado')]
    public function testOutroEscritorio(): void
    {
        $client            = $this->cliente();
        [$user, $tenant]   = $this->criarUsuario('a');
        [, $tenantVizinho] = $this->criarUsuario('b');
        $pastaDoVizinho    = (int) $this->criarPasta($tenantVizinho)->getId();
        $this->em()->clear();
        $this->logarComTenant($client, $user, $tenant);

        $this->lancar($client, $pastaDoVizinho, []);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->previstos($pastaDoVizinho));
    }

    #[TestDox('"Marcar como recebido" congela: grava o valor calculado e zera o percentual')]
    public function testQuitarCongela(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuario();
        $pastaId         = (int) $this->criarPasta($tenant)->getId();
        $this->logarComTenant($client, $user, $tenant);
        $this->lancar($client, $pastaId, ['percentual' => '15']);
        $id = (int) $this->previstos($pastaId)[0]['id'];

        $client->request('POST', "/pasta/{$pastaId}/pagamento/{$id}/quitacao", [
            '_token' => 'TOKEN_pasta_pagamento_quitacao_' . $id,
        ], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertResponseIsSuccessful();
        $linha = $this->previstos($pastaId)[0];
        self::assertSame(['1929.00', null], [$linha['valor'], $linha['percentual']], '15% de 12.860,00');
        self::assertNotNull($linha['pago_em']);
        $dados = $this->json($client);
        self::assertSame('R$ 1.929,00', $dados['resumo']['recebido'], 'recebido, deixa de ser previsto');
        self::assertSame('R$ 14.789,00', $dados['resumo']['contratado']);
    }

    #[TestDox('"Corrigir valor" congela: grava o valor digitado e zera o percentual; compara com o calculado')]
    public function testCorrigirCongela(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuario();
        $pastaId         = (int) $this->criarPasta($tenant)->getId();
        $this->logarComTenant($client, $user, $tenant);
        $this->lancar($client, $pastaId, ['tipo' => 'sucumbencia', 'percentual' => '10']);
        $id = (int) $this->previstos($pastaId)[0]['id'];

        $client->request('POST', "/pasta/{$pastaId}/pagamento/{$id}/valor", [
            '_token'        => 'TOKEN_pasta_pagamento_corrigir_' . $id,
            'valor'         => '1.500,00',
            'valorAnterior' => '1.286,00',
        ], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertResponseIsSuccessful();
        $linha = $this->previstos($pastaId)[0];
        self::assertSame(['1500.00', null, null], [$linha['valor'], $linha['percentual'], $linha['pago_em']]);
        $html = $this->json($client)['resumo']['html'];
        self::assertStringContainsString('condicionado ao resultado', $html, 'segue previsto até ser recebido');
        self::assertStringContainsString('previsto em sucumbência', $html);
    }

    #[TestDox('impressão: o bloco Financeiro deixa o previsto fora e mostra a situação "Previsto" com o %')]
    public function testImpressao(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuario();
        $pastaId         = (int) $this->criarPasta($tenant)->getId();
        $this->logarComTenant($client, $user, $tenant);
        $this->lancar($client, $pastaId, ['percentual' => '20']);

        $crawler = $client->request('GET', "/pasta/{$pastaId}/resumo/imprimir?secao=extrato&fragmento=1&financeiro=1");

        self::assertResponseIsSuccessful();
        $bloco = $crawler->filter('[data-rp-parte="financeiro"]');
        self::assertCount(1, $bloco);
        $cabecalho = trim($bloco->filter('.rp-fin-linha')->text());
        self::assertStringContainsString('Contratado R$ 12.860,00', $cabecalho);
        self::assertStringContainsString('Previsto (êxito/sucumbência) R$ 2.572,00', $cabecalho);
        $previsto = $bloco->filter('tbody tr')->last();
        self::assertSame('Previsto', trim($previsto->filter('td.rp-situacao')->text()));
        self::assertSame('', trim($previsto->filter('td')->eq(1)->text()), 'sem vencimento');
        self::assertStringContainsString('R$ 2.572,00 (20%)', preg_replace('/\s+/', ' ', $previsto->filter('td.rp-valor')->text()) ?? '');
    }
}
