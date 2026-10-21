<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\Entity\PreferenciaDoUsuario;
use App\TrabalhoExterno\Repository\PreferenciaDoUsuarioRepository;
use App\TrabalhoExterno\Service\Contexto;

/**
 * Guarda a visita à aba Vagas (`bj-te-visto-<perfil>`, desenho 06 l.1008): a tela chama ao entrar,
 * DEPOIS de listar. "NOVA" é a vaga criada depois da visita anterior a esta. Na prévia, nada.
 */
final class RegistrarVisitaAsVagasUseCase
{
    public function __construct(
        private readonly PreferenciaDoUsuarioRepository $preferencias,
    ) {
    }

    public function executar(Contexto $ctx): void
    {
        if ($ctx->previa) {
            return;
        }
        $agora = new \DateTimeImmutable();
        $visita = $this->preferencias->obter($ctx->usuario, PreferenciaDoUsuario::ULTIMA_VISITA_VAGAS);
        $visita->definir(['em' => $agora->format(\DATE_ATOM)], $agora);
        $this->preferencias->salvar($visita, flush: true);
    }
}
