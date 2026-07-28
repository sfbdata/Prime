<?php

declare(strict_types=1);

namespace App\Cobranca\Controller;

use App\Cobranca\DTO\AdicionarTelefonePessoaInput;
use App\Cobranca\DTO\CriarPessoaVinculadaInput;
use App\Cobranca\DTO\EditarConfiguracaoObjetoInput;
use App\Cobranca\DTO\EditarTelefonePessoaInput;
use App\Cobranca\DTO\ExcluirTelefonePessoaInput;
use App\Cobranca\DTO\PessoaFichaOutput;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Entity\Pessoa;
use App\Cobranca\Enum\FormaHonorarios;
use App\Cobranca\Enum\QualificacaoContato;
use App\Cobranca\Enum\TipoTelefone;
use App\Cobranca\Exception\ObjetoNaoEncontradoException;
use App\Cobranca\Exception\PessoaNaoEncontradaException;
use App\Cobranca\Exception\PessoaTelefoneNaoEncontradoException;
use App\Cobranca\Form\AdicionarTelefoneType;
use App\Cobranca\Form\CriarPessoaVinculadaType;
use App\Cobranca\Form\EditarConfiguracaoObjetoType;
use App\Cobranca\Form\EncerrarVinculoType;
use App\Cobranca\Form\VincularPessoaAObjetoType;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\ObjetoCobrancaRepository;
use App\Cobranca\Repository\PessoaRepository;
use App\Cobranca\Service\MontadorModaisCaso;
use App\Cobranca\Service\ResolvedorConfigEncargos;
use App\Cobranca\UseCase\AdicionarTelefonePessoaUseCase;
use App\Cobranca\UseCase\CriarPessoaVinculadaAoObjetoUseCase;
use App\Cobranca\UseCase\EditarConfiguracaoObjetoUseCase;
use App\Cobranca\UseCase\EditarTelefonePessoaUseCase;
use App\Cobranca\UseCase\ExcluirTelefonePessoaUseCase;
use App\Cobranca\UseCase\MontarDetalheObjetoUseCase;
use App\Cobranca\UseCase\MontarFichaPessoaUseCase;
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
 * Camada HTTP da página unificada do Objeto de Cobrança (ajuste 2). Abrir o objeto = ver a cobrança
 * inteira (pessoas + obrigações + pagamentos + acordos + documentos + histórico) numa página só. O
 * `CasoCobranca` continua como âncora invisível (1 por objeto): resolvido aqui de forma tenant-safe e
 * usado para montar o corpo operacional. Controller FINO: gate de módulo, resolução anti-IDOR,
 * delegação ao UseCase de leitura e ao `MontadorModaisCaso`, render de Output DTOs.
 */
#[Route('/cobrancas/objetos')]
#[IsGranted('ROLE_USER')]
final class ObjetoController extends AbstractController
{
    use AutorizacaoCobranca;

