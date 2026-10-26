<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Contribua\DTO\RetratoDoEspaco;
use App\Contribua\Service\SituacaoDoEspaco;
use App\Master\DTO\ContaAlteradaOutput;
use App\Master\DTO\EspacoDaContaOutput;
use App\Master\Entity\ConvenioDaUf;
use App\Master\Repository\ConvenioDaUfRepository;
use App\Master\Repository\PainelMasterRepository;
use App\Master\Service\LocalizacaoDaPessoa;
use App\Tenant\Service\NumeroDaConta;
use Psr\Clock\ClockInterface;

/**
 * "Contas e escritórios" e o ranking de "Armazenamento" do Painel do Master: uma linha por escritório
 * fora da quarentena (e, à parte, as contas em quarentena, spec perfis-1.2-contas C4b), com metadados (quantidades, consumo, situação). Nunca o conteúdo jurídico.
 *
 * Plano, limite de armazenamento, % da cota, faixa e limite de usuários (`espaco`) saem do
 * `RetratoDoEspaco` da Contribua (spec perfis-1.2-planos-e-limites P5), montado com o uso que o painel
 * já mediu (mesmas tabelas e mesma regra do bloqueio): uma medição por conta, não duas.
 *
 * `numero` é o mesmo "conta Nº" do Contribua e do Pix (`NumeroDaConta`, spec perfis-1.2-permissoes-e-hierarquia A1):
 * o selo da linha e a busca por número no painel. Os retratos saem em
 * lote (`SituacaoDoEspaco::retratarVarios`): uma consulta para todas as contas, não uma por conta.
 */
final class ConsultarContasUseCase
{
    public function __construct(
        private readonly PainelMasterRepository $repositorio,
        private readonly ClockInterface $relogio,
        private readonly int $carenciaPurgaDias,
        private readonly SituacaoDoEspaco $situacaoDoEspaco,
        private readonly ConvenioDaUfRepository $convenios,
    ) {
    }

    /**
     * @return list<array{id: int, numero: string, nome: string, desde: string, ultimoAcesso: ?string, titular: ?string, usuarios: int, pastas: int, documentos: int, bytes: int, bytesRecentes: int, situacao: string, ehDoMaster: bool, purgaEm: ?string, espaco: array<string, mixed>, uf: ?string, convenio: array{modo: string, valor: float}}>
     */
    public function executar(): array
    {
        $desde    = $this->relogio->now()->modify('-30 days');
        // Os vínculos da conta em quarentena continuam até a purga: a contagem dela também aparece (C4b).
        $usuarios = $this->repositorio->usuariosPorEscritorio() + $this->repositorio->usuariosPorEscritorioEmQuarentena();
        $pastas   = $this->repositorio->pastasPorEscritorio();
        $consumo  = $this->repositorio->consumoPorEscritorio($desde);

        $escritorios = [...$this->repositorio->escritorios(), ...$this->repositorio->escritoriosEmQuarentena()];
        $retratos = $this->retratos($escritorios, $consumo);
        $convenios = $this->convenios->todosPorUf();

        $contas = [];
        // As contas em quarentena (C4b) vêm depois, para a opção "Em quarentena" do filtro. Pastas e
        // consumo do painel não olham a quarentena: nelas saem zerados.
        foreach ($escritorios as $e) {
            $id = $e['id'];
            $contas[] = [
                'id'            => $id,
                'numero'        => NumeroDaConta::doId($id),
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
                'purgaEm'       => $this->purgaEm($e['excluido_em']),
                'espaco'        => EspacoDaContaOutput::doRetrato($retratos[$id])->paraArray(),
                ...self::convenioDaConta(LocalizacaoDaPessoa::uf($e['estado']), $convenios),
            ];
        }

        return $contas;
    }

    /**
     * Data prevista da purga: excluidoEm + `tenant_carencia_purga_dias` (a mesma conta do comando de purga).
     * Na quarentena o uso não é medido (sai zerado): vale o plano e o limite, não a faixa. O convênio é o da OAB do
     * estado da conta (desenho 05 VS 25.10.26); sem estado válido a conta não tem rótulo.
     */
    private function purgaEm(?string $excluidoEm): ?string
    {
        return $excluidoEm !== null ? (new \DateTimeImmutable($excluidoEm))->modify(sprintf('+%d days', $this->carenciaPurgaDias))->format('Y-m-d') : null;
    }

    /**
     * @param array<string, ConvenioDaUf> $convenios
     *
     * @return array{uf: ?string, convenio: array{modo: string, valor: float}}
     */
    private static function convenioDaConta(?string $uf, array $convenios): array
    {
        $c = $uf !== null ? ($convenios[$uf] ?? null) : null;

        return ['uf' => $uf, 'convenio' => ['modo' => $c?->getModo() ?? 'livre', 'valor' => $c?->getValor() ?? 0.0]];
    }

    /**
     * Retratos do espaço em lote (`retratarVarios`): uma consulta para todas as contas, não uma por conta.
     *
     * @param list<array{id: int}>                     $escritorios
     * @param array<int, array{bytes: int}>             $consumo
     *
     * @return array<int, RetratoDoEspaco>
     */
    private function retratos(array $escritorios, array $consumo): array
    {
        $bytesPorConta = [];
        foreach ($escritorios as $e) {
            $bytesPorConta[$e['id']] = $consumo[$e['id']]['bytes'] ?? 0;
        }

        return $this->situacaoDoEspaco->retratarVarios($bytesPorConta);
    }
}
