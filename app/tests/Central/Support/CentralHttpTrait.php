<?php

declare(strict_types=1);

namespace App\Tests\Central\Support;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Ajudantes HTTP dos testes da Central: cliente com o CSRF `ajax` previsível, abrir conversa, enviar, sair do
 * escritório. Quem usa estende `JusPrimeWebTestCase` com `CriaFixturesInteligenciaTrait`.
 */
trait CentralHttpTrait
{
    private const CSRF = ['HTTP_X-CSRF-Token' => 'TOKEN_ajax', 'CONTENT_TYPE' => 'application/json'];

    private bool $csrfInstalado = false;

    private function cliente(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        if (!$this->csrfInstalado) {
            $this->csrfInstalado = true;
            static::getContainer()->set('security.csrf.token_storage', new class implements ClearableTokenStorageInterface {
                public function getToken(string $tokenId): string { return 'TOKEN_' . $tokenId; }
                public function setToken(string $tokenId, string $token): void {}
                public function removeToken(string $tokenId): ?string { return null; }
                public function hasToken(string $tokenId): bool { return true; }
                public function clear(): void {}
            });
        }

        return $client;
    }

    /**
     * A lista de conversas da última resposta de `/central/conversas`, sem a conversa com o BlueJus IA (que a lista traz
     * sempre, F3 PR 3a): os testes das conversas entre pessoas comparam só as delas.
     *
     * @return list<array<string, mixed>>
     */
    private function conversasSemIa(KernelBrowser $client): array
    {
        return array_values(array_filter($this->json($client)['conversas'], static fn (array $c): bool => $c['ia'] !== true));
    }

    private function colega(Tenant $tenant, string $rotulo): User
    {
        return $this->criarUsuarioComPermissoes($tenant, [], $rotulo);
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        return json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $corpo */
    private function post(KernelBrowser $client, string $url, array $corpo): void
    {
        $client->request('POST', $url, [], [], self::CSRF, json_encode($corpo, JSON_THROW_ON_ERROR));
    }

    private function abrir(KernelBrowser $client, User $com): int
    {
        $this->post($client, '/central/conversas', ['destinatarioId' => $com->getId()]);
        self::assertResponseIsSuccessful();

        return (int) $this->json($client)['conversa']['id'];
    }

    private function enviar(KernelBrowser $client, int $conversa, string $texto): int
    {
        $this->post($client, "/central/conversas/$conversa/mensagens", ['conteudo' => $texto]);
        self::assertResponseStatusCodeSame(201);

        return (int) $this->json($client)['mensagem']['id'];
    }

    private function sairDoEscritorio(User $usuario, Tenant $tenant): void
    {
        // O sistema real apaga o vínculo ao remover o colaborador (RemoverColaboradorDoEscritorioUseCase).
        $vinculo = $this->em()->getRepository(UserTenant::class)->findOneBy(['user' => $usuario, 'tenant' => $tenant]);
        $this->em()->remove($vinculo);
        $this->em()->flush();
    }
}
