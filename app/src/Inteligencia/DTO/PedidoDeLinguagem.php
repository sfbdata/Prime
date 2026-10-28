<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

/**
 * O que vai para o provedor de linguagem — formato neutro de fornecedor. `sistema` é a instrução
 * do sistema; `mensagens` é a conversa (`papel` = 'usuario' | 'assistente'). `rotuloDeUso` serve
 * para o adaptador etiquetar a chamada (custo/log), nunca entra no prompt.
 *
 * `anexos` (PR F, "Ler N documentos"): os arquivos que vão junto da ÚLTIMA mensagem do usuário, na ordem
 * do bloco DOCUMENTOS ANEXADOS do prompt. Quem não sabe mandar arquivo recusa o pedido com anexo de forma
 * explícita (nunca o manda sem eles, fingindo que leu); o `ProvedorAnthropic` manda inline, em base64.
 *
 * `modelo` (opcional, Chat I.A em página inteira): o modelo DESTA chamada. Nulo = o modelo da plataforma (o do banco,
 * escolhido pelo Master). Quem preenche já validou contra {@see \App\Inteligencia\Enum\ModeloDeIa}.
 *
 * Nunca é logado inteiro: carrega o texto das publicações (conteúdo processual) e, com anexos, os arquivos.
 */
final readonly class PedidoDeLinguagem
{
    /**
     * @param list<array{papel: string, conteudo: string}> $mensagens
     * @param list<AnexoDeDocumento>                        $anexos
     */
    public function __construct(
        public string $sistema,
        public array $mensagens,
        public int $maxTokens,
        public float $temperatura,
        public bool $exigeJson,
        public string $rotuloDeUso,
        public array $anexos = [],
        public ?string $modelo = null,
    ) {
    }

    public function temAnexos(): bool
    {
        return $this->anexos !== [];
    }

    /** Conteúdo das mensagens do usuário, concatenado — o que o modelo lê além do sistema. */
    public function textoDoUsuario(): string
    {
        $partes = [];
        foreach ($this->mensagens as $mensagem) {
            if ($mensagem['papel'] === 'usuario') {
                $partes[] = $mensagem['conteudo'];
            }
        }

        return implode("\n\n", $partes);
    }
}
