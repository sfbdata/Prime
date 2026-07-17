<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\ObrigacaoController;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\StatusCaso;
use App\Tests\Factory\Cobranca\AlocacaoPagamentoFactory;
use App\Tests\Factory\Cobranca\ObrigacaoFactory;
use App\Tests\Factory\Cobranca\PagamentoFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Mutações de Obrigações (Onda 8B): registrar obrigação e reconhecer valor. Cobre gate de módulo +
 * capacidade (`resources.cobranca.gerenciar`), CSRF, anti-IDOR cross-tenant (404), erro de domínio
 * (caso encerrado) e o happy path com persistência.
 */
#[CoversClass(ObrigacaoController::class)]
final class ObrigacaoMutacaoControllerTest extends CobrancaWebTestCase
{
    #[TestDox('Registrar obrigação: happy path persiste e volta ao caso')]
    public function testRegistrarObrigacaoHappy(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $casoId = (int) $caso->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'registrar_obrigacao');

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/obrigacoes', [
            'registrar_obrigacao' => [
                'descricao' => 'Boleto marcador ABC',
                'valorOriginal' => '1.500,00',
                'vencimentoOriginal' => '2026-08-10',
                'referenciaExterna' => 'REF-1',
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId() . '#secao-divida');
        $client->followRedirect();
        self::assertStringContainsString('Boleto marcador ABC', (string) $client->getResponse()->getContent());
    }

    #[TestDox('Registrar obrigação em caso ENCERRADO: erro de domínio, não persiste')]
    public function testRegistrarObrigacaoCasoEncerrado(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        // Caso ativo só para renderizar o form e obter o token; o alvo é um caso encerrado.
        [, $casoAtivo] = $this->semearGrafo($tenant);
        [, $casoEncerrado] = $this->semearGrafo($tenant, ['status' => StatusCaso::Encerrado]);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $casoAtivo->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'registrar_obrigacao');

        $client->request('POST', '/cobrancas/casos/' . $casoEncerrado->getId() . '/obrigacoes', [
            'registrar_obrigacao' => [
                'descricao' => 'NAO DEVE PERSISTIR XYZ',
                'valorOriginal' => '100,00',
                'vencimentoOriginal' => '2026-08-10',
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $casoEncerrado->getObjeto()->getId() . '#secao-divida');
        $client->followRedirect();
        self::assertStringNotContainsString('NAO DEVE PERSISTIR XYZ', (string) $client->getResponse()->getContent());
    }

    #[TestDox('Registrar obrigação sem a capacidade: negado (redirect, não caso)')]
    public function testRegistrarObrigacaoSemCapacidade(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarOperadorSemCapacidade($client);
        [, $caso] = $this->semearGrafo($tenant);
        $casoId = (int) $caso->getId();

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/obrigacoes', [
            'registrar_obrigacao' => ['descricao' => 'X', 'valorOriginal' => '10,00', 'vencimentoOriginal' => '2026-08-10', '_token' => 'irrelevante'],
        ]);

        self::assertResponseRedirects();
        self::assertStringNotContainsString('/cobrancas/casos/' . $casoId, (string) $client->getResponse()->headers->get('Location'), 'a capacidade nega antes do CSRF; deve ir para a homepage, não para o caso');
    }

    #[TestDox('IDOR: registrar obrigação em caso de OUTRO tenant devolve 404')]
    public function testRegistrarObrigacaoCrossTenant404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        [, $casoAlheio] = $this->semearGrafo($this->tenantAvulso());

        $client->request('POST', '/cobrancas/casos/' . $casoAlheio->getId() . '/obrigacoes', [
            'registrar_obrigacao' => ['descricao' => 'X', 'valorOriginal' => '10,00', 'vencimentoOriginal' => '2026-08-10', '_token' => 'irrelevante'],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('CSRF inválido: registrar obrigação não persiste')]
    public function testRegistrarObrigacaoCsrfInvalido(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $casoId = (int) $caso->getId();

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/obrigacoes', [
            'registrar_obrigacao' => ['descricao' => 'MARCADOR CSRF RUIM', 'valorOriginal' => '10,00', 'vencimentoOriginal' => '2026-08-10', '_token' => 'token-falso'],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId() . '#secao-divida');
        $client->followRedirect();
        self::assertStringNotContainsString('MARCADOR CSRF RUIM', (string) $client->getResponse()->getContent());
    }

    #[TestDox('Editar obrigação: happy path corrige os campos e volta ao objeto')]
    public function testEditarObrigacaoHappy(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $obrigacao = ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'valorOriginal' => 100000, 'encargosReconhecidos' => 0,
        ])->_real();
        $obrigacaoId = (int) $obrigacao->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'editar_obrigacao');

        $client->request('POST', '/cobrancas/obrigacoes/' . $obrigacaoId . '/editar', [
            'editar_obrigacao' => [
                'descricao' => 'Boleto corrigido ABC',
                'valorOriginal' => '1.200,00',
                'vencimentoOriginal' => '2026-09-01',
                'referenciaExterna' => 'REF-9',
                'encargosReconhecidos' => '250,00',
                'motivo' => 'Valor digitado errado na importação',
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId() . '#secao-divida');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $fresh = $em->find(Obrigacao::class, $obrigacaoId);
        self::assertSame('Boleto corrigido ABC', $fresh->getDescricao());
        self::assertSame(120000, $fresh->getValorOriginal(), 'valor original corrigido para R$1.200,00');
        self::assertSame(25000, $fresh->getEncargosReconhecidos(), 'encargos corrigidos para R$250,00');
        self::assertSame('2026-09-01', $fresh->getVencimentoOriginal()->format('Y-m-d'));
    }

    #[TestDox('Editar obrigação sem a capacidade: negado (redirect, não caso)')]
    public function testEditarSemCapacidade(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarOperadorSemCapacidade($client);
        [, $caso] = $this->semearGrafo($tenant);
        $obrigacao = ObrigacaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso])->_real();

        $client->request('POST', '/cobrancas/obrigacoes/' . $obrigacao->getId() . '/editar', [
            'editar_obrigacao' => ['descricao' => 'X', 'valorOriginal' => '10,00', 'vencimentoOriginal' => '2026-09-01', 'motivo' => 'x', '_token' => 'irrelevante'],
        ]);

        self::assertResponseRedirects();
        self::assertStringNotContainsString('/cobrancas/casos/', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('IDOR: editar obrigação de OUTRO tenant devolve 404')]
    public function testEditarCrossTenant404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        [, $casoAlheio] = $this->semearGrafo($this->tenantAvulso());
        $obrigacaoAlheia = ObrigacaoFactory::createOne([
            'tenant' => $casoAlheio->getTenant(),
            'caso' => $casoAlheio,
        ])->_real();

        $client->request('POST', '/cobrancas/obrigacoes/' . $obrigacaoAlheia->getId() . '/editar', [
            'editar_obrigacao' => ['descricao' => 'X', 'valorOriginal' => '10,00', 'vencimentoOriginal' => '2026-09-01', 'motivo' => 'x', '_token' => 'irrelevante'],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('CSRF inválido: editar obrigação não altera')]
    public function testEditarCsrfInvalido(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $obrigacao = ObrigacaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'valorOriginal' => 100000, 'descricao' => 'Original XYZ'])->_real();
        $obrigacaoId = (int) $obrigacao->getId();

        $client->request('POST', '/cobrancas/obrigacoes/' . $obrigacaoId . '/editar', [
            'editar_obrigacao' => ['descricao' => 'NAO DEVE MUDAR', 'valorOriginal' => '1,00', 'vencimentoOriginal' => '2026-09-01', 'motivo' => 'x', '_token' => 'token-falso'],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId() . '#secao-divida');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame('Original XYZ', $em->find(Obrigacao::class, $obrigacaoId)->getDescricao(), 'CSRF inválido não altera a obrigação');
    }

    #[TestDox('Excluir obrigação: happy path remove a linha e volta ao objeto')]
    public function testExcluirObrigacaoHappy(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $obrigacao = ObrigacaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso])->_real();
        $obrigacaoId = (int) $obrigacao->getId();

        // O token CSRF (manual, por obrigação) vem do botão excluir da linha.
        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $token = $crawler->filter('button[data-acao-url="/cobrancas/obrigacoes/' . $obrigacaoId . '/excluir"]')->attr('data-token');

        $client->request('POST', '/cobrancas/obrigacoes/' . $obrigacaoId . '/excluir', [
            'motivo' => 'Lançada em duplicidade',
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId() . '#secao-divida');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertNull($em->find(Obrigacao::class, $obrigacaoId), 'a obrigação foi removida');
    }

    #[TestDox('Excluir sem a capacidade: negado (redirect, não caso), obrigação intacta')]
    public function testExcluirSemCapacidade(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarOperadorSemCapacidade($client);
        [, $caso] = $this->semearGrafo($tenant);
        $obrigacao = ObrigacaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso])->_real();
        $obrigacaoId = (int) $obrigacao->getId();

        $client->request('POST', '/cobrancas/obrigacoes/' . $obrigacaoId . '/excluir', [
            'motivo' => 'x', '_token' => 'irrelevante',
        ]);

        self::assertResponseRedirects();
        self::assertStringNotContainsString('/cobrancas/casos/', (string) $client->getResponse()->headers->get('Location'));

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertNotNull($em->find(Obrigacao::class, $obrigacaoId), 'sem capacidade não exclui');
    }

    #[TestDox('IDOR: excluir obrigação de OUTRO tenant devolve 404')]
    public function testExcluirCrossTenant404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        [, $casoAlheio] = $this->semearGrafo($this->tenantAvulso());
        $obrigacaoAlheia = ObrigacaoFactory::createOne(['tenant' => $casoAlheio->getTenant(), 'caso' => $casoAlheio])->_real();

        $client->request('POST', '/cobrancas/obrigacoes/' . $obrigacaoAlheia->getId() . '/excluir', [
            'motivo' => 'x', '_token' => 'irrelevante',
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('CSRF inválido: excluir obrigação não remove')]
    public function testExcluirCsrfInvalido(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $obrigacao = ObrigacaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso])->_real();
        $obrigacaoId = (int) $obrigacao->getId();

        $client->request('POST', '/cobrancas/obrigacoes/' . $obrigacaoId . '/excluir', [
            'motivo' => 'x', '_token' => 'token-falso',
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId() . '#secao-divida');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertNotNull($em->find(Obrigacao::class, $obrigacaoId), 'CSRF inválido não remove a obrigação');
    }

    #[TestDox('Excluir obrigação com pagamento alocado: bloqueado no servidor, não remove')]
    public function testExcluirComPagamentoAlocadoBloqueia(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $obrigacao = ObrigacaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'valorOriginal' => 20000, 'encargosReconhecidos' => 0])->_real();
        $obrigacaoId = (int) $obrigacao->getId();
        // Pagamento com alocação nesta obrigação → guard de exclusão barra.
        $pagamento = PagamentoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'valorDivida' => 10000, 'valorHonorarios' => 0])->_real();
        AlocacaoPagamentoFactory::createOne(['tenant' => $tenant, 'pagamento' => $pagamento, 'obrigacao' => $obrigacao, 'valor' => 10000]);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $token = $crawler->filter('button[data-acao-url="/cobrancas/obrigacoes/' . $obrigacaoId . '/excluir"]')->attr('data-token');

        $client->request('POST', '/cobrancas/obrigacoes/' . $obrigacaoId . '/excluir', [
            'motivo' => 'tentando excluir', '_token' => $token,
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId() . '#secao-divida');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertNotNull($em->find(Obrigacao::class, $obrigacaoId), 'obrigação com pagamento alocado não é excluída');
    }

    #[TestDox('Excluir obrigação sem motivo: rejeitado, não remove')]
    public function testExcluirSemMotivo(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $obrigacao = ObrigacaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso])->_real();
        $obrigacaoId = (int) $obrigacao->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $token = $crawler->filter('button[data-acao-url="/cobrancas/obrigacoes/' . $obrigacaoId . '/excluir"]')->attr('data-token');

        $client->request('POST', '/cobrancas/obrigacoes/' . $obrigacaoId . '/excluir', [
            'motivo' => '   ', '_token' => $token,
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId() . '#secao-divida');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertNotNull($em->find(Obrigacao::class, $obrigacaoId), 'sem motivo não exclui');
    }
}
