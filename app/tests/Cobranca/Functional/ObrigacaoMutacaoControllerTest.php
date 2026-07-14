<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\ObrigacaoController;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\StatusCaso;
use App\Tests\Factory\Cobranca\ObrigacaoFactory;
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

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId());
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

        self::assertResponseRedirects('/cobrancas/objetos/' . $casoEncerrado->getObjeto()->getId());
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

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $client->followRedirect();
        self::assertStringNotContainsString('MARCADOR CSRF RUIM', (string) $client->getResponse()->getContent());
    }

    #[TestDox('Reconhecer valor: happy path atualiza os encargos, preserva o original')]
    public function testReconhecerValorHappy(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $obrigacao = ObrigacaoFactory::createOne([
            'tenant' => $tenant,
            'caso' => $caso,
            'valorOriginal' => 100000,
            'encargosReconhecidos' => 0,
        ])->_real();
        $obrigacaoId = (int) $obrigacao->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'reconhecer_valor_atualizado');

        $client->request('POST', '/cobrancas/obrigacoes/' . $obrigacaoId . '/reconhecer-valor', [
            'reconhecer_valor_atualizado' => ['encargosReconhecidos' => '250,00', 'motivo' => 'Juros e multa', '_token' => $token],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $fresh = $em->find(Obrigacao::class, $obrigacaoId);
        self::assertSame(25000, $fresh->getEncargosReconhecidos(), 'encargos reconhecidos atualizados para R$250,00');
        self::assertSame(100000, $fresh->getValorOriginal(), 'valor original preservado (invariável 20)');
    }

    #[TestDox('IDOR: reconhecer valor de obrigação de OUTRO tenant devolve 404')]
    public function testReconhecerValorCrossTenant404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        [, $casoAlheio] = $this->semearGrafo($this->tenantAvulso());
        $obrigacaoAlheia = ObrigacaoFactory::createOne([
            'tenant' => $casoAlheio->getTenant(),
            'caso' => $casoAlheio,
        ])->_real();

        $client->request('POST', '/cobrancas/obrigacoes/' . $obrigacaoAlheia->getId() . '/reconhecer-valor', [
            'reconhecer_valor_atualizado' => ['encargosReconhecidos' => '10,00', '_token' => 'irrelevante'],
        ]);

        self::assertResponseStatusCodeSame(404);
    }
}