    private const MODULO_PASTAS = 'pastas';

    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
        private readonly ObjetoCobrancaRepository $objetoRepository,
        private readonly CasoCobrancaRepository $casoRepository,
        private readonly MontarDetalheObjetoUseCase $montarDetalheObjeto,
        private readonly MontadorModaisCaso $montadorModais,
        private readonly CriarPessoaVinculadaAoObjetoUseCase $criarPessoaVinculada,
        private readonly PessoaRepository $pessoaRepository,
        private readonly EditarConfiguracaoObjetoUseCase $editarConfiguracaoObjeto,
        // Só para EXIBIR no modal de encargos o que a carteira já tem configurado (nível 1 da
        // cascata). Nenhum cálculo passa por aqui — o dinheiro continua sendo resolvido no
        // `EncargosVivos`, na leitura de cada obrigação.
        private readonly ResolvedorConfigEncargos $resolvedorConfigEncargos,
        // Aba Responsáveis: gravar o telefone da cobrada e remontar a ficha para devolver a lista
        // atualizada sem recarregar a página (2026-07-28). Ambos já existiam, servindo a ficha da pessoa.
        private readonly AdicionarTelefonePessoaUseCase $adicionarTelefonePessoa,
        private readonly MontarFichaPessoaUseCase $montarFichaPessoa,
        // Corrigir e excluir telefone na própria aba (2026-07-28). O `validator` serve às rotas
        // por-item, que validam o Input DTO sem passar por Form Type (ver `primeiroErroDeValidacao`).
        private readonly EditarTelefonePessoaUseCase $editarTelefonePessoa,
        private readonly ExcluirTelefonePessoaUseCase $excluirTelefonePessoa,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('/{id}', name: 'cobranca_objeto_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id, Request $request): Response
    {
        $tenant = $this->tenantComModulo();
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $objeto = $this->objetoRepository->findOneByIdDoTenant($id, $tenant);
        if ($objeto === null) {
            throw $this->createNotFoundException('Objeto de cobrança não encontrado.');
        }

        // O caso âncora (1 por objeto). Objeto ainda sem cobrança (raro após a Fatia 3, que cria o caso
        // na criação) volta para a carteira com um aviso — a página do objeto só existe com uma cobrança.
        $caso = $this->casoRepository->casoAncoraDoObjeto($objeto);
        if ($caso === null) {
            $this->addFlash('warning', 'Este objeto ainda não tem cobrança iniciada.');

            return $this->redirectToRoute('cobranca_carteira_show', ['id' => $objeto->getCarteira()?->getId()]);
        }

        // Modais de mutação só para quem tem a capacidade — mesmo gate que os esconde no Twig. Movimentação
        // financeira é capacidade SEPARADA de gerenciar (SPEC §22). Judicializar exige o módulo `pastas`.
        //
        // ⚠️ `$podeGerenciar` AQUI é só a CAPACIDADE. O `podeGerenciar` do Twig
        // (`show.html.twig`) é capacidade **E** caso aberto — mesmo nome, semânticas diferentes. Quem
        // usar esta variável para uma rota que também recusa caso encerrado precisa somar a condição.
        $usuario = $this->usuarioLogado();
        $podeGerenciar = $this->permissionChecker->hasPermission($usuario, $tenant, 'resources.cobranca.gerenciar');
        $podeMovimentar = $this->permissionChecker->hasPermission($usuario, $tenant, 'resources.cobranca.movimentacao_financeira');
        $podeAcessarPastas = $this->permissionChecker->canAccessModule($usuario, $tenant, self::MODULO_PASTAS);

        // B5: se a última mutação falhou na validação, reabrimos aquele modal com o digitado e o erro.
        // One-shot (some na leitura) e por objeto. Consumido sob QUALQUER das duas capacidades porque o
        // erro pode vir tanto de um modal de gerenciar quanto de um financeiro (capacidades separadas).
        $erroModal = ($podeGerenciar || $podeMovimentar) ? $this->consumirErroDeModal($request, $id) : null;

        $forms = $podeGerenciar ? $this->montadorModais->deMutacao($caso, $podeAcessarPastas, $erroModal) : [];
        if ($podeMovimentar) {
            $forms += $this->montadorModais->financeiros($caso, $erroModal);
        }

        $documentos = $this->montadorModais->documentosParaFm($caso);

        // O usuário logado vai junto só para o histórico saber quais anotações ELE pode corrigir nas
        // 48h (2026-07-22) e quais qualificações ELE pode desfazer nos 5 min (2026-07-27) — a decisão
        // é do servidor, nunca do template.
        $detalhe = $this->montarDetalheObjeto->executar($objeto, $caso, $usuario);

        return $this->render('cobranca/objeto/show.html.twig', [
            'objeto' => $detalhe,
            'forms' => $forms,
            'modalErroId' => $erroModal['modalId'] ?? null,
            'modalErroAcao' => $erroModal['acao'] ?? null,
            'casoId' => $caso->getId(),
            'podeGerenciarDocumentos' => $podeGerenciar,
            'secoes' => $documentos['secoes'],
            'arquivosFm' => $documentos['arquivos'],
            // Ações do card da pessoa (Ajuste 10 — era a aba Pessoas) — só para quem gerencia. "Nova
            // pessoa" cadastra+vincula; "Vincular" liga uma pessoa já existente do escritório;
            // "Encerrar vínculo" fecha um vínculo aberto.
            'formNovaPessoa' => $podeGerenciar ? $this->novaPessoaView($erroModal) : null,
            'formVincular' => $podeGerenciar
                ? $this->createForm(VincularPessoaAObjetoType::class, null, ['pessoas' => $this->pessoaRepository->opcoesDoTenant($tenant)])->createView()
                : null,
            'formEncerrarVinculo' => $podeGerenciar ? $this->createForm(EncerrarVinculoType::class)->createView() : null,
            // #9-T3: config de encargos do OBJETO (nível 2 da cascata) — aposenta o editor de
            // honorários do CASO na tela (o backend dele segue dormente, ver MontadorModaisCaso).
            'formConfigEncargos' => $podeGerenciar ? $this->configEncargosObjetoView($objeto, $erroModal) : null,
            // Guarda "Menor da revisão T2": a carteira sem forma percentual de honorários desabilita o
            // override de honorários do objeto no modal (evita "exigível cobra honorário, split zera").
            'carteiraSemHonorarios' => $objeto->getCarteira()?->getFormaHonorarios() === FormaHonorarios::SemPercentual,
            // ── Aba Responsáveis (spec §2.3 e §3, Etapa 6) ────────────────────────────────────────
            // Mini-form inline de adicionar telefone da pessoa COBRADA. Reusa `AdicionarTelefoneType`
            // e a rota da ficha (`cobranca_pessoa_telefone_adicionar`): a aba passou a listar os
            // telefones de verdade, e sem o FormView não há como renderizar o campo com o CSRF que
            // aquela rota espera. Só nasce com a capacidade que a rota exige, e só com cobrada.
            'formTelefoneCobrada' => ($podeGerenciar && $detalhe->fichaCobrada !== null)
                ? $this->telefoneCobradaView($detalhe->fichaCobrada->id)
                : null,
            // A ORDEM dos três botões do painel é fixa e mora no enum (`doPainel()`): as duas negativas
            // primeiro, a positiva por último. Vem do controller porque Twig não chama método estático —
            // e reordenar no template faria a tela discordar da fonte única.
            'qualificacoesDoPainel' => QualificacaoContato::doPainel(),
        ]);
    }

    #[Route('/{id}/configuracao-encargos', name: 'cobranca_objeto_configurar_encargos', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function configurarEncargos(int $id, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.gerenciar');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        // Anti-IDOR: o objeto tem de ser do próprio escritório.
        $objeto = $this->objetoRepository->findOneByIdDoTenant($id, $tenant);
        if ($objeto === null) {
            throw $this->createNotFoundException('Objeto de cobrança não encontrado.');
        }

        $input = new EditarConfiguracaoObjetoInput();
        $input->objetoId = $id;
        $form = $this->createForm(EditarConfiguracaoObjetoType::class, $input);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->editarConfiguracaoObjeto->executar($input, $tenant);
                $this->addFlash('success', 'Configuração de encargos do objeto atualizada.');
            } catch (ObjetoNaoEncontradoException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        } else {
            // B5: erro de campo reabre o modal com o digitado; CSRF (erro de raiz) segue com flash.
            $this->tratarFormInvalido($request, $form, $id, 'configEncargosObjeto', 'modalConfigEncargosObjeto', 'editar_configuracao_objeto');
        }

