<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Pasta\Exception\ObservacaoDetalhesNaoEditavelException;
use App\Pasta\Service\JanelaDeEdicaoDeComentario;
use App\Shared\Service\SanitizadorTextoRico;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Edição controlada de uma observação da aba Detalhes.
 *
 * Salvaguardas: só o autor da observação pode editá-la, e apenas dentro de uma
 * janela curta após a criação (`JanelaDeEdicaoDeComentario`, 15 minutos) — o suficiente para corrigir erros de escrita.
 */
final class EditarObservacaoDetalhesUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SanitizadorTextoRico $sanitizador,
        private readonly JanelaDeEdicaoDeComentario $janela,
    ) {}

    public function podeEditar(PastaObservacaoDetalhes $observacao, User $usuario, Tenant $tenant, ?\DateTimeImmutable $agora = null): bool
    {
        $agora ??= new \DateTimeImmutable();

        if ($observacao->getTenant() !== $tenant) {
            return false;
        }

        if (!$observacao->pertenceAo($usuario)) {
            return false;
        }

        return $this->janela->estaAberta($observacao->getCriadaEm(), $agora);
    }

    public function executar(PastaObservacaoDetalhes $observacao, User $usuario, Tenant $tenant, string $conteudo): void
    {
        if (!$this->podeEditar($observacao, $usuario, $tenant)) {
            throw new ObservacaoDetalhesNaoEditavelException('Esta observação não pode ser editada.');
        }

        // Mesma limpeza do envio: a edição é outra porta de entrada para o mesmo campo, e uma
        // porta sem sanitização anularia a da outra.
        $conteudo = $this->sanitizador->limpar(trim($conteudo)) ?? '';

        if ($this->sanitizador->estaVazio($conteudo) || $this->sanitizador->comprimentoDoTexto($conteudo) > 5000) {
            throw new \InvalidArgumentException('Conteúdo inválido: deve ter entre 1 e 5000 caracteres.');
        }

        $observacao->setConteudo($conteudo);
        $observacao->setEditadaEm(new \DateTimeImmutable());

        $this->em->flush();
    }
}
