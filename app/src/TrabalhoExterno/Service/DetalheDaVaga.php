<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\TrabalhoExterno\Entity\PreferenciaDoUsuario;
use App\TrabalhoExterno\Enum\TipoItemMarcado;
use App\TrabalhoExterno\Repository\MarcacaoDoUsuarioRepository;
use App\TrabalhoExterno\Repository\PreferenciaDoUsuarioRepository;

/**
 * O detalhe da vaga (`vaga` das respostas) no recorte de quem lê: localiza no escopo (404), junta a
 * marcação pessoal, a última visita e as candidaturas, e apresenta. Usado pelas vagas e pelas
 * candidaturas, que respondem com a vaga atualizada.
 */
final class DetalheDaVaga
{
    public function __construct(
        private readonly LocalizadorDeVaga $localizador,
        private readonly ApresentadorDeVaga $apresentador,
        private readonly MarcacaoDoUsuarioRepository $marcacoes,
        private readonly PreferenciaDoUsuarioRepository $preferencias,
        private readonly ResumoDasCandidaturas $resumo,
    ) {
    }

    /** @return array<string, mixed> */
    public function detalhar(Contexto $ctx, string $codigo): array
    {
        $agora = new \DateTimeImmutable();
        $vaga = $this->localizador->localizar($ctx, $codigo, $agora);
        $id = (int) $vaga->getId();
        $marca = $ctx->previa ? null : ($this->marcacoes->doUsuario($ctx->usuario, TipoItemMarcado::Vaga, [$id])[$id] ?? null);
        $em = $this->preferencias->doUsuario($ctx->usuario, PreferenciaDoUsuario::ULTIMA_VISITA_VAGAS)?->getValor()['em'] ?? null;

        return $this->apresentador->detalhe($ctx, $vaga, $marca, is_string($em) ? new \DateTimeImmutable($em) : null, $agora, $this->resumo->paraDetalhe($ctx, $vaga));
    }
}
