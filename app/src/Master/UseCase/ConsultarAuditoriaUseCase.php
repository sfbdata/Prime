<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Master\Repository\PainelMasterRepository;
use App\Master\Service\RotuloDeAuditoria;

/**
 * "Segurança e auditoria" do Painel do Master: a trilha administrativa do `audit_log` (contas,
 * usuários, vínculos, perfis, permissões, convites e purgas), traduzida para rótulo humano.
 *
 * O `changes` nunca sai cru: só o rótulo da ação e os NOMES dos campos alterados
 * ({@see RotuloDeAuditoria}). Entrada no sistema (login) não é auditada como evento próprio.
 */
final class ConsultarAuditoriaUseCase
{
    public const LIMITE = 1000;

    public function __construct(
        private readonly PainelMasterRepository $repositorio,
    ) {
    }

    /**
     * @return array{registros: list<array{quando: string, quem: string, acao: string, conta: string, contaId: ?int, detalhe: string}>, limite: int, truncado: bool}
     */
    public function executar(): array
    {
        $linhas = $this->repositorio->trilhaAdministrativa(self::LIMITE);

        $registros = [];
        foreach ($linhas as $l) {
            $rotulo = RotuloDeAuditoria::traduzir($l['acao'], $l['entidade'], $l['mudancas']);
            $registros[] = [
                'quando'  => (new \DateTimeImmutable($l['criadoEm']))->format(\DATE_ATOM),
                'quem'    => $l['responsavel'] ?? $l['responsavelEmail'] ?? 'Sistema',
                'acao'    => $rotulo['acao'],
                'conta'   => self::conta($l),
                'contaId' => $l['tenantId'],
                'detalhe' => $rotulo['detalhe'],
            ];
        }

        return ['registros' => $registros, 'limite' => self::LIMITE, 'truncado' => count($linhas) >= self::LIMITE];
    }

    /**
     * @param array{acao: string, mudancas: ?string, tenantId: ?int, conta: ?string} $linha
     */
    private static function conta(array $linha): string
    {
        if ($linha['conta'] !== null) {
            return $linha['conta'];
        }

        if ($linha['acao'] === 'purge' && $linha['mudancas'] !== null) {
            $dados = json_decode($linha['mudancas'], true);
            if (is_array($dados) && is_string($dados['nome'] ?? null)) {
                return $dados['nome'];
            }
        }

        return $linha['tenantId'] !== null ? 'Conta removida' : 'Plataforma (sem conta)';
    }
}
