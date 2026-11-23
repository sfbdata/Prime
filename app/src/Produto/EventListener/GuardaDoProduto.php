<?php

declare(strict_types=1);

namespace App\Produto\EventListener;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Master\Service\RegraDosMasters;
use App\Produto\DTO\ClassificacaoDaRota;
use App\Produto\Enum\ModoDoMultiproduto;
use App\Produto\Enum\Produto;
use App\Produto\Service\CatalogoDeProdutos;
use App\Produto\Service\ChaveDoMultiproduto;
use App\Produto\Service\ProdutosDoEscritorio;
use App\Service\Tenant\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Guarda do produto (spec docs/specs/multiproduto-contratacao-e-voto.md, §6): rota de um produto que o escritório não
 * tem responde 404, nunca 403 nem 503. Governada pela chave `MULTIPRODUTO_MODO` ({@see ChaveDoMultiproduto}).
 *
 * Fica FORA do `PermissionChecker` (§2, regra 2): o primário (`isSystem`) também leva 404; só o Master passa. As
 * camadas de permissão de hoje (docs/AUTORIZACAO.md) continuam iguais e vêm depois desta pergunta.
 *
 * Sai sem agir quando: o modo é `desligado`; a requisição não é a principal (a página de erro é sub-requisição);
 * a rota é de núcleo, pública ou plataforma (ou interna do framework, começada por `_`); ninguém está logado; é o
 * Master; não há escritório. O Convidado do Trabalho Externo (rotas com `_sem_escritorio`) passa por esta última
 * saída, como no TenantContextValidatorListener: sem escritório na sessão, nunca leva 404; com escritório, a rota é
 * do Expediente como qualquer outra.
 *
 * O escritório é o do `{tenantId}` da URL quando a rota o tem (o mesmo que o TenantUrlScopeListener fixa no filtro);
 * senão, o da sessão. No modo `observacao` só registra no canal `multiproduto` (rota, id do escritório e produtos
 * exigidos, sem dado pessoal) e deixa seguir; no `bloqueio`, 404 (também para JSON).
 *
 * Prioridade 3, a mesma do BloqueioDeRecursoDesativado: depois do firewall (8), do vínculo com o escritório (6) e do
 * escopo da URL (4). Na mesma prioridade a ordem não é garantida, e esta guarda não depende de nenhum vizinho.
 * No worker e na linha de comando ela não existe (§2, regra 6).
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 3)]
final class GuardaDoProduto
{
    public function __construct(
        private readonly ChaveDoMultiproduto $chave,
        private readonly ProdutosDoEscritorio $produtos,
        private readonly Security $security,
        private readonly RegraDosMasters $regraDosMasters,
        private readonly TenantContext $tenantContext,
        private readonly EntityManagerInterface $em,
        #[Autowire(service: 'monolog.logger.multiproduto')]
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$this->chave->ligada() || !$event->isMainRequest()) {
            return;
        }

        $requisicao = $event->getRequest();
        $rota = (string) $requisicao->attributes->get('_route', '');
        $classificacao = $this->classificacaoQueExigeProduto($requisicao, $rota);
        if (null === $classificacao || $this->passaSemConferir()) {
            return;
        }

        $tenant = $this->escritorioDaRequisicao($requisicao);
        if (null === $tenant || $this->produtos->inclui($tenant, $classificacao->produtos)) {
            return;
        }

        $this->recusar($rota, $tenant, $classificacao->produtos);
    }

    /** A classificação da rota, só quando ela é de produto e a guarda precisa agir; senão, nulo. */
    private function classificacaoQueExigeProduto(Request $requisicao, string $rota): ?ClassificacaoDaRota
    {
        if ('' === $rota || str_starts_with($rota, '_')) {
            return null;
        }
        $classificacao = CatalogoDeProdutos::classificar($rota);

        return $classificacao->exigeProduto() ? $classificacao : null;
    }

    /** Ninguém logado (o firewall já barra a rota não pública) ou o Master: a guarda não confere. */
    private function passaSemConferir(): bool
    {
        $usuario = $this->security->getUser();

        return !$usuario instanceof User || $this->regraDosMasters->ehMaster($usuario);
    }

    private function escritorioDaRequisicao(Request $requisicao): ?Tenant
    {
        $daUrl = $requisicao->attributes->get('tenantId');
        if (null !== $daUrl && ctype_digit((string) $daUrl)) {
            return $this->em->find(Tenant::class, (int) $daUrl);
        }

        return $this->tenantContext->getCurrentTenant();
    }

    /** @param list<Produto> $exigidos */
    private function recusar(string $rota, Tenant $tenant, array $exigidos): void
    {
        if (ModoDoMultiproduto::Observacao === $this->chave->modo()) {
            $this->logger->info('Rota de produto que o escritório não tem', [
                'rota' => $rota,
                'tenant' => $tenant->getId(),
                'produtos' => array_map(static fn (Produto $p): string => $p->value, $exigidos),
            ]);

            return;
        }

        throw new NotFoundHttpException('Página não encontrada.');
    }
}
