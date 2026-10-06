<?php

declare(strict_types=1);

namespace App\Pasta\Controller;

use App\Djen\DTO\PublicacaoDjenOutput;
use App\Djen\Entity\PublicacaoDjen;
use App\Djen\Repository\PublicacaoDjenRepository;
use App\Djen\Service\FormatadorTeorDjen;
use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Processo\DTO\NotaTecnicaOutput;
use App\Processo\Entity\NotaTecnica;
use App\Processo\Entity\Processo;
use App\Processo\Repository\NotaTecnicaRepository;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Leitura do teor de uma publicação DENTRO da pasta — o acordeão da aba Push Processual.
 *
 * Existe em vez de reusar `/push-processual/{id}` por duas razões: aquela tela é página inteira e
 * tiraria o usuário do caso, e ela é gateada por `modules.djen.view`, que aqui NÃO é exigida (o
 * dono decidiu que o push é conteúdo da pasta: quem pode abrir a pasta pode lê-lo).
 *
 * Trocar o gate do módulo por outro exige que o guarda daqui seja completo. São três camadas:
 *   1. a pasta é do escritório da sessão;
 *   2. o usuário pode VER esta pasta;
 *   3. a publicação é de um dos processos DESTA pasta — a restrição vai na consulta
 *      (`findOneByIdENumerosDoTenant`), não numa comparação posterior que algum caminho possa
 *      esquecer. Sem ela, o id na URL viraria leitor de qualquer publicação do escritório.
 *
 * Marcar como lida num GET é o mesmo contrato da tela do módulo: abrir É ler. A pasta excluída
 * (lápide) continua podendo ler — o listener de somente-leitura só barra método não-seguro, e a
 * escrita aqui é na publicação, não na pasta.
 */
#[Route('/pasta')]
final class PastaPushProcessualController extends AbstractController
{
    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
        private readonly PublicacaoDjenRepository $publicacaoRepository,
        private readonly NotaTecnicaRepository $notaTecnicaRepository,
    ) {
    }

    #[Route(
        '/{id}/push/{publicacaoId}',
        name: 'pasta_push_teor',
        requirements: ['id' => '\d+', 'publicacaoId' => '\d+'],
        methods: ['GET'],
    )]
    public function teor(Pasta $pasta, int $publicacaoId, FormatadorTeorDjen $formatadorTeor): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $tenant      = $this->tenantContext->getCurrentTenant();

        // O resolver de entidade busca por PK, e o TenantFilter não se aplica a find() — a
        // conferência do dono da pasta é explícita.
        if ($tenant === null || $pasta->getTenant() !== $tenant) {
            throw $this->createNotFoundException('Pasta não encontrada.');
        }

        if (!$this->permissionChecker->canAccessResource($currentUser, $tenant, 'pasta', (int) $pasta->getId(), 'view')) {
            throw $this->createAccessDeniedException('Sem permissão para ver esta pasta.');
        }

        $publicacao = $this->publicacaoRepository->findOneByIdENumerosDoTenant(
            $publicacaoId,
            $tenant,
            $this->numerosDosProcessos($pasta),
        );

        if ($publicacao === null) {
            throw $this->createNotFoundException('Publicação não encontrada nesta pasta.');
        }

        if (!$publicacao->isLida()) {
            $publicacao->setLida(true);
            $this->publicacaoRepository->salvar($publicacao, true);
        }

        // Notas técnicas penduradas NESTA movimentação. A nota é do processo, então o teor precisa
        // saber QUAL processo da pasta é o da publicação para o compositor criar a nota nele.
        $processoDaPublicacao = $this->processoDaPublicacao($pasta, $publicacao);

        // Teor externo (CNJ): sanitizado pelo mesmo formatador da tela do módulo antes de exibir.
        return $this->render('pasta/_push_teor.html.twig', [
            'publicacao' => PublicacaoDjenOutput::fromEntity($publicacao, $formatadorTeor->formatar($publicacao->getTexto())),
            // Quem sair daqui para o módulo volta para ESTA pasta, já na aba certa. O fragmento
            // precisa ir explícito: o navegador não manda `#push` no Referer.
            'voltarPara' => $this->generateUrl('pasta_show', ['id' => $pasta->getId()]) . '#push',
            'pastaId'        => (int) $pasta->getId(),
            'notaProcessoId' => $processoDaPublicacao?->getId(),
            'notasTecnicas'  => array_map(
                static fn (NotaTecnica $nota): NotaTecnicaOutput => NotaTecnicaOutput::fromEntity($nota),
                $this->notaTecnicaRepository->listarPorPublicacao($publicacao, $tenant),
            ),
        ]);
    }

    /**
     * O processo DESTA pasta a que a publicação pertence: pela FK quando a sincronização a gravou
     * e ele está na pasta; senão pelo número CNJ — o mesmo casamento que trouxe a publicação para cá.
     */
    private function processoDaPublicacao(Pasta $pasta, PublicacaoDjen $publicacao): ?Processo
    {
        $vinculado = $publicacao->getProcesso();
        if ($vinculado !== null && $pasta->temProcesso($vinculado)) {
            return $vinculado;
        }

        foreach ($pasta->getPastaProcessos() as $vinculo) {
            if ($vinculo->getProcesso()->getNumeroProcesso() === $publicacao->getNumeroProcesso()) {
                return $vinculo->getProcesso();
            }
        }

        return null;
    }

    /** @return string[] números CNJ dos processos vinculados à pasta */
    private function numerosDosProcessos(Pasta $pasta): array
    {
        $numeros = [];
        foreach ($pasta->getPastaProcessos() as $vinculo) {
            $numeros[] = $vinculo->getProcesso()->getNumeroProcesso();
        }

        return $numeros;
    }
}