        return $this->redirectToRoute('cobranca_objeto_show', ['id' => $id]);
    }

    #[Route('/{id}/pessoas', name: 'cobranca_objeto_pessoa_criar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function criarPessoa(int $id, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.gerenciar');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        // Anti-IDOR: o objeto tem de ser do próprio escritório.
        $objeto = $this->objetoRepository->findOneByIdDoTenant($id, $tenant);
        if ($objeto === null) {
            throw $this->createNotFoundException('Objeto de cobrança não encontrado.');
        }

        $input = new CriarPessoaVinculadaInput();
        $form = $this->createForm(CriarPessoaVinculadaType::class, $input);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->criarPessoaVinculada->executar($input, $id, $tenant, $this->usuarioLogado());
                $this->addFlash('success', 'Pessoa cadastrada e vinculada ao objeto.');
            } catch (ObjetoNaoEncontradoException | PessoaNaoEncontradaException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        } else {
            // B5: modal de ação estática (action fixo no Twig) — sem URL a repor. Reidratado inline no show.
            $this->tratarFormInvalido($request, $form, $id, 'novaPessoa', 'modalNovaPessoa', 'criar_pessoa_vinculada');
        }

        return $this->redirectToRoute('cobranca_objeto_show', ['id' => $id]);
    }

    /**
     * Adiciona um telefone à pessoa COBRADA sem tirar o gestor da aba Responsáveis (2026-07-28).
     *
     * Por que uma rota no objeto e não a da ficha (`cobranca_pessoa_telefone_adicionar`): aquela rota
     * não sabe de onde veio e sempre termina em `cobranca_pessoa_show` — quem cadastrava um número
     * durante a ligação era jogado para outra página. Esta sabe qual objeto chamou, então sabe o que
     * devolver: em AJAX, o fragmento da lista já atualizado (a página não recarrega e a aba não muda);
     * sem JavaScript, um redirect de volta para a PRÓPRIA aba. A rota da ficha segue intacta, servindo
     * a página da pessoa.
     *
     * Gravar continua sendo trabalho do `AdicionarTelefonePessoaUseCase` (regra do "primeiro nasce
     * atual" e sombra `Pessoa::telefone` incluídas): aqui não há regra nova, só HTTP.
     */
    #[Route('/{id}/responsaveis/telefones', name: 'cobranca_objeto_telefone_adicionar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function adicionarTelefoneDaCobrada(int $id, Request $request): Response
    {
        // Mesma capacidade que a rota da ficha exige e que o gate de PII da aba usa para mostrar a
        // lista: quem não pode ver os telefones também não pode acrescentar um.
        $tenant = $this->tenantComCapacidade('resources.cobranca.gerenciar');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $pessoa = $this->cobradaDoObjetoOuFalha($id, $tenant);
        if ($pessoa === null) {
            return $this->respostaTelefone($request, $id, false, 'Este objeto não tem pessoa cobrada.', null);
        }

        $input = new AdicionarTelefonePessoaInput();
        $input->pessoaId = $pessoa->getId();
        $form = $this->createForm(AdicionarTelefoneType::class, $input);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->flashErrosDoForm($form);

            return $this->respostaTelefone($request, $id, false, $this->primeiroErro($form), null);
        }

        try {
            $this->adicionarTelefonePessoa->executar($input, $tenant, $this->usuarioLogado());
        } catch (PessoaNaoEncontradaException $e) {
            $this->addFlash('danger', $e->getMessage());

            return $this->respostaTelefone($request, $id, false, $e->getMessage(), null);
        }

        $this->addFlash('success', 'Telefone adicionado.');

        // A ficha é remontada DEPOIS da gravação: é ela que traz a lista com o item novo, na mesma
        // ordem e com o mesmo selo `Atual` que o `show` mostraria.
        return $this->respostaTelefone($request, $id, true, 'Telefone adicionado.', $this->montarFichaPessoa->executar($pessoa));
    }

    /**
     * Corrige o número de um telefone da pessoa COBRADA, sem sair da aba (2026-07-28).
     *
     * Mesma dupla de caras da rota de adicionar (fragmento em AJAX, PRG sem JavaScript) e mesma
     * capacidade. O CSRF é POR ITEM (`editar_telefone_<id>`), como o "marcar como atual": token
     * genérico aceitaria trocar o alvo da edição por outro telefone da mesma pessoa.
     */
    #[Route('/{id}/responsaveis/telefones/{itemId}', name: 'cobranca_objeto_telefone_editar', methods: ['POST'], requirements: ['id' => '\d+', 'itemId' => '\d+'])]
    public function editarTelefoneDaCobrada(int $id, int $itemId, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.gerenciar');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $pessoa = $this->cobradaDoObjetoOuFalha($id, $tenant);
        if ($pessoa === null) {
            return $this->respostaTelefone($request, $id, false, 'Este objeto não tem pessoa cobrada.', null);
        }

        if (!$this->isCsrfTokenValid('editar_telefone_' . $itemId, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de segurança inválido.');

            return $this->respostaTelefone($request, $id, false, 'Token de segurança inválido.', null);
        }

        $input = new EditarTelefonePessoaInput();
        $input->pessoaId = $pessoa->getId();
        $input->telefoneId = $itemId;
        $input->numero = (string) $request->request->get('numero');
        // `tryFrom` e não `from`: valor fora do enum (form adulterado, campo velho) vira NULO, que o
        // UseCase lê como "não mexer no tipo" — em vez de estourar 500 numa correção de telefone.
        $input->tipo = TipoTelefone::tryFrom((string) $request->request->get('tipo', ''));

        // Sem Form Type: o campo é um só e a ação é POR LINHA da lista — um FormView por telefone
        // inflaria o fragmento devolvido no AJAX sem trazer nada que o CSRF por item já não dê. As
        // regras do número continuam no DTO (fonte única com a rota da ficha), validadas aqui.
        $erro = $this->primeiroErroDeValidacao($input);
        if ($erro !== null) {
            $this->addFlash('danger', $erro);

            return $this->respostaTelefone($request, $id, false, $erro, null);
        }

        try {
            $this->editarTelefonePessoa->executar($input, $tenant);
        } catch (PessoaNaoEncontradaException | PessoaTelefoneNaoEncontradoException $e) {
            $this->addFlash('danger', $e->getMessage());

            return $this->respostaTelefone($request, $id, false, $e->getMessage(), null);
        }

        $this->addFlash('success', 'Telefone corrigido.');

        return $this->respostaTelefone($request, $id, true, 'Telefone corrigido.', $this->montarFichaPessoa->executar($pessoa));
    }

    /**
     * Exclui um telefone da pessoa COBRADA, sem sair da aba (2026-07-28).
     *
     * Excluir o telefone ATUAL promove o mais recente que sobrou (decisão do dono) — quem faz isso é
     * o UseCase; aqui só se conta ao gestor QUAL número virou o atual, senão a lista muda de selo
     * sozinha e ele descobre por conta própria.
     */
    #[Route('/{id}/responsaveis/telefones/{itemId}/excluir', name: 'cobranca_objeto_telefone_excluir', methods: ['POST'], requirements: ['id' => '\d+', 'itemId' => '\d+'])]
    public function excluirTelefoneDaCobrada(int $id, int $itemId, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.gerenciar');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $pessoa = $this->cobradaDoObjetoOuFalha($id, $tenant);
        if ($pessoa === null) {
            return $this->respostaTelefone($request, $id, false, 'Este objeto não tem pessoa cobrada.', null);
        }

        if (!$this->isCsrfTokenValid('excluir_telefone_' . $itemId, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de segurança inválido.');

            return $this->respostaTelefone($request, $id, false, 'Token de segurança inválido.', null);
        }

        $input = new ExcluirTelefonePessoaInput();
        $input->pessoaId = $pessoa->getId();
        $input->telefoneId = $itemId;

        try {
            $promovido = $this->excluirTelefonePessoa->executar($input, $tenant);
        } catch (PessoaNaoEncontradaException | PessoaTelefoneNaoEncontradoException $e) {
            $this->addFlash('danger', $e->getMessage());

            return $this->respostaTelefone($request, $id, false, $e->getMessage(), null);
        }

        $mensagem = $promovido === null
            ? 'Telefone excluído.'
            : 'Telefone excluído. ' . $promovido->getNumero() . ' passou a ser o atual.';
        $this->addFlash('success', $mensagem);

        return $this->respostaTelefone($request, $id, true, $mensagem, $this->montarFichaPessoa->executar($pessoa));
    }

    /**
     * A pessoa cobrada do objeto, com as duas guardas anti-IDOR: o objeto tem de ser do próprio
     * escritório (404 se não for) e a cobrada sai do caso âncora dele. `null` = objeto sem cobrada,
     * que as rotas tratam como recusa comum (não é erro do gestor, e não é 404 do objeto).
     */
    private function cobradaDoObjetoOuFalha(int $id, Tenant $tenant): ?Pessoa
    {
        $objeto = $this->objetoRepository->findOneByIdDoTenant($id, $tenant);
        if ($objeto === null) {
            throw $this->createNotFoundException('Objeto de cobrança não encontrado.');
        }

        return $this->casoRepository->casoAncoraDoObjeto($objeto)?->getPessoaCobradaAtual();
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

    /**
     * Resposta única das duas caras da rota de telefone.
     *
     * AJAX recebe JSON com o fragmento pronto (`html`) — renderizar no servidor mantém o selo `Atual`,
     * o contador, o estado vazio e o CSRF do mini-form com UMA implementação só; remontar a lista em
     * JavaScript criaria a segunda. Requisição normal recebe o PRG de sempre, agora para a própria aba
     * (`?aba=responsaveis`, que o JS do show já sabe restaurar) em vez da ficha da pessoa.
     *
     * A recusa vai com 200 e `ok: false` de propósito: para o handler do navegador, "o servidor não
     * aceitou este número" e "a rede caiu" são casos diferentes — o primeiro mostra a mensagem, o
     * segundo reenvia o form pelo caminho tradicional. Um 4xx aqui embaralharia os dois.
     */
    private function respostaTelefone(Request $request, int $objetoId, bool $ok, string $mensagem, ?PessoaFichaOutput $ficha): Response
    {
        if (!$request->isXmlHttpRequest()) {
            return $this->redirectToRoute('cobranca_objeto_show', ['id' => $objetoId, 'aba' => 'responsaveis']);
        }

        return $this->json([
            'ok' => $ok,
            'mensagem' => $mensagem,
            'html' => $ficha === null ? null : $this->renderView('cobranca/objeto/_partials/_telefones_cobrada.html.twig', [
                'ficha' => $ficha,
                // Chegar aqui já provou a capacidade (o gate no topo da action), que é exatamente o que
                // `podeAbrirFicha` significa no Twig.
                'podeAbrirFicha' => true,
                'formTelefoneCobrada' => $this->telefoneCobradaView($ficha->id),
                'objetoId' => $objetoId,
            ]),
        ]);
    }

    /**
     * Primeira mensagem de erro do form, para o aviso inline do AJAX (que não lê flash). O fallback
     * cobre o form recusado sem erro nomeado — CSRF inválido, por exemplo.
     */
    private function primeiroErro(FormInterface $form): string
    {
        foreach ($form->getErrors(true) as $erro) {
            return $erro->getMessage();
        }

        return 'Não foi possível adicionar o telefone.';
    }

    /**
     * B5: o modal "Nova pessoa" é criado inline (não passa pelo MontadorModaisCaso). Reidrata-o quando a
     * última criação falhou na validação — mesma lógica do `reidratarSeErro` do montador.
     *
     * @param array{form: string, modalId: string, payload: array<string, mixed>, acao: ?string}|null $erroModal
     */
    private function novaPessoaView(?array $erroModal): FormView
    {
        $form = $this->createForm(CriarPessoaVinculadaType::class);
        if ($erroModal !== null && $erroModal['form'] === 'novaPessoa') {
            $form->submit($erroModal['payload']);
        }

        return $form->createView();
    }

    /**
     * Mini-form inline de adicionar telefone da pessoa cobrada (spec §2.3). `pessoaId` NÃO é campo do
     * form (`AdicionarTelefoneType` só tem `numero`): quem o define é a rota de destino, a partir da
     * URL — por isso o Input aqui existe só para dar `data_class` ao form.
     */
    private function telefoneCobradaView(int $pessoaId): FormView
    {
        $input = new AdicionarTelefonePessoaInput();
        $input->pessoaId = $pessoaId;

        return $this->createForm(AdicionarTelefoneType::class, $input)->createView();
    }

    /**
     * #9-T3: o modal "Editar configuração de encargos" (nível 2 da cascata) é criado inline (mesmo
     * padrão de `novaPessoaView`), pré-carregado com os 10 overrides ATUAIS do objeto — como o modal de
     * config da carteira faz. B5: se a última mutação falhou na validação, reidrata com o payload cru.
     *
     * @param array{form: string, modalId: string, payload: array<string, mixed>, acao: ?string}|null $erroModal
     */
    private function configEncargosObjetoView(ObjetoCobranca $objeto, ?array $erroModal): FormView
    {
        $input = new EditarConfiguracaoObjetoInput();
        $input->objetoId = $objeto->getId();
        $input->taxaJurosMensalBp = $objeto->getTaxaJurosMensalBp();
        $input->regimeJuros = $objeto->getRegimeJuros();
        $input->taxaMultaBp = $objeto->getTaxaMultaBp();
        $input->baseMulta = $objeto->getBaseMulta();
        $input->taxaCorrecaoBp = $objeto->getTaxaCorrecaoBp();
        $input->baseCorrecao = $objeto->getBaseCorrecao();
        $input->taxaHonorariosBp = $objeto->getTaxaHonorariosBp();
        $input->baseHonorarios = $objeto->getBaseHonorarios();
        $input->carenciaHonorariosDias = $objeto->getCarenciaHonorariosDias();
        $input->toleranciaJurosMultaDias = $objeto->getToleranciaJurosMultaDias();

        // O que a carteira tem configurado (nível 1 da cascata), para o modal MOSTRAR esses valores em
        // vez de anunciar herança. Vem do próprio `ResolvedorConfigEncargos` — que já converte a
        // alíquota de honorários (forma+percentual → bp) e aplica o fallback D3 da carência —, para a
        // tela nunca exibir um número derivado por uma regra paralela à do cálculo.
        $carteira = $objeto->getCarteira();

        $form = $this->createForm(EditarConfiguracaoObjetoType::class, $input, [
            'configCarteira' => $carteira !== null ? $this->resolvedorConfigEncargos->resolverDaCarteira($carteira) : null,
        ]);
        if ($erroModal !== null && $erroModal['form'] === 'configEncargosObjeto') {
            $form->submit($erroModal['payload']);
        }

        return $form->createView();
    }
}
