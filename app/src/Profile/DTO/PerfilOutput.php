<?php
declare(strict_types=1);
namespace App\Profile\DTO;

use App\Auth\Enum\StatusOab;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Profile\Entity\UserProfile;

final class PerfilOutput
{
    public function __construct(
        public readonly ?string $nomeCompleto,
        public readonly string $nomeExibido,
        public readonly string $iniciais,
        public readonly string $email,
        public readonly ?string $cargo,
        public readonly ?string $lotacao,
        public readonly ?string $fotoUrl,
        public readonly ?string $status,
        public readonly ?string $cpf,
        public readonly ?\DateTimeInterface $dataNascimento,
        public readonly ?string $ctps,
        public readonly ?string $serie,
        public readonly \DateTimeImmutable $membroDesde,
        public readonly ?string $oabNumero,
        public readonly ?string $oabUf,
        public readonly ?StatusOab $oabStatus,
        public readonly ?string $oabNomeOficial,
        public readonly ?\DateTimeImmutable $oabVerificadaEm,
    ) {
    }

    public static function fromEntity(UserProfile $perfil, User $user, ?UserTenant $userTenant): self
    {
        $nomeCompleto = $perfil->getNomeCompleto();
        $nomeExibido = $nomeCompleto ?? $user->getFullName() ?? '';

        return new self(
            nomeCompleto: $nomeCompleto,
            nomeExibido: $nomeExibido,
            iniciais: self::calcularIniciais($nomeExibido),
            email: $user->getEmail() ?? '',
            cargo: $userTenant?->getCargo()?->getNome(),
            lotacao: $userTenant?->getLotacao()?->getNome(),
            fotoUrl: $perfil->getFotoUrl(),
            status: $perfil->getStatus(),
            cpf: $perfil->getCpf(),
            dataNascimento: $perfil->getDataNascimento(),
            ctps: $perfil->getCtps(),
            serie: $perfil->getSerie(),
            membroDesde: $user->getCreatedAt() ?? new \DateTimeImmutable(),
            oabNumero: $user->getOabNumero(),
            oabUf: $user->getOabUf(),
            oabStatus: $user->getOabStatus(),
            oabNomeOficial: $user->getOabNomeOficial(),
            oabVerificadaEm: $user->getOabVerificadaEm(),
        );
    }

    private static function calcularIniciais(string $nome): string
    {
        $partes = array_filter(explode(' ', trim($nome)));

        if ($partes === []) {
            return '?';
        }

        $partes = array_values($partes);

        if (count($partes) === 1) {
            return mb_strtoupper(mb_substr($partes[0], 0, 1));
        }

        return mb_strtoupper(mb_substr($partes[0], 0, 1) . mb_substr($partes[count($partes) - 1], 0, 1));
    }
}
