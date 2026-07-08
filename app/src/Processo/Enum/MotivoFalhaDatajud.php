<?php

declare(strict_types=1);

namespace App\Processo\Enum;

/**
 * Classifica por que uma consulta ao Datajud (CNJ) falhou, para que controller e CLI
 * mostrem uma mensagem amigável e o status HTTP adequado — em vez de um "erro genérico"
 * que junta causas muito diferentes (rede, CNJ fora do ar, limite, configuração).
 */
enum MotivoFalhaDatajud: string
{
    /** Chave da API ausente ou recusada pelo CNJ — problema de configuração do sistema. */
    case NaoConfigurado = 'nao_configurado';

    /** CNJ respondeu com erro de servidor (5xx) ou houve falha de rede. */
    case Indisponivel = 'indisponivel';

    /** A requisição ao CNJ estourou o tempo limite. */
    case Timeout = 'timeout';

    /** CNJ respondeu 429 — consultas demais em pouco tempo. */
    case LimiteExcedido = 'limite_excedido';

    /** O tribunal existe, mas não tem índice na base pública do CNJ (ex.: algumas justiças militares). */
    case TribunalSemBasePublica = 'tribunal_sem_base_publica';

    /** CNJ devolveu algo fora do formato esperado (não-JSON, sem hits, etc.). */
    case RespostaInvalida = 'resposta_invalida';

    public function statusHttp(): int
    {
        return match ($this) {
            // 500 (não 503): 503 é reservado pela borda nginx para a página de manutenção
            // (error_page 503 @manutencao) — usar 503 aqui arriscaria mascarar o erro.
            self::NaoConfigurado         => 500,
            self::Indisponivel           => 502,
            self::Timeout                => 504,
            self::LimiteExcedido         => 429,
            self::TribunalSemBasePublica => 422,
            self::RespostaInvalida       => 502,
        };
    }

    /**
     * Mensagem amigável e explicativa para o usuário final, com sugestão de ação.
     * A sigla do tribunal, quando informada, é usada onde ajuda o usuário a entender o contexto.
     */
    public function mensagemUsuario(?string $siglaTribunal = null): string
    {
        return match ($this) {
            self::NaoConfigurado =>
                'A consulta ao CNJ está indisponível no momento. Avise o suporte.',
            self::Indisponivel =>
                'A base do CNJ está instável no momento. Tente novamente em alguns minutos.',
            self::Timeout =>
                'O CNJ demorou a responder. Tente novamente em instantes.',
            self::LimiteExcedido =>
                'Muitas consultas em sequência. Aguarde alguns segundos e tente novamente.',
            self::TribunalSemBasePublica => ($siglaTribunal !== null && $siglaTribunal !== '')
                ? sprintf('O %s ainda não disponibiliza consulta pública pelo CNJ. Preencha os dados manualmente.', $siglaTribunal)
                : 'Este tribunal ainda não disponibiliza consulta pública pelo CNJ. Preencha os dados manualmente.',
            self::RespostaInvalida =>
                'O CNJ devolveu uma resposta inesperada. Tente de novo; se persistir, avise o suporte.',
        };
    }
}
