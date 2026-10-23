<?php

declare(strict_types=1);

namespace App\Tarefa\Enum;

/**
 * As 8 caixas da esteira da pasta (desenho 04 de 22/10, dc L149 `FASES`). A meta é a passagem da pasta por uma
 * etapa; a ordem dos cases é a ordem da esteira. Nome, setor e descrição moram no catálogo
 * (`metas.etapa.<valor>.*`); o escritório pode renomear a caixa e a etapa (`CaixaDaEsteira`).
 */
enum EtapaDaPasta: string
{
    case Comercial      = 'comercial';
    case Abertura       = 'abertura';
    case Controladoria  = 'controladoria';
    case Documentacao   = 'documentacao';
    case Juridico       = 'juridico';
    case Protocolo      = 'protocolo';
    case Acompanhamento = 'acompanhamento';
    case Encerramento   = 'encerramento';

    /**
     * Regra do desenho para a meta sem etapa (dc L151 `faseDe`), na mesma ordem de teste: a primeira que casar
     * vence; o que não casar é Jurídico. A migration 149900 aplica a mesma regra em SQL nas metas antigas.
     */
    private const PELO_TITULO = [
        '/contrato|honor/u'              => self::Comercial,
        '/cadastro|abertura|relat/u'     => self::Abertura,
        '/an.lise de pasta|confer|pend/u' => self::Controladoria,
        '/document|procura/u'            => self::Documentacao,
        '/protocol|ajuiz|distribu/u'     => self::Protocolo,
        '/audi|prazo|publica|manifest/u' => self::Acompanhamento,
        '/arquiv|encerr/u'               => self::Encerramento,
    ];

    public static function deduzirDoTitulo(string $titulo): self
    {
        $minusculo = mb_strtolower($titulo);
        foreach (self::PELO_TITULO as $padrao => $etapa) {
            if (preg_match($padrao, $minusculo) === 1) {
                return $etapa;
            }
        }

        return self::Juridico;
    }

    /**
     * A mesma regra do {@see deduzirDoTitulo}, para a sugestão da Nova meta no navegador (desenho 02 L4049 `nmSug`):
     * o corpo de cada padrão, sem delimitador e sem flag, na ordem de teste. O template a renderiza num data-attribute
     * e o JS monta o `RegExp` dela, para não haver uma segunda lista que diverge desta. O que não casar é Jurídico.
     *
     * @return list<array{padrao: string, etapa: string}>
     */
    public static function regrasDoTitulo(): array
    {
        $regras = [];
        foreach (self::PELO_TITULO as $padrao => $etapa) {
            $regras[] = ['padrao' => substr($padrao, 1, (int) strrpos($padrao, '/') - 1), 'etapa' => $etapa->value];
        }

        return $regras;
    }

    /** Posição na esteira, de 1 a 8 ("CAIXA N"). */
    public function posicao(): int
    {
        return array_search($this, self::cases(), true) + 1;
    }

    public function icone(): string
    {
        return match ($this) {
            self::Comercial      => 'bi-briefcase',
            self::Abertura       => 'bi-folder-plus',
            self::Controladoria  => 'bi-clipboard-check',
            self::Documentacao   => 'bi-file-earmark-arrow-up',
            self::Juridico       => 'bi-pen',
            self::Protocolo      => 'bi-send-check',
            self::Acompanhamento => 'bi-activity',
            self::Encerramento   => 'bi-check2-circle',
        };
    }

    /** Chaves do catálogo; quem exibe aplica `|trans`. */
    public function rotulo(): string
    {
        return 'metas.etapa.' . $this->value . '.nome';
    }

    public function setor(): string
    {
        return 'metas.etapa.' . $this->value . '.setor';
    }

    public function descricao(): string
    {
        return 'metas.etapa.' . $this->value . '.descricao';
    }
}
