<?php

declare(strict_types=1);

namespace App\Pasta\Twig;

use App\Pasta\Service\JanelaDeEdicaoDeComentario;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Ponte fina entre os templates da pasta e a `JanelaDeEdicaoDeComentario`: o template não
 * calcula prazo nenhum, só pergunta ao mesmo serviço que os UseCases usam. A posse (autor ==
 * usuário logado) continua no template, como antes; aqui é só o tempo.
 */
final class JanelaDeEdicaoExtension extends AbstractExtension
{
    public function __construct(
        private readonly JanelaDeEdicaoDeComentario $janela,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('comentario_editavel', $this->janela->estaAberta(...)),
            new TwigFunction('comentario_minutos_restantes', $this->janela->minutosRestantes(...)),
            new TwigFunction('comentario_janela_minutos', static fn (): int => JanelaDeEdicaoDeComentario::MINUTOS),
        ];
    }
}
