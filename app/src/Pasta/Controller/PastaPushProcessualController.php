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
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
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
            // O "ID do documento" que o desenho manda copiar. Vai à parte para não alargar o DTO,
            // que é compartilhado com a tela do módulo.
            'numeroComunicacao' => $publicacao->getNumeroComunicacao(),
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
     * Marca a publicação como lida ou NÃO lida, pela pasta (desenho 1.2.3: "Marcar como lida").
     *
     * Abrir o teor já marca como lida (GET acima); esta rota existe sobretudo para DESFAZER isso —
     * quem abriu e quer deixar o aviso para depois. `lida` é do escritório, não do usuário: é o
     * mesmo campo que o módulo e o selo da aba leem.
     *
     * Guarda idêntica à do teor (tenant da pasta, permissão de VER a pasta, publicação casada com
     * um processo DESTA pasta na própria consulta). Ver basta porque ver já marca como lida no GET:
     * exigir edição aqui faria quem só lê poder "ler" e não poder "desler". CSRF porque é escrita.
     * Pasta excluída (lápide) recebe a recusa do PastaSomenteLeituraListener, como todo POST nela.
     */
    #[Route(
        '/{id}/push/{publicacaoId}/lida',
        name: 'pasta_push_lida',
        requirements: ['id' => '\d+', 'publicacaoId' => '\d+'],
        methods: ['POST'],
    )]
    public function marcarLida(Pasta $pasta, int $publicacaoId, Request $request): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $tenant      = $this->tenantContext->getCurrentTenant();

        if ($tenant === null || $pasta->getTenant() !== $tenant) {
            return $this->json(['erro' => 'Pasta não encontrada.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->permissionChecker->canAccessResource($currentUser, $tenant, 'pasta', (int) $pasta->getId(), 'view')) {
            return $this->json(['erro' => 'Sem permissão para ver esta pasta.'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->isCsrfTokenValid('pasta_push_lida_' . $pasta->getId(), (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_FORBIDDEN);
        }

        if (!$request->request->has('lida')) {
            return $this->json(['erro' => 'Informe se a publicação fica lida ou não lida.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $publicacao = $this->publicacaoRepository->findOneByIdENumerosDoTenant(
            $publicacaoId,
            $tenant,
            $this->numerosDosProcessos($pasta),
        );

        if ($publicacao === null) {
            return $this->json(['erro' => 'Publicação não encontrada nesta pasta.'], Response::HTTP_NOT_FOUND);
        }

        $lida = $request->request->getBoolean('lida');
        if ($publicacao->isLida() !== $lida) {
            $publicacao->setLida($lida);
            $this->publicacaoRepository->salvar($publicacao, true);
        }

        return $this->json(['sucesso' => true, 'lida' => $publicacao->isLida()]);
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

        // Só dígitos dos dois lados: a publicação grava o CNJ sem máscara e `Processo` não normaliza
        // — comparar cru deixaria o processo mascarado sem notas, enquanto o UseCase o aceita.
        $numero = preg_replace('/\D+/', '', (string) $publicacao->getNumeroProcesso()) ?? '';
        foreach ($pasta->getPastaProcessos() as $vinculo) {
            if ($numero !== '' && (preg_replace('/\D+/', '', (string) $vinculo->getProcesso()->getNumeroProcesso()) ?? '') === $numero) {
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
