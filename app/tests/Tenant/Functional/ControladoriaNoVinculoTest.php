<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Functional;

use App\Controller\TenantController;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Form\EditUserTenantRoleType;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\CriaEscritorioSemProcessoTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Marcação "Controladoria" na edição do vínculo (spec relatorio-processos-controladoria, D1): mesma
 * tela e mesma guarda do edit-role, rótulo e ajuda da spec, gravada em `user_tenant.controladoria`.
 */
#[CoversClass(TenantController::class)]
#[CoversClass(EditUserTenantRoleType::class)]
final class ControladoriaNoVinculoTest extends JusPrimeWebTestCase
{
    use CriaEscritorioSemProcessoTrait;

    private KernelBrowser $client;
    private Tenant $tenant;
    private TenantRole $secundario;
    private User $paula;
    private User $carla;

    protected function setUp(): void
    {
        $this->client     = static::createClient();
        $this->tenant     = $this->novoEscritorio();
        $this->secundario = $this->novoPerfil($this->tenant, false);
        $this->paula      = $this->novaPessoa($this->tenant, 'Paula Primária', $this->novoPerfil($this->tenant, true));
        $this->carla      = $this->novaPessoa($this->tenant, 'Carla', $this->secundario);
    }

    private function url(): string
    {
        return sprintf('/tenant/%d/user/%d/edit-role', $this->tenant->getId(), $this->carla->getId());
    }

    private function controladoriaNoBanco(): bool
    {
        return (bool) $this->em()->getConnection()->fetchOne(
            'SELECT controladoria FROM user_tenant WHERE user_id = ? AND tenant_id = ?',
            [$this->carla->getId(), $this->tenant->getId()],
        );
    }

    /** POST direto: o CSRF do form é stateless (valor "csrf-token" + Referer da mesma origem). */
    private function postar(bool $controladoria): void
    {
        $campos = [
            'codigoFuncionario' => '',
            'tenantRole'        => (string) $this->secundario->getId(),
            'newPassword'       => ['first' => '', 'second' => ''],
            '_token'            => 'csrf-token',
        ];
        if ($controladoria) {
            $campos['controladoria'] = '1';
        }
        $this->client->request('POST', $this->url(), ['edit_user_tenant_role' => $campos], [], ['HTTP_REFERER' => 'http://localhost' . $this->url()]);
    }

    #[TestDox('A tela mostra o interruptor "Controladoria" com a ajuda da spec, desligado por padrão')]
    public function testTelaMostraOCampo(): void
    {
        $this->logarComTenant($this->client, $this->paula, $this->tenant);
        $crawler = $this->client->request('GET', $this->url());

        self::assertResponseIsSuccessful();
        $campo = $crawler->filter('#tab-perfil .form-check.form-switch > input[type="checkbox"][name="edit_user_tenant_role[controladoria]"]');
        self::assertCount(1, $campo);
        self::assertNull($campo->attr('checked'));
        self::assertSame('Controladoria', trim($crawler->filter('#tab-perfil .form-check.form-switch > label')->text()));
        self::assertStringContainsString('Recebe o relatório mensal de pastas sem processo e a cópia dos avisos à Controladoria.', $crawler->filter('#tab-perfil')->text());
    }

    #[TestDox('Marcar grava true; enviar sem a marcação grava false')]
    public function testMarcaEDesmarca(): void
    {
        $this->logarComTenant($this->client, $this->paula, $this->tenant);
        $this->postar(true);
        self::assertResponseRedirects();
        self::assertTrue($this->controladoriaNoBanco());

        $this->postar(false);
        self::assertResponseRedirects();
        self::assertFalse($this->controladoriaNoBanco());
    }

    #[TestDox('Quem não administra usuários não marca: 403 e nada muda')]
    public function testSemPermissao403(): void
    {
        $this->logarComTenant($this->client, $this->carla, $this->tenant);
        $this->postar(true);

        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->controladoriaNoBanco());
    }
}
