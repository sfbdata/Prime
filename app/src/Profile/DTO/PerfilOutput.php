<?php
declare(strict_types=1);
namespace App\Profile\DTO;

use App\Auth\Enum\StatusOab;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Profile\Entity\UserProfile;
use App\Profile\Entity\VinculoNoEscritorio;
use App\Profile\Enum\Atuacao;
use App\Profile\Enum\Genero;
use App\Profile\Enum\VinculoColaborador;

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
        public readonly ?string $telefone = null,
        public readonly VinculoColaborador $vinculo = VinculoColaborador::Clt,
        public readonly ?\DateTimeImmutable $dataAdmissao = null,
        public readonly ?\DateTimeImmutable $contratoInicio = null,
        public readonly ?string $cnpj = null,
        public readonly ?\DateTimeImmutable $revisadoEm = null,
        public readonly ?Genero $genero = null,
        public readonly ?Atuacao $atuacao = null,
        /** A chave "Registra ponto eletrônico" no escritório atual (spec seguranca-b-jornada): o rótulo do vínculo lê ela. */
        public readonly bool $registraPonto = false,
        /** Só alimenta o modal de edição; a grade e a revisão do cadastro não mostram (desenho 07). */
        public readonly ?string $chavePixRecompensa = null,
    ) {
    }

    /** Vínculo ausente vale como Empregado CLT (padrão do desenho 07). */
    public static function fromEntity(UserProfile $perfil, User $user, ?UserTenant $userTenant, ?VinculoNoEscritorio $vinculo = null): self
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
            telefone: $perfil->getTelefone(),
            chavePixRecompensa: $perfil->getChavePixRecompensa(),
            vinculo: $vinculo?->getTipo() ?? VinculoColaborador::Clt,
            dataAdmissao: $userTenant?->getDataAdmissao(),
            contratoInicio: $vinculo?->getContratoInicio(),
            cnpj: $vinculo?->getCnpj(),
            revisadoEm: $perfil->getRevisadoEm(),
            genero: $perfil->getGenero(),
            atuacao: $perfil->getAtuacao(),
            registraPonto: $userTenant?->isRegistraPonto() ?? false,
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
