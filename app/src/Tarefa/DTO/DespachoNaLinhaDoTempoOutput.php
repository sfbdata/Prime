<?php

declare(strict_types=1);

namespace App\Tarefa\DTO;

/**
 * Um despacho na linha do tempo da meta (desenho 04B de 22/10, L71 e L161), além do que o `TimelineItemDTO` já
 * traz (texto, autor, anexo). Os rótulos (`natureza`, `tipo`) são chaves do catálogo; a tela aplica `|trans`.
 *
 * `tipo` é o que vai no cabeçalho depois de "DESPACHO N": a natureza, se houver; senão o rótulo da ação (L127:
 * "Comentário", "Enviado para revisão" ou "Devolvida"). `acao` (valor de `AcaoDoDespacho`, nulo nas antigas)
 * escolhe o ícone e a cor do marcador.
 *
 * O número ("DESPACHO N") e o "MAIS RECENTE" valem para o histórico inteiro e moram no
 * `ItemDaLinhaDoTempoOutput`. `codigo` nulo é mensagem de antes da Fase 2a: sem linha de local e data nem linha de registro (D1), e editável.
 * `registradoPor` e `cargo` são os do registro, congelados no momento do despacho.
 *
 * `para` são as pessoas mencionadas com @ (C3), pelo nome curto do desenho (L161: as duas primeiras palavras), para
 * o "quem → para". `anexoNome`/`anexoDocumentoId` são do anexo juntado aos documentos da pasta (C4, "nome · juntado à
 * pasta"); anexo antigo, no caminho de `uploads/tarefas`, deixa os dois nulos e segue no `TimelineItemDTO`.
 */
final class DespachoNaLinhaDoTempoOutput
{
    public function __construct(
        public readonly ?string $natureza,
        public readonly string $tipo,
        public readonly ?string $acao,
        public readonly ?string $codigo,
        public readonly string $autor,
        public readonly ?string $registradoPor,
        public readonly ?string $cargo,
        public readonly \DateTimeImmutable $registradoEm,
        public readonly ?string $cidade,
        public readonly ?\DateTimeImmutable $prazo,
        public readonly bool $prazoVencido,
        public readonly ?string $ciente,
        public readonly ?\DateTimeImmutable $cienteEm,
        public readonly ?string $certidao,
        public readonly ?string $certificadoPor,
        public readonly ?\DateTimeImmutable $certificadoEm,
        public readonly bool $podeCertificar,
        /** @var list<string> */
        public readonly array $para = [],
        public readonly ?string $anexoNome = null,
        public readonly ?int $anexoDocumentoId = null,
    ) {
    }
}
