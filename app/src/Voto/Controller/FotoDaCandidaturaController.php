<?php

declare(strict_types=1);

namespace App\Voto\Controller;

use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Http\EntregaDeArquivo;
use App\Voto\Armazenamento\ChavesDoVoto;
use App\Voto\Enum\EstadoDaAssembleia;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Repository\OpcaoDaPautaRepository;
use App\Voto\Service\GuardaDaEquipeDoVoto;
use App\Voto\Service\UnidadeDoLink;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Entrega a foto 3x4 da candidatura, sempre por chave (nunca por caminho) e só depois de provar quem pede:
 *  - a equipe, na tela das pautas: `/voto/assembleias/{id}/fotos/{nome}`, com a guarda do Voto e a assembleia do
 *    escritório da sessão (outro escritório: 404); o nome só vale se uma opção DESSA assembleia o tem;
 *  - o eleitor, na urna: `/votar/{token}/foto/{opcao}`, sem login. O link da unidade leva à assembleia; só assembleia
 *    publicada entrega foto, e a opção tem de ser de uma pauta dela. Link inválido consome o limitador por IP, como a
 *    cédula (`CedulaController`), e responde 404 sem corpo.
 */
final class FotoDaCandidaturaController extends AbstractController
{
    public function __construct(
        private readonly GuardaDaEquipeDoVoto $guarda,
        private readonly AssembleiaRepository $assembleias,
        private readonly OpcaoDaPautaRepository $opcoes,
        private readonly UnidadeDoLink $link,
        private readonly ArmazenamentoDeArquivos $armazenamento,
        private readonly EntregaDeArquivo $entrega,
        private readonly RateLimiterFactoryInterface $votoCedulaInvalidoLimiter,
    ) {
    }

    #[Route('/voto/assembleias/{id}/fotos/{nome}', name: 'voto_assembleia_foto_candidatura', requirements: ['id' => '\d+', 'nome' => '[0-9a-f]{32}\.(?:jpe?g|png|webp)'], methods: ['GET'])]
    public function daEquipe(int $id, string $nome): Response
    {
        $tenant = $this->guarda->exigir();
        $assembleia = $this->assembleias->buscarDoEscritorio($id, $tenant) ?? throw $this->createNotFoundException();
        $opcao = $this->opcoes->buscarComFotoDaAssembleia($assembleia, $nome) ?? throw $this->createNotFoundException();
        $chave = ChavesDoVoto::fotoDaCandidatura($opcao);
        if (!$this->armazenamento->existe($chave)) {
            throw $this->createNotFoundException();
        }

        $resposta = $this->entrega->resposta($chave, 'foto-da-candidatura', inline: true);
        $resposta->headers->set('Cache-Control', 'private, max-age=300');

        return $resposta;
    }

    #[Route('/votar/{token}/foto/{opcao}', name: 'voto_assembleia_cedula_foto', requirements: ['token' => '[a-f0-9]{40}', 'opcao' => '\d+'], methods: ['GET'])]
    public function daUrna(Request $request, string $token, int $opcao): Response
    {
        $unidade = $this->link->localizar($token);
        if (null === $unidade) {
            return $this->naoEncontrada($request);
        }
        $assembleia = $unidade->getAssembleia();
        $candidatura = EstadoDaAssembleia::Publicada === $assembleia->getEstado() ? $this->opcoes->buscarDaAssembleia($opcao, $assembleia) : null;
        if (null === $candidatura || null === $candidatura->getFoto()) {
            return $this->semCorpo();
        }
        $chave = ChavesDoVoto::fotoDaCandidatura($candidatura);
        if (!$this->armazenamento->existe($chave)) {
            return $this->semCorpo();
        }

        $resposta = $this->entrega->resposta($chave, 'foto-da-candidatura', inline: true);
        $resposta->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $resposta->headers->set('Cache-Control', 'private, max-age=300');
        $resposta->headers->set('Referrer-Policy', 'no-referrer');

        return $resposta;
    }

    /** 404 que consome o limitador por IP; estourou, 429 (a mesma regra da cédula). */
    private function naoEncontrada(Request $request): Response
    {
        $aceito = $this->votoCedulaInvalidoLimiter->create($request->getClientIp())->consume()->isAccepted();

        return $this->semCorpo($aceito ? Response::HTTP_NOT_FOUND : Response::HTTP_TOO_MANY_REQUESTS);
    }

    private function semCorpo(int $status = Response::HTTP_NOT_FOUND): Response
    {
        $resposta = new Response('', $status);
        $resposta->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $resposta->headers->set('Cache-Control', 'no-store, private');

        return $resposta;
    }
}
