<?php

declare(strict_types=1);

namespace App\Tests\Produto\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Produto\Controller\FerramentasDoEscritorioController;
use App\Produto\Entity\Contratacao;
use App\Produto\Enum\SituacaoDaContratacao;
use App\Produto\Repository\ContratacaoRepository;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Apoio dos testes das "Ferramentas do escritório" (spec multiproduto, §12 "PR 8"): o dono do escritório, o POST com
 * CSRF fixo ('TOKEN_<id>' sempre válido, padrão dos testes do painel do Master) e a leitura das linhas.
 * Quem usa também usa {@see ComEscritoriosPorProduto} e tem `$this->client`.
 */
trait ComFerramentasNoPerfil
{
    private const TOKEN_DAS_FERRAMENTAS = 'TOKEN_' . FerramentasDoEscritorioController::TOKEN;

    /** O primário do escritório, gravado como criador (= dono, `Tenant::getCriadoPor()`). */
    private function dono(Tenant $tenant): User
    {
        $dono = $this->primario($tenant);
        $this->em()->find(Tenant::class, $tenant->getId())?->setCriadoPor($dono);
        $this->em()->flush();

        return $dono;
    }

    /** @param mixed $corpo array vira JSON; string vai como está */
    private function postarFerramenta(string $produto, mixed $corpo, string $token = self::TOKEN_DAS_FERRAMENTAS): void
    {
        $this->client->request('POST', '/perfil/ferramentas/' . $produto, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $token,
        ], content: is_string($corpo) ? $corpo : (string) json_encode($corpo));
    }

    /** @return array<string, mixed> */
    private function respostaJson(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 8, \JSON_THROW_ON_ERROR);
    }

    /** @return list<string> os produtos com linha ativa, em ordem alfabética, lidos do banco */
    private function ativosDo(Tenant $tenant): array
    {
        $ativos = [];
        foreach ($this->linhasDo($tenant) as $linha) {
            if (SituacaoDaContratacao::Ativa === $linha->getSituacao()) {
                $ativos[] = $linha->getProduto()->value;
            }
        }
        sort($ativos);

        return $ativos;
    }

    /** @return list<Contratacao> */
    private function linhasDo(Tenant $tenant): array
    {
        $this->em()->clear();

        return static::getContainer()->get(ContratacaoRepository::class)->todasDoTenant($this->em()->getReference(Tenant::class, $tenant->getId()));
    }

    private function instalarCsrfFixo(): void
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
}
