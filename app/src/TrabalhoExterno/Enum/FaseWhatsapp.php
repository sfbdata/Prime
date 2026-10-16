<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Enum;

/**
 * As oito fases que podem gerar aviso pelo WhatsApp (`Component.WAF` do desenho): rótulo da fase e a
 * frase que completa "Olá, Fulano! <serviço> em <cidade>/<UF>: …".
 */
enum FaseWhatsapp: string
{
    case Nova = 'nova';
    case Analise = 'analise';
    case Proposta = 'proposta';
    case Aceita = 'aceita';
    case Pago = 'pago';
    case Execucao = 'execucao';
    case Validacao = 'validacao';
    case Concluida = 'concluida';

    public function rotulo(): string
    {
        return match ($this) {
            self::Nova => 'Solicitação recebida',
            self::Analise => 'Em análise',
            self::Proposta => 'Proposta enviada',
            self::Aceita => 'Proposta aceita',
            self::Pago => 'Pagamento confirmado',
            self::Execucao => 'Em execução',
            self::Validacao => 'Em validação',
            self::Concluida => 'Finalizada',
        };
    }

    public function frase(): string
    {
        return match ($this) {
            self::Nova => 'recebemos a sua solicitação e ela já está na fila de análise.',
            self::Analise => 'a sua demanda está em análise pela nossa equipe.',
            self::Proposta => 'a proposta comercial está pronta. Entre no BlueJus para conferir e aceitar.',
            self::Aceita => 'a proposta foi aceita. Vamos preparar a execução.',
            self::Pago => 'confirmamos o pagamento. Obrigado!',
            self::Execucao => 'a diligência está em execução. Acompanhe o andamento em tempo real.',
            self::Validacao => 'a execução foi concluída e está em conferência final.',
            self::Concluida => 'o serviço foi finalizado. Os comprovantes estão disponíveis no BlueJus.',
        };
    }

    /** @return array<string, bool> todas as fases ligadas (padrão de um contato novo) */
    public static function todasLigadas(): array
    {
        $fases = [];
        foreach (self::cases() as $f) {
            $fases[$f->value] = true;
        }

        return $fases;
    }
}
