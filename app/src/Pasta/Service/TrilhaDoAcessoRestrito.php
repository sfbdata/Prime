<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Trilha do acesso restrito (spec acesso-restrito-e-compartilhar §4.6, regra 7): uma linha no `audit_log` por
 * abertura de pasta (a tela, o download e o ZIP) e por recusa das barreiras, só para quem tem o perfil restrito.
 * Perfil sem restrição não ganha trilha de leitura.
 *
 * Grava direto pela conexão: a linha fica mesmo quando a requisição termina em 404 ou 403, e nunca leva junto
 * (num flush) o que a requisição tenha deixado pendente no EntityManager. Só ids, a rota e o IP: nenhum nome e nenhum
 * texto livre em `changes`.
 */
final class TrilhaDoAcessoRestrito
{
    public const ACAO_LEITURA = 'leitura';
    public const ACAO_NEGADO  = 'negado';

    /** `entity_class` da recusa que não aponta um registro (a tela "Fora do seu acesso"). */
    public const SEM_REGISTRO = 'rota';

    /** As rotas que contam como abrir a pasta: a tela, o download de documento e o ZIP. */
    public const ROTAS_DE_LEITURA = ['pasta_show', 'pasta_documento_download', 'pasta_documentos_zip', 'pasta_financeiro_documento_download'];

    public function __construct(
        private readonly Connection $conexao,
        private readonly ClockInterface $relogio,
    ) {
    }

    public static function contaComoLeitura(Request $request): bool
    {
        return $request->isMethodSafe()
            && in_array((string) $request->attributes->get('_route'), self::ROTAS_DE_LEITURA, true);
    }

    public function leitura(User $quem, Tenant $tenant, Request $request, string $classe, int $id): void
    {
        $this->gravar(self::ACAO_LEITURA, $quem, $tenant, $request, $classe, $id);
    }

    public function negado(User $quem, Tenant $tenant, Request $request, string $classe = self::SEM_REGISTRO, ?int $id = null): void
    {
        $this->gravar(self::ACAO_NEGADO, $quem, $tenant, $request, $classe, $id);
    }

    private function gravar(string $acao, User $quem, Tenant $tenant, Request $request, string $classe, ?int $id): void
    {
        $rota = (string) $request->attributes->get('_route', '');
        $this->conexao->insert('audit_log', [
            'action'        => $acao,
            'entity_class'  => $classe,
            'entity_id'     => $id !== null ? (string) $id : null,
            'changes'       => json_encode(['evento' => $acao, 'rota' => $rota, 'metodo' => $request->getMethod()], \JSON_THROW_ON_ERROR),
            'actor_user_id' => $quem->getId(),
            'actor_email'   => $quem->getEmail(),
            'tenant_id'     => $tenant->getId(),
            'ip_address'    => $request->getClientIp(),
            'route'         => mb_substr($rota, 0, 255),
            'created_at'    => $this->relogio->now()->format('Y-m-d H:i:s'),
        ]);
    }
}
