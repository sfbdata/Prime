<?php

declare(strict_types=1);

namespace App\Voto\Controller;

use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Http\EntregaDeArquivo;
use App\Voto\Armazenamento\ChavesDoVoto;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Service\AvisosDoVoto;
use App\Voto\Service\GuardaDaEquipeDoVoto;
use App\Voto\UseCase\AnexarEditalDaAssembleiaUseCase;
use App\Voto\UseCase\RemoverEditalDaAssembleiaUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Edital de convocação (PDF) da assembleia: a equipe anexa, troca, baixa e remove. Mesma guarda, mesmo CSRF
 * (`voto_assembleia_{id}`) e mesma volta (painel da Gestão eleitoral, na assembleia) dos vizinhos. Quem só visualiza
 * (auditoria, fiscal, conselho) baixa, mas não escreve: a barreira de rota da equipe dá 403 nos POSTs. Outro escritório: 404.
 * O arquivo é entregue por chave, depois de provar a posse da assembleia.
 */
#[Route('/voto/assembleias/{id}/edital', requirements: ['id' => '\d+'])]
final class EditalDaAssembleiaController extends AbstractController
{
    public function __construct(
        private readonly GuardaDaEquipeDoVoto $guarda,
        private readonly AssembleiaRepository $assembleias,
        private readonly ArmazenamentoDeArquivos $armazenamento,
        private readonly EntregaDeArquivo $entrega,
        private readonly AvisosDoVoto $avisos,
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    #[Route('', name: 'voto_assembleia_edital', methods: ['GET'])]
    public function baixar(int $id): Response
    {
        $tenant = $this->guarda->exigir();
        $assembleia = $this->assembleias->buscarDoEscritorio($id, $tenant) ?? throw $this->createNotFoundException();
        if (!$assembleia->temEdital()) {
            throw $this->createNotFoundException();
        }
        $chave = ChavesDoVoto::edital($assembleia);
        if (!$this->armazenamento->existe($chave)) {
            throw $this->createNotFoundException();
        }

        $resposta = $this->entrega->resposta($chave, $assembleia->getEditalNome() ?? 'edital.pdf', inline: true);
        $resposta->headers->set('Cache-Control', 'no-store, private');
        $resposta->headers->set('X-Robots-Tag', 'noindex');

        return $resposta;
    }

    #[Route('', name: 'voto_assembleia_edital_anexar', methods: ['POST'])]
    public function anexar(int $id, Request $request, AnexarEditalDaAssembleiaUseCase $anexar): RedirectResponse
    {
        $tenant = $this->guarda->exigir();
        if (!$this->isCsrfTokenValid('voto_assembleia_' . $id, (string) $request->request->get('_token'))) {
            $this->avisos->erro($this->tradutor->trans('voto.assembleia.gestor.aviso.sessao_expirada'));

            return $this->aoPainel($id);
        }
        $arquivo = $request->files->get('edital');
        if (!$arquivo instanceof UploadedFile || UPLOAD_ERR_NO_FILE === $arquivo->getError()) {
            $this->avisos->erro($this->tradutor->trans('voto.assembleia.gestor.edital.sem_arquivo'));

            return $this->aoPainel($id);
        }

        try {
            $trocou = $anexar->executar($tenant, $id, $arquivo);
            $this->avisos->ok($this->tradutor->trans('voto.assembleia.gestor.edital.' . ($trocou ? 'trocado' : 'anexado')));
        } catch (AssembleiaNaoEncontradaException) {
            throw $this->createNotFoundException();
        } catch (OperacaoInvalidaNaAssembleiaException $e) {
            $this->avisos->erro($e->getMessage());
        }

        return $this->aoPainel($id);
    }

    #[Route('/remover', name: 'voto_assembleia_edital_remover', methods: ['POST'])]
    public function remover(int $id, Request $request, RemoverEditalDaAssembleiaUseCase $remover): RedirectResponse
    {
        $tenant = $this->guarda->exigir();
        if (!$this->isCsrfTokenValid('voto_assembleia_' . $id, (string) $request->request->get('_token'))) {
            $this->avisos->erro($this->tradutor->trans('voto.assembleia.gestor.aviso.sessao_expirada'));

            return $this->aoPainel($id);
        }

        try {
            $remover->executar($tenant, $id);
            $this->avisos->ok($this->tradutor->trans('voto.assembleia.gestor.edital.removido'));
        } catch (AssembleiaNaoEncontradaException) {
            throw $this->createNotFoundException();
        } catch (OperacaoInvalidaNaAssembleiaException $e) {
            $this->avisos->erro($e->getMessage());
        }

        return $this->aoPainel($id);
    }

    private function aoPainel(int $id): RedirectResponse
    {
        return $this->redirectToRoute('voto_index', ['aba' => 'ges', '_fragment' => 'assembleia-' . $id]);
    }
}
