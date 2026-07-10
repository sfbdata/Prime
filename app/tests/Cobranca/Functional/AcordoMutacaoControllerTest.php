<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\AcordoController;
use App\Cobranca\Entity\Acordo;
use App\Cobranca\Enum\StatusAcordo;
use App\Cobranca\Repository\AcordoRepository;
use App\Cobranca\Entity\CasoCobranca;
use App\Tests\Factory\Cobranca\AcordoFactory;
use App\Tests\Factory\Cobranca\ObrigacaoFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Mutações de Acordos (Onda 8B): criar (substitui obrigações + parcelas), romper, cancelar, cumprir.
 * Gate módulo + capacidade `resources.cobranca.gerenciar`, CSRF (Form e manual no cumprir), anti-IDOR
 * (404), erro de domínio (acordo não ativo) e happy path com persistência.
 */
#[CoversClass(AcordoController::class)]
final class AcordoMutacaoControllerTest extends CobrancaWebTestCase
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    #[TestDox('Criar acordo: happy path cria o acordo substituindo a obrigação')]
    public function testCriarHappy(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $obrigacao = ObrigacaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'valorOriginal' => 100000, 'encargosReconhecidos' => 0])->_real();
        $casoId = (int) $caso->getId();

        $crawler = $client->request('GET', '/cobrancas/casos/' . $casoId);
        $token = $this->tokenDoFormulario($crawler, 'acordo_criar');

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/acordos', [
            'acordo_criar' => [
                'dataAcordo' => '2026-08-01',
                'obrigacoesSubstituidasIds' => [(string) $obrigacao->getId()],
                'parcelas' => [
                    ['descricao' => 'Parcela 1/1', 'valor' => '500,00', 'vencimento' => '2026-09-01'],
                ],
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/casos/' . $casoId);
        $this->em()->clear();
        $casoFresh = $this->em()->find(CasoCobranca::class, $casoId);
        $acordos = static::getContainer()->get(AcordoRepository::class)->doCaso($casoFresh);
        self::assertCount(1, $acordos, 'o acordo deve ter sido criado');
    }

    #[TestDox('Criar acordo sem capacidade: negado')]
    public function testCriarSemCapacidade(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarOperadorSemCapacidade($client);
        [, $caso] = $this->semearGrafo($tenant);
        $casoId = (int) $caso->getId();

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/acordos', [
            'acordo_criar' => ['dataAcordo' => '2026-08-01', '_token' => 'irrelevante'],
        ]);

        self::assertResponseRedirects();
        self::assertStringNotContainsString('/cobrancas/casos/' . $casoId, (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('IDOR: criar acordo em caso de outro tenant → 404')]
    public function testCriarCrossTenant404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        [, $casoAlheio] = $this->semearGrafo($this->tenantAvulso());

        $client->request('POST', '/cobrancas/casos/' . $casoAlheio->getId() . '/acordos', [
            'acordo_criar' => ['dataAcordo' => '2026-08-01', '_token' => 'irrelevante'],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Criar acordo com CSRF inválido: não cria')]
    public function testCriarCsrfInvalido(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $obrigacao = ObrigacaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'valorOriginal' => 100000])->_real();
        $casoId = (int) $caso->getId();

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/acordos', [
            'acordo_criar' => [
                'dataAcordo' => '2026-08-01',
                'obrigacoesSubstituidasIds' => [(string) $obrigacao->getId()],
                'parcelas' => [['descricao' => 'P', 'valor' => '500,00', 'vencimento' => '2026-09-01']],
                '_token' => 'falso',
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/casos/' . $casoId);
        $this->em()->clear();
        $casoFresh = $this->em()->find(CasoCobranca::class, $casoId);
        self::assertCount(0, static::getContainer()->get(AcordoRepository::class)->doCaso($casoFresh));
    }

    #[TestDox('Romper acordo ativo: happy path muda o status')]
    public function testRomperHappy(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $acordo = AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'status' => StatusAcordo::Ativo])->_real();
        $acordoId = (int) $acordo->getId();

        $crawler = $client->request('GET', '/cobrancas/casos/' . $caso->getId());
        $token = $this->tokenDoFormulario($crawler, 'romper_acordo');

        $client->request('POST', '/cobrancas/acordos/' . $acordoId . '/romper', [
            'romper_acordo' => ['motivo' => 'Parcelas em atraso', '_token' => $token],
        ]);

        self::assertResponseRedirects('/cobrancas/casos/' . $caso->getId());
        $this->em()->clear();
        self::assertSame(StatusAcordo::Rompido, $this->em()->find(Acordo::class, $acordoId)->getStatus());
    }

    #[TestDox('Romper acordo não ativo: erro de domínio, status inalterado')]
    public function testRomperNaoAtivo(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $acordo = AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'status' => StatusAcordo::Cancelado])->_real();
        $acordoId = (int) $acordo->getId();

        $crawler = $client->request('GET', '/cobrancas/casos/' . $caso->getId());
        $token = $this->tokenDoFormulario($crawler, 'romper_acordo');

        $client->request('POST', '/cobrancas/acordos/' . $acordoId . '/romper', [
            'romper_acordo' => ['motivo' => 'X', '_token' => $token],
        ]);

        self::assertResponseRedirects('/cobrancas/casos/' . $caso->getId());
        $this->em()->clear();
        self::assertSame(StatusAcordo::Cancelado, $this->em()->find(Acordo::class, $acordoId)->getStatus());
    }

    #[TestDox('IDOR: romper acordo de outro tenant → 404')]
    public function testRomperCrossTenant404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        [, $casoAlheio] = $this->semearGrafo($this->tenantAvulso());
        $acordoAlheio = AcordoFactory::createOne(['tenant' => $casoAlheio->getTenant(), 'caso' => $casoAlheio])->_real();

        $client->request('POST', '/cobrancas/acordos/' . $acordoAlheio->getId() . '/romper', [
            'romper_acordo' => ['motivo' => 'X', '_token' => 'irrelevante'],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Cancelar acordo ativo: happy path muda o status')]
    public function testCancelarHappy(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $acordo = AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'status' => StatusAcordo::Ativo])->_real();
        $acordoId = (int) $acordo->getId();

        $crawler = $client->request('GET', '/cobrancas/casos/' . $caso->getId());
        $token = $this->tokenDoFormulario($crawler, 'cancelar_acordo');

        $client->request('POST', '/cobrancas/acordos/' . $acordoId . '/cancelar', [
            'cancelar_acordo' => ['motivo' => 'Erro de lançamento', '_token' => $token],
        ]);

        self::assertResponseRedirects('/cobrancas/casos/' . $caso->getId());
        $this->em()->clear();
        self::assertSame(StatusAcordo::Cancelado, $this->em()->find(Acordo::class, $acordoId)->getStatus());
    }

    #[TestDox('Cancelar acordo sem capacidade: negado, status inalterado')]
    public function testCancelarSemCapacidade(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarOperadorSemCapacidade($client);
        [, $caso] = $this->semearGrafo($tenant);
        $acordo = AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'status' => StatusAcordo::Ativo])->_real();
        $acordoId = (int) $acordo->getId();

        $client->request('POST', '/cobrancas/acordos/' . $acordoId . '/cancelar', [
            'cancelar_acordo' => ['motivo' => 'X', '_token' => 'irrelevante'],
        ]);

        self::assertResponseRedirects();
        self::assertStringNotContainsString('/cobrancas/casos/', (string) $client->getResponse()->headers->get('Location'));
        $this->em()->clear();
        self::assertSame(StatusAcordo::Ativo, $this->em()->find(Acordo::class, $acordoId)->getStatus());
    }

    #[TestDox('IDOR: cancelar acordo de outro tenant → 404')]
    public function testCancelarCrossTenant404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        [, $casoAlheio] = $this->semearGrafo($this->tenantAvulso());
        $acordoAlheio = AcordoFactory::createOne(['tenant' => $casoAlheio->getTenant(), 'caso' => $casoAlheio, 'status' => StatusAcordo::Ativo])->_real();

        $client->request('POST', '/cobrancas/acordos/' . $acordoAlheio->getId() . '/cancelar', [
            'cancelar_acordo' => ['motivo' => 'X', '_token' => 'irrelevante'],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Cancelar acordo com CSRF inválido: status inalterado')]
    public function testCancelarCsrfInvalido(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $acordo = AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'status' => StatusAcordo::Ativo])->_real();
        $acordoId = (int) $acordo->getId();

        $client->request('POST', '/cobrancas/acordos/' . $acordoId . '/cancelar', [
            'cancelar_acordo' => ['motivo' => 'X', '_token' => 'falso'],
        ]);

        self::assertResponseRedirects('/cobrancas/casos/' . $caso->getId());
        $this->em()->clear();
        self::assertSame(StatusAcordo::Ativo, $this->em()->find(Acordo::class, $acordoId)->getStatus());
    }

    #[TestDox('Cumprir acordo: happy path (CSRF manual do modal inline)')]
    public function testCumprirHappy(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $acordo = AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'status' => StatusAcordo::Ativo])->_real();
        $acordoId = (int) $acordo->getId();

        $crawler = $client->request('GET', '/cobrancas/casos/' . $caso->getId());
        $token = (string) $crawler->filter('#modalCumprirAcordo-' . $acordoId . ' input[name="_token"]')->attr('value');

        $client->request('POST', '/cobrancas/acordos/' . $acordoId . '/cumprir', ['_token' => $token]);

        self::assertResponseRedirects('/cobrancas/casos/' . $caso->getId());
        $this->em()->clear();
        self::assertSame(StatusAcordo::Cumprido, $this->em()->find(Acordo::class, $acordoId)->getStatus());
    }

    #[TestDox('Cumprir acordo com CSRF inválido: não muda o status')]
    public function testCumprirCsrfInvalido(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $acordo = AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'status' => StatusAcordo::Ativo])->_real();
        $acordoId = (int) $acordo->getId();

        $client->request('POST', '/cobrancas/acordos/' . $acordoId . '/cumprir', ['_token' => 'falso']);

        self::assertResponseRedirects('/cobrancas/casos/' . $caso->getId());
        $this->em()->clear();
        self::assertSame(StatusAcordo::Ativo, $this->em()->find(Acordo::class, $acordoId)->getStatus());
    }
}
