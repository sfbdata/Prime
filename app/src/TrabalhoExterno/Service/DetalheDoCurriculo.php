<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\TrabalhoExterno\Entity\Candidatura;
use App\TrabalhoExterno\Entity\Curriculo;
use App\TrabalhoExterno\Enum\TipoItemMarcado;
use App\TrabalhoExterno\Repository\CandidaturaRepository;
use App\TrabalhoExterno\Repository\MarcacaoDoUsuarioRepository;
use App\TrabalhoExterno\Repository\TermoDeVoluntariadoRepository;

/**
 * O detalhe do currículo (`curriculo` das respostas) no recorte de quem lê: a Central e o próprio dono
 * veem as vagas a que ele se candidatou; o dono externo de uma vaga, só o recorte sem nota, sem
 * situação no banco e sem as outras candidaturas.
 */
final class DetalheDoCurriculo
{
    public function __construct(
        private readonly LocalizadorDeCurriculo $localizador,
        private readonly ApresentadorDeCurriculo $apresentador,
        private readonly MarcacaoDoUsuarioRepository $marcacoes,
        private readonly CandidaturaRepository $candidaturas,
        private readonly TermoDeVoluntariadoRepository $termos,
    ) {
    }

    /** @return array<string, mixed> */
    public function detalhar(Contexto $ctx, Curriculo $cv, bool $comMarca = true): array
    {
        $agora = new \DateTimeImmutable();
        if ($this->localizador->ehDoDonoDaVaga($ctx, $cv)) {
            return $this->apresentador->doDonoDaVaga($ctx, $cv, $agora);
        }
        $id = (int) $cv->getId();
        $marca = $ctx->previa || !$comMarca ? null : ($this->marcacoes->doUsuario($ctx->usuario, TipoItemMarcado::Curriculo, [$id])[$id] ?? null);
        $vagas = array_map(static fn (Candidatura $c): string => $c->getVaga()->getTitulo(), $this->candidaturas->doCurriculo($cv));

        return $this->apresentador->detalhe($ctx, $cv, $marca, $agora, $vagas, $ctx->configuracao->termoLigado() ? $this->termos->maisRecenteDo($cv) : null);
    }
}
