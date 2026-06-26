<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Pasta\Controller\PecaImagemController;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * C5.1 — a imagem do editor de peças (`/uploads/pastas/<nome>`), antes servida ESTÁTICA pelo nginx
 * (sem auth), passa a ser entregue pelo PHP via PecaImagemController. O firewall (^/ ROLE_USER) barra
 * o anônimo; o usuário autenticado recebe a imagem. Documentos (.pdf/.html) NÃO casam a rota (têm
 * rotas próprias por entidade). O comportamento do nginx em si é validado por `nginx -t` + smoke.
 */
#[CoversClass(PecaImagemController::class)]
final class PecaImagemControllerTest extends JusPrimeWebTestCase
{
    private ?string $arquivoTemp = null;

    protected function tearDown(): void
    {
        if ($this->arquivoTemp !== null && is_file($this->arquivoTemp)) {
            @unlink($this->arquivoTemp);
        }
        $this->arquivoTemp = null;
        parent::tearDown();
    }

    #[TestDox('Anônimo é bloqueado pelo firewall (redirect p/ login), nunca recebe o arquivo')]
    public function testAnonimoBloqueado(): void
    {
        $client = static::createClient();
        $client->request('GET', '/uploads/pastas/qualquer.jpg');

        self::assertResponseStatusCodeSame(302, 'sem login o firewall deve redirecionar, não servir');
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('Autenticado recebe a imagem existente (200, inline)')]
    public function testAutenticadoServeImagem(): void
    {
        $client = static::createClient();
        $nome   = $this->criarArquivoTemp('.png');

        $this->logarUsuario($client);
        $client->request('GET', '/uploads/pastas/' . $nome);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('inline', (string) $client->getResponse()->headers->get('Content-Disposition'));
    }

    #[TestDox('Autenticado + arquivo inexistente retorna 404')]
    public function testAutenticadoInexistente404(): void
    {
        $client = static::createClient();
        $this->logarUsuario($client);

        $client->request('GET', '/uploads/pastas/naoexiste_' . uniqid() . '.jpg');

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Extensão não-imagem (.pdf) não casa a rota — documentos não são servidos por aqui')]
    public function testExtensaoNaoImagemNaoCasa(): void
    {
        // Trava a garantia de segurança: só imagens passam. Documentos/peças (.pdf/.html/.docx)
        // têm rotas próprias por entidade (com tenant/posse). Mesmo logado e com o arquivo no
        // disco, a requirement da rota rejeita a extensão → 404 (rota não encontrada).
        $client = static::createClient();
        $nome   = $this->criarArquivoTemp('.pdf');

        $this->logarUsuario($client);
        $client->request('GET', '/uploads/pastas/' . $nome);

        self::assertResponseStatusCodeSame(404, 'extensão não-imagem não pode ser servida pela rota de imagem');
    }

    // ----------------------------------------------------------------- helpers

    private function logarUsuario(KernelBrowser $client): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant PecaImg ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('peca_img_' . uniqid() . '@test.com');
        $user->setFullName('Usuário Peça Imagem');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('dummy_hash');
        $em->persist($user);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Papel ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        $userTenant = new UserTenant($user, $tenant);
        $userTenant->setTenantRole($role);
        $em->persist($userTenant);
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
    }

    private function criarArquivoTemp(string $ext): string
    {
        $dir = rtrim((string) static::getContainer()->getParameter('uploads_dir'), '/');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $nome              = 'testfile_' . uniqid() . $ext;
        $this->arquivoTemp = $dir . '/' . $nome;
        file_put_contents($this->arquivoTemp, 'conteudo-de-teste');

        return $nome;
    }
}
