<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tenant\Service\AuditoriaDaEdicaoSemPrazo;
use App\Tenant\Service\PoderDeEdicaoSemPrazo;

/**
 * Quem edita ou exclui um comentário de 15 minutos (registro da pasta, observação de Detalhes e do Financeiro, nota
 * técnica do processo; spec `edicao-sem-prazo-master-primario.md` §2, itens 1 a 4): o AUTOR dentro da
 * {@see JanelaDeEdicaoDeComentario}, OU quem tem o {@see PoderDeEdicaoSemPrazo} (Master; primário com a opção ligada).
 * O tenant, o "do sistema" e as guardas da tela (lápide, cadeado, módulo) continuam com os UseCases e controllers.
 * Usado pelos oito UseCases e, pela mesma resposta, pela tela (`sem_prazo_libera`/`sem_prazo_selo`).
 */
final class PrazoDoComentario
{
    public function __construct(
        private readonly JanelaDeEdicaoDeComentario $janela,
        private readonly PoderDeEdicaoSemPrazo $poder,
        private readonly AuditoriaDaEdicaoSemPrazo $auditoria,
    ) {
    }

    /** A regra de sempre: autor e janela aberta. */
    public function pelaRegraDeSempre(bool $doAutor, \DateTimeImmutable $criadaEm, ?\DateTimeImmutable $agora = null): bool
    {
        return $doAutor && $this->janela->estaAberta($criadaEm, $agora);
    }

    public function libera(User $usuario, Tenant $tenant, bool $doAutor, \DateTimeImmutable $criadaEm, ?\DateTimeImmutable $agora = null): bool
    {
        return $this->poder->libera($usuario, $tenant, $this->pelaRegraDeSempre($doAutor, $criadaEm, $agora));
    }

    /**
     * O rastro (§6), só quando foi o poder que liberou. Chame depois da checagem e antes do `flush`.
     *
     * @param AuditoriaDaEdicaoSemPrazo::EDITAR|AuditoriaDaEdicaoSemPrazo::EXCLUIR $acao
     */
    public function registrar(string $acao, User $usuario, Tenant $tenant, bool $doAutor, \DateTimeImmutable $criadaEm, object $conteudo, ?User $autor): void
    {
        $id = method_exists($conteudo, 'getId') ? $conteudo->getId() : null;
        $this->auditoria->registrar(
            $acao,
            $usuario,
            $tenant,
            $this->pelaRegraDeSempre($doAutor, $criadaEm),
            $conteudo::class,
            is_int($id) ? $id : null,
            $autor?->getId(),
        );
    }
}
