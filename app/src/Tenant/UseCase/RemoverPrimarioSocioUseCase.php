<?php
declare(strict_types=1);

namespace App\Tenant\UseCase;

use App\Entity\Tenant\Tenant;
use App\Repository\UserTenantRepository;
use App\Tenant\DTO\RemoverPrimarioSocioInput;
use App\Tenant\Exception\OperacaoDePrimarioRecusada;
use App\Tenant\Service\RegraDosPrimarios;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Remove um primário sócio: ele volta a ser secundário, com o perfil escolhido (spec D4).
 *
 * Quem dispara: o primário do escritório (ou o Master), na lixeira do bloco "Primários do
 * escritório". A autorização é do controller.
 * Objetivo: tirar de um sócio as competências de gestão, sem tirá-lo do escritório.
 * Pré-condições: o alvo tem vínculo ATIVO com ESTE escritório e é primário; o perfil de destino
 * é SECUNDÁRIO e do MESMO escritório; o alvo não é o titular; sobra ao menos um primário.
 * Fluxo principal: busca o vínculo pelo par (alvo, escritório), aplica a RegraDosPrimarios,
 * troca o perfil pelo de destino e grava.
 * Fluxos alternativos: sem vínculo ativo aqui; não é primário; destino ausente, de sistema ou de
 * outro escritório; titular; último primário. Todos recusados com mensagem.
 * Pós-condições: o vínculo passa ao perfil de destino. Nada mais muda (pastas, tarefas e acessos
 * item a item ficam como estão).
 * Por que o destino é obrigatório: o sistema não guarda o perfil que o sócio tinha antes da
 * promoção, e deixar o vínculo sem perfil tiraria todo o acesso dele em silêncio.
 * Auditoria: AuditLogSubscriber (UserTenant é Auditavel), como na promoção.
 */
final class RemoverPrimarioSocioUseCase
{
    public function __construct(
        private readonly UserTenantRepository $userTenantRepository,
        private readonly RegraDosPrimarios $regraDosPrimarios,
        private readonly EntityManagerInterface $em,
    ) {}

    public function executar(RemoverPrimarioSocioInput $input): void
    {
        $vinculo = $this->userTenantRepository->findAtivoPorUserETenant($input->alvo, $input->tenant);
        if ($vinculo === null || !$vinculo->isActive() || !$this->mesmoEscritorio($vinculo->getTenant(), $input->tenant)) {
            throw new OperacaoDePrimarioRecusada('Este usuário não é primário ativo desta conta.');
        }

        // Perfil de sistema no vínculo ativo basta: conta desativada com perfil primário também
        // pode ser rebaixada (ela não conta como primário, então não segura a trava do último).
        if ($vinculo->getTenantRole()?->isSystem() !== true) {
            throw new OperacaoDePrimarioRecusada('Este usuário não é primário do escritório.');
        }

        $destino = $input->perfilDestino;
        if ($destino === null) {
            throw new OperacaoDePrimarioRecusada(
                'Escolha o perfil secundário que este usuário passa a ter. '
                . 'Se não houver nenhum, crie um perfil secundário antes.'
            );
        }

        if ($destino->isSystem() || !$this->mesmoEscritorio($destino->getTenant(), $input->tenant)) {
            throw new OperacaoDePrimarioRecusada('O perfil de destino precisa ser um perfil secundário desta conta.');
        }

        $this->regraDosPrimarios->garantirQuePodeDeixarDeSerPrimario($vinculo);

        $vinculo->setTenantRole($destino);
        $vinculo->setUpdatedAt(new \DateTimeImmutable());

        $this->em->flush();
    }

    /** Mesma conta: identidade, ou id igual quando os dois têm id (proxy x entidade carregada). */
    private function mesmoEscritorio(?Tenant $a, Tenant $b): bool
    {
        return $a === $b || ($a !== null && $a->getId() !== null && $a->getId() === $b->getId());
    }
}
