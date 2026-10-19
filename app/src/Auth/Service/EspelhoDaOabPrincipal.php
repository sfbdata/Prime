<?php

declare(strict_types=1);

namespace App\Auth\Service;

use App\Auth\Entity\InscricaoOab;
use App\Auth\Enum\TipoInscricaoOab;
use App\Auth\Repository\InscricaoOabRepository;
use App\Entity\Auth\User;

/**
 * Mantém a inscrição principal e os campos User.oab* em acordo (spec docs/specs/meu-perfil-oab-inscricoes.md §2).
 *
 * - `inscricoesDe`: se a pessoa tem OAB no espelho mas nenhuma inscrição (cadastro, convite ou criar escritório
 *   gravaram só no User), cria a principal a partir do espelho. Não dá flush: quem chama persiste junto.
 * - `doUsuarioParaPrincipal`: depois que a revisão do Master mudou o status no User (fluxo por User), copia
 *   status, nome oficial e data para a principal.
 * - `principalParaUsuario`: depois de mudar inscrições pelo perfil, copia a principal para o User (ou limpa).
 */
final class EspelhoDaOabPrincipal
{
    public function __construct(
        private readonly InscricaoOabRepository $inscricoes,
    ) {
    }

    /** @return list<InscricaoOab> */
    public function inscricoesDe(User $usuario): array
    {
        $lista = $this->inscricoes->doUsuario($usuario);
        if ($lista !== [] || !$this->temOabNoEspelho($usuario)) {
            return $lista;
        }

        $principal = new InscricaoOab($usuario, (string) $usuario->getOabNumero(), (string) $usuario->getOabUf(), TipoInscricaoOab::Principal);
        $this->copiarSituacaoDoUsuario($usuario, $principal);
        $this->inscricoes->salvar($principal);

        return [$principal];
    }

    public function doUsuarioParaPrincipal(User $usuario): void
    {
        foreach ($this->inscricoesDe($usuario) as $inscricao) {
            if ($inscricao->ehPrincipal()) {
                $inscricao->alterar((string) $usuario->getOabNumero(), (string) $usuario->getOabUf());
                $this->copiarSituacaoDoUsuario($usuario, $inscricao);
            }
        }
    }

    /** @param list<InscricaoOab> $inscricoes as que valem depois da mudança */
    public function principalParaUsuario(User $usuario, array $inscricoes): void
    {
        foreach ($inscricoes as $inscricao) {
            if ($inscricao->ehPrincipal()) {
                $inscricao->espelharNoUsuario();

                return;
            }
        }
        $usuario->setOabNumero(null);
        $usuario->setOabUf(null);
        $usuario->setOabStatus(null);
        $usuario->setOabNomeOficial(null);
        $usuario->setOabVerificadaEm(null);
    }

    private function temOabNoEspelho(User $usuario): bool
    {
        return (string) $usuario->getOabNumero() !== '' && (string) $usuario->getOabUf() !== '';
    }

    private function copiarSituacaoDoUsuario(User $usuario, InscricaoOab $inscricao): void
    {
        $status = $usuario->getOabStatus();
        if ($status !== null) {
            $inscricao->registrarVerificacao($status, $usuario->getOabNomeOficial(), $usuario->getOabVerificadaEm());
        }
    }
}
