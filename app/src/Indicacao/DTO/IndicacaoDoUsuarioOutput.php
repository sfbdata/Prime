<?php

declare(strict_types=1);

namespace App\Indicacao\DTO;

/** Uma linha de "SUAS INDICAÇÕES" (bj-contas.js L122): "CONTA CRIADA" quando `contaCriada`, senão "ENVIADA". */
final class IndicacaoDoUsuarioOutput
{
    public function __construct(
        public readonly ?string $nome,
        public readonly ?string $email,
        public readonly string $canal,
        public readonly \DateTimeImmutable $em,
        public readonly bool $contaCriada,
    ) {
    }

    /** @return array{nome: ?string, email: ?string, canal: string, em: string, contaCriada: bool} */
    public function paraJson(): array
    {
        return ['nome' => $this->nome, 'email' => $this->email, 'canal' => $this->canal, 'em' => $this->em->format('d/m/Y'), 'contaCriada' => $this->contaCriada];
    }
}
