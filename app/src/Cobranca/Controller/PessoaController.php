<?php

declare(strict_types=1);

namespace App\Cobranca\Controller;

use App\Cobranca\DTO\AdicionarEmailPessoaInput;
use App\Cobranca\DTO\AdicionarEnderecoPessoaInput;
use App\Cobranca\DTO\AdicionarTelefonePessoaInput;
use App\Cobranca\DTO\EditarQualificacaoPessoaInput;
use App\Cobranca\DTO\EncerrarVinculoInput;
use App\Cobranca\DTO\MarcarEmailAtualInput;
use App\Cobranca\DTO\MarcarEnderecoAtualInput;
use App\Cobranca\DTO\MarcarTelefoneAtualInput;
use App\Cobranca\DTO\VincularPessoaAObjetoInput;
use App\Cobranca\Entity\Pessoa;
use App\Cobranca\Exception\ObjetoNaoEncontradoException;
use App\Cobranca\Exception\PessoaEmailNaoEncontradoException;
use App\Cobranca\Exception\PessoaEnderecoNaoEncontradoException;
use App\Cobranca\Exception\PessoaNaoEncontradaException;
use App\Cobranca\Exception\PessoaTelefoneNaoEncontradoException;
use App\Cobranca\Exception\VinculoJaEncerradoException;
use App\Cobranca\Exception\VinculoNaoEncontradoException;
use App\Cobranca\Form\AdicionarEmailType;
use App\Cobranca\Form\AdicionarEnderecoType;
use App\Cobranca\Form\AdicionarTelefoneType;
use App\Cobranca\Form\EditarQualificacaoPessoaType;
use App\Cobranca\Form\EncerrarVinculoType;
use App\Cobranca\Form\VincularPessoaAObjetoType;
use App\Cobranca\Repository\ObjetoCobrancaRepository;
use App\Cobranca\Repository\PessoaRepository;
use App\Cobranca\Repository\VinculoPessoaObjetoRepository;
use App\Cobranca\UseCase\AdicionarEmailPessoaUseCase;
use App\Cobranca\UseCase\AdicionarEnderecoPessoaUseCase;
use App\Cobranca\UseCase\AdicionarTelefonePessoaUseCase;
use App\Cobranca\UseCase\EditarQualificacaoPessoaUseCase;
use App\Cobranca\UseCase\EncerrarVinculoUseCase;
use App\Cobranca\UseCase\MarcarEmailAtualUseCase;
use App\Cobranca\UseCase\MarcarEnderecoAtualUseCase;
use App\Cobranca\UseCase\MarcarTelefoneAtualUseCase;
use App\Cobranca\UseCase\MontarFichaPessoaUseCase;
use App\Cobranca\UseCase\VincularPessoaAObjetoUseCase;
use App\Entity\Tenant\Tenant;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Mutações de Pessoa e Vínculo (Onda 8B-E) + ficha da pessoa (spec de qualificação §7, Ponto #1-B).
 * Controller FINO: gate módulo + capacidade `resources.cobranca.gerenciar`, resolução tenant-safe por
 * id (anti-IDOR → 404), Form → UseCase, PRG sempre. Nenhuma regra de negócio aqui — os UseCases fazem
 * flush internamente.
 */
#[Route('/cobrancas')]
#[IsGranted('ROLE_USER')]
final class PessoaController extends AbstractController
{
    use AutorizacaoCobranca;

    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
        private readonly PessoaRepository $pessoaRepository,
        private readonly ObjetoCobrancaRepository $objetoRepository,
        private readonly VinculoPessoaObjetoRepository $vinculoRepository,
        private readonly VincularPessoaAObjetoUseCase $vincularPessoa,
        private readonly EncerrarVinculoUseCase $encerrarVinculo,
        private readonly MontarFichaPessoaUseCase $montarFichaPessoa,
        private readonly EditarQualificacaoPessoaUseCase $editarQualificacaoPessoa,
        private readonly AdicionarEnderecoPessoaUseCase $adicionarEnderecoPessoa,
        private readonly AdicionarTelefonePessoaUseCase $adicionarTelefonePessoa,
        private readonly AdicionarEmailPessoaUseCase $adicionarEmailPessoa,
        private readonly MarcarEnderecoAtualUseCase $marcarEnderecoAtual,
        private readonly MarcarTelefoneAtualUseCase $marcarTelefoneAtual,
        private readonly MarcarEmailAtualUseCase $marcarEmailAtual,
    ) {
    }

    #[Route('/objetos/{id}/vinculos', name: 'cobranca_vinculo_criar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function vincular(int $id, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.gerenciar');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $objeto = $this->objetoRepository->findOneByIdDoTenant($id, $tenant);
        if ($objeto === null) {
            throw $this->createNotFoundException('Objeto de cobrança não encontrado.');
        }

        $input = new VincularPessoaAObjetoInput();
        $input->objetoId = $id;
        $form = $this->createForm(VincularPessoaAObjetoType::class, $input, ['pessoas' => $this->pessoaRepository->opcoesDoTenant($tenant)]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->vincularPessoa->executar($input, $tenant, $this->usuarioLogado());
                $this->addFlash('success', 'Pessoa vinculada ao objeto.');
            } catch (PessoaNaoEncontradaException | ObjetoNaoEncontradoException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        } else {
            $this->flashErrosDoForm($form);
        }

        // Ajuste 2: pessoas/vínculos são geridos DENTRO do objeto — volta para a página do objeto.
        return $this->redirectToRoute('cobranca_objeto_show', ['id' => $id]);
    }

    #[Route('/vinculos/{id}/encerrar', name: 'cobranca_vinculo_encerrar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function encerrar(int $id, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.gerenciar');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $vinculo = $this->vinculoRepository->findOneByIdDoTenant($id, $tenant);
        if ($vinculo === null) {
            throw $this->createNotFoundException('Vínculo não encontrado.');
        }
        $objetoId = $vinculo->getObjeto()?->getId();

        $input = new EncerrarVinculoInput();
        $input->vinculoId = $id;
        $form = $this->createForm(EncerrarVinculoType::class, $input);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->encerrarVinculo->executar($input, $tenant);
                $this->addFlash('success', 'Vínculo encerrado.');
            } catch (VinculoNaoEncontradoException | VinculoJaEncerradoException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        } else {
            $this->flashErrosDoForm($form);
        }

        // Ajuste 2: volta para a página do objeto (onde os vínculos são geridos).
        return $this->redirectToRoute('cobranca_objeto_show', ['id' => $objetoId]);
    }

    /**
     * Ficha completa da pessoa (spec de qualificação §7): qualificação + as 3 listas. Monta os forms de
     * edição/adição já apontados para as respectivas rotas de mutação (form_start define a action no
     * template, como o restante do módulo).
     */
    #[Route('/pessoas/{id}', name: 'cobranca_pessoa_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.gerenciar');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $pessoa = $this->resolverPessoa($id, $tenant);
        $ficha = $this->montarFichaPessoa->executar($pessoa);

        $qualificacaoInput = new EditarQualificacaoPessoaInput();
        $qualificacaoInput->pessoaId = $id;
        $qualificacaoInput->nome = $pessoa->getNome();
        $qualificacaoInput->cpf = $pessoa->getCpf();
        $qualificacaoInput->cnpj = $pessoa->getCnpj();
        $qualificacaoInput->observacao = $pessoa->getObservacao();
        $qualificacaoInput->dataNascimento = $pessoa->getDataNascimento();
        $qualificacaoInput->estadoCivil = $pessoa->getEstadoCivil();
        $qualificacaoInput->profissao = $pessoa->getProfissao();
        $qualificacaoInput->rg = $pessoa->getRg();
        $qualificacaoInput->orgaoEmissorRg = $pessoa->getOrgaoEmissorRg();

        $enderecoInput = new AdicionarEnderecoPessoaInput();
        $enderecoInput->pessoaId = $id;

        $telefoneInput = new AdicionarTelefonePessoaInput();
        $telefoneInput->pessoaId = $id;

        $emailInput = new AdicionarEmailPessoaInput();
        $emailInput->pessoaId = $id;

        return $this->render('cobranca/pessoa/show.html.twig', [
            'pessoa' => $ficha,
            'formQualificacao' => $this->createForm(EditarQualificacaoPessoaType::class, $qualificacaoInput)->createView(),
            'formEndereco' => $this->createForm(AdicionarEnderecoType::class, $enderecoInput)->createView(),
            'formTelefone' => $this->createForm(AdicionarTelefoneType::class, $telefoneInput)->createView(),
            'formEmail' => $this->createForm(AdicionarEmailType::class, $emailInput)->createView(),
        ]);
    }

    #[Route('/pessoas/{id}/qualificacao', name: 'cobranca_pessoa_qualificacao_editar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function editarQualificacao(int $id, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.gerenciar');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $this->resolverPessoa($id, $tenant);

        $input = new EditarQualificacaoPessoaInput();
        $input->pessoaId = $id;
        $form = $this->createForm(EditarQualificacaoPessoaType::class, $input);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->editarQualificacaoPessoa->executar($input, $tenant);
                $this->addFlash('success', 'Qualificação atualizada.');
            } catch (PessoaNaoEncontradaException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        } else {
            $this->flashErrosDoForm($form);
        }

        return $this->redirectToRoute('cobranca_pessoa_show', ['id' => $id]);
    }

    #[Route('/pessoas/{id}/enderecos', name: 'cobranca_pessoa_endereco_adicionar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function adicionarEndereco(int $id, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.gerenciar');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $this->resolverPessoa($id, $tenant);

        $input = new AdicionarEnderecoPessoaInput();
        $input->pessoaId = $id;
        $form = $this->createForm(AdicionarEnderecoType::class, $input);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->adicionarEnderecoPessoa->executar($input, $tenant, $this->usuarioLogado());
                $this->addFlash('success', 'Endereço adicionado.');
            } catch (PessoaNaoEncontradaException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        } else {
            $this->flashErrosDoForm($form);
        }

        return $this->redirectToRoute('cobranca_pessoa_show', ['id' => $id]);
    }

    #[Route('/pessoas/{id}/enderecos/{itemId}/atual', name: 'cobranca_pessoa_endereco_atual', methods: ['POST'], requirements: ['id' => '\d+', 'itemId' => '\d+'])]
    public function marcarEnderecoAtualAction(int $id, int $itemId, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.gerenciar');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $this->resolverPessoa($id, $tenant);

        if (!$this->isCsrfTokenValid('marcar_endereco_atual_' . $itemId, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de segurança inválido.');

            return $this->redirectToRoute('cobranca_pessoa_show', ['id' => $id]);
        }

        $input = new MarcarEnderecoAtualInput();
        $input->pessoaId = $id;
        $input->enderecoId = $itemId;

        try {
            $this->marcarEnderecoAtual->executar($input, $tenant);
            $this->addFlash('success', 'Endereço marcado como atual.');
        } catch (PessoaNaoEncontradaException | PessoaEnderecoNaoEncontradoException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('cobranca_pessoa_show', ['id' => $id]);
    }

    #[Route('/pessoas/{id}/telefones', name: 'cobranca_pessoa_telefone_adicionar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function adicionarTelefone(int $id, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.gerenciar');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $this->resolverPessoa($id, $tenant);

        $input = new AdicionarTelefonePessoaInput();
        $input->pessoaId = $id;
        $form = $this->createForm(AdicionarTelefoneType::class, $input);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->adicionarTelefonePessoa->executar($input, $tenant, $this->usuarioLogado());
                $this->addFlash('success', 'Telefone adicionado.');
            } catch (PessoaNaoEncontradaException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        } else {
            $this->flashErrosDoForm($form);
        }

        return $this->redirectToRoute('cobranca_pessoa_show', ['id' => $id]);
    }

    #[Route('/pessoas/{id}/telefones/{itemId}/atual', name: 'cobranca_pessoa_telefone_atual', methods: ['POST'], requirements: ['id' => '\d+', 'itemId' => '\d+'])]
    public function marcarTelefoneAtualAction(int $id, int $itemId, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.gerenciar');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $this->resolverPessoa($id, $tenant);

        if (!$this->isCsrfTokenValid('marcar_telefone_atual_' . $itemId, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de segurança inválido.');

            return $this->redirectToRoute('cobranca_pessoa_show', ['id' => $id]);
        }

        $input = new MarcarTelefoneAtualInput();
        $input->pessoaId = $id;
        $input->telefoneId = $itemId;

        try {
            $this->marcarTelefoneAtual->executar($input, $tenant);
            $this->addFlash('success', 'Telefone marcado como atual.');
        } catch (PessoaNaoEncontradaException | PessoaTelefoneNaoEncontradoException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('cobranca_pessoa_show', ['id' => $id]);
    }

    #[Route('/pessoas/{id}/emails', name: 'cobranca_pessoa_email_adicionar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function adicionarEmail(int $id, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.gerenciar');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $this->resolverPessoa($id, $tenant);

        $input = new AdicionarEmailPessoaInput();
        $input->pessoaId = $id;
        $form = $this->createForm(AdicionarEmailType::class, $input);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->adicionarEmailPessoa->executar($input, $tenant, $this->usuarioLogado());
                $this->addFlash('success', 'E-mail adicionado.');
            } catch (PessoaNaoEncontradaException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        } else {
            $this->flashErrosDoForm($form);
        }

        return $this->redirectToRoute('cobranca_pessoa_show', ['id' => $id]);
    }

    #[Route('/pessoas/{id}/emails/{itemId}/atual', name: 'cobranca_pessoa_email_atual', methods: ['POST'], requirements: ['id' => '\d+', 'itemId' => '\d+'])]
    public function marcarEmailAtualAction(int $id, int $itemId, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.gerenciar');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $this->resolverPessoa($id, $tenant);

        if (!$this->isCsrfTokenValid('marcar_email_atual_' . $itemId, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de segurança inválido.');

            return $this->redirectToRoute('cobranca_pessoa_show', ['id' => $id]);
        }

        $input = new MarcarEmailAtualInput();
        $input->pessoaId = $id;
        $input->emailId = $itemId;

        try {
            $this->marcarEmailAtual->executar($input, $tenant);
            $this->addFlash('success', 'E-mail marcado como atual.');
        } catch (PessoaNaoEncontradaException | PessoaEmailNaoEncontradoException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('cobranca_pessoa_show', ['id' => $id]);
    }

    /**
     * Resolução tenant-safe da Pessoa por id (anti-IDOR): inexistente ou de outro escritório vira 404
     * (spec de qualificação §8 — "itens nunca cruzam tenant").
     */
    private function resolverPessoa(int $id, Tenant $tenant): Pessoa
    {
        $pessoa = $this->pessoaRepository->findOneByIdDoTenant($id, $tenant);
        if ($pessoa === null) {
            throw $this->createNotFoundException('Pessoa não encontrada.');
        }

        return $pessoa;
    }
}
