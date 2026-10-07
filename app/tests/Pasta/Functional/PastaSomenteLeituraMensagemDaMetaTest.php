<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tarefa\TarefaMensagem;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Pasta\Entity\Pasta;
use App\Pasta\EventListener\PastaSomenteLeituraListener;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * D-DOC-RO, dois níveis (P14b): `tarefa_mensagem_editar` recebe a `TarefaMensagem`, que só chega à
 * pasta por `getTarefa()->getPasta()`. Na pasta excluída (lápide) a edição recebe a mesma recusa
 * das demais escritas e o texto não muda; na pasta viva segue editando.
 *
 * Meta SEM pasta não é representável (`tarefa.pasta_id` é NOT NULL); esse caso de borda está
 * provado no `PastaSomenteLeituraPelaMetaListenerTest`, com objetos de mão.
 *
 * O efeito é conferido por SQL cru (DBAL): uma releitura pelo EM compartilhado poderia mostrar o
 * identity map, não o banco.
 */
#[CoversClass(PastaSomenteLeituraListener::class)]
final class PastaSomenteLeituraMensagemDaMetaTest extends JusPrimeWebTestCase
{
    private const XHR = ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'];

    #[TestDox('editar mensagem de meta em pasta riscada: recusa (403 JSON) e o texto não muda')]
    public function testEditarMensagemNaPastaRiscadaRecusa(): void
    {
        [$client, $user, $tenant] = $this->preparar();
        $msgId = $this->criarMensagem($tenant, $user, true);

        $client->request('POST', "/tarefas/mensagem/{$msgId}/editar", $this->corpo($msgId), [], self::XHR);

        self::assertResponseStatusCodeSame(403);
        $dados = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('erro', $dados['status'] ?? null);
        self::assertStringContainsString('somente para leitura', (string) ($dados['mensagem'] ?? ''));
        self::assertSame(['Conteúdo original', null], $this->mensagemNoBanco($msgId));
    }

    #[TestDox('editar mensagem de meta em pasta riscada sem JS: volta para a pasta com o aviso, sem gravar')]
    public function testEditarMensagemNaPastaRiscadaSemXhrRedireciona(): void
    {
        [$client, $user, $tenant] = $this->preparar();
        $msgId   = $this->criarMensagem($tenant, $user, true);
        $pastaId = $this->pastaDaMensagem($msgId);

        $client->request('POST', "/tarefas/mensagem/{$msgId}/editar", $this->corpo($msgId));

        self::assertResponseRedirects('/pasta/' . $pastaId);
        self::assertSame(['Conteúdo original', null], $this->mensagemNoBanco($msgId));
    }

    #[TestDox('editar mensagem de meta em pasta viva: segue editando')]
    public function testEditarMensagemNaPastaVivaFunciona(): void
    {
        [$client, $user, $tenant] = $this->preparar();
        $msgId = $this->criarMensagem($tenant, $user, false);

        $client->request('POST', "/tarefas/mensagem/{$msgId}/editar", $this->corpo($msgId), [], self::XHR);

        self::assertResponseIsSuccessful();
        [$texto, $editadoEm] = $this->mensagemNoBanco($msgId);
        self::assertStringContainsString('Conteúdo editado', (string) $texto);
        self::assertNotNull($editadoEm);
    }

    // ── apoio ────────────────────────────────────────────────────────────────

    /** @return array<string, string> */
    private function corpo(int $msgId): array
    {
        return [
            '_token'   => 'TOKEN_editar_mensagem_tarefa_' . $msgId,
            'conteudo' => 'Conteúdo editado',
        ];
    }

    /** @return array{KernelBrowser, User, Tenant} */
    private function preparar(): array
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $this->logarComTenant($client, $user, $tenant);

        return [$client, $user, $tenant];
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** @return array{?string, ?string} texto e editado_em, lidos do banco */
    private function mensagemNoBanco(int $msgId): array
    {
        $linha = $this->em()->getConnection()->fetchAssociative(
            'SELECT mensagem, editado_em FROM tarefa_mensagem WHERE id = :id',
            ['id' => $msgId],
        );
        self::assertIsArray($linha);

        return [$linha['mensagem'], $linha['editado_em']];
    }

    private function pastaDaMensagem(int $msgId): int
    {
        return (int) $this->em()->getConnection()->fetchOne(
            'SELECT t.pasta_id FROM tarefa_mensagem m JOIN tarefa t ON t.id = m.tarefa_id WHERE m.id = :id',
            ['id' => $msgId],
        );
    }

    /**
     * Usuário com papel de sistema (administrador) no escritório: o módulo exige acesso, e o
     * `verificarAcessoTarefa` exige que o criador da pasta seja do escritório.
     *
     * @return array{User, Tenant}
     */
    private function criarUsuarioAdmin(): array
    {
        $em     = $this->em();
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Lapide Meta ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('lapide_meta_' . uniqid() . '@test.com');
        $user->setFullName('Admin Lapide Meta');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Administrador ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        $vinculo = new UserTenant($user, $tenant);
        $vinculo->setTenantRole($role);
        $em->persist($vinculo);
        $em->flush();

        return [$user, $tenant];
    }

    /** Pasta (riscada ou viva) → meta → mensagem do próprio usuário. Devolve o id da mensagem. */
    private function criarMensagem(Tenant $tenant, User $autor, bool $pastaExcluida): int
    {
        $em = $this->em();

        $pasta = new Pasta();
        $pasta->setNup(($pastaExcluida ? 'LAPIDE-META-' : 'VIVA-META-') . uniqid());
        $pasta->setTenant($tenant);
        $pasta->setCriadoPor($autor);
        $em->persist($pasta);

        $tarefa = new Tarefa();
        $tarefa->setTitulo('Meta da lápide');
        $tarefa->setDescricao('Descrição');
        $tarefa->setPasta($pasta);
        $tarefa->setTenant($tenant);
        $em->persist($tarefa);

        $mensagem = new TarefaMensagem();
        $mensagem->setTarefa($tarefa);
        $mensagem->setUsuario($autor);
        $mensagem->setMensagem('Conteúdo original');
        $mensagem->setTenant($tenant);
        $em->persist($mensagem);
        $em->flush();

        // A exclusão vem DEPOIS da mensagem: é o cenário real (conversa antiga numa pasta que
        // foi riscada depois).
        if ($pastaExcluida) {
            $pasta->marcarExcluida($autor, new \DateTimeImmutable());
            $em->flush();
        }

        $id = (int) $mensagem->getId();
        $em->clear();

        return $id;
    }

    private function instalarCsrfStorage(): void
    {
        $storage = new class implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string { return 'TOKEN_' . $tokenId; }
            public function setToken(string $tokenId, string $token): void {}
            public function removeToken(string $tokenId): ?string { return null; }
            public function hasToken(string $tokenId): bool { return true; }
            public function clear(): void {}
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }
}
