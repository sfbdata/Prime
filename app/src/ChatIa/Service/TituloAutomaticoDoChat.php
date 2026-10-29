<?php

declare(strict_types=1);

namespace App\ChatIa\Service;

use App\ChatIa\Entity\ConversaDeChat;
use App\Inteligencia\DTO\PedidoDeLinguagem;
use App\Inteligencia\Enum\ModeloDeIa;
use App\Inteligencia\Service\ProvedorDeLinguagem;

/**
 * O título da conversa depois da primeira resposta (decisão 8): uma chamada CURTA (`completar`, até 24 tokens, o modelo
 * mais barato), e, se ela falhar por qualquer motivo, o início da pergunta. Nunca lança: o título é enfeite, a resposta
 * já foi entregue. O texto do pedido nunca é logado.
 */
final class TituloAutomaticoDoChat
{
    public const MAXIMO_DE_TOKENS = 24;
    public const ROTULO_DE_USO = 'chat_livre_titulo/chat-livre-v1';
    private const TAMANHO_DO_FALLBACK = 60;
    private const TRECHO_DA_RESPOSTA = 600;

    public function __construct(private readonly ProvedorDeLinguagem $provedor)
    {
    }

    public function gerar(string $pergunta, string $resposta): string
    {
        try {
            $titulo = $this->limpar($this->provedor->completar($this->pedido($pergunta, $resposta))->texto);
        } catch (\Throwable) {
            $titulo = '';
        }

        return $titulo !== '' ? $titulo : self::doInicioDaPergunta($pergunta);
    }

    public static function doInicioDaPergunta(string $pergunta): string
    {
        $linha = trim((string) preg_replace('/\s+/u', ' ', $pergunta));
        if (mb_strlen($linha) <= self::TAMANHO_DO_FALLBACK) {
            return $linha;
        }

        return rtrim(mb_substr($linha, 0, self::TAMANHO_DO_FALLBACK - 3)) . '...';
    }

    private function pedido(string $pergunta, string $resposta): PedidoDeLinguagem
    {
        return new PedidoDeLinguagem(
            sistema: 'Dê um título curto (no máximo 6 palavras, em português, sem aspas, sem ponto final e sem travessão) para a conversa abaixo. Responda só com o título.',
            mensagens: [['papel' => 'usuario', 'conteudo' => "PERGUNTA:\n" . mb_substr($pergunta, 0, self::TRECHO_DA_RESPOSTA) . "\n\nRESPOSTA:\n" . mb_substr($resposta, 0, self::TRECHO_DA_RESPOSTA)]],
            maxTokens: self::MAXIMO_DE_TOKENS,
            temperatura: 0.2,
            exigeJson: false,
            rotuloDeUso: self::ROTULO_DE_USO,
            modelo: ModeloDeIa::Haiku55->value,
        );
    }

    private function limpar(string $texto): string
    {
        $linha = trim(explode("\n", trim($texto))[0]);
        $linha = trim($linha, " \t\"'“”‘’*#.");

        return mb_substr($linha, 0, ConversaDeChat::TAMANHO_MAXIMO_DO_TITULO);
    }
}
