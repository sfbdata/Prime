<?php

declare(strict_types=1);

namespace App\Comercial\UseCase;

use App\Comercial\DTO\DestinatarioDaCampanha;
use App\Comercial\Entity\ContatoComercial;
use App\Comercial\Exception\ContatoRecusado;
use App\Comercial\Repository\ContatoComercialRepository;
use App\Comercial\Service\AuditoriaDaBaseDeContatos;
use App\Entity\Auth\User;

/** "Incluir contato" da Base de contatos: um e-mail e, se quiser, o nome. Origem `manual`; repetido é recusado. */
final class IncluirContatoDoComercialUseCase
{
    public function __construct(
        private readonly ContatoComercialRepository $contatos,
        private readonly AuditoriaDaBaseDeContatos $auditoria,
    ) {
    }

    /** @throws ContatoRecusado e-mail inválido ou já na base */
    public function executar(string $email, ?string $nome, User $master): ContatoComercial
    {
        $destinatario = new DestinatarioDaCampanha($email);
        if (!$destinatario->valido() || mb_strlen($destinatario->email) > ContatoComercial::TAMANHO_DO_EMAIL) {
            throw new ContatoRecusado('Informe um e-mail válido.');
        }
        $novo = new ContatoComercial($destinatario->email, $nome, ContatoComercial::ORIGEM_MANUAL, null, $master, new \DateTimeImmutable());
        // Uma só instrução com ON CONFLICT: o repetido (inclusive o que perde a corrida) vira a mesma recusa, sem 500.
        if ($this->contatos->inserirSeNovo($novo) === null) {
            throw new ContatoRecusado('Este e-mail já está na base de contatos.');
        }
        $contato = $this->contatos->porEmail($novo->getEmail()) ?? $novo;
        $this->auditoria->registrar(AuditoriaDaBaseDeContatos::INCLUIDO, (string) $contato->getId(), ['email' => $contato->getEmail()], $master);

        return $contato;
    }
}
