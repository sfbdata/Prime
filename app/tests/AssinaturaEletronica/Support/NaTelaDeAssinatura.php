<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Support;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * O que os funcionais da tela Assinatura repetem: entrar num escritório novo (com ou sem o módulo, com a chave do
 * Master ligada ou desligada), CSRF previsível, chamar as rotas JSON e montar PDF e PNG mínimos.
 *
 * A classe que usa estende `JusPrimeWebTestCase` e preenche `$client` no `setUp()`.
 */
trait NaTelaDeAssinatura
{
    private const CSRF = 'TOKEN_ajax';
    private const PDF_MINIMO = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private KernelBrowser $client;

    /** O token CSRF de cada id vira `TOKEN_<id>`: o cabeçalho `X-CSRF-Token: TOKEN_ajax` passa. */
    private function csrfPrevisivel(): void
    {
        static::getContainer()->set('security.csrf.token_storage', new class () implements ClearableTokenStorageInterface {
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
        });
    }

    /**
     * Usuário de um escritório novo, com ou sem `modules.assinatura_eletronica.view`, e a chave do Master ligada (sem
     * linha em `recurso_estado`) ou desligada como a migration a deixa.
     *
     * @return array{User, Tenant}
     */
    private function entrar(bool $comPermissao, bool $chaveLigada = true): array
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = (new Tenant())->setName('Escritório Assinatura ' . uniqid());
        $em->persist($tenant);
        $perfil = new TenantRole();
        $perfil->setTenant($tenant);
        $perfil->setName('Perfil Assinatura ' . uniqid());
        $em->persist($perfil);
        if ($comPermissao) {
            $concessao = new TenantRolePermission();
            $concessao->setTenantRole($perfil);
            $concessao->setPermission($this->permissao());
            $em->persist($concessao);
            $perfil->getTenantRolePermissions()->add($concessao);
        }

        $user = new User();
        $user->setEmail('assinatura_' . uniqid() . '@test.com');
        $user->setFullName('Advogada Assinatura');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $em->persist($user);
        $vinculo = new UserTenant($user, $tenant);
        $vinculo->setTenantRole($perfil);
        $em->persist($vinculo);
        $em->flush();

        $this->conexao()->executeStatement('DELETE FROM recurso_estado WHERE chave = ?', ['assinatura']);
        if (!$chaveLigada) {
            $this->conexao()->executeStatement("INSERT INTO recurso_estado (chave, ativo, alterado_em) VALUES ('assinatura', false, NOW())");
        }
        $this->logarComTenant($this->client, $user, $tenant);

        return [$user, $tenant];
    }

    private function permissao(): Permission
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        return $em->getRepository(Permission::class)->findOneBy(['code' => 'modules.assinatura_eletronica.view'])
            ?? self::fail('a migration Version20261109130000 cria a permissão');
    }

    /** @param array<string, mixed> $dados */
    private function salvar(?int $id, array $dados, ?UploadedFile $arquivo = null, ?UploadedFile $logo = null): int
    {
        $arquivos = array_filter(['arquivo' => $arquivo, 'logo' => $logo]);
        $url = '/assinatura/documentos' . ($id !== null ? '/' . $id : '');
        $this->client->request('POST', $url, ['dados' => json_encode($dados, \JSON_THROW_ON_ERROR)], $arquivos, ['HTTP_X_CSRF_TOKEN' => self::CSRF]);

        return (int) ($this->json()['id'] ?? 0);
    }

    /** @param array<string, mixed> $corpo */
    private function postarJson(string $url, array $corpo): void
    {
        $this->client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => self::CSRF], json_encode($corpo, \JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function detalhe(int $id): array
    {
        $this->client->request('GET', '/assinatura/documentos/' . $id);
        self::assertResponseIsSuccessful();

        return $this->json();
    }

    /** @return array<string, mixed> */
    private function listar(): array
    {
        $this->client->request('GET', '/assinatura/documentos');
        self::assertResponseIsSuccessful();

        return $this->json();
    }

    /** Muda a situação direto no banco: o envio (que tira do rascunho) é da etapa 2. */
    private function situacao(int $id, string $situacao): void
    {
        $this->conexao()->executeStatement('UPDATE assinatura_eletronica_documento SET situacao = ? WHERE id = ?', [$situacao, $id]);
        static::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        $resposta = $this->client->getResponse();
        $json = json_decode((string) $resposta->getContent(), true);

        return \is_array($json) ? $json : self::fail(sprintf('resposta %d não é JSON: %s', $resposta->getStatusCode(), mb_substr((string) $resposta->getContent(), 0, 600)));
    }

    private function conexao(): Connection
    {
        return static::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }

    private function pdf(string $nome = 'contrato.pdf'): UploadedFile
    {
        return $this->temporario($nome, self::PDF_MINIMO);
    }

    private function png(): UploadedFile
    {
        return $this->temporario('logo.png', (string) base64_decode(self::PNG_1X1, true));
    }

    private function temporario(string $nome, string $conteudo): UploadedFile
    {
        $caminho = (string) tempnam(sys_get_temp_dir(), 'assinatura-');
        file_put_contents($caminho, $conteudo);

        return new UploadedFile($caminho, $nome, null, null, true);
    }
}
