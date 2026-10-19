<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Contribua\Service\SituacaoDoEspaco;
use App\Master\DTO\ContaAlteradaOutput;
use App\Master\DTO\EspacoDaContaOutput;
use App\Master\Repository\PainelMasterRepository;
use Psr\Clock\ClockInterface;

/**
 * "Contas e escritórios" e o ranking de "Armazenamento" do Painel do Master: uma linha por escritório
 * fora da quarentena (e, à parte, as contas em quarentena, spec perfis-1.2-contas C4b), com metadados (quantidades, consumo, situação). Nunca o conteúdo jurídico.
 *
 * Plano, limite de armazenamento, % da cota, faixa e limite de usuários (`espaco`) saem do
 * `RetratoDoEspaco` da Contribua (spec perfis-1.2-planos-e-limites P5), montado com o uso que o painel
 * já mediu (mesmas tabelas e mesma regra do bloqueio): uma medição por conta, não duas.
 */
final class ConsultarContasUseCase
{
    public function __construct(
        private readonly PainelMasterRepository $repositorio,
        private readonly ClockInterface $relogio,
        private readonly int $carenciaPurgaDias,
        private readonly SituacaoDoEspaco $situacaoDoEspaco,
    ) {
    }

    /**
     * @return list<array{id: int, nome: string, desde: string, ultimoAcesso: ?string, titular: ?string, usuarios: int, pastas: int, documentos: int, bytes: int, bytesRecentes: int, situacao: string, ehDoMaster: bool, purgaEm: ?string, espaco: array<string, mixed>}>
     */
    public function executar(): array
    {
        $desde    = $this->relogio->now()->modify('-30 days');
        // Os vínculos da conta em quarentena continuam até a purga: a contagem dela também aparece (C4b).
        $usuarios = $this->repositorio->usuariosPorEscritorio() + $this->repositorio->usuariosPorEscritorioEmQuarentena();
        $pastas   = $this->repositorio->pastasPorEscritorio();
        $consumo  = $this->repositorio->consumoPorEscritorio($desde);

        $contas = [];
        // As contas em quarentena (C4b) vêm depois, para a opção "Em quarentena" do filtro. Pastas e
        // consumo do painel não olham a quarentena: nelas saem zerados.
        foreach ([...$this->repositorio->escritorios(), ...$this->repositorio->escritoriosEmQuarentena()] as $e) {
            $id = $e['id'];
            $contas[] = [
                'id'            => $id,
                'nome'          => $e['nome'],
                'desde'         => (new \DateTimeImmutable($e['criado_em']))->format('m/Y'),
                'ultimoAcesso'  => isset($usuarios[$id]['ultimoAcesso']) ? (new \DateTimeImmutable($usuarios[$id]['ultimoAcesso']))->format(\DATE_ATOM) : null,
                'titular'       => $e['titular'],
                'usuarios'      => $usuarios[$id]['usuarios'] ?? 0,
                'pastas'        => $pastas[$id] ?? 0,
                'documentos'    => $consumo[$id]['documentos'] ?? 0,
                'bytes'         => $consumo[$id]['bytes'] ?? 0,
                'bytesRecentes' => $consumo[$id]['bytesRecentes'] ?? 0,
                'situacao'      => ContaAlteradaOutput::situacao($e['ativo'], $e['excluido_em'] !== null),
                // Conta criada por um Master (C5): o painel esconde "Excluir conta" e o servidor recusa.
                'ehDoMaster'    => $e['do_master'],
                // Data prevista da purga: excluidoEm + `tenant_carencia_purga_dias` (a mesma conta do comando de purga).
                'purgaEm'       => $e['excluido_em'] !== null ? (new \DateTimeImmutable($e['excluido_em']))->modify(sprintf('+%d days', $this->carenciaPurgaDias))->format('Y-m-d') : null,
                // Na quarentena o uso não é medido (sai zerado): vale o plano e o limite, não a faixa.
                'espaco'        => EspacoDaContaOutput::doRetrato($this->situacaoDoEspaco->retratar($id, $consumo[$id]['bytes'] ?? 0))->paraArray(),
            ];
        }

        return $contas;
    }
}
