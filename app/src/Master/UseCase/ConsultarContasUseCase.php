<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Master\Repository\PainelMasterRepository;
use Psr\Clock\ClockInterface;

/**
 * "Contas e escritórios" e o ranking de "Armazenamento" do Painel do Master: uma linha por escritório
 * fora da quarentena, com metadados (quantidades, consumo, situação). Nunca o conteúdo jurídico.
 *
 * Plano, cota de armazenamento e limite de usuários não existem no banco (E3): não saem daqui, e a
 * tela mostra "sem plano" / "sem limite" em vez de inventar.
 */
final class ConsultarContasUseCase
{
    public function __construct(
        private readonly PainelMasterRepository $repositorio,
        private readonly ClockInterface $relogio,
    ) {
    }

    /**
     * @return list<array{id: int, nome: string, desde: string, ultimoAcesso: ?string, titular: ?string, usuarios: int, pastas: int, documentos: int, bytes: int, bytesRecentes: int, situacao: string, ehDoMaster: bool}>
     */
    public function executar(): array
    {
        $desde    = $this->relogio->now()->modify('-30 days');
        $usuarios = $this->repositorio->usuariosPorEscritorio();
        $pastas   = $this->repositorio->pastasPorEscritorio();
        $consumo  = $this->repositorio->consumoPorEscritorio($desde);

        $contas = [];
        foreach ($this->repositorio->escritorios() as $e) {
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
                'situacao'      => $e['ativo'] ? 'Ativa' : 'Suspensa',
                // Conta criada por um Master (C5): o painel esconde "Excluir conta" e o servidor recusa.
                'ehDoMaster'    => $e['do_master'],
            ];
        }

        return $contas;
    }
}
