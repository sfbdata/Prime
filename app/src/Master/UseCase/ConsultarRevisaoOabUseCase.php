<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Auth\Enum\StatusOab;
use App\Auth\Repository\InscricaoOabRepository;
use App\Master\Repository\ConfiguracaoGlobalRepository;
use App\Master\Repository\PainelMasterRepository;
use App\Master\Service\InscricaoOab;

/**
 * "Revisão de OAB" do Painel do Master (desenho 05 - Perfis 1.2 de 31/10): todas as inscrições da plataforma, de
 * todos os escritórios, com as contagens de cada aba e o estado da validação automática.
 *
 * A tela filtra e busca no navegador, como no desenho; por isso a lista vem inteira (com teto de segurança).
 * "Pendentes" é tudo que não está Confirmada. O escritório exibido segue a regra de "Usuários e OABs": o do vínculo
 * primário, depois o de acesso mais recente ({@see ConsultarUsuariosOabUseCase::contaDaPessoa()}).
 */
final class ConsultarRevisaoOabUseCase
{
    public const FREQUENCIAS = ['cadastro', 'diaria', 'semanal'];
    private const TETO = 5000;

    public function __construct(
        private readonly InscricaoOabRepository $inscricoes,
        private readonly PainelMasterRepository $painel,
        private readonly ConfiguracaoGlobalRepository $configuracoes,
    ) {
    }

    /**
     * @return array{contagens: array{pend: int, div: int, nao: int, conf: int, todas: int}, linhas: list<array{id: int, nome: string, email: string, escritorio: string, numero: string, uf: string, inscricao: string, principal: bool, nomeCna: ?string, status: string, verificadaEm: ?string}>, auto: array{ligada: bool, frequencia: string, ultimaRodada: ?string}}
     */
    public function executar(): array
    {
        $nomePorEscritorio = array_column($this->painel->escritorios(), 'nome', 'id');
        $contaPorPessoa = [];
        foreach ($this->painel->pessoas() as $p) {
            $contaPorPessoa[$p['id']] = ConsultarUsuariosOabUseCase::contaDaPessoa($p['vinculos'], $nomePorEscritorio);
        }

        $contagens = ['pend' => 0, 'div' => 0, 'nao' => 0, 'conf' => 0, 'todas' => 0];
        $linhas = [];
        foreach ($this->inscricoes->paraRevisao([], '', 1, self::TETO) as $i) {
            $usuario = $i->getUsuario();
            $status  = $i->getStatus();
            $contagens['todas']++;
            $contagens[match ($status) {
                StatusOab::Confirmada    => 'conf',
                StatusOab::Divergente    => 'div',
                StatusOab::NaoVerificada => 'nao',
            }]++;
            if ($status !== StatusOab::Confirmada) {
                $contagens['pend']++;
            }

            $linhas[] = [
                'id'           => (int) $i->getId(),
                'nome'         => (string) $usuario->getFullName(),
                'email'        => (string) $usuario->getEmail(),
                'escritorio'   => $contaPorPessoa[(int) $usuario->getId()] ?? 'Conta não identificada',
                'numero'       => $i->getNumero(),
                'uf'           => $i->getUf(),
                'inscricao'    => InscricaoOab::normalizar($i->getNumero(), $i->getUf()),
                'principal'    => $i->ehPrincipal(),
                'nomeCna'      => $i->getNomeOficial(),
                'status'       => $status->value,
                'verificadaEm' => $i->getVerificadaEm()?->format(\DateTimeInterface::ATOM),
            ];
        }

        return ['contagens' => $contagens, 'linhas' => $linhas, 'auto' => $this->auto()];
    }

    /** @return array{ligada: bool, frequencia: string, ultimaRodada: ?string} */
    public function auto(): array
    {
        $valores = $this->configuracoes->buscarUnica()?->getRevisaoOab() ?? [];
        $frequencia = $valores['frequencia'] ?? 'cadastro';

        return [
            'ligada'       => ($valores['ligada'] ?? '0') === '1',
            'frequencia'   => \in_array($frequencia, self::FREQUENCIAS, true) ? $frequencia : 'cadastro',
            'ultimaRodada' => ($valores['ultimaRodada'] ?? '') !== '' ? $valores['ultimaRodada'] : null,
        ];
    }
}
