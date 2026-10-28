<?php

declare(strict_types=1);

namespace App\Tenant\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Master\Service\RegraDosMasters;
use App\Repository\UserTenantRepository;
use App\Tenant\Exception\AcessoAoEscritorioNegado;
use App\Tenant\Exception\PedidoDeExclusaoRecusado;
use App\Tenant\Service\RegraDosPrimarios;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Confere, no servidor, a senha do primário logado e o nome do escritório digitado antes de o botão "Excluir
 * escritório" seguir para o fluxo de sempre (spec meu-escritorio.md; o pedido, o código por e-mail e a palavra EXCLUIR
 * continuam no ExclusaoDaContaController). Só confere: não cria pedido nem muda a conta. Só o primário COM vínculo
 * (o Master sem vínculo não); a conta do Master não tem exclusão; no máximo 5 tentativas em 15 minutos por usuário.
 */
final class ConferirExclusaoDoEscritorioUseCase
{
    public const SENHA_ERRADA = 'Senha incorreta.';
    public const NOME_DIFERENTE = 'O nome digitado não é igual ao do escritório.';
    public const MUITAS_TENTATIVAS = 'Muitas tentativas. Aguarde alguns minutos e tente de novo.';

    public function __construct(
        private readonly UserTenantRepository $vinculos,
        private readonly RegraDosPrimarios $regraDosPrimarios,
        private readonly RegraDosMasters $regraDosMasters,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly RateLimiterFactoryInterface $exclusaoConferirLimiter,
    ) {
    }

    /**
     * @throws AcessoAoEscritorioNegado
     * @throws PedidoDeExclusaoRecusado 403 conta do Master, 422 senha ou nome, 429 muitas tentativas
     */
    public function executar(User $ator, Tenant $tenant, string $senha, string $nome, bool $escritorioDaSessao = true): void
    {
        // O fluxo de exclusão que vem depois age no escritório da SESSÃO: conferir outro escritório não vale.
        if (!$escritorioDaSessao) {
            throw new AcessoAoEscritorioNegado();
        }
        $vinculo = $this->vinculos->findAtivoPorUserETenant($ator, $tenant);
        if ($vinculo === null || !$this->regraDosPrimarios->ehPrimario($vinculo)) {
            throw new AcessoAoEscritorioNegado();
        }
        $recusa = $this->regraDosMasters->recusaParaExcluir($tenant);
        if ($recusa !== null) {
            throw new PedidoDeExclusaoRecusado($recusa, 403);
        }
        if (!$this->exclusaoConferirLimiter->create((string) $ator->getId())->consume()->isAccepted()) {
            throw new PedidoDeExclusaoRecusado(self::MUITAS_TENTATIVAS, 429);
        }
        if ($senha === '' || !$this->hasher->isPasswordValid($ator, $senha)) {
            throw new PedidoDeExclusaoRecusado(self::SENHA_ERRADA);
        }
        if (trim($nome) !== trim((string) $tenant->getName())) {
            throw new PedidoDeExclusaoRecusado(self::NOME_DIFERENTE);
        }
    }
}
