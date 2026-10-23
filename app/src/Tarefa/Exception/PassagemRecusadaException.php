<?php

declare(strict_types=1);

namespace App\Tarefa\Exception;

/**
 * Lançada quando a passagem de etapa ou a devolução da meta não pode ser gravada (spec `minhas-metas-esteira-2210.md`,
 * Fase 3, D3). A mensagem é a que o usuário lê; falta de permissão vira 403 no controller.
 */
final class PassagemRecusadaException extends \DomainException
{
    private const SEM_PERMISSAO = 1;

    public function ehFaltaDePermissao(): bool
    {
        return $this->getCode() === self::SEM_PERMISSAO;
    }

    /** D3: concluir e passar adiante, o responsável da etapa ou quem criou a meta. */
    public static function semPermissaoParaPassar(): self
    {
        return new self('Só o responsável pela etapa ou quem criou a meta conclui e passa adiante.', self::SEM_PERMISSAO);
    }

    /** D3: devolver, só o responsável (não o próprio criador). */
    public static function semPermissaoParaDevolver(): self
    {
        return new self('Só o responsável pela meta devolve para quem enviou.', self::SEM_PERMISSAO);
    }

    public static function jaConcluida(): self
    {
        return new self('Esta meta já está concluída.');
    }

    public static function foraDoEscritorio(): self
    {
        return new self('Meta não encontrada.');
    }

    public static function tipoInvalido(): self
    {
        return new self('Escolha o tipo de demanda.');
    }

    public static function etapaInvalida(): self
    {
        return new self('Escolha uma etapa da sequência deste tipo de demanda.');
    }

    /** Spec 2.2: o motivo da volta de etapa vai nas pendências e é exigido. */
    public static function voltaSemMotivo(): self
    {
        return new self('Ao voltar de etapa, escreva o motivo nas pendências.');
    }

    public static function prazoInvalido(): self
    {
        return new self('O prazo não pode ser antes de hoje.');
    }

    public static function prazoIlegivel(): self
    {
        return new self('Informe uma data válida para o prazo.');
    }

    public static function textoLongo(): self
    {
        return new self('As pendências e o que foi feito vão até 2.000 caracteres cada.');
    }

    public static function devolucaoForaDePendente(): self
    {
        return new self('Só a meta pendente volta para quem enviou.');
    }

    public static function semQuemEnviou(): self
    {
        return new self('A meta não tem quem enviou para receber a devolução.');
    }

    /** Textos do desenho (04B L167). */
    public static function semMotivo(): self
    {
        return new self('Escolha o motivo da devolução.');
    }

    public static function semOQueFalta(): self
    {
        return new self('Explique o que falta para conseguir avançar.');
    }
}
