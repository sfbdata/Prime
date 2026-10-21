<?php

declare(strict_types=1);

namespace App\Auth\Service;

use App\Auth\Entity\InscricaoOab;
use App\Auth\Enum\StatusOab;
use App\Auth\Enum\TipoInscricaoOab;
use App\Auth\Repository\InscricaoOabRepository;
use App\Entity\Auth\User;

/**
 * Mantém a inscrição principal e os campos User.oab* em acordo (spec docs/specs/meu-perfil-oab-inscricoes.md §2).
 *
 * - `inscricoesDe`: se a pessoa tem OAB no espelho mas nenhuma inscrição (cadastro, convite ou criar escritório
 *   gravaram só no User), cria a principal a partir do espelho, direto no banco e à prova de duas abas.
 * - `doUsuarioParaPrincipal`: depois que a revisão do Master mudou o status no User (fluxo por User), copia
 *   status, nome oficial e data para a principal.
 * - `principalParaUsuario`: depois de mudar inscrições pelo perfil, copia a principal para o User (ou limpa).
 * - `garantirPrincipal`: nos fluxos que gravam a OAB no User (cadastro público, convite da plataforma, criar
 *   escritório), cria a principal em memória ou acompanha a que já existe, sem flush (o flush é do caso de uso).
 *   Sem ela a pessoa ficaria fora da fila de revisão do Master, que é por inscrição.
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

        $this->inscricoes->criarPrincipalDoEspelhoSeFaltar($usuario);

        return $this->inscricoes->doUsuario($usuario);
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

    /**
     * Toda pessoa com OAB no espelho tem a inscrição principal. Usuário ainda não gravado (cadastro, convite): cria a
     * principal e só faz `persist` (ninguém mais enxerga esse usuário, não há corrida). Usuário já gravado (criar
     * escritório): se não há nenhuma inscrição, cria a principal com os valores em memória por INSERT ... ON CONFLICT
     * DO NOTHING (duas abas ao mesmo tempo não estouram o índice único); depois a principal acompanha número, UF e
     * situação do User. Não usa o SQL do `inscricoesDe`, que lê o User do banco e não veria o que o caso de uso ainda
     * não gravou.
     */
    public function garantirPrincipal(User $usuario): void
    {
        if (!$this->temOabNoEspelho($usuario)) {
            return;
        }

        if ($usuario->getId() !== null) {
            $existentes = $this->inscricoes->doUsuario($usuario);
            if ($existentes === []) {
                $this->inscricoes->criarPrincipalSeFaltar(
                    $usuario,
                    trim((string) $usuario->getOabNumero()),
                    strtoupper(trim((string) $usuario->getOabUf())),
                    $usuario->getOabStatus() ?? StatusOab::NaoVerificada,
                    $usuario->getOabNomeOficial(),
                    $usuario->getOabVerificadaEm(),
                );
                $existentes = $this->inscricoes->doUsuario($usuario);
            }
            foreach ($existentes as $inscricao) {
                if ($inscricao->ehPrincipal()) {
                    $inscricao->alterar(trim((string) $usuario->getOabNumero()), strtoupper(trim((string) $usuario->getOabUf())));
                    $this->copiarSituacaoDoUsuario($usuario, $inscricao);
                }
            }

            return;
        }

        $principal = new InscricaoOab(
            $usuario,
            trim((string) $usuario->getOabNumero()),
            strtoupper(trim((string) $usuario->getOabUf())),
            TipoInscricaoOab::Principal,
        );
        $this->copiarSituacaoDoUsuario($usuario, $principal);
        $this->inscricoes->salvar($principal);
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
