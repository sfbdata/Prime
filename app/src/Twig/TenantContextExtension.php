<?php
declare(strict_types=1);

namespace App\Twig;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Repository\UserTenantRepository;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `ResetInterface` (tag `kernel.reset` por autoconfiguração): a memória de `meus_escritorios` é por
 * requisição e não pode vazar para a seguinte (worker de longa duração, suíte de testes).
 */
final class TenantContextExtension extends AbstractExtension implements ResetInterface
{
    /** @var array{userId: int|null, vinculos: UserTenant[]}|null */
    private ?array $vinculosDaRequisicao = null;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly UserTenantRepository $userTenantRepository,
        private readonly Security $security,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('current_tenant', $this->currentTenant(...)),
            new TwigFunction('meus_escritorios', $this->meusEscritorios(...)),
            new TwigFunction('papel_na_barra', $this->papelNaBarra(...)),
            new TwigFunction('eh_usuario_primario', $this->ehUsuarioPrimario(...)),
        ];
    }

    public function currentTenant(): ?Tenant
    {
        return $this->tenantContext->getCurrentTenant();
    }

    /**
     * Vínculos ativos do usuário logado, para o seletor de escritórios no header.
     *
     * @return UserTenant[]
     */
    public function meusEscritorios(): array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return [];
        }

        // Memória por requisição: a base chama isto no seletor de escritórios e o papel_na_barra
        // reaproveita a mesma lista, sem consulta a mais por página.
        if ($this->vinculosDaRequisicao === null || $this->vinculosDaRequisicao['userId'] !== $user->getId()) {
            $this->vinculosDaRequisicao = [
                'userId'   => $user->getId(),
                'vinculos' => $this->userTenantRepository->findActiveByUser($user),
            ];
        }

        return $this->vinculosDaRequisicao['vinculos'];
    }

    public function reset(): void
    {
        $this->vinculosDaRequisicao = null;
    }

    /**
     * Subtítulo de papel sob o nome, na barra superior (desenho "05 - Perfis 1.0", linha 37):
     * "MASTER" para ROLE_SUPER_ADMIN; "USUÁRIO PRIMÁRIO" quando o vínculo com o escritório atual
     * tem o perfil de sistema; "USUÁRIO SECUNDÁRIO" quando tem outro perfil. As partes se juntam
     * com " · ". Roda em TODA página: nunca lança. Sem escritório na sessão sai só o que der
     * (o Master fora de escritório vê só "MASTER"; sem nada, string vazia).
     */
    public function papelNaBarra(): string
    {
        try {
            $user = $this->security->getUser();
            if (!$user instanceof User) {
                return '';
            }

            $partes = [];
            if (in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
                $partes[] = 'MASTER';
            }

            $vinculo = $this->vinculoNoEscritorioAtual();
            if ($vinculo !== null) {
                $partes[] = $vinculo->getTenantRole()?->isSystem() === true
                    ? 'USUÁRIO PRIMÁRIO'
                    : 'USUÁRIO SECUNDÁRIO';
            }

            return implode(' · ', $partes);
        } catch (\Throwable) {
            // Roda em TODA página: um subtítulo nunca pode derrubar a tela.
            return '';
        }
    }

    /**
     * O usuário logado é o primário do escritório atual (vínculo com o perfil de sistema)? É o que
     * decide, no menu do usuário e nas abas do Meu Perfil, quem vê "Convites do escritório" e
     * "Abrir conta" (desenho "07 - Meu Perfil" e "03 - EXPEDIENTE", VS 18.10.26: só o primário).
     * Só decide o que aparece; a rota de destino mantém a própria guarda. Nunca lança.
     */
    public function ehUsuarioPrimario(): bool
    {
        try {
            return $this->vinculoNoEscritorioAtual()?->getTenantRole()?->isSystem() === true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Reaproveita os vínculos ativos já lidos para o seletor de escritórios (a base chama
     * meus_escritorios() antes): sem consulta extra. O escritório atual vem da sessão.
     */
    private function vinculoNoEscritorioAtual(): ?UserTenant
    {
        $tenantAtual = $this->tenantContext->getCurrentTenant();
        if ($tenantAtual === null) {
            return null;
        }

        foreach ($this->meusEscritorios() as $vinculo) {
            if ($vinculo->getTenant()->getId() === $tenantAtual->getId()) {
                return $vinculo;
            }
        }

        return null;
    }
}
