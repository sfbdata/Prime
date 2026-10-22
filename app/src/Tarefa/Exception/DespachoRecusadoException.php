<?php

declare(strict_types=1);

namespace App\Tarefa\Exception;

/** Lançada quando o Despachar não pode gravar. A mensagem é a que o usuário lê. */
final class DespachoRecusadoException extends \DomainException
{
    public static function semConteudo(): self
    {
        return new self('Escreva o despacho ou anexe um arquivo.');
    }

    public static function foraDoEscritorio(): self
    {
        return new self('Meta não encontrada.');
    }

    /** "Enviar para revisão" aparece sempre (04B L66); fora da meta pendente, o servidor recusa sem mudar nada. */
    public static function envioForaDePendente(): self
    {
        return new self('Só a meta pendente pode ser enviada para revisão. Nada foi gravado.');
    }

    public static function devolucaoForaDeRevisao(): self
    {
        return new self('Só a meta em revisão pode ser devolvida. Nada foi gravado.');
    }

    public static function semCodigoLivre(): self
    {
        return new self('Não foi possível registrar o despacho agora. Tente de novo.');
    }

    /** Tipo ou tamanho do anexo recusado pela regra dos documentos da pasta (C4): nada é gravado. */
    public static function anexoRecusado(string $motivo): self
    {
        return new self($motivo . ' Nada foi gravado.');
    }
}
