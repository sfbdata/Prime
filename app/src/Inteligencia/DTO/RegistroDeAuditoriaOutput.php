<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Enum\Agente;
use App\Inteligencia\Enum\Comando;
use App\Inteligencia\Enum\TipoDeAnalise;

/**
 * Uma linha do "Registro de auditoria" do drawer (desenho, dc L1030-1035: hora + o quê). Só
 * metadados da linha de `inteligencia_analise`: quem pediu, o quê (agente ou Push), quando e o
 * status. Nenhum texto do modelo entra aqui, nem da excluída.
 *
 * C6-IA-01: a linha também pode ser um download ou uma impressão da análise (`acao` =
 * {@see self::ACAO_DOWNLOAD} | {@see self::ACAO_IMPRESSAO}, lida do `audit_log`); aí `oQue` é o
 * rótulo da análise, `solicitanteNome` é quem baixou/imprimiu e o status não se aplica.
 */
final readonly class RegistroDeAuditoriaOutput
{
    public const ACAO_ANALISE = 'analise';
    public const ACAO_DOWNLOAD = 'download';
    public const ACAO_IMPRESSAO = 'impressao';

    public function __construct(
        public int $analiseId,
        public \DateTimeImmutable $quando,
        public string $oQue,
        public string $status,
        public string $statusRotulo,
        public ?string $solicitanteNome,
        public bool $excluida,
        public string $acao = self::ACAO_ANALISE,
    ) {
    }

    /**
     * O rótulo da análise na trilha: o do comando do drawer, senão o nome do agente, senão o do tipo.
     * A pergunta da aba Perguntar (F3) é "Pergunta à IA" mesmo tendo o Gestor como agente: a trilha
     * diz quem perguntou e quando, nunca o texto da pergunta (que é da pessoa).
     */
    public static function rotuloDaAnalise(?Agente $agente, TipoDeAnalise $tipo, ?Comando $comando = null): string
    {
        if ($tipo === TipoDeAnalise::PerguntaLivre) {
            return 'Pergunta à IA';
        }

        return $comando?->rotulo() ?? $agente?->nome() ?? match ($tipo) {
            TipoDeAnalise::ResumoPush => 'Análise IA do Push',
            TipoDeAnalise::AnalisePasta => 'Análise de agente',
            TipoDeAnalise::ResumoDocumento => 'Resumo de documento',
            TipoDeAnalise::ChatDaCentral => 'Chat com a IA',
        };
    }

    /**
     * Uma linha de download/impressão vinda do `audit_log` (ver
     * `AnaliseDeInteligenciaRepository::listarExportacoesDoAlvo`). `$acao` é
     * {@see self::ACAO_DOWNLOAD} ou {@see self::ACAO_IMPRESSAO}, já traduzida pelo UseCase.
     *
     * @param array{acao: string, analiseId: int, quando: \DateTimeImmutable, autorNome: ?string, agente: ?Agente, tipo: TipoDeAnalise, comando?: ?Comando} $linha
     */
    public static function daExportacao(array $linha, string $acao): self
    {
        return new self(
            analiseId: $linha['analiseId'],
            quando: $linha['quando'],
            oQue: self::rotuloDaAnalise($linha['agente'], $linha['tipo'], $linha['comando'] ?? null),
            status: '',
            statusRotulo: '',
            solicitanteNome: $linha['autorNome'],
            excluida: false,
            acao: $acao,
        );
    }

    public function ehDownload(): bool
    {
        return $this->acao === self::ACAO_DOWNLOAD;
    }

    public function ehImpressao(): bool
    {
        return $this->acao === self::ACAO_IMPRESSAO;
    }

    public static function fromEntity(AnaliseDeInteligencia $analise): self
    {
        $status = $analise->getStatus();

        return new self(
            analiseId: (int) $analise->getId(),
            quando: $analise->getCriadaEm(),
            oQue: self::rotuloDaAnalise($analise->getAgente(), $analise->getTipo(), $analise->getComando()),
            status: $status->value,
            statusRotulo: $status->rotulo(),
            solicitanteNome: $analise->getSolicitante()?->getFullName(),
            excluida: $analise->estaExcluida(),
        );
    }
}
