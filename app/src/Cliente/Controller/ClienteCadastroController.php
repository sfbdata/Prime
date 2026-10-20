<?php

declare(strict_types=1);

namespace App\Cliente\Controller;

use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClienteDocumento;
use App\Cliente\Entity\ClientePF;
use App\Cliente\Exception\CadastroInvalidoException;
use App\Cliente\Exception\DocumentoJaCadastradoException;
use App\Cliente\Exception\SemPermissaoParaEditarClienteException;
use App\Cliente\Repository\ClienteRepository;
use App\Cliente\Repository\ClientePFRepository;
use App\Cliente\Repository\ClientePJRepository;
use App\Cliente\Service\HistoricoDoCliente;
use App\Cliente\Service\PendenciasDoCadastro;
use App\Cliente\Service\QualificacaoDoCliente;
use App\Cliente\UseCase\CriarClienteDaPastaUseCase;
use App\Cliente\UseCase\SalvarCadastroDoClienteUseCase;
use App\Cliente\Entity\ClientePJ;
use App\Pasta\Exception\SemPermissaoParaEditarPastaException;
use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Controller\PastaReuController;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Repository\PastaReuRepository;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Shared\Service\DigitoVerificador;
use App\Twig\DocumentoBrExtension;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Janela "Cadastro e qualificação do cliente" do botão Cadastro da pasta (desenho 1.2.3, `cadAbrir`; E12f).
 *
 * GET devolve o fragmento da janela (ver o cliente basta; sem EDITAR os campos vêm travados). POST salva pelo
 * {@see SalvarCadastroDoClienteUseCase} e devolve JSON. A conferência de documento serve o aviso "Este CPF já possui
 * cadastro no sistema." ao sair do campo. Todas conferem o escritório antes de qualquer coisa (404).
 */
final class ClienteCadastroController extends AbstractController
{
    public static function idDoToken(int $clienteId): string
    {
        return 'cliente_cadastro_' . $clienteId;
    }

    public function __construct(
        private readonly ClienteRepository $clienteRepository,
        private readonly ClientePFRepository $pfRepository,
        private readonly ClientePJRepository $pjRepository,
        private readonly PastaRepository $pastaRepository,
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
        private readonly PendenciasDoCadastro $pendencias,
        private readonly QualificacaoDoCliente $qualificacao,
        private readonly SalvarCadastroDoClienteUseCase $salvar,
        private readonly PastaReuRepository $reuRepository,
        private readonly CriarClienteDaPastaUseCase $criarCliente,
        private readonly HistoricoDoCliente $historico,
    ) {}

