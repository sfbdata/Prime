<?php

declare(strict_types=1);

namespace App\Processo\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Service\JanelaDeEdicaoDeComentario;
use App\Processo\DTO\NotaTecnicaOutput;
use App\Processo\Entity\NotaTecnica;
use App\Processo\Exception\NotaTecnicaNaoEditavelException;
use App\Processo\Repository\NotaTecnicaRepository;
use App\Shared\Service\SanitizadorTextoRico;

/**
 * Edição controlada de uma nota técnica.
 *
 * Salvaguardas: só o autor pode editá-la, e apenas dentro da janela curta após a criação
 * (`JanelaDeEdicaoDeComentario`, 15 minutos — o mesmo serviço das observações da pasta, para a
 * regra existir num lugar só). Decisão do dono (2026-10-05).
 */
final class EditarNotaTecnicaUseCase
{
    public function __construct(
        private readonly NotaTecnicaRepository $repository,
        private readonly SanitizadorTextoRico $sanitizador,
        private readonly JanelaDeEdicaoDeComentario $janela,
    ) {}

    public function podeEditar(NotaTecnica $nota, User $usuario, Tenant $tenant, ?\DateTimeImmutable $agora = null): bool
    {
        $agora ??= new \DateTimeImmutable();

        if ($nota->getTenant() !== $tenant) {
            return false;
        }

        if (!$nota->pertenceAo($usuario)) {
            return false;
        }

        return $this->janela->estaAberta($nota->getCriadaEm(), $agora);
    }

    public function executar(NotaTecnica $nota, User $usuario, Tenant $tenant, string $conteudo): NotaTecnicaOutput
    {
        if (!$this->podeEditar($nota, $usuario, $tenant)) {
            throw new NotaTecnicaNaoEditavelException('Esta nota técnica não pode ser editada.');
        }

        // Mesma limpeza da criação: a edição é outra porta de entrada para o mesmo campo, e uma
        // porta sem sanitização anularia a da outra.
        $conteudo = $this->sanitizador->limpar(trim($conteudo)) ?? '';

        if ($this->sanitizador->estaVazio($conteudo) || $this->sanitizador->comprimentoDoTexto($conteudo) > 5000) {
            throw new \InvalidArgumentException('Conteúdo inválido: deve ter entre 1 e 5000 caracteres.');
        }

        $nota->editar($conteudo);
        $this->repository->salvar($nota, flush: true);

        return NotaTecnicaOutput::fromEntity($nota);
    }
}
