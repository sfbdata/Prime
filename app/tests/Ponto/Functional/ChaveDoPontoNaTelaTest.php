<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Controller\TenantController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Ponto\Controller\JornadaColaboradorController;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Ponto\Support\CenarioDeJornadaPorEscritorioTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * A chave "Registra ponto eletrônico" na página do colaborador no escritório (spec seguranca-b-jornada §3.4 e §3.5): o
 * interruptor fica na aba de jornada, com o estado do vínculo deste escritório, e a rota tem a guarda da jornada.
 */
#[CoversClass(TenantController::class)]
#[CoversClass(JornadaColaboradorController::class)]
final class ChaveDoPontoNaTelaTest extends JusPrimeWebTestCase
{
    use CenarioDeJornadaPorEscritorioTrait;

    private KernelBrowser $client;
    private Tenant $a;
    private Tenant $b;
    private User $pessoa;
    private UserTenant $vinculoEmA;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->instalarCsrfFalso();
        $this->a = $this->escritorio('Escritório A');
        $this->b = $this->escritorio('Escritório B');
        $this->pessoa = $this->pessoa('Pessoa nos dois');
        $this->vinculoEmA = $this->vincular($this->pessoa, $this->a);
        $this->vincular($this->pessoa, $this->b, ['modules.ponto.view'], false, false);
    }

    #[TestDox('A aba de jornada mostra o interruptor com o estado da chave neste escritório')]
    public function testInterruptorMostraAChaveDoEscritorio(): void
    {
        $this->entrarComoAdminDe($this->a);

        $ligada = $this->client->request('GET', $this->urlDaPagina($this->a));
        self::assertResponseIsSuccessful();
        $interruptor = $ligada->filter('#tab-escala .form-switch > input#registraPontoChave');
        self::assertCount(1, $interruptor);
        self::assertNotNull($interruptor->attr('checked'), 'em A a chave está ligada');
        self::assertSame('Registra ponto eletrônico', trim($ligada->filter('#tab-escala .form-switch > label')->text()));

        $this->entrarComoAdminDe($this->b);
        $desligada = $this->client->request('GET', $this->urlDaPagina($this->b));
        self::assertNull($desligada->filter('#registraPontoChave')->attr('checked'), 'em B a chave está desligada');
    }

    #[TestDox('O admin de A não liga a chave da pessoa em B pela URL de B (403), nem de quem não tem vínculo em A (404)')]
    public function testGuardaDaChave(): void
    {
        $this->entrarComoAdminDe($this->a);

        $this->chamarJson($this->client, 'POST', $this->urlDaChave($this->b, $this->pessoa), ['registraPonto' => true]);
        self::assertResponseStatusCodeSame(403);

        $deFora = $this->pessoa('Sem vínculo em A');
        $this->vincular($deFora, $this->b);
        $this->chamarJson($this->client, 'POST', $this->urlDaChave($this->a, $deFora), ['registraPonto' => false]);
        self::assertResponseStatusCodeSame(404);

        self::assertFalse((bool) $this->em()->getConnection()->fetchOne(
            'SELECT registra_ponto FROM user_tenant WHERE user_id = ? AND tenant_id = ?',
            [$this->pessoa->getId(), $this->b->getId()],
        ));
    }

    #[TestDox('Desligar a chave grava o vínculo deste escritório e só ele')]
    public function testDesligarGravaSoNesteEscritorio(): void
    {
        $this->entrarComoAdminDe($this->a);

        $resposta = $this->chamarJson($this->client, 'POST', $this->urlDaChave($this->a, $this->pessoa), ['registraPonto' => false]);

        self::assertSame(['ok' => true, 'registraPonto' => false], $resposta);
        self::assertFalse((bool) $this->em()->getConnection()->fetchOne(
            'SELECT registra_ponto FROM user_tenant WHERE id = ?',
            [$this->vinculoEmA->getId()],
        ));
    }

    private function entrarComoAdminDe(Tenant $tenant): void
    {
        $admin = $this->pessoa('Admin');
        $this->vincular($admin, $tenant, [], true);
        $this->logarComTenant($this->client, $admin, $tenant);
    }

    private function urlDaPagina(Tenant $tenant): string
    {
        return sprintf('/tenant/%d/user/%d/edit-role', $tenant->getId(), $this->pessoa->getId());
    }

    private function urlDaChave(Tenant $tenant, User $pessoa): string
    {
        return sprintf('/tenant/%d/user/%d/registra-ponto', $tenant->getId(), $pessoa->getId());
    }
}
