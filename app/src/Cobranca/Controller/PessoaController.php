<?php

declare(strict_types=1);

namespace App\Cobranca\Controller;

use App\Cobranca\DTO\AdicionarEmailPessoaInput;
use App\Cobranca\DTO\AdicionarEnderecoPessoaInput;
use App\Cobranca\DTO\AdicionarTelefonePessoaInput;
use App\Cobranca\DTO\EditarQualificacaoPessoaInput;
use App\Cobranca\DTO\EditarTelefonePessoaInput;
use App\Cobranca\DTO\EncerrarVinculoInput;
use App\Cobranca\DTO\ExcluirTelefonePessoaInput;
use App\Cobranca\DTO\MarcarEmailAtualInput;
use App\Cobranca\DTO\MarcarEnderecoAtualInput;
use App\Cobranca\DTO\MarcarTelefoneAtualInput;
use App\Cobranca\DTO\VincularPessoaAObjetoInput;
use App\Cobranca\Entity\Pessoa;
use App\Cobranca\Enum\TipoTelefone;
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
use App\Cobranca\UseCase\EditarTelefonePessoaUseCase;
use App\Cobranca\UseCase\EncerrarVinculoUseCase;
use App\Cobranca\UseCase\ExcluirTelefonePessoaUseCase;
use App\Cobranca\UseCase\MarcarEmailAtualUseCase;
use App\Cobranca\UseCase\MarcarEnderecoAtualUseCase;
use App\Cobranca\UseCase\MarcarTelefoneAtualUseCase;
use App\Cobranca\UseCase\MontarFichaPessoaUseCase;
use App\Cobranca\UseCase\VincularPessoaAObjetoUseCase;
use App\Entity\Tenant\Tenant;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

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
        // Corrigir e excluir telefone (2026-07-28). A mesma dupla existe na aba Responsáveis do
        // objeto; aqui é o PRG de sempre da ficha. O `validator` cobre as rotas por-item, que usam o
        // Input DTO sem Form Type (campo único + CSRF por item, como o "marcar como atual").
        private readonly EditarTelefonePessoaUseCase $editarTelefonePessoa,
        private readonly ExcluirTelefonePessoaUseCase $excluirTelefonePessoa,
        private readonly ValidatorInterface $validator,
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

        return $this->render('cobranca/pessoa/show.html.twig', $this->contextoDaFicha($pessoa));
    }

    /**
     * O MESMO conteúdo da ficha, em fragmento, para o modal único da aba Responsáveis
     * (`docs/specs/cobranca-modal-unico-pessoa.md`): as três abas com os quatro blocos que a página já
     * mostra. Rota separada — e não um parâmetro do `show` — porque o que ela devolve não é uma página:
     * não estende o `base.html.twig` e não serve para navegação.
     *
     * Mesma capacidade e mesma resolução tenant-safe do `show`: o modal não vê nada além do que a
     * página protegida já mostrava.
     */
    #[Route('/pessoas/{id}/ficha-modal', name: 'cobranca_pessoa_ficha_modal', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function fichaModal(int $id): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.gerenciar');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $pessoa = $this->resolverPessoa($id, $tenant);

        return $this->render('cobranca/pessoa/_partials/_ficha_abas.html.twig', $this->contextoDaFicha($pessoa));
    }

    /**
     * Os quatro blocos da ficha (qualificação + as três listas) prontos para renderizar. Existe para a
     * PÁGINA e o FRAGMENTO do modal montarem exatamente o mesmo contexto — dois montadores paralelos
     * divergiriam no dia em que um campo entrasse só de um lado.
     *
     * @return array{pessoa: \App\Cobranca\DTO\PessoaFichaOutput, formQualificacao: FormView, formEndereco: FormView, formTelefone: FormView, formEmail: FormView}
     */
    private function contextoDaFicha(Pessoa $pessoa): array
    {
        $id = (int) $pessoa->getId();

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

        return [
            'pessoa' => $this->montarFichaPessoa->executar($pessoa),
            'formQualificacao' => $this->createForm(EditarQualificacaoPessoaType::class, $qualificacaoInput)->createView(),
            'formEndereco' => $this->createForm(AdicionarEnderecoType::class, $enderecoInput)->createView(),
            'formTelefone' => $this->createForm(AdicionarTelefoneType::class, $telefoneInput)->createView(),
            'formEmail' => $this->createForm(AdicionarEmailType::class, $emailInput)->createView(),
        ];
    }

    /**
     * Resposta das mutações da ficha, com DOIS caras (o mesmo par que as rotas de telefone do objeto já
     * usam): em AJAX — o modal — devolve o fragmento das abas já atualizado, para o JS trocar no lugar
     * sem fechar o que o gestor estava fazendo; fora dele, o PRG de sempre para a página da ficha, que
     * continua funcionando exatamente como funcionava.
     *
     * A ficha é remontada DEPOIS da gravação: é ela que traz o item novo, na mesma ordem e com o mesmo
     * selo `Atual` que o `show` mostraria.
     *
     * O FLASH mora aqui, e só no caminho sem AJAX (achado da revisão de 2026-07-28). No modal ninguém
     * lê flash — a mensagem volta no JSON e aparece no rodapé na hora —, mas ela ficava gravada na
     * sessão assim mesmo: cinco edições seguidas viravam cinco avisos empilhados no recarregamento que
     * fecha o modal, inclusive os erros que o gestor já tinha corrigido ali dentro.
     */
    private function respostaFicha(Request $request, Pessoa $pessoa, bool $ok, string $mensagem, bool $jaAvisado = false): Response
    {
        if (!$request->isXmlHttpRequest()) {
            // `$jaAvisado`: o form inválido já passou pelo `flashErrosDoForm`, que mostra TODOS os erros
            // — o comportamento da página desde sempre. Flashar aqui de novo só repetiria o primeiro.
            if (!$jaAvisado) {
                $this->addFlash($ok ? 'success' : 'danger', $mensagem);
            }

            return $this->redirectToRoute('cobranca_pessoa_show', ['id' => $pessoa->getId()]);
        }

        return $this->json([
            'ok' => $ok,
            'mensagem' => $mensagem,
            'html' => $this->renderView('cobranca/pessoa/_partials/_ficha_abas.html.twig', $this->contextoDaFicha($pessoa)),
        ]);
    }

    #[Route('/pessoas/{id}/qualificacao', name: 'cobranca_pessoa_qualificacao_editar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function editarQualificacao(int $id, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.gerenciar');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $pessoa = $this->resolverPessoa($id, $tenant);

        $input = new EditarQualificacaoPessoaInput();
        $input->pessoaId = $id;
        $form = $this->createForm(EditarQualificacaoPessoaType::class, $input);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            if (!$request->isXmlHttpRequest()) {
                $this->flashErrosDoForm($form);
            }

            return $this->respostaFicha($request, $pessoa, false, $this->primeiroErro($form, 'Não foi possível salvar a qualificação.'), jaAvisado: true);
        }

        try {
            $this->editarQualificacaoPessoa->executar($input, $tenant);
        } catch (PessoaNaoEncontradaException $e) {
            return $this->respostaFicha($request, $pessoa, false, $e->getMessage());
        }

        return $this->respostaFicha($request, $pessoa, true, 'Qualificação atualizada.');
    }

    #[Route('/pessoas/{id}/enderecos', name: 'cobranca_pessoa_endereco_adicionar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function adicionarEndereco(int $id, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.gerenciar');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $pessoa = $this->resolverPessoa($id, $tenant);

        $input = new AdicionarEnderecoPessoaInput();
        $input->pessoaId = $id;
        $form = $this->createForm(AdicionarEnderecoType::class, $input);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            if (!$request->isXmlHttpRequest()) {
                $this->flashErrosDoForm($form);
            }

            return $this->respostaFicha($request, $pessoa, false, $this->primeiroErro($form, 'Não foi possível adicionar o endereço.'), jaAvisado: true);
        }

        try {
            $this->adicionarEnderecoPessoa->executar($input, $tenant, $this->usuarioLogado());
        } catch (PessoaNaoEncontradaException $e) {
            return $this->respostaFicha($request, $pessoa, false, $e->getMessage());
        }

        return $this->respostaFicha($request, $pessoa, true, 'Endereço adicionado.');
    }

    #[Route('/pessoas/{id}/enderecos/{itemId}/atual', name: 'cobranca_pessoa_endereco_atual', methods: ['POST'], requirements: ['id' => '\d+', 'itemId' => '\d+'])]
    public function marcarEnderecoAtualAction(int $id, int $itemId, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.gerenciar');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $pessoa = $this->resolverPessoa($id, $tenant);

        if (!$this->isCsrfTokenValid('marcar_endereco_atual_' . $itemId, (string) $request->request->get('_token'))) {
            return $this->respostaFicha($request, $pessoa, false, 'Token de segurança inválido.');
        }

        $input = new MarcarEnderecoAtualInput();
        $input->pessoaId = $id;
        $input->enderecoId = $itemId;

        try {
            $this->marcarEnderecoAtual->executar($input, $tenant);
        } catch (PessoaNaoEncontradaException | PessoaEnderecoNaoEncontradoException $e) {
            return $this->respostaFicha($request, $pessoa, false, $e->getMessage());
        }

        return $this->respostaFicha($request, $pessoa, true, 'Endereço marcado como atual.');
    }

    #[Route('/pessoas/{id}/telefones', name: 'cobranca_pessoa_telefone_adicionar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function adicionarTelefone(int $id, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.gerenciar');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $pessoa = $this->resolverPessoa($id, $tenant);

        $input = new AdicionarTelefonePessoaInput();
        $input->pessoaId = $id;
        $form = $this->createForm(AdicionarTelefoneType::class, $input);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            if (!$request->isXmlHttpRequest()) {
                $this->flashErrosDoForm($form);
            }

            return $this->respostaFicha($request, $pessoa, false, $this->primeiroErro($form, 'Não foi possível adicionar o telefone.'), jaAvisado: true);
        }

        try {
            $this->adicionarTelefonePessoa->executar($input, $tenant, $this->usuarioLogado());
        } catch (PessoaNaoEncontradaException $e) {
            return $this->respostaFicha($request, $pessoa, false, $e->getMessage());
        }

        return $this->respostaFicha($request, $pessoa, true, 'Telefone adicionado.');
    }

    #[Route('/pessoas/{id}/telefones/{itemId}/atual', name: 'cobranca_pessoa_telefone_atual', methods: ['POST'], requirements: ['id' => '\d+', 'itemId' => '\d+'])]
    public function marcarTelefoneAtualAction(int $id, int $itemId, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.gerenciar');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $pessoa = $this->resolverPessoa($id, $tenant);

        if (!$this->isCsrfTokenValid('marcar_telefone_atual_' . $itemId, (string) $request->request->get('_token'))) {
            return $this->respostaFicha($request, $pessoa, false, 'Token de segurança inválido.');
        }

        $input = new MarcarTelefoneAtualInput();
        $input->pessoaId = $id;
        $input->telefoneId = $itemId;

        try {
            $this->marcarTelefoneAtual->executar($input, $tenant);
        } catch (PessoaNaoEncontradaException | PessoaTelefoneNaoEncontradoException $e) {
            return $this->respostaFicha($request, $pessoa, false, $e->getMessage());
        }

        return $this->respostaFicha($request, $pessoa, true, 'Telefone marcado como atual.');
    }

    /**
     * Corrige o NÚMERO de um telefone da ficha (2026-07-28). A mesma ação existe na aba Responsáveis
     * do objeto, servida por `ObjetoController` — o UseCase é o mesmo; muda só o destino da volta.
     *
     * CSRF por item (`editar_telefone_<id>`), como o "marcar como atual" logo acima: token genérico
     * aceitaria trocar o alvo da edição por outro telefone da mesma pessoa.
     */
    #[Route('/pessoas/{id}/telefones/{itemId}/editar', name: 'cobranca_pessoa_telefone_editar', methods: ['POST'], requirements: ['id' => '\d+', 'itemId' => '\d+'])]
    public function editarTelefone(int $id, int $itemId, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.gerenciar');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $pessoa = $this->resolverPessoa($id, $tenant);

        if (!$this->isCsrfTokenValid('editar_telefone_' . $itemId, (string) $request->request->get('_token'))) {
            return $this->respostaFicha($request, $pessoa, false, 'Token de segurança inválido.');
        }

        $input = new EditarTelefonePessoaInput();
        $input->pessoaId = $id;
        $input->telefoneId = $itemId;
        $input->numero = (string) $request->request->get('numero');
        // `tryFrom` e não `from`: valor fora do enum (form adulterado, campo velho) vira NULO, que o
        // UseCase lê como "não mexer no tipo" — em vez de estourar 500 numa correção de telefone.
        $input->tipo = TipoTelefone::tryFrom((string) $request->request->get('tipo', ''));

        // Sem Form Type: campo único numa ação POR LINHA da lista. As regras do número seguem no DTO
        // (fonte única com a rota da aba), validadas aqui — ver `primeiroErroDeValidacao`.
        $erro = $this->primeiroErroDeValidacao($input);
        if ($erro !== null) {
            return $this->respostaFicha($request, $pessoa, false, $erro);
        }

        try {
            $this->editarTelefonePessoa->executar($input, $tenant);
        } catch (PessoaNaoEncontradaException | PessoaTelefoneNaoEncontradoException $e) {
            return $this->respostaFicha($request, $pessoa, false, $e->getMessage());
        }

        return $this->respostaFicha($request, $pessoa, true, 'Telefone corrigido.');
    }

    /**
     * Exclui um telefone da ficha (2026-07-28). Excluir o ATUAL promove o mais recente que sobrou
     * (decisão do dono) — quem faz isso é o UseCase; aqui só se diz QUAL número passou a ser o atual,
     * senão o selo muda de linha sozinho e o gestor descobre por conta própria.
     */
    #[Route('/pessoas/{id}/telefones/{itemId}/excluir', name: 'cobranca_pessoa_telefone_excluir', methods: ['POST'], requirements: ['id' => '\d+', 'itemId' => '\d+'])]
    public function excluirTelefone(int $id, int $itemId, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.gerenciar');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $pessoa = $this->resolverPessoa($id, $tenant);

        if (!$this->isCsrfTokenValid('excluir_telefone_' . $itemId, (string) $request->request->get('_token'))) {
            return $this->respostaFicha($request, $pessoa, false, 'Token de segurança inválido.');
        }

        $input = new ExcluirTelefonePessoaInput();
        $input->pessoaId = $id;
        $input->telefoneId = $itemId;

        try {
            $promovido = $this->excluirTelefonePessoa->executar($input, $tenant);
        } catch (PessoaNaoEncontradaException | PessoaTelefoneNaoEncontradoException $e) {
            return $this->respostaFicha($request, $pessoa, false, $e->getMessage());
        }

        $mensagem = $promovido === null
            ? 'Telefone excluído.'
            : 'Telefone excluído. ' . $promovido->getNumero() . ' passou a ser o atual.';
        return $this->respostaFicha($request, $pessoa, true, $mensagem);
    }

    /**
     * Primeira mensagem de erro do form, para o aviso inline do modal (que não lê flash). O padrão
     * cobre o form recusado sem erro nomeado — CSRF inválido, por exemplo.
     */
    private function primeiroErro(FormInterface $form, string $padrao): string
    {
        foreach ($form->getErrors(true) as $erro) {
            return $erro->getMessage();
        }

        return $padrao;
    }

    /**
     * Primeira violação das regras do DTO, em texto — `null` quando está tudo certo. Existe para as
     * rotas por-item, que não passam por Form Type mas usam o MESMO Input do resto do domínio.
     */
    private function primeiroErroDeValidacao(object $input): ?string
    {
        foreach ($this->validator->validate($input) as $violacao) {
            return (string) $violacao->getMessage();
        }

        return null;
    }

    #[Route('/pessoas/{id}/emails', name: 'cobranca_pessoa_email_adicionar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function adicionarEmail(int $id, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.gerenciar');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $pessoa = $this->resolverPessoa($id, $tenant);

        $input = new AdicionarEmailPessoaInput();
        $input->pessoaId = $id;
        $form = $this->createForm(AdicionarEmailType::class, $input);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            if (!$request->isXmlHttpRequest()) {
                $this->flashErrosDoForm($form);
            }

            return $this->respostaFicha($request, $pessoa, false, $this->primeiroErro($form, 'Não foi possível adicionar o e-mail.'), jaAvisado: true);
        }

        try {
            $this->adicionarEmailPessoa->executar($input, $tenant, $this->usuarioLogado());
        } catch (PessoaNaoEncontradaException $e) {
            return $this->respostaFicha($request, $pessoa, false, $e->getMessage());
        }

        return $this->respostaFicha($request, $pessoa, true, 'E-mail adicionado.');
    }

    #[Route('/pessoas/{id}/emails/{itemId}/atual', name: 'cobranca_pessoa_email_atual', methods: ['POST'], requirements: ['id' => '\d+', 'itemId' => '\d+'])]
    public function marcarEmailAtualAction(int $id, int $itemId, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.gerenciar');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $pessoa = $this->resolverPessoa($id, $tenant);

        if (!$this->isCsrfTokenValid('marcar_email_atual_' . $itemId, (string) $request->request->get('_token'))) {
            return $this->respostaFicha($request, $pessoa, false, 'Token de segurança inválido.');
        }

        $input = new MarcarEmailAtualInput();
        $input->pessoaId = $id;
        $input->emailId = $itemId;

        try {
            $this->marcarEmailAtual->executar($input, $tenant);
        } catch (PessoaNaoEncontradaException | PessoaEmailNaoEncontradoException $e) {
            return $this->respostaFicha($request, $pessoa, false, $e->getMessage());
        }

        return $this->respostaFicha($request, $pessoa, true, 'E-mail marcado como atual.');
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