    #[Route('/clientes/{id}/cadastro', name: 'cliente_cadastro', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function janela(int $id, Request $request): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        [$cliente, $tenant] = $this->carregar($id);
        if (!$this->permissionChecker->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_CLIENTE, $id, AccessRequest::ACTION_VIEW)) {
            throw $this->createAccessDeniedException('Sem acesso a este cliente.');
        }

        $pasta = $this->pastaDoContexto($request->query->get('pasta'), $tenant);
        // A pasta de contexto só vale para quem pode VÊ-la: senão, nem aba Réu nem "usar outro cadastro" nela.
        if ($pasta !== null && !$this->permissionChecker->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_PASTA, (int) $pasta->getId(), AccessRequest::ACTION_VIEW)) {
            $pasta = null;
        }

        return $this->renderJanela($cliente, $pasta, $usuario, $tenant, $request, false,
            $this->permissionChecker->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_CLIENTE, $id, AccessRequest::ACTION_EDIT));
    }

    /**
     * "Novo cliente" (E12i): a janela sem cadastro, aberta pela pasta. Nome já preenchido com o da pasta (o
     * identificador digitado), a não ser em `?autor=1` (outro autor). `?tipo=pj` troca para empresa. Ver a pasta basta
     * para abrir; editá-la (e ela não estar excluída) para salvar.
     */
    #[Route('/pasta/{id}/cadastro/novo', name: 'pasta_cadastro_novo', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function novo(int $id, Request $request): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        [$pasta, $tenant] = $this->carregarPasta($id, $usuario, AccessRequest::ACTION_VIEW);
        $pj = $request->query->get('tipo') === 'pj';
        $nome = $request->query->get('autor') === '1' ? '' : (string) $pasta->getNomeCliente();
        $cliente = $pj ? (new ClientePJ())->setRazaoSocial($nome)->setCnpj('')->setEnderecSede('')->setRepresentanteLegal('')
                ->setRepresentanteCpf('')->setRepresentanteRg('')->setRepresentanteCargo('')
            : (new ClientePF())->setNomeCompleto($nome)->setCpf('')->setRg('')->setRgOrgaoExpedidor('');
        $cliente->setEmail('')->setCep('')->setEndereco('')->setCidade('')->setEstado('');

        return $this->renderJanela($cliente, $pasta, $usuario, $tenant, $request, true,
            !$pasta->estaExcluida() && $this->permissionChecker->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_PASTA, $id, AccessRequest::ACTION_EDIT));
    }

    #[Route('/pasta/{id}/cadastro/novo', name: 'pasta_cadastro_novo_salvar', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function criar(Pasta $pasta, Request $request): JsonResponse
    {
        // Recebe a Pasta (não o id) para a trava da lápide (PastaSomenteLeituraListener) cobrir a rota.
        /** @var User $usuario */
        $usuario = $this->getUser();
        $id = (int) $pasta->getId();
        [$pasta, $tenant] = $this->carregarPasta($id, $usuario, AccessRequest::ACTION_VIEW);
        if (!$this->isCsrfTokenValid('pasta_cadastro_novo_' . $id, (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Sessão expirada. Recarregue a página e tente de novo.'], Response::HTTP_FORBIDDEN);
        }
        $campos = $request->request->all();
        unset($campos['_token'], $campos['pasta'], $campos['tipo']);
        try {
            $cliente = $this->criarCliente->executar($pasta, $request->request->get('tipo') === 'pj' ? 'pj' : 'pf',
                array_map('strval', array_filter($campos, 'is_scalar')), $usuario, $tenant);
        } catch (SemPermissaoParaEditarPastaException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        } catch (DocumentoJaCadastradoException $e) {
            $visivel = $this->permissionChecker->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_CLIENTE, (int) $e->existente->getId(), AccessRequest::ACTION_VIEW);

            return $this->json(['erro' => $e->getMessage(), 'existente' => $visivel ? $this->resumoDe($e->existente) : null], Response::HTTP_CONFLICT);
        } catch (CadastroInvalidoException $e) {
            return $this->json(['erro' => $e->getMessage(), 'aba' => $e->aba], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\DomainException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'ok'        => true,
            'criado'    => true,
            'mensagem'  => 'Cadastro salvo.',
            'urlJanela' => $this->generateUrl('cliente_cadastro', ['id' => $cliente->getId(), 'pasta' => $id]),
        ], Response::HTTP_CREATED);
    }

    /** Aviso ao sair do campo CPF/CNPJ no "Novo cliente": o documento já é de outro cadastro do escritório? */
    #[Route('/pasta/{id}/cadastro/novo/documento', name: 'pasta_cadastro_novo_documento', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function documentoNovo(int $id, Request $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        [, $tenant] = $this->carregarPasta($id, $usuario, AccessRequest::ACTION_VIEW);

        return $this->json(['existente' => $this->existenteVisivel((string) $request->query->get('doc', ''), null, $usuario, $tenant)]);
    }

    private function renderJanela(Cliente $cliente, ?Pasta $pasta, User $usuario, Tenant $tenant, Request $request, bool $novo, bool $podeEditar): Response
    {
        $autores = [];
        if ($pasta !== null) {
            foreach ($pasta->getClientes() as $c) {
                $autores[] = ['id' => (int) $c->getId(), 'nome' => $c->getNomeExibicao(), 'principal' => $pasta->getClientePrincipal() === $c];
            }
        }

        return $this->render('cliente/_cadastro.html.twig', [
            'cliente'      => $cliente,
            'novo'         => $novo,
            'pf'           => $cliente instanceof ClientePF,
            'pendencias'   => $this->pendencias->porAba($cliente),
            // Lista (não objeto) para o JS somar às que ele recalcula: as de documento não dependem do formulário.
            'pendenciasDocs' => array_values(array_filter($this->pendencias->porAba($cliente), static fn (array $p): bool => $p['aba'] === 'docs')),
            'qualificacao' => $this->qualificacao->de($cliente),
            'podeEditar'   => $podeEditar,
            'pasta'        => $pasta,
            'podeVincular' => $pasta !== null && !$pasta->estaExcluida() && $this->permissionChecker->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_PASTA, (int) $pasta->getId(), AccessRequest::ACTION_EDIT),
            'estadosCivis' => SalvarCadastroDoClienteUseCase::ESTADOS_CIVIS,
            'docs'         => $novo ? [] : $this->documentosDoCadastro($cliente),
            // Aba Histórico (E12 3d): as 40 mais novas, primeiro a mais nova; cadastro novo mostra o vazio.
            'historico'    => $novo ? [] : $this->historico->paraTela($cliente, $tenant),
            // Aba Réu (E12h): só com a pasta de contexto, porque o réu é da pasta, não do cliente.
            'reus'         => $pasta !== null ? PastaReuController::paraTela($this->reuRepository->daPasta($pasta, $tenant)) : null,
            // Seletor de autores (decisão do dono, E12i): os clientes da pasta, para trocar sem fechar a janela.
            'autores'      => $autores,
            'abaInicial'   => \in_array($request->query->get('aba'), ['pessoais', 'reu', 'endereco', 'contato', 'docs', 'qual', 'hist'], true) ? $request->query->get('aba') : 'pessoais',
            'mostrarOcr'   => $request->query->get('ocr') === '1',
        ]);
    }

    #[Route('/clientes/{id}/cadastro', name: 'cliente_cadastro_salvar', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function salvar(int $id, Request $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        [$cliente, $tenant] = $this->carregar($id);
        if (!$this->isCsrfTokenValid(self::idDoToken($id), (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Sessão expirada. Recarregue a página e tente de novo.'], Response::HTTP_FORBIDDEN);
        }

        $campos = $request->request->all();
        unset($campos['_token'], $campos['pasta']);
        try {
            $alterados = $this->salvar->executar($cliente, array_map('strval', array_filter($campos, 'is_scalar')), $usuario, $tenant,
                $this->pastaDoContexto($request->request->get('pasta'), $tenant));
        } catch (SemPermissaoParaEditarClienteException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        } catch (DocumentoJaCadastradoException $e) {
            // Só mostra quem é o outro cadastro a quem pode vê-lo (mesma regra da conferência ao sair do campo).
            $visivel = $this->permissionChecker->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_CLIENTE, (int) $e->existente->getId(), AccessRequest::ACTION_VIEW);

            return $this->json(['erro' => $e->getMessage(), 'existente' => $visivel ? $this->resumoDe($e->existente) : null], Response::HTTP_CONFLICT);
        } catch (CadastroInvalidoException $e) {
            return $this->json(['erro' => $e->getMessage(), 'aba' => $e->aba], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $pendencias = $this->pendencias->porAba($cliente);

        return $this->json([
            'ok'           => true,
            'mudou'        => $alterados !== [],
            'alterados'    => $alterados,
            'mensagem'     => $pendencias === [] ? 'Cadastro salvo.' : 'Cadastro salvo. Ainda há pendências.',
            'pendencias'   => $pendencias,
            'qualificacao' => $this->qualificacao->de($cliente),
        ]);
    }

    /** Aviso ao sair do campo CPF/CNPJ: o documento já é de outro cadastro do escritório? */
    #[Route('/clientes/{id}/cadastro/documento', name: 'cliente_cadastro_documento', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function documento(int $id, Request $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        [$cliente, $tenant] = $this->carregar($id);
        if (!$this->permissionChecker->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_CLIENTE, $id, AccessRequest::ACTION_VIEW)) {
            throw $this->createAccessDeniedException('Sem acesso a este cliente.');
        }

        return $this->json(['existente' => $this->existenteVisivel((string) $request->query->get('doc', ''), $cliente, $usuario, $tenant)]);
    }

    /** @return array{id: int, nome: string, documento: string, completo: bool}|null */
    private function existenteVisivel(string $doc, ?Cliente $excluir, User $usuario, Tenant $tenant): ?array
    {
        $digitos = DigitoVerificador::digitos($doc);
        $outro = match (true) {
            strlen($digitos) === 11 => $this->pfRepository->findOneByCpfDoTenant($digitos, $tenant),
            strlen($digitos) === 14 => $this->pjRepository->findOneByCnpjDoTenant($digitos, $tenant),
            default => null,
        };
        $visivel = $outro !== null && ($excluir === null || $outro->getId() !== $excluir->getId())
            && $this->permissionChecker->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_CLIENTE, (int) $outro->getId(), AccessRequest::ACTION_VIEW);

        return $visivel ? $this->resumoDe($outro) : null;
    }

    /** @return array{0: Pasta, 1: Tenant} */
    private function carregarPasta(int $id, User $usuario, string $acao): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        $pasta = $tenant !== null ? $this->pastaRepository->find($id) : null;
        if ($tenant === null || $pasta === null || $pasta->getTenant()?->getId() !== $tenant->getId()) {
            throw $this->createNotFoundException('Pasta não encontrada');
        }
        if (!$this->permissionChecker->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_PASTA, $id, $acao)) {
            throw $this->createAccessDeniedException('Sem acesso a esta pasta.');
        }

        return [$pasta, $tenant];
    }

    /**
     * Os dois cartões da aba Documentos (desenho 1.2.3): o anexo mais recente de cada categoria, ou null.
     *
     * @return array<string, ?ClienteDocumento>
     */
    private function documentosDoCadastro(Cliente $cliente): array
    {
        $docs = [ClienteDocumento::CATEGORIA_IDENTIFICACAO => null, ClienteDocumento::CATEGORIA_COMPROVANTE_RESIDENCIA => null];
        foreach ($cliente->getDocumentos() as $doc) {
            $cat = $doc->getCategoria();
            // O vinculado cujo documento da pasta foi para a lixeira some da aba (sem link quebrado).
            if (\array_key_exists($cat, $docs) && $doc->estaDisponivel() && ($docs[$cat] === null || $doc->getUploadedAt() > $docs[$cat]->getUploadedAt())) {
                $docs[$cat] = $doc;
            }
        }

        return $docs;
    }

    /** @return array{0: Cliente, 1: Tenant} */
    private function carregar(int $id): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        $cliente = $tenant !== null ? $this->clienteRepository->find($id) : null;
        if ($tenant === null || $cliente === null || $cliente->getTenant()?->getId() !== $tenant->getId()) {
            throw $this->createNotFoundException('Cliente não encontrado');
        }

        return [$cliente, $tenant];
    }

    private function pastaDoContexto(mixed $pastaId, Tenant $tenant): ?Pasta
    {
        if (!\is_string($pastaId) || !ctype_digit($pastaId)) {
            return null;
        }
        $pasta = $this->pastaRepository->find((int) $pastaId);

        return $pasta !== null && $pasta->getTenant()?->getId() === $tenant->getId() ? $pasta : null;
    }

    /** @return array{id: int, nome: string, documento: string, completo: bool} */
    private function resumoDe(Cliente $c): array
    {
        $doc = $c instanceof ClientePF ? $c->getCpf() : (method_exists($c, 'getCnpj') ? $c->getCnpj() : '');

        return [
            'id'        => (int) $c->getId(),
            'nome'      => $c->getNomeExibicao(),
            'documento' => trim((new DocumentoBrExtension())->documentoBrRotulo($doc) . ' ' . (new DocumentoBrExtension())->documentoBr($doc)),
            'completo'  => $this->pendencias->estaCompleto($c),
        ];
    }
}
