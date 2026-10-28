<?php

declare(strict_types=1);

namespace App\Tenant\UseCase;

use App\Contribua\DTO\RetratoDoEspaco;
use App\Contribua\Service\SituacaoDoEspaco;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Service\DigitoVerificador;
use App\Sync\Repository\TenantDriveConexaoRepository;
use App\Tenant\DTO\MeuEscritorioOutput;
use App\Tenant\Service\CodigoDaContaDoEscritorio;
use App\Tenant\Service\EnderecoDoEscritorio;
use App\Tenant\Service\GuardaDaEdicaoDoEscritorio;
use App\Tenant\Service\GuardaDaExclusaoPeloPrimario;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Monta o que a tela Meu Escritório mostra (spec meu-escritorio.md). Quem pode ver já foi decidido pela porta de leitura
 * do controller; aqui sai o que cada pessoa pode FAZER (editar, excluir). Na primeira consulta de um escritório sem
 * código de conta, gera e grava um (a migration não preenche os existentes).
 */
final class ConsultarMeuEscritorioUseCase
{
    public function __construct(
        private readonly CodigoDaContaDoEscritorio $codigos,
        private readonly GuardaDaEdicaoDoEscritorio $guardaDaEdicao,
        private readonly GuardaDaExclusaoPeloPrimario $guardaDaExclusao,
        private readonly SituacaoDoEspaco $espaco,
        private readonly TenantDriveConexaoRepository $drive,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function executar(User $ator, Tenant $tenant, bool $escritorioDaSessao = true): MeuEscritorioOutput
    {
        if ($this->codigos->garantir($tenant)) {
            $this->em->flush();
        }

        $doc = $tenant->getCnpj();
        $alteradoPor = $tenant->getAlteradoPor();

        return new MeuEscritorioOutput(
            (string) $tenant->getCodigoConta(),
            (string) $tenant->getName(),
            $doc,
            $this->formatarDoc($doc),
            $this->ouNulo($tenant->getOab()),
            $this->ouNulo($tenant->getEmailContato()),
            $this->ouNulo($tenant->getTelefone()),
            $this->ouNulo($tenant->getCep()),
            EnderecoDoEscritorio::linha($tenant),
            EnderecoDoEscritorio::cidadeUf($tenant),
            $tenant->getCreatedAt() ?? new \DateTimeImmutable(),
            $tenant->isActive() === true && $tenant->getExcluidoEm() === null,
            $this->statusDica($tenant),
            $alteradoPor === null ? null : ((string) $alteradoPor->getFullName() ?: $alteradoPor->getEmail()),
            $tenant->getAlteradoEm(),
            $tenant->getLogoChave() !== null && $tenant->getLogoChave() !== '',
            $this->guardaDaEdicao->pode($ator, $tenant),
            $escritorioDaSessao && $this->guardaDaExclusao->podeVer($ator, $tenant),
            $this->drive->findAtivaDoTenant($tenant) !== null,
        );
    }

    private function ouNulo(?string $valor): ?string
    {
        return $valor === null || trim($valor) === '' ? null : trim($valor);
    }

    private function formatarDoc(?string $doc): ?string
    {
        $d = DigitoVerificador::digitos($doc);

        return match (\strlen($d)) {
            14 => sprintf('%s.%s.%s/%s-%s', substr($d, 0, 2), substr($d, 2, 3), substr($d, 5, 3), substr($d, 8, 4), substr($d, 12, 2)),
            11 => sprintf('%s.%s.%s-%s', substr($d, 0, 3), substr($d, 3, 3), substr($d, 6, 3), substr($d, 9, 2)),
            default => $d === '' ? null : $d,
        };
    }

    /** "Plano X · A de B GB usados (N%)". Sem o retrato do espaço (plano ou consumo indisponíveis), a tela fica sem a dica. */
    private function statusDica(Tenant $tenant): ?string
    {
        try {
            $retrato = $this->espaco->doEscritorio((int) $tenant->getId());
        } catch (\Throwable) {
            return null;
        }
        $usados = str_replace(' GB', '', RetratoDoEspaco::formatarGb($retrato->bytesUsados));
        if ($retrato->bytesLimite === null) {
            return sprintf('Plano %s · %s GB usados', $retrato->planoNome, $usados);
        }

        return sprintf('Plano %s · %s de %s usados (%d%%)', $retrato->planoNome, $usados, RetratoDoEspaco::formatarGb($retrato->bytesLimite), (int) $retrato->percentual);
    }
}
