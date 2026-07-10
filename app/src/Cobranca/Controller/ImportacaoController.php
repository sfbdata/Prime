<?php

declare(strict_types=1);

namespace App\Cobranca\Controller;

use App\Cobranca\DTO\ImportarRelatorioInput;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Form\ImportarRelatorioType;
use App\Cobranca\Repository\CarteiraRepository;
use App\Cobranca\Service\Importacao\TopLifeInadimplenciaAdapter;
use App\Cobranca\UseCase\ImportarRelatorioCarteiraUseCase;
use App\Entity\Tenant\Tenant;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Importação visual de um relatório de inadimplência para dentro de UMA Carteira (Etapa 8C, SPEC §21).
 * Fluxo de 2 passos: upload → prever (dry-run, não persiste) → confirmar (transacional, idempotente).
 *
 * O adapter lê de um CAMINHO de arquivo e o `ResultadoLeitura` não é serializável, então o arquivo
 * enviado é guardado num diretório temporário ISOLADO POR TENANT (`import-tmp/<tenantId>/<token>.xlsx`,
 * token gerado no servidor → sem path traversal) entre o preview e a confirmação; o ponteiro fica na
 * SESSÃO (por-usuário → sem IDOR). A confirmação re-lê o mesmo arquivo, importa e apaga o temporário.
 *
 * Controller FINO: gate de módulo + capacidade, resolução tenant-safe da Carteira (anti-IDOR → 404),
 * validação do upload no Form/DTO, delega ao UseCase. Nenhuma regra de negócio aqui.
 */
#[Route('/cobrancas')]
#[IsGranted('ROLE_USER')]
final class ImportacaoController extends AbstractController
{
    use AutorizacaoCobranca;

