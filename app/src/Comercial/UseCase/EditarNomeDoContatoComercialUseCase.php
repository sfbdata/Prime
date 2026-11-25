<?php

declare(strict_types=1);

namespace App\Comercial\UseCase;

use App\Comercial\Exception\ContatoRecusado;
use App\Comercial\Repository\ContatoComercialRepository;
use App\Comercial\Service\AuditoriaDaBaseDeContatos;
use App\Entity\Auth\User;
use Doctrine\ORM\EntityManagerInterface;

/** Edita o nome de um contato da Base (o e-mail não muda; para trocar, exclua e inclua). Nome vazio limpa o nome. */
final class EditarNomeDoContatoComercialUseCase
{
    public function __construct(
        private readonly ContatoComercialRepository $contatos,
        private readonly EntityManagerInterface $em,
        private readonly AuditoriaDaBaseDeContatos $auditoria,
    ) {
    }

    /** @throws ContatoRecusado contato inexistente */
    public function executar(int $id, ?string $nome, User $master): void
    {
        $contato = $this->contatos->find($id) ?? throw new ContatoRecusado('Contato não encontrado.');
        $antes = $contato->getNome();
        $contato->renomear($nome, new \DateTimeImmutable());
        $this->em->flush();
        $this->auditoria->registrar(AuditoriaDaBaseDeContatos::RENOMEADO, (string) $id, ['email' => $contato->getEmail(), 'nome' => [$antes, $contato->getNome()]], $master);
    }
}
