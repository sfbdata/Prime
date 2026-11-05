<?php

declare(strict_types=1);

namespace App\ChatIa\Service;

use App\ChatIa\Armazenamento\ChavesDeChatIa;
use App\ChatIa\Entity\AnexoDeChat;
use App\ChatIa\Repository\AnexoDeChatRepository;
use App\ChatIa\Repository\ConversaDeChatRepository;
use App\ChatIa\Repository\MensagemDeChatRepository;
use App\Inteligencia\Contract\RetencaoDoChatIa;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\RemocaoAposTransacao;

/**
 * A ponta do Chat I.A da retenção da BlueJus IA (auditoria 1.19; decisões do Coordenador de 05/11/2026: os anexos
 * expiram junto com as falas, e a conversa que fica sem fala é removida). Roda no console, sem sessão e sem o
 * TenantFilter: as consultas varrem todos os escritórios de propósito. O texto das falas não vai a log.
 *
 * Ordem (skill `armazenamento-de-arquivos`, INV-6): as chaves dos arquivos são montadas dentro da transação, ANTES de
 * as linhas saírem; os arquivos só saem depois do COMMIT. Falha do banco sobe e nenhum arquivo sai.
 */
final class ExpiracaoDoChatIa implements RetencaoDoChatIa
{
    public function __construct(
        private readonly MensagemDeChatRepository $mensagens,
        private readonly AnexoDeChatRepository $anexos,
        private readonly ConversaDeChatRepository $conversas,
        private readonly TransacaoDoChat $transacao,
        private readonly RemocaoAposTransacao $remocao,
    ) {
    }

    public function apagarAntesDe(\DateTimeImmutable $limite): array
    {
        /** @var array{0: array{mensagens: int, anexos: int, conversas: int}, 1: list<ChaveDeArquivo>} $apagado */
        $apagado = $this->transacao->executar(fn (): array => $this->apagarNoBanco($limite));
        [$contagens, $chaves] = $apagado;

        // Depois do COMMIT (o fim do `executar`): o banco já decidiu, os arquivos podem sair.
        $this->remocao->remover($chaves, 'chat_ia_retencao');

        return $contagens;
    }

    /** @return array{0: array{mensagens: int, anexos: int, conversas: int}, 1: list<ChaveDeArquivo>} */
    private function apagarNoBanco(\DateTimeImmutable $limite): array
    {
        $afetadas = $this->mensagens->conversasComMensagemAntesDe($limite);
        $chaves = self::chavesDe($this->anexos->dasMensagensAntesDe($limite));
        $anexos = $this->anexos->apagarDasMensagensAntesDe($limite);
        $mensagens = $this->mensagens->apagarAntesDe($limite);

        // Só as conversas que perderam fala nesta rodada. A trava impede que uma fala nova chegue entre a conferência
        // e a remoção (e que um anexo solto apareça depois de as chaves serem montadas).
        $vazias = $this->conversas->travarVaziasEntre($afetadas);
        $soltos = $this->anexos->dasConversas($vazias);
        $chaves = [...$chaves, ...self::chavesDe($soltos)];
        $conversas = $this->conversas->apagarVaziasTravadas($vazias);

        return [['mensagens' => $mensagens, 'anexos' => $anexos + count($soltos), 'conversas' => $conversas], $chaves];
    }

    /**
     * @param list<AnexoDeChat> $anexos
     *
     * @return list<ChaveDeArquivo>
     */
    private static function chavesDe(array $anexos): array
    {
        return array_map(static fn (AnexoDeChat $a): ChaveDeArquivo => ChavesDeChatIa::anexo($a), $anexos);
    }
}
