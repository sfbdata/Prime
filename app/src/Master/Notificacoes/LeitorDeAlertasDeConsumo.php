<?php

declare(strict_types=1);

namespace App\Master\Notificacoes;

use App\Entity\Auth\User;
use App\Master\DTO\DestinoDaResposta;
use App\Master\DTO\MensagemDaCentral;
use App\Master\DTO\RespostaNaOrigem;
use App\Master\Repository\PainelMasterRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Origem `alerta_consumo`: o "Alerta de consumo" que o `AlertaDeConsumoNaTrilha` grava na trilha quando um envio faz
 * a conta subir de faixa (spec perfis-1.2-planos-e-limites P11; spec docs/specs/master-central-leitores-exclusao-e-consumo.md).
 * Lido da própria trilha (`audit_log`, sem tabela nova): vem do "Sistema BlueJus" e não aceita resposta (D7). O
 * assunto é o texto até os dois-pontos, como no desenho.
 */
final class LeitorDeAlertasDeConsumo implements OrigemDaCentral
{
    public const ORIGEM = 'alerta_consumo';

    private const SELECT = 'SELECT a.id, a.tenant_id, a.changes, a.created_at, t.name AS escritorio
                              FROM audit_log a
                         LEFT JOIN tenant t ON t.id = a.tenant_id
                             WHERE a.action = :acao AND a.entity_class = :entidade';

    public function __construct(
        private readonly Connection $conexao,
    ) {
    }

    public function origem(): string
    {
        return self::ORIGEM;
    }

    public function todas(): array
    {
        $linhas = $this->conexao->fetchAllAssociative(
            self::SELECT . ' ORDER BY a.created_at DESC, a.id DESC LIMIT :limite',
            self::filtro() + ['limite' => self::LIMITE],
            ['limite' => ParameterType::INTEGER],
        );

        return array_map($this->emMensagem(...), $linhas);
    }

    public function uma(int $idOrigem): ?MensagemDaCentral
    {
        $linha = $this->conexao->fetchAssociative(self::SELECT . ' AND a.id = :id', self::filtro() + ['id' => $idOrigem]);

        return $linha === false ? null : $this->emMensagem($linha);
    }

    public function destinoDaResposta(int $idOrigem): ?DestinoDaResposta
    {
        return null;
    }

    public function respostaNaOrigem(int $idOrigem): ?RespostaNaOrigem
    {
        return null;
    }

    public function registrarResposta(int $idOrigem, string $texto, User $master): void
    {
        throw new \DomainException('Alerta não aceita resposta.');
    }

    /** @return array{acao: string, entidade: string} */
    private static function filtro(): array
    {
        return ['acao' => PainelMasterRepository::ACAO_ALERTA_DE_CONSUMO, 'entidade' => PainelMasterRepository::ENTIDADE_ALERTA_DE_CONSUMO];
    }

    /** @param array<string, mixed> $l */
    private function emMensagem(array $l): MensagemDaCentral
    {
        $dados = json_decode((string) $l['changes'], true);
        $dados = is_array($dados) ? $dados : [];
        $tenantId = $l['tenant_id'] === null ? null : (int) $l['tenant_id'];
        $conta = $tenantId === null ? 'Conta' : 'Conta Nº ' . str_pad((string) $tenantId, 6, '0', \STR_PAD_LEFT);
        $nome = is_string($l['escritorio']) && $l['escritorio'] !== '' ? $l['escritorio'] . ' (' . $conta . ')' : $conta;
        $uso = isset($dados['percentual']) && is_numeric($dados['percentual']) ? sprintf(' Uso atual: %d%%.', (int) $dados['percentual']) : '';

        return new MensagemDaCentral(
            origem: self::ORIGEM,
            idOrigem: (int) $l['id'],
            tipo: 'alerta',
            rotuloDoTipo: 'Alerta',
            deNome: 'Sistema BlueJus',
            deEmail: null,
            escritorio: $tenantId === null ? '' : $conta,
            tenantId: $tenantId,
            assunto: 'Alerta de consumo',
            texto: sprintf('Alerta de consumo: %s passou de %d%% do armazenamento.%s', $nome, (int) ($dados['limiar'] ?? 0), $uso),
            em: new \DateTimeImmutable((string) $l['created_at']),
            podeResponder: false,
        );
    }
}
