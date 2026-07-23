<?php

declare(strict_types=1);

namespace App\Tarefa\UseCase;

use App\Entity\Tarefa\TarefaMensagem;
use App\Shared\Service\SanitizadorTextoRico;
use Doctrine\ORM\EntityManagerInterface;

final class EditarTarefaMensagemUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SanitizadorTextoRico $sanitizador,
    ) {}

    public function executar(TarefaMensagem $mensagem, string $conteudo): void
    {
        // Vem do editor rico (HTML): limpo ANTES de persistir. Texto puro atravessa intacto.
        $conteudo = $this->sanitizador->limpar(trim($conteudo)) ?? '';

        // `estaVazio` porque o editor entrega `<p><br></p>` quando nada foi digitado; o limite
        // conta o texto visível, não a marcação.
        if ($this->sanitizador->estaVazio($conteudo) || $this->sanitizador->comprimentoDoTexto($conteudo) > 5000) {
            throw new \InvalidArgumentException('Conteúdo inválido: deve ter entre 1 e 5000 caracteres.');
        }

        $mensagem->setMensagem($conteudo);
        $mensagem->setEditadoEm(new \DateTimeImmutable());
        $this->em->flush();
    }
}
