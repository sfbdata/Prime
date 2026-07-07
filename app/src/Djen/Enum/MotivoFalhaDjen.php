<?php

declare(strict_types=1);

namespace App\Djen\Enum;

/**
 * Classifica por que uma consulta ao DJEN (API pública do CNJ) falhou, para que controller e CLI
 * mostrem uma mensagem amigável e o status HTTP adequado — em vez de um "erro genérico".
 *
 * Espelha a MotivoFalhaDatajud do domínio Processo, com um caso a mais: SistemaOcupado, o
 * HTTP 500 "O sistema está muito ocupado" que o DJEN devolve sob carga (transitório, vale re-tentar).
 */
enum MotivoFalhaDjen: string
{
    /** Base URL do DJEN ausente — problema de configuração do sistema. */
    case NaoConfigurado = 'nao_configurado';

    /** DJEN respondeu com erro de servidor (5xx não-ocupado) ou houve falha de rede. */
    case Indisponivel = 'indisponivel';

    /** A requisição ao DJEN estourou o tempo limite. */
    case Timeout = 'timeout';

    /** DJEN respondeu 429 — consultas demais em pouco tempo (rate limit por IP). */
    case LimiteExcedido = 'limite_excedido';

    /** DJEN respondeu 500 "O sistema está muito ocupado" — sobrecarga temporária, re-tentável. */
    case SistemaOcupado = 'sistema_ocupado';

    /** DJEN devolveu algo fora do formato esperado (não-JSON, envelope de erro, 422 negocial). */
    case RespostaInvalida = 'resposta_invalida';

    public function statusHttp(): int
    {
        return match ($this) {
            // 500 (não 503): 503 é reservado pela borda nginx para a página de manutenção.
            self::NaoConfigurado  => 500,
            self::Indisponivel    => 502,
            self::Timeout         => 504,
            self::LimiteExcedido  => 429,
            self::SistemaOcupado  => 502,
            self::RespostaInvalida => 502,
        };
    }

    /**
     * Falha transitória: vale aplicar backoff e re-tentar (usado pelo comando de sincronização).
     * Configuração e resposta inválida NÃO são transitórias — re-tentar não adianta.
     */
    public function ehTransitorio(): bool
    {
        return match ($this) {
            self::SistemaOcupado, self::LimiteExcedido, self::Timeout, self::Indisponivel => true,
            self::NaoConfigurado, self::RespostaInvalida => false,
        };
    }

    public function mensagemUsuario(): string
    {
        return match ($this) {
            self::NaoConfigurado =>
                'A consulta ao DJEN está indisponível no momento. Avise o suporte.',
            self::Indisponivel =>
                'O DJEN está instável no momento. Tente novamente em alguns minutos.',
            self::Timeout =>
                'O DJEN demorou a responder. Tente novamente em instantes.',
            self::LimiteExcedido =>
                'Muitas consultas em sequência ao DJEN. Aguarde um minuto e tente novamente.',
            self::SistemaOcupado =>
                'O sistema do DJEN está sobrecarregado agora. Tente novamente em alguns minutos.',
            self::RespostaInvalida =>
                'O DJEN devolveu uma resposta inesperada. Tente de novo; se persistir, avise o suporte.',
        };
    }
}