    private const CAPACIDADE = 'resources.cobranca.gerenciar';

    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
        private readonly CarteiraRepository $carteiraRepository,
        private readonly TopLifeInadimplenciaAdapter $adapter,
        private readonly ImportarRelatorioCarteiraUseCase $importar,
        private readonly string $cobrancasUploadsDir,
    ) {
    }

    #[Route('/carteiras/{id}/importar', name: 'cobranca_importacao_upload', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function upload(int $id): Response
    {
        $tenant = $this->tenantComCapacidade(self::CAPACIDADE);
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $carteira = $this->resolverCarteira($id, $tenant);

        return $this->render('cobranca/importacao/upload.html.twig', [
            'carteiraId' => $carteira->getId(),
            'carteiraNome' => $carteira->getNome(),
            'form' => $this->createForm(ImportarRelatorioType::class)->createView(),
        ]);
    }

    #[Route('/carteiras/{id}/importar/prever', name: 'cobranca_importacao_prever', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function prever(int $id, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade(self::CAPACIDADE);
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $carteira = $this->resolverCarteira($id, $tenant);

        $input = new ImportarRelatorioInput();
        $form = $this->createForm(ImportarRelatorioType::class, $input);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->flashErrosDoForm($form);

            return $this->redirectToRoute('cobranca_importacao_upload', ['id' => $id]);
        }

        /** @var UploadedFile $arquivo */
        $arquivo = $input->arquivo;
        $nomeOriginal = $arquivo->getClientOriginalName();

        try {
            $token = $this->guardarTemporario($arquivo, $tenant);
            $leitura = $this->adapter->ler($this->caminhoTemporario($tenant, $token));
        } catch (\Throwable $e) {
            $this->descartarTemporario($tenant, $token ?? '');
            $this->addFlash('danger', 'Não foi possível ler o relatório enviado. Confira se é a planilha correta.');

            return $this->redirectToRoute('cobranca_importacao_upload', ['id' => $id]);
        }

        // Ponteiro do arquivo temporário na SESSÃO (por-usuário) — nunca no cliente.
        $request->getSession()->set($this->chaveSessao($id), ['token' => $token, 'nome' => $nomeOriginal]);

        $resultado = $this->importar->prever($id, $leitura, $tenant);

        return $this->render('cobranca/importacao/preview.html.twig', [
            'carteiraId' => $carteira->getId(),
            'carteiraNome' => $carteira->getNome(),
            'nomeArquivo' => $nomeOriginal,
            'resultado' => $resultado,
            'confirmado' => false,
        ]);
    }

    #[Route('/carteiras/{id}/importar/confirmar', name: 'cobranca_importacao_confirmar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function confirmar(int $id, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade(self::CAPACIDADE);
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $carteira = $this->resolverCarteira($id, $tenant);

        if (!$this->isCsrfTokenValid('importar_confirmar_' . $id, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de segurança inválido. Reenvie o relatório.');

            return $this->redirectToRoute('cobranca_importacao_upload', ['id' => $id]);
        }

        $ponteiro = $request->getSession()->get($this->chaveSessao($id));
        if (!is_array($ponteiro) || !isset($ponteiro['token']) || !$this->tokenValido((string) $ponteiro['token'])) {
            $this->addFlash('warning', 'A sessão da importação expirou. Envie o relatório novamente.');

            return $this->redirectToRoute('cobranca_importacao_upload', ['id' => $id]);
        }

        $token = (string) $ponteiro['token'];
        $caminho = $this->caminhoTemporario($tenant, $token);
        if (!is_file($caminho)) {
            $request->getSession()->remove($this->chaveSessao($id));
            $this->addFlash('warning', 'O arquivo temporário não está mais disponível. Envie o relatório novamente.');

            return $this->redirectToRoute('cobranca_importacao_upload', ['id' => $id]);
        }

        try {
            $leitura = $this->adapter->ler($caminho);
            $resultado = $this->importar->confirmar($id, $leitura, $tenant, $this->usuarioLogado());
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Falha ao importar o relatório. Nenhum dado foi gravado.');

            return $this->redirectToRoute('cobranca_importacao_upload', ['id' => $id]);
        } finally {
            $this->descartarTemporario($tenant, $token);
            $request->getSession()->remove($this->chaveSessao($id));
        }

        $this->addFlash('success', sprintf(
            '%d obrigação(ões) importada(s), %d atualizada(s), %d rejeitada(s).',
            $resultado->totalImportadas(),
            $resultado->totalAtualizadas(),
            $resultado->totalRejeitadas(),
        ));

        return $this->render('cobranca/importacao/preview.html.twig', [
            'carteiraId' => $carteira->getId(),
            'carteiraNome' => $carteira->getNome(),
            'nomeArquivo' => (string) ($ponteiro['nome'] ?? ''),
            'resultado' => $resultado,
            'confirmado' => true,
        ]);
    }

    private function resolverCarteira(int $id, Tenant $tenant): Carteira
    {
        $carteira = $this->carteiraRepository->findOneByIdDoTenant($id, $tenant);
        if ($carteira === null) {
            throw $this->createNotFoundException('Carteira de cobrança não encontrada.');
        }

        return $carteira;
    }

    /** Move o upload para o diretório temporário do tenant e devolve o token (nome do arquivo). */
    private function guardarTemporario(UploadedFile $arquivo, Tenant $tenant): string
    {
        $token = bin2hex(random_bytes(16));
        $arquivo->move($this->diretorioTemporario($tenant), $token . '.xlsx');

        return $token;
    }

    private function descartarTemporario(Tenant $tenant, string $token): void
    {
        if ($token === '' || !$this->tokenValido($token)) {
            return;
        }
        $caminho = $this->caminhoTemporario($tenant, $token);
        if (is_file($caminho)) {
            @unlink($caminho);
        }
    }

    private function diretorioTemporario(Tenant $tenant): string
    {
        return $this->cobrancasUploadsDir . '/import-tmp/' . $tenant->getId();
    }

    private function caminhoTemporario(Tenant $tenant, string $token): string
    {
        return $this->diretorioTemporario($tenant) . '/' . $token . '.xlsx';
    }

    private function tokenValido(string $token): bool
    {
        return preg_match('/^[0-9a-f]{32}$/', $token) === 1;
    }

    private function chaveSessao(int $carteiraId): string
    {
        return 'cobranca_import_' . $carteiraId;
    }
}
