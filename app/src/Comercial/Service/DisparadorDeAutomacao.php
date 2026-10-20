<?php

declare(strict_types=1);

namespace App\Comercial\Service;

use App\Comercial\Enum\Automacao;
use App\Comercial\Envio\CarteiroInterface;
use App\Comercial\Envio\MensagemDeEmail;
use App\Comercial\Envio\TipoDeEnvio;
use App\Comercial\Repository\AutomacaoDeEmailRepository;

/**
 * Monta o modelo de uma automação e entrega ao Carteiro, se ela está ligada (spec §3.5). Todas transacionais, exceto
 * a `dica`, que é lista (`comercial_dicas`) e respeita o descadastro. Escopo plataforma.
 */
final class DisparadorDeAutomacao
{
    public const LISTA_DICAS = 'comercial_dicas';

    public function __construct(
        private readonly AutomacaoDeEmailRepository $automacoes,
        private readonly ModelosDeAutomacao $modelos,
        private readonly CarteiroInterface $carteiro,
    ) {
    }

    public function estaLigada(Automacao $automacao): bool
    {
        return $this->automacoes->estado($automacao)->ligada;
    }

    /**
     * @param array<string, string> $variaveis
     *
     * @return bool se entregou ao Carteiro
     */
    public function disparar(Automacao $automacao, string $para, ?string $nomeCompleto, array $variaveis = []): bool
    {
        if (!$this->estaLigada($automacao)) {
            return false;
        }
        $modelo = $this->modelos->montar($automacao, $nomeCompleto, $variaveis);
        $ehDica = $automacao === Automacao::Dica;
        $this->carteiro->enviar(new MensagemDeEmail(
            para: $para,
            assunto: $modelo->assunto,
            corpoHtml: $modelo->corpoHtml,
            tipo: $ehDica ? TipoDeEnvio::Lista : TipoDeEnvio::Transacional,
            origem: $automacao->origem(),
            lista: $ehDica ? self::LISTA_DICAS : null,
            nome: $nomeCompleto,
            selo: $modelo->selo,
            titulo: $modelo->titulo,
            rastrear: $ehDica,
        ));

        return true;
    }
}
