<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Pasta\Controller\PeticionarController;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoriaNoContainer;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * `POST /pasta/{id}/peticionar/upload` (o soltar arquivos e o "Adicionar peça" do Peticionar) por
 * quem não pode escrever: quem só vê a pasta, e quem edita mas a pasta está excluída (lápide).
 * Nos dois casos a recusa vem antes de qualquer gravação: nenhum arquivo no armazenamento (o
 * dublê em memória registra toda gravação) e nenhuma linha em `pasta_documento`.
 */
#[CoversClass(PeticionarController::class)]
final class PeticionarUploadSemEscritaControllerTest extends JusPrimeWebTestCase
{
    use CriaUsuarioComPermissoesTrait;

    private const PDF_MINIMO = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    /** @var list<string> */
    private array $arquivosCriados = [];

    protected function tearDown(): void
    {
        foreach ($this->arquivosCriados as $caminho) {
            if (is_file($caminho)) {
                @unlink($caminho);
            }
        }
        $this->arquivosCriados = [];

        parent::tearDown();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    #[TestDox('quem só VÊ a pasta: upload recebe 403 e nada é gravado (nem arquivo, nem linha)')]
    public function testQuemSoVeRecebe403ENadaEGravado(): void
    {
        [$client, $armazenamento, $tenant, $pasta] = $this->cenario();
        $leitor = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view', 'modules.pastas.view']);
        $this->logarComTenant($client, $leitor, $tenant);

        $this->enviar($client, (int) $pasta->getId(), true);

        self::assertResponseStatusCodeSame(403);
        $dados = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($dados);
        self::assertFalse($dados['success'] ?? null);
        self::assertSame('Sem permissão.', $dados['error'] ?? null);

        $this->assertNadaGravado($armazenamento, $pasta);
    }

    #[TestDox('pasta excluída: quem edita recebe a recusa em JSON (com X-Requested-With) e nada é gravado')]
    public function testPastaExcluidaRecusaQuemEditaEmJson(): void
    {
        [$client, $armazenamento, , $pasta, $gestor] = $this->cenario();
        $this->excluir($pasta, $gestor);

        $this->enviar($client, (int) $pasta->getId(), true);

        self::assertResponseStatusCodeSame(403);
        $dados = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($dados);
        // É a recusa do PastaSomenteLeituraListener ({mensagem}), que o peticionar-documentos.js lê.
        self::assertStringContainsString('somente para leitura', (string) ($dados['mensagem'] ?? ''));

        $this->assertNadaGravado($armazenamento, $pasta);
    }

    #[TestDox('pasta excluída sem X-Requested-With: a recusa é o 302 para a pasta, e nada é gravado')]
    public function testPastaExcluidaSemCabecalhoAjaxRedireciona(): void
    {
        [$client, $armazenamento, , $pasta, $gestor] = $this->cenario();
        $this->excluir($pasta, $gestor);

        $this->enviar($client, (int) $pasta->getId(), false);

        // Por isso o soltar arquivos manda o cabeçalho: sem ele, o fetch seguiria o 302 e leria HTML.
        self::assertResponseRedirects("/pasta/{$pasta->getId()}");

        $this->assertNadaGravado($armazenamento, $pasta);
    }

    #[TestDox('controle: a mesma pessoa, na pasta não excluída, grava (o cenário não recusa por outro motivo)')]
    public function testControleNaPastaAtivaGrava(): void
    {
        [$client, $armazenamento, , $pasta] = $this->cenario();

        $this->enviar($client, (int) $pasta->getId(), true);

        self::assertResponseIsSuccessful((string) $client->getResponse()->getContent());
        self::assertSame(1, $this->linhasDaPasta($pasta));
        $armazenamento->memoria->ultimaGravada();
    }

    // ----------------------------------------------------------------- helpers

    /** @return array{KernelBrowser, ArmazenamentoEmMemoriaNoContainer, Tenant, Pasta, User} */
    private function cenario(): array
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();
        $armazenamento = ArmazenamentoEmMemoriaNoContainer::instalarEm(static::getContainer());

        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant PETSE ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('petse_' . uniqid() . '@test.com');
        $user->setFullName('Gestor Peticionar');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'senha123'));
        $em->persist($user);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Gestor ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        $vinculo = new UserTenant($user, $tenant);
        $vinculo->setTenantRole($role);
        $em->persist($vinculo);

        $pasta = new Pasta();
        $pasta->setNup('PETSE-' . uniqid());
        $pasta->setTenant($tenant);
        $em->persist($pasta);
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);

        return [$client, $armazenamento, $tenant, $pasta, $user];
    }

    private function excluir(Pasta $pasta, User $por): void
    {
        $pasta->marcarExcluida($por, new \DateTimeImmutable());
        $this->em()->flush();
    }

    private function enviar(KernelBrowser $client, int $pastaId, bool $ajax): void
    {
        $origem = sys_get_temp_dir() . '/petic_se_' . bin2hex(random_bytes(6));
        file_put_contents($origem, self::PDF_MINIMO);
        $this->arquivosCriados[] = $origem;

        $client->request(
            'POST',
            "/pasta/{$pastaId}/peticionar/upload",
            ['_token' => 'TOKEN_peticionar_upload_' . $pastaId, 'categoria' => PastaDocumento::CATEGORIA_PECA],
            ['arquivo' => new UploadedFile($origem, 'peca.pdf', 'application/pdf', null, true)],
            $ajax ? ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'] : [],
        );
    }

    private function assertNadaGravado(ArmazenamentoEmMemoriaNoContainer $armazenamento, Pasta $pasta): void
    {
        self::assertSame(0, $this->linhasDaPasta($pasta), 'nenhuma linha em pasta_documento');

        try {
            $armazenamento->memoria->ultimaGravada();
            self::fail('nenhum arquivo deveria ter sido gravado no armazenamento');
        } catch (\LogicException $e) {
            self::assertSame('Nada foi gravado neste armazenamento.', $e->getMessage());
        }
    }

    /** Direto do banco, sem filtro do Doctrine: inclui até linha que tivesse ido para a lixeira. */
    private function linhasDaPasta(Pasta $pasta): int
    {
        return (int) $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM pasta_documento WHERE pasta_id = :id',
            ['id' => $pasta->getId()],
        );
    }

    private function instalarCsrfStorage(): void
    {
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
}
