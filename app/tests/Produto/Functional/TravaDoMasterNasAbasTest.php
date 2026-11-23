<?php

declare(strict_types=1);

namespace App\Tests\Produto\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\UseCase\AlterarIaDoEscritorioUseCase;
use App\Master\Controller\IaDoMasterController;
use App\Master\Controller\VotoDoMasterController;
use App\Produto\Enum\Produto;
use App\Produto\Service\ContratacoesDoEscritorio;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Voto\UseCase\AlterarLiberacaoDoVotoUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Zenstruck\Foundry\Test\Factories;

/**
 * Trava do Master (spec docs/specs/multiproduto-contratacao-e-voto.md, D16) pelas abas de hoje do painel: bloquear o Voto
 * ou a I.A. de um escritório encerra a contratação com origem `master`, e o dono não religa pelo Meu Perfil (422);
 * liberar pela mesma aba desfaz a trava.
 */
#[CoversClass(AlterarLiberacaoDoVotoUseCase::class)]
#[CoversClass(AlterarIaDoEscritorioUseCase::class)]
#[CoversClass(ContratacoesDoEscritorio::class)]
final class TravaDoMasterNasAbasTest extends JusPrimeWebTestCase
{
    use Factories;
    use ComEscritoriosPorProduto;
    use ComFerramentasNoPerfil;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->instalarCsrfFixo();
    }

    #[TestDox('aba Voto: bloquear trava (o dono leva 422 ao ligar); liberar destrava (o dono liga)')]
    public function testAbaVoto(): void
    {
        $tenant = $this->escritorio();
        $dono = $this->dono($tenant);
        $master = $this->master();

        $this->comoMaster($master);
        $this->postarNoMaster('/master/voto/escritorios/' . $tenant->getId(), ['liberado' => false], VotoDoMasterController::TOKEN);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->trava()->travadoPeloMaster($this->tenantDoBanco($tenant), Produto::Voto));
        self::assertNotContains('voto', $this->ativosDo($tenant), 'o bloqueio materializou as outras e deixou o Voto de fora');

        $this->logarComTenant($this->client, $dono, $tenant);
        $this->postarFerramenta('voto', ['ligar' => true]);
        self::assertResponseStatusCodeSame(422);

        $this->comoMaster($master);
        $this->postarNoMaster('/master/voto/escritorios/' . $tenant->getId(), ['liberado' => true], VotoDoMasterController::TOKEN);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->trava()->travadoPeloMaster($this->tenantDoBanco($tenant), Produto::Voto));
        self::assertContains('voto', $this->ativosDo($tenant));

        $this->logarComTenant($this->client, $dono, $tenant);
        $this->postarFerramenta('voto', ['ligar' => true]);
        self::assertResponseIsSuccessful();
    }

    #[TestDox('aba I.A.: bloquear trava (o dono leva 422 ao ligar); liberar destrava; trocar só o teto com a I.A. incluída não cria linha')]
    public function testAbaIa(): void
    {
        $tenant = $this->escritorio(Produto::Expediente, Produto::Ia);
        $dono = $this->dono($tenant);
        $master = $this->master();
        $url = '/master/ia/escritorios/' . $tenant->getId();

        $this->comoMaster($master);
        $this->postarNoMaster($url, ['liberada' => true, 'modoDeCobranca' => 'incluso', 'orcamento' => '7'], IaDoMasterController::TOKEN);
        self::assertResponseIsSuccessful();
        self::assertCount(2, $this->linhasDo($tenant), 'a I.A. já estava incluída: nenhuma linha nova');

        $this->postarNoMaster($url, ['liberada' => false, 'modoDeCobranca' => 'incluso', 'orcamento' => '7'], IaDoMasterController::TOKEN);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->trava()->travadoPeloMaster($this->tenantDoBanco($tenant), Produto::Ia));
        self::assertSame(['expediente'], $this->ativosDo($tenant));

        $this->logarComTenant($this->client, $dono, $tenant);
        $this->postarFerramenta('ia', ['ligar' => true]);
        self::assertResponseStatusCodeSame(422);

        $this->comoMaster($master);
        $this->postarNoMaster($url, ['liberada' => true, 'modoDeCobranca' => 'incluso', 'orcamento' => '7'], IaDoMasterController::TOKEN);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->trava()->travadoPeloMaster($this->tenantDoBanco($tenant), Produto::Ia));
        self::assertSame(['expediente', 'ia'], $this->ativosDo($tenant));
    }

    private function master(): User
    {
        return UserFactory::createOne(['fullName' => 'Master da trava', 'roles' => ['ROLE_SUPER_ADMIN', 'ROLE_MASTER_PRIMARIO']])->_real();
    }

    /** Entra como Master sem escritório na sessão (a sessão anterior pode ter o do dono). */
    private function comoMaster(User $master): void
    {
        $this->client->loginUser($master);
        $sessao = $this->client->getSession();
        $sessao->remove('current_tenant_id');
        $sessao->save();
        $this->marcarTermosAceitos($this->client);
    }

    /** @param array<string, mixed> $corpo */
    private function postarNoMaster(string $url, array $corpo, string $token): void
    {
        $this->client->request('POST', $url, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => 'TOKEN_' . $token,
        ], content: (string) json_encode($corpo));
    }

    private function trava(): ContratacoesDoEscritorio
    {
        return static::getContainer()->get(ContratacoesDoEscritorio::class);
    }

    private function tenantDoBanco(Tenant $tenant): Tenant
    {
        $this->em()->clear();

        return $this->em()->getReference(Tenant::class, $tenant->getId());
    }
}
