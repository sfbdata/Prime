<?php

declare(strict_types=1);

namespace App\Comercial\UseCase;

use App\Comercial\DTO\DisparoOutput;
use App\Comercial\DTO\EstadoDaAutomacao;
use App\Comercial\Enum\Automacao;
use App\Comercial\Repository\AutomacaoDeEmailRepository;
use Doctrine\DBAL\Connection;

/**
 * O bloco "Automações" do painel do Master (desenho 05 L.262-268): o estado das 8 e os 6 últimos disparos. O
 * destinatário vai mascarado (`a***@dominio.com`, decisão de 19/10, Q7: minimização).
 */
final class ListarAutomacoesUseCase
{
    public const ULTIMOS = 6;

    public function __construct(
        private readonly AutomacaoDeEmailRepository $automacoes,
        private readonly Connection $conexao,
    ) {
    }

    /** @return array{automacoes: list<EstadoDaAutomacao>, ultimos: list<DisparoOutput>} */
    public function executar(): array
    {
        return ['automacoes' => $this->automacoes->todas(), 'ultimos' => $this->ultimosDisparos()];
    }

    /** @return list<DisparoOutput> */
    private function ultimosDisparos(): array
    {
        $linhas = $this->conexao->fetchAllAssociative(
            "SELECT origem, assunto, destinatario, criado_em, situacao FROM comercial_envio
              WHERE origem LIKE 'automacao:%' OR origem LIKE 'simulacao:%'
              ORDER BY criado_em DESC, id DESC LIMIT " . self::ULTIMOS,
        );

        return array_map(static fn (array $l): DisparoOutput => new DisparoOutput(
            self::gatilho((string) $l['origem']),
            (string) $l['assunto'],
            self::mascarar((string) $l['destinatario']),
            (new \DateTimeImmutable((string) $l['criado_em']))->format('d/m/Y H:i'),
            $l['situacao'] === 'simulado',
        ), $linhas);
    }

    private static function gatilho(string $origem): string
    {
        $automacao = Automacao::tryFrom(substr($origem, (int) strpos($origem, ':') + 1));

        return $automacao?->gatilho() ?? 'Automação';
    }

    public static function mascarar(string $email): string
    {
        $arroba = strrpos($email, '@');
        if ($arroba === false || $arroba === 0) {
            return '***';
        }

        return mb_substr($email, 0, 1) . '***' . substr($email, $arroba);
    }
}
