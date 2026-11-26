<?php

declare(strict_types=1);

namespace App\Voto\Controller;

use App\Entity\Auth\User;
use App\Voto\DTO\MinutaDeAtaGerada;
use App\Voto\Enum\FormatoDaMinuta;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Exception\ModeloDeAtaInvalidoException;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Service\AvisosDoVoto;
use App\Voto\Service\DocxDaMinuta;
use App\Voto\Service\GuardaDaEquipeDoVoto;
use App\Voto\UseCase\GerarMinutaDeAtaUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Minuta de ata em Word (com papel timbrado opcional) e em PDF (desenho 18, "Minuta de ata"), gerada na hora e devolvida
 * como download, sem gravar arquivo. POST por causa do modelo .docx enviado a cada geração (campo `timbre`, que o navegador guarda e o servidor
 * nunca guarda) e do token da assembleia. Mesma guarda dos vizinhos: sem liberação 403; outro escritório e rascunho 404.
 * Sem votação apurada ou modelo inválido: volta ao painel com o aviso.
 */
#[Route('/voto/assembleias/{id}', requirements: ['id' => '\d+', 'pauta' => '\d+'])]
final class MinutaDaAtaController extends AbstractController
{
    public function __construct(
        private readonly GuardaDaEquipeDoVoto $guarda,
        private readonly GerarMinutaDeAtaUseCase $gerar,
        private readonly AvisosDoVoto $avisos,
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    #[Route('/minuta/{formato}', name: 'voto_assembleia_minuta', requirements: ['formato' => 'docx|pdf'], methods: ['POST'])]
    public function baixar(int $id, string $formato, Request $request): Response
    {
        return $this->gerarMinuta($id, null, $formato, $request);
    }

    /** A minuta de uma votação só (o desenho oferece por eleição): mesma guarda, token e regra. */
    #[Route('/pautas/{pauta}/minuta/{formato}', name: 'voto_assembleia_pauta_minuta', requirements: ['formato' => 'docx|pdf'], methods: ['POST'])]
    public function baixarDaPauta(int $id, int $pauta, string $formato, Request $request): Response
    {
        return $this->gerarMinuta($id, $pauta, $formato, $request);
    }

    private function gerarMinuta(int $id, ?int $pauta, string $formato, Request $request): Response
    {
        $tenant = $this->guarda->exigir();
        if (!$this->isCsrfTokenValid('voto_assembleia_' . $id, (string) $request->request->get('_token'))) {
            $this->avisos->erro($this->tradutor->trans('voto.assembleia.gestor.aviso.sessao_expirada'));

            return $this->aoPainel($id);
        }
        $formato = FormatoDaMinuta::from($formato);

        try {
            $modelo = FormatoDaMinuta::Docx === $formato ? $this->modeloEnviado($request) : null;

            return $this->arquivo($this->gerar->executar($tenant, $id, $this->guarda->nomeDeQuemOpera(), $formato, $modelo, $pauta, ator: $this->getUser() instanceof User ? $this->getUser() : null));
        } catch (AssembleiaNaoEncontradaException) {
            throw $this->createNotFoundException();
        } catch (OperacaoInvalidaNaAssembleiaException|ModeloDeAtaInvalidoException $e) {
            $this->avisos->erro($e->getMessage());

            return $this->aoPainel($id);
        }
    }

    /** Os bytes do modelo enviado (campo `timbre`), ou null se não veio; o limite de tamanho é conferido antes de ler. */
    private function modeloEnviado(Request $request): ?string
    {
        $arquivo = $request->files->get('timbre');
        if (!$arquivo instanceof UploadedFile || UPLOAD_ERR_NO_FILE === $arquivo->getError()) {
            return null;
        }
        $grande = \in_array($arquivo->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || (int) $arquivo->getSize() > DocxDaMinuta::TAMANHO_MAXIMO_DO_MODELO;
        if ($grande || !$arquivo->isValid()) {
            throw new ModeloDeAtaInvalidoException($this->tradutor->trans('voto.assembleia.gestor.minuta.' . ($grande ? 'modelo_grande' : 'modelo_invalido')));
        }

        return $arquivo->getContent();
    }

    private function aoPainel(int $id): Response
    {
        return $this->redirectToRoute('voto_index', ['aba' => 'ges', '_fragment' => 'assembleia-' . $id]);
    }

    private function arquivo(MinutaDeAtaGerada $minuta): Response
    {
        return new Response($minuta->conteudo, 200, [
            'Content-Type' => $minuta->formato->tipoDeConteudo(),
            'Content-Disposition' => 'attachment; filename="' . $minuta->nomeDoArquivo . '"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
