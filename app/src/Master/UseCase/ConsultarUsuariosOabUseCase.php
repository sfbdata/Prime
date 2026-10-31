<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Auth\Enum\StatusOab;
use App\Master\Repository\PainelMasterRepository;
use App\Master\Service\InscricaoOab;

/**
 * "Usuários e OABs" do Painel do Master: KPIs de inscrição, distribuição por UF e pendências.
 *
 * Contagem normalizada: número sem pontos e zeros à esquerda + UF em maiúsculas. Inscrição só conta
 * como confirmada depois da validação (`oab_status = confirmada`). As pendências identificam a
 * CONTA e a inscrição, nunca o nome da pessoa (o desenho mostra assim).
 */
final class ConsultarUsuariosOabUseCase
{
    public function __construct(
        private readonly PainelMasterRepository $repositorio,
    ) {
    }

    /**
     * @return array{registros: int, unicas: int, duplicadas: int, naoValidadas: int, ufs: array<string, int>, pendencias: list<array{tipo: string, conta: string, inscricao: string, normalizada: string}>}
     */
    public function executar(): array
    {
        $nomePorEscritorio = array_column($this->repositorio->escritorios(), 'nome', 'id');

        $registros = [];
        $naoValidadas = 0;
        $pendencias = [];
        foreach ($this->repositorio->pessoas() as $p) {
            $numero = $p['oabNumero'];
            $uf     = $p['oabUf'];
            $conta  = self::contaDaPessoa($p['vinculos'], $nomePorEscritorio);

            if ($numero === null) {
                if ($uf !== null) {
                    $pendencias[] = ['tipo' => 'incompleto', 'conta' => $conta, 'inscricao' => '', 'normalizada' => ''];
                }
                continue;
            }

            $normalizada = InscricaoOab::normalizar($numero, $uf);
            $registros[] = $normalizada;
            $inscricao = $numero . '/' . (string) $uf;

            if ($uf === null) {
                $pendencias[] = ['tipo' => 'incompleto', 'conta' => $conta, 'inscricao' => $inscricao, 'normalizada' => $normalizada];
            }

            if ($p['oabStatus'] !== StatusOab::Confirmada->value) {
                $naoValidadas++;
                $pendencias[] = [
                    'tipo'        => $p['oabStatus'] === StatusOab::Divergente->value ? 'divergente' : 'nao_validada',
                    'conta'       => $conta,
                    'inscricao'   => $inscricao,
                    'normalizada' => $normalizada,
                ];
            }

            if ($uf !== null && InscricaoOab::precisaFormatar($numero, $uf)) {
                $pendencias[] = ['tipo' => 'formatacao', 'conta' => $conta, 'inscricao' => $inscricao, 'normalizada' => $normalizada];
            }
        }

        $unicas = array_values(array_unique($registros));
        $ufs = [];
        foreach ($unicas as $chave) {
            $uf = (string) substr($chave, (int) strrpos($chave, '/') + 1);
            if ($uf !== '') {
                $ufs[$uf] = ($ufs[$uf] ?? 0) + 1;
            }
        }
        arsort($ufs);

        return [
            'registros'    => count($registros),
            'unicas'       => count($unicas),
            'duplicadas'   => count($registros) - count($unicas),
            'naoValidadas' => $naoValidadas,
            'ufs'          => $ufs,
            'pendencias'   => $pendencias,
        ];
    }

    /**
     * Conta exibida para a pessoa: a do vínculo primário, depois a de acesso mais recente.
     *
     * @param list<array{tenantId: int, primario: bool, ultimoAcesso: string}> $vinculos
     * @param array<int, string>                                               $nomePorEscritorio
     */
    public static function contaDaPessoa(array $vinculos, array $nomePorEscritorio): string
    {
        usort($vinculos, static function (array $a, array $b): int {
            if ($a['primario'] !== $b['primario']) {
                return $a['primario'] ? -1 : 1;
            }

            return strcmp($b['ultimoAcesso'], $a['ultimoAcesso']);
        });

        return $nomePorEscritorio[$vinculos[0]['tenantId'] ?? 0] ?? 'Conta não identificada';
    }
}
