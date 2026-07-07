<?php

declare(strict_types=1);

namespace App\Djen\UseCase;

use App\Auth\Service\ValidadorOab;
use App\Djen\DTO\OabMonitoradaInput;
use App\Djen\Entity\OabMonitorada;
use App\Djen\Exception\OabMonitoradaJaExisteException;
use App\Djen\Repository\OabMonitoradaRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Cadastra uma OAB a ser monitorada no DJEN por um escritório.
 *
 * História: um advogado/gestor quer que o sistema acompanhe as publicações de uma OAB (dele, de um
 * sócio, ou de um terceiro que nem é usuário). Normaliza (só dígitos / UF maiúscula), valida o formato
 * pelo ponto único (ValidadorOab) e impede duplicata dentro do escritório. Erro de duplicata é de
 * entrada do usuário (OabMonitoradaJaExisteException), tratado no controller.
 */
final class AdicionarOabMonitoradaUseCase
{
    public function __construct(
        private readonly OabMonitoradaRepository $oabRepository,
        private readonly ValidadorOab $validadorOab,
    ) {
    }

    public function executar(OabMonitoradaInput $input, Tenant $tenant, User $criadoPor): OabMonitorada
    {
        $numero = preg_replace('/\D+/', '', (string) $input->numero) ?? '';
        $uf = strtoupper(trim((string) $input->uf));

        // Ponto único de validação de formato (lança \InvalidArgumentException se malformado).
        $this->validadorOab->validarFormato($numero, $uf);

        if ($this->oabRepository->findByNumeroUfDoTenant($numero, $uf, $tenant) !== null) {
            throw new OabMonitoradaJaExisteException($numero, $uf);
        }

        $oab = new OabMonitorada();
        $oab->setTenant($tenant);
        $oab->setNumero($numero);
        $oab->setUf($uf);
        $oab->setApelido($this->normalizarApelido($input->apelido));
        $oab->setCriadoPor($criadoPor);
        $oab->setAtivo(true);

        $this->oabRepository->salvar($oab, true);

        return $oab;
    }

    private function normalizarApelido(?string $apelido): ?string
    {
        if ($apelido === null) {
            return null;
        }

        $apelido = trim($apelido);

        return $apelido !== '' ? $apelido : null;
    }
}
