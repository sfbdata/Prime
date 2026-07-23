<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Exception\MensagemPastaNaoEditavelException;
use App\Shared\Service\SanitizadorTextoRico;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Edição controlada de uma mensagem do chat da pasta.
 *
 * Salvaguardas: só o autor da mensagem pode editá-la, e apenas dentro de uma
 * janela curta após a criação — o suficiente para corrigir erros de escrita.
 */
final class EditarMensagemPastaUseCase
{
    /** Janela em que a mensagem ainda pode ser editada, contada da criação. */
    private const JANELA = 'PT24H';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SanitizadorTextoRico $sanitizador,
    ) {}

    public function podeEditar(PastaMensagem $mensagem, User $usuario, Tenant $tenant, ?\DateTimeImmutable $agora = null): bool
    {
        $agora ??= new \DateTimeImmutable();

        if ($mensagem->getTenant() !== $tenant) {
            return false;
        }

        if (!$mensagem->pertenceAo($usuario)) {
            return false;
        }

        $limite = $mensagem->getCriadaEm()->add(new \DateInterval(self::JANELA));

        return $agora <= $limite;
    }

    public function executar(PastaMensagem $mensagem, User $usuario, Tenant $tenant, string $conteudo): void
    {
        if (!$this->podeEditar($mensagem, $usuario, $tenant)) {
            throw new MensagemPastaNaoEditavelException('Esta mensagem não pode ser editada.');
        }

        // Vem do editor rico (HTML): limpo ANTES de persistir. Texto puro atravessa intacto.
        $conteudo = $this->sanitizador->limpar(trim($conteudo)) ?? '';

        // `estaVazio` porque o editor entrega `<p><br></p>` quando nada foi digitado; o limite
        // conta o texto visível, não a marcação.
        if ($this->sanitizador->estaVazio($conteudo) || $this->sanitizador->comprimentoDoTexto($conteudo) > 5000) {
            throw new \InvalidArgumentException('Conteúdo inválido: deve ter entre 1 e 5000 caracteres.');
        }

        $mensagem->setConteudo($conteudo);
        $mensagem->setEditadaEm(new \DateTimeImmutable());

        $this->em->flush();
    }
}
